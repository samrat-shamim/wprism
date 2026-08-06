# Dispatch Loop (`LINEAR-LOOP`) — duo-wp

Simplified adaptation of genesis-monorepo's `docs/agents/dispatch-loop.md` for
this repo. It applies only when the dispatch prompt contains the literal token
`LINEAR-LOOP` (accept `LINEAR_LOOP` as the same token). Re-read this file at
the start of the run, after every close, and at every polling resume. If the
dispatch prompt conflicts with this file, prompt parameters win; this
procedure still wins over improvisation.

Two shipping modes:

- **Distributed (default for dispatched agents):** branch per issue → PR →
  squash-merge → verified close gate. Assume this mode unless the prompt says
  otherwise. Repo: `github.com/duotronic-ai/duo-wp` (squash-only merges;
  branches auto-delete on merge).
- **Owner-session:** direct commits to `main`. Reserved for sessions the
  project owner drives interactively on the primary machine — never for a
  dispatched agent. Owner sessions work in a dedicated worktree pinned to
  `main` (`git worktree add ../duo-wp-main main`), never in a checkout an
  agent may be using.

**One checkout per actor, no exceptions:** every agent and session operates in
its own clone or its own `git worktree` — never in a working copy anything
else uses. A shared checkout means one actor's branch switch or reset lands
under another actor's feet mid-edit (this rule exists because it happened).

Genesis's "no partial ships" becomes "no *silent* partial ships" — slices are
legitimate in this project only with an owner scope note (Close Gate step 6).

## Required parameters

- `AGENT_NAME` — title/comment prefix, e.g. `[codexmac] Original title`.
  Keep it short and alphanumeric (it also namespaces branches and sandbox
  pairs).
- `PROJECT_URL(s)` — default when omitted: the "Duo WP Branchability —
  Correctness Closure" project.
- Poll cadence and limit. Default: every 10 minutes, up to 60 minutes.
- `PORT_BASE` (distributed hosts sharing a VM) — a per-agent even port base
  ≥ 8900 for sandbox pairs; default 8900 when the agent is alone on the host.

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

Once per host, before the first claim:

```
git clone https://github.com/duotronic-ai/duo-wp duo-wp-<AGENT_NAME> && cd duo-wp-<AGENT_NAME>
bash scripts/agent-bootstrap.sh
```

The clone directory is yours alone (one checkout per actor, above). If the
host already has a clone that other sessions use, do NOT work in it — make
your own clone.

Your clone's default checkout stays on `main` and is never edited directly:
**every issue is worked in its own worktree created from `origin/main`**
(Work and Verify step 1). Worktrees live beside the clone as
`../duo-wp-wt-<issue>` and are removed after the issue closes.

The script fail-loud-verifies host prerequisites (git, jq, php, curl, docker +
compose v2; `gh` authenticated for the close gate), pre-pulls the sandbox
images, and prints current pair load. Re-run it whenever a verification step
reports a missing tool — a missing tool is a **setup step, not a blocker**;
escalate only when provisioning itself fails.

Sandbox discipline (see `docs/sandbox.md`):

- Envs come from `sandbox/bin/pair.sh`. Name pairs `<AGENT_NAME><issue-no>`
  (e.g. `a73213`) and allocate ports from your `PORT_BASE` so co-hosted agents
  never collide. **Destroy your pairs when done.** Heed the >2-live-pairs
  warning; check `pair.sh list` before adding load.
- Never run `docker compose down`/`make clean` against stacks you did not
  create; never touch another agent's pair.
- **Conformance sweeps run on your own pair** — never queue behind the
  shared `conf` instance: `CONF_PAIR=<AGENT_NAME>cf CONF1_PORT=<port>
  CONF2_PORT=<port> bash sandbox/conformance/run.sh <manifest>` (custom
  pair names require explicit ports; allocate from your `PORT_BASE`). The
  sweep pair counts against the host pair budget like any other — and it
  is a SECOND pair while your issue pair exists, so stop the issue pair
  during a sweep if the budget is tight, and destroy the sweep pair when
  the sweep ends. Only if you deliberately share the literal `conf` pair
  does the old single-writer rule apply: check
  `pgrep -f "conformance/run.sh"` and wait rather than interleave (two
  writers on one pair produce false failures — reset's DROP/CREATE lands
  under the other run's feet).

**Resource lifecycle (mandatory):**

- **Budget:** at most **one running pair per agent** at a time. The
  host-wide budget is dynamic — **1 docker core per running pair**,
  RAM-guarded (~2 GiB per actively-verifying pair), computed from the
  machine's actual resources by `pair_budget()` in `sandbox/bin/pair.sh`
  and **enforced by `pair.sh up`**: a new pair over budget refuses, with
  `DUO_PAIR_BUDGET_OVERRIDE=1` as the named report-not-hide escape hatch —
  never set it unless the dispatch prompt explicitly says so. Check
  `pair.sh list` before every `up`; need a second env? Stop or destroy
  your first.
- **Release when idle:** whenever you are not actively executing against
  your pair — polling Linear, waiting on a human/review, blocked, writing
  code or docs for more than ~15 minutes — `pair.sh stop <name>` (frees all
  its RAM/CPU; containers, volumes, and databases are kept) and
  `pair.sh start <name>` on resume. An idle-but-running pair is a leak.
- **Clean up when done:** `pair.sh destroy <name>` the moment an issue's
  verification is finished (before the PR wait, not after — re-verification
  after review feedback recreates it in minutes with `up`).
- **End-of-run sweep:** before your final report or exit, `pair.sh list`
  must show none of your pairs running (destroyed for closed issues,
  stopped for an issue you're returning to). Say so in the report.

## Candidate selection

1. List the project's Backlog issues; sort by priority, then unblock impact,
   then identifier.
2. Inspect serious candidates with `get_issue(includeRelations: true)`. Skip
   anything not Backlog, already assigned/claimed/prefixed by another agent,
   blocked by an open issue, or a parent with unfinished children. No
   relation-capable read = not claimable.
3. **Shared-working-copy check:** when operating in a working copy you share
   with other sessions (owner-session hosts), run `git status` — if the
   issue's likely files carry uncommitted changes you don't own, the issue is
   not claimable here; report it. Never edit/commit/revert another session's
   in-flight files. (Distributed clones are isolated by construction; this
   rule then applies only to the pair/`conf` contention above.)
4. Re-fetch the issue immediately before claiming. If anything changed or a
   blocker appeared, drop it and pick again.

## Claim gate

A claim is complete only when Linear readback proves it. Do not create a
branch or edit files before this passes.

1. Set state In Progress; assign yourself if the account allows.
2. Prefix the title exactly: `[AGENT_NAME] {original title}`.
3. Comment: `Claimed by AGENT_NAME. Worktree: {path}. Branch: {branch}.
   Original title: {title}.`
4. Re-read with relations and confirm: state, title prefix, claim comment,
   no new blocker. Missing marker → fix and re-read, or release. Never work
   an issue with a missing prefix, missing claim comment, or open blocker.

## Work and verify

1. **Per-issue worktree (always):**

   ```
   git fetch origin
   git worktree add ../duo-wp-wt-DUO-XXXX origin/main -b {branch}
   cd ../duo-wp-wt-DUO-XXXX
   ```

   `{branch}` is the issue's own `gitBranchName` from Linear. Always from
   freshly-fetched `origin/main`, never a stale local `main`; never work in
   the clone's own checkout. Re-entering an interrupted issue whose worktree
   or branch already exists: reuse the existing branch's worktree
   (`git worktree add <path> <branch>` if only the branch survives) and
   rebase it onto `origin/main` — never reset it to `origin/main`, which
   would discard the earlier work; after a rebase, pushes need
   `--force-with-lease`.
2. Fix the **root cause** within the pinned architecture — no quick fixes,
   silent fallbacks, or compat shims. Match the codebase's comment style
   (rationale-dense docblocks stating constraints and evidence).
3. Every fix ships with regression coverage that fails against the prior
   defect (a `sandbox/tests/regress_*.sh` or a conformance fixture — read an
   existing one for the idiom). Tests use the product path, not shortcuts.
4. Verify per the issue's Evidence section, plus mechanically: `php -l` every
   touched PHP file, `bash -n` every touched script. Anything touching
   `agent/src`, `manifests/`, or the harness needs conformance evidence:
   run `bash sandbox/conformance/run.sh <manifest>` locally for every
   affected manifest **before** opening the PR (PR CI runs the full
   9-manifest matrix as confirmation, not as your first test). Warnings are
   not green: human output, machine output, and exit status must agree.
5. Out-of-scope discoveries: report on the issue (or file a new one), never
   fix silently.

## Close gate (distributed mode — strict order)

1. Push the branch; open a PR titled `DUO-XXXX: {summary}` whose body carries
   the evidence: what changed (file:line), test tails (paste, don't
   paraphrase), conformance evidence for affected manifests, and anything
   re-homed or discovered.
2. The merge gate is your **local conformance evidence** from Work and Verify
   step 4, quoted in the PR body — the repo's CI workflow is currently
   disabled by owner decision. (If/when it is re-enabled, PR CI green becomes
   an additional required gate; a red leg is then yours to root-cause —
   your change / a latent real finding (valuable — report it) /
   infrastructure — before any re-run.)
3. `gh pr merge --squash` only when the PR fully satisfies the claimed issue
   against current `origin/main`. Then **prove the merge**:

   ```
   bash scripts/close-gate-check.sh <pr-number>
   ```

   It verifies GitHub reports MERGED with a 40-hex squash SHA (from the API —
   never local HEAD), single-parent squash, and `merge-base --is-ancestor`
   against freshly-fetched `origin/main`.
4. If the helper fails: do **not** mark Done. Comment the discrepancy on the
   issue (PR number, helper output, current `origin/main` HEAD) and escalate.
   A Done issue whose commit is not on `main` is the phantom-Done class.
5. Only after the helper passes: comment on the issue with the PR link, the
   squash SHA, the literal line `merge-base --is-ancestor: ok`, and the test
   evidence; remove your `[AGENT_NAME] ` title prefix; mark Done.
6. **Slice case:** if deliberately partial, do not mark Done — append a
   `## Scope note` to the issue description stating what was delivered (with
   the SHA) and where every remaining criterion was re-homed, then strip your
   `[AGENT_NAME] ` prefix and return the issue to Backlog. A Done issue must
   never hide unchecked boxes.
7. Re-read the issue and confirm the markers. Clean up the issue's whole
   footprint: `pair.sh destroy` any pair(s) you created for it,
   `git worktree remove ../duo-wp-wt-DUO-XXXX`, and `git branch -D {branch}`
   (the remote branch auto-deletes on merge). Then re-read this file before
   selecting the next issue.

(Owner-session mode replaces steps 1–3 with a direct commit to `main`
referencing `DUO-XXXX`, and step 5's evidence comment quotes the commit SHA
instead of a PR.)

## Stale claim release

A leftover claim marker is not a tombstone. Before declaring the queue empty:
inspect prefixed/claimed open issues (relations + comments). Release only when
there is no fresh claim comment, no foreign assignee doing visible work, no
open PR for its branch, and no human hold — comment
`Releasing stale claim from [{old}]. Reason: no active ownership found.`,
strip the prefix, return to Backlog, then claim through the normal gate. If
staleness can't be proven, it is not claimable — report it.

## Escalation / empty queue

Blocked (needs a human decision, a dependency, or verification that won't
provision): comment the blocker on the issue with exact evidence, leave the
claim in place, and report. When nothing is claimable, report each blocked
issue and its blocker, poll at the configured cadence, and stop when the
limit expires — never idle silently, never mark anything Done to make the
queue look better than it is.
