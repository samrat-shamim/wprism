# Dispatch Loop (`LINEAR-LOOP`) — duo-wp

Applies only when the dispatch prompt contains the literal token
`LINEAR-LOOP` (accept `LINEAR_LOOP` as the same token). Re-read this file at
the start of the run, after every close, and at every polling resume. If the
dispatch prompt conflicts with this file, prompt parameters win; this
procedure still wins over improvisation.

Shipping model: branch per issue → PR → squash-merge → verified close gate,
against `github.com/duotronic-ai/duo-wp` (squash-only merges; branches
auto-delete on merge).

**One checkout per actor, no exceptions:** every agent and session operates in
its own clone or its own `git worktree` — never in a working copy anything
else uses. A shared checkout means one actor's branch switch or reset lands
under another actor's feet mid-edit (this rule exists because it happened).

No *silent* partial ships: slices are legitimate only with an owner scope
note (Close gate step 6).

## The loop at a glance

Claim (Linear readback-proven) → worktree from fresh `origin/main` →
root-cause fix + regression that fails on the prior defect → offline-all +
the MINIMAL live set (Evidence scoping) → independent review-only pass in its
own checkout, land every finding → squash-merge → `close-gate-check.sh` proof
→ Linear close (PR link, squash SHA, the literal line
`merge-base --is-ancestor: ok`, evidence; strip prefix; Done) → destroy
pairs, remove worktree, delete branch → re-read this file → next issue. The
glance is a map, not the procedure — the sections below govern.

## Required parameters

- `AGENT_NAME` — title/comment prefix, e.g. `[codexmac] Original title`.
  Keep it short and alphanumeric (it also namespaces branches and sandbox
  pairs).
- `PROJECT_URL(s)` — default when omitted: the "Duo WP Branchability —
  Correctness Closure" project.
- Poll cadence and limit. Default: every 10 minutes, up to 60 minutes.
- `PORT_BASE` (hosts sharing a VM) — a per-agent even port base
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
your own clone. The clone's default checkout stays on `main` and is never
edited directly: every issue is worked in its own worktree from
`origin/main` (Work and verify step 1), living beside the clone as
`../duo-wp-wt-<issue>` and removed after the issue closes.

The script fail-loud-verifies host prerequisites (git, jq, php, curl, docker +
compose v2; `gh` authenticated for the close gate), pre-pulls the sandbox
images, and prints current pair load. Re-run it whenever a verification step
reports a missing tool — a missing tool is a **setup step, not a blocker**;
escalate only when provisioning itself fails.

Sandbox discipline (see `docs/sandbox.md`):

- Envs come from `sandbox/bin/pair.sh`. Name pairs `<AGENT_NAME><issue-no>`
  (e.g. `a73213`) and allocate ports from your `PORT_BASE` so co-hosted agents
  never collide. **Destroy your pairs when done.** Heed `pair.sh`'s
  over-budget warning; check `pair.sh list` before adding load.
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
  does the old single-writer rule apply: check for a running sweep (see the
  pgrep field note) and wait rather than interleave — two writers on one
  pair produce false failures (reset's DROP/CREATE lands under the other
  run's feet).

**Resource lifecycle (mandatory):**

- **Budget:** run as many pairs as are ACTIVELY executing independent live
  suites on DISTINCT pairs — parallel verification is encouraged whenever it
  genuinely shortens the wall clock — and none while idle. (This bullet is
  the single home of the per-agent rule; the single-writer rule applies only
  to two writers on ONE pair.) The host-wide budget is dynamic — **2 pairs
  per docker core** (after a 2-core reserve for the shared MariaDB and
  daemon churn; pairs are DB/PHP-boot-bound, not CPU-bound), RAM-guarded at
  ~1 GiB per actively-verifying pair (typical active use; the `mem_limit`s
  in `pair.yml` remain the per-container backstop for a runaway container,
  not an aggregate host guarantee), computed from
  the machine's actual resources by `pair_budget()` in `sandbox/bin/pair.sh`
  and **enforced by `pair.sh up`**: a new pair over budget refuses, with
  `DUO_PAIR_BUDGET_OVERRIDE=1` as the named report-not-hide escape hatch —
  never set it unless the dispatch prompt explicitly says so. Check
  `pair.sh list` before every `up`; a pair with no suite actively running
  against it does not qualify — stop it (Release when idle, below). One
  exemption: the pair recorded by a HELD host certification lock is already
  budgeted — a bundle destroys and recreates that one pair per leg across
  ~50 minutes and must not lose the slot it reserved mid-run (DUO-3396).
  The name is matched exactly; a crashed bundle's leftover record grants
  nothing.
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
3. Re-fetch the issue immediately before claiming. If anything changed or a
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
   (`git worktree add <path> <branch>` if only the branch survives). If no
   candidate freeze `C+B` is recorded, rebase the interrupted branch onto
   `origin/main` — never reset it to `origin/main`, which would discard the
   earlier work; after that rebase, pushes need `--force-with-lease`. If a
   freeze is recorded, resume and review/bundle the exact frozen `C` instead;
   a required bound-input update must use the explicit break-and-repeat rule
   in the ordering section above, not an automatic rebase on re-entry.
2. Fix the **root cause** within the pinned architecture — no quick fixes,
   silent fallbacks, or compat shims. Match the codebase's comment style
   (rationale-dense docblocks stating constraints and evidence).
3. Every fix ships with regression coverage that fails against the prior
   defect (a `sandbox/tests/regress_*.sh` or a conformance fixture — read an
   existing one for the idiom). Tests use the product path, not shortcuts.
4. Verify per the issue's Evidence section, plus mechanically: `php -l` every
   touched PHP file, `bash -n` every touched script, and — first and densest,
   because there is NO CI and local evidence is the whole merge gate — `make
   regress-offline-all` green (DUO-3285): the full offline (no-docker) corpus,
   cheap enough to run every time, catching mechanisms whose own proof sits
   outside the suite you'd think to run by name. Live conformance is then
   scoped to the MINIMAL reasonably-safe set per Evidence scoping below —
   usually zero or one sweep, never the full manifest matrix by habit.
   Warnings are not green: human output, machine output, and exit status must
   agree.
5. Out-of-scope discoveries: report on the issue (or file a new one), never
   fix silently.

### Evidence scoping

- **Minimal reasonably-safe live set, by diff — scope it, never run the matrix
  by habit.** `make regress-offline-all` (~5 min) is unconditional (DUO-3285);
  nothing below reduces it. The unit of scoping is the set of live surfaces
  that actually EXECUTE the changed code — that set can be empty, one, or
  several, and the cases below are how you compute it. Run that smallest set,
  before the PR; it is a requirement, not a suggestion:
  - **Every changed code path executes offline** (`docs/`, `Makefile`, offline
    suites and their fixtures) → **no sweep**; the offline corpus is the gate.
    The DIRECTORY is not the test: `sandbox/tests/` also holds LIVE-only
    scripts (grinds, certify, live `regress_*`), and an edit to one of those
    executes nowhere offline — prove its edited logic offline instead (a
    static pin or a simulated input driving the same jq/shell logic, the
    DUO-3362/DUO-3406 pattern) or run the edited script's own path once.
  - **`agent/src`/`Policy.php` engine internals** → **one sweep of the
    cheapest manifest that executes the changed path** (`core` for generic
    capture/apply/publish/lint paths; a plugin's manifest when the change is
    that plugin's surface — typed tables → woocommerce, json_refs/blobs →
    elementor, serialization → polylang). If independent review PROVES no live
    path reaches the change (a direct-call-only surface, a WordPress-free host
    verb), the affected set is empty: no sweep, with that proof recorded in
    the PR.
  - **A `sandbox/conformance/` / `sandbox/bin/` harness change** → one real
    sweep that EXECUTES the edited file: for a per-manifest check/seed/
    postdeploy that means THAT manifest's own sweep (an unrelated sweep never
    runs the edited file); for shared harness (`run.sh`, `asserts.sh`,
    `pair.sh`) any cheapest sweep exercises it. The code that produces
    evidence needs at least one real run of itself.
  - **A `manifests/*.json` / `providers/` / `interpreters/` / `regenerators/` /
    dispositions EDIT** → one sweep of EACH changed adapter's own fixture —
    minimality means skipping unaffected adapters, never skipping changed ones.
  - **A flaky / timing-sensitive assertion** → N-consecutive sweeps (typically
    3) of the ONE relevant manifest — not the matrix.
  - **A live-pair `regress-*` suite** runs only when the diff touches the
    mechanism its own header names — not by habit.
  - **The reference certification bundle** (`make certify-reference-bundle`) is
    a per-issue gate ONLY when manifest/adapter **digest** bytes changed (the
    edits above): those digests are what Policy load-time validation binds, so a
    stale registry refuses at runtime. (The ATTESTATION additionally binds ~120
    other inputs — engine/cli/spec/harness bytes, `manifests/capabilities/evidence.json`
    — that such changes expire WITHOUT a runtime refusal; refreshing that is
    batched maintenance, `php scripts/capability-registry.php check` and refresh
    on reported expiry, not a per-issue gate — the ratified scoping decision this
    records, not an oversight.) Otherwise the bundle is the operator's
    release-time certification — and because every certification-byte issue
    re-runs it anyway, the full matrix already keeps a rolling cadence at no
    added per-issue cost. It is NOT a per-issue gate and is never run "to be
    safe."
  - **Safety floor:** an engine change you genuinely cannot bound to specific
    surfaces gets a small representative SUBSET — `core` plus the richest
    affected adapter surface(s) — never a silent skip, and never the
    bundle-as-guess. Unknown → conservative subset, and say so in the PR.
- **Reproduce the mechanism offline first — the highest-leverage habit, and
  what keeps "one sweep" reasonably safe.** Where the mechanism admits one, a
  deterministic, mutation-proven offline reproduction of the exact failure
  (e.g. priming PHP's stat cache to force the stale-stat path; a stubbed
  compose-death to force the infrastructure-vs-engine branch) is the PRIMARY
  proof. Once that pin bites, the live sweep only CONFIRMS the real
  environment still passes — so ONE representative sweep suffices instead of
  a matrix, and the proof holds even when a shared, contended docker host
  makes live runs slow or flaky.
- **Exact-source gate: bind every live run to its commit (DUO-3377).** A
  pair's `agent`/`manifests` bind mounts resolve to the CANONICAL checkout,
  not to whichever checkout ran `pair.sh` (DUO-3277 — a persistent pair
  survives its worktree's removal), so a live run launched from your issue
  worktree exercises the canonical checkout's bytes and its verdict, green or
  red, is about code you did not write (a stale-code verdict once read as a
  candidate regression for a day — DUO-3316). `pair.sh up|reset|start` always
  prints the mounted source path and HEAD; set
  `DUO_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD)` (conformance:
  `CONF_EXPECTED_SOURCE_SHA=...`, which `run.sh` exports as that) and any
  other source — wrong commit, or uncommitted `agent`/`manifests` bytes —
  refuses BEFORE the budget reservation, the database drop/create, and any
  container start. The remedy the refusal names is the one the bundle already
  uses: a standalone clone at the candidate HEAD (`git clone --branch
  {branch} {canonical} ../duo-wp-live-DUO-XXXX`), run from there.
  `stop`/`destroy`/`list` are deliberately ungated — cleanup must never be
  blocked by a variable left exported in your shell.
- **Ordering: freeze the reviewed candidate before bundle certification.**
  The terminal sequence is: `rebase once → final independent review of exact
  C over B → record/freeze C+B → bundle at C → deterministic import/generate
  child E → merge`. The initial independent review still happens before the
  bundle work; the final review is the exact-head readback immediately before
  the freeze. Land every finding before that freeze — a single manifest-byte
  fix from review moves a digest and invalidates a running bundle wholesale
  (cost a full restart on DUO-3338).

  Record candidate `C`, base `B`, and the bundle bound-input closure in the PR
  before launch. Once `C+B` is recorded, do not rebase merely because
  unrelated `main` commits land after the freeze. Merge the frozen PR if
  GitHub still reports it clean/mergeable and no direct or conflicting
  semantic overlap is found. The only allowed post-freeze source-branch
  commit is deterministic bundle import/generation output (plus an
  evidence-pointer-only PR body update).

  If a required bound-input change must enter this PR, explicitly break the
  freeze, preserve the immutable old bundle as historical evidence, nominate
  one new candidate, and repeat the final review/bundle sequence once. Never
  silently relabel old evidence current. Unbound changes can proceed without
  invalidating the frozen candidate; document the range proof. This freeze
  rule does not weaken exact-source binding, bundle import validation,
  force-hatch refusal, independent review, or the close-gate proof below.

  The bundle remains the last evidence-producing step. It refuses linked
  worktrees and dirty trees: run it from a clean standalone clone at the
  branch's exact `C` (the `duo-wp-cert-<issue>` pattern), never from your
  issue worktree, with `CERT_BUNDLE_PAIR`/`CERT_BUNDLE_PORT1`/
  `CERT_BUNDLE_PORT2` allocated from your `PORT_BASE` (its defaults —
  `certbundle`, 8880/8881 — collide on a shared host). It also serializes
  itself host-wide: a second launch refuses by naming the holder, or with
  `CERT_BUNDLE_WAIT=1` polls until the lock frees and takes it then (a
  bounded poll, not a queue). Never clear the way by killing — see the
  pattern-kill field note below (DUO-3382). "The independent review" here is
  the dispatch protocol's pre-merge review-only subagent pass in its own
  checkout, recorded in the PR. Sequence (maximal — the live/sweep/bundle
  steps each apply only per Evidence scoping's minimal set): implement →
  offline-all → targeted live suites → targeted sweeps → review → fixes +
  registry regenerate → rebase once → final independent review of exact C over
  B → freeze C+B → bundle at C (clean clone) → deterministic import/generate
  child E → merge promptly (Close gate order unchanged).
- **Registry regenerate after ANY manifest/provider/regenerator byte change**
  (`php scripts/capability-registry.php generate`, candidate state) before
  running anything live — interpreter, manifest-sourced provider, and (since
  DUO-3360) regenerator file bytes are all digest-bound into adapter identity,
  so a stale registry refuses every `duo` command (observed live, twice, on
  DUO-3338).
- **Keep the standalone sweeps for changed manifests even though the
  bundle re-runs them**: a sweep failure costs a ~2-minute re-run; the
  same failure discovered inside the bundle costs the whole bundle.
  Sweeps are discovery; the bundle is evidence generation.
- **Cost yardstick (2026-08-09, this host):** offline-all ~5 min; one
  live-pair suite 6–15 min (pair boot ~2–3 min of that); one conformance
  sweep ~2–3 min; full bundle ~50–70 min. A worst-case blast radius
  (engine + six manifests, DUO-3338) is ~2 h serial; a typical bounded
  issue is 15–25 min.

## Close gate (strict order)

1. Push the branch; open a PR titled `DUO-XXXX: {summary}` whose body carries
   the evidence: what changed (file:line), test tails (paste, don't
   paraphrase), conformance evidence for affected manifests, and anything
   re-homed or discovered.
2. The merge gate is your **local conformance evidence** from Work and verify
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

## Field notes — shell & process hygiene (hard-won)

One rule per incident; each cost real debugging time. Follow them, and
extend this list when you pay for a new one. **Contract for new
notes: one bolded rule, a one-clause mechanism, and the issue ref — the full
narrative lives in the issue; when a suite or helper later enforces the
rule, shrink the note to a pointer.** Stories are recoverable via their
DUO refs where given.

- **Reading the process table for a running sweep:** naive
  `pgrep -f "conformance/run.sh"` matches your own watcher and every other
  agent's (a self-sustaining false-busy deadlock, observed live). Use BOTH
  defenses — bracket trick AND start anchor:
  `pgrep -f "^[b]ash sandbox/conformance/run.sh"`. The character class can't
  match its own literal; the anchor excludes wrapper shells that merely
  embed the string.
- **Kill only a PID your own launcher recorded — never by pattern.** A
  `pkill -f certify_reference_bundle` preamble matched three OTHER agents'
  runs — parent and wrapper both — orphaning each and destroying its work
  root (~40 min lost per victim, none of them the intended target;
  DUO-3382). A pattern cannot tell your run from anyone else's, and `-f`
  widens it to every wrapper that merely quotes the string. "Don't start a
  second one" is the tool's job, not the process table's:
  `certify_reference_bundle.sh` takes a per-host flock
  (`/tmp/duo-certbundle.lock`) BEFORE its preflight, which the KERNEL
  releases when its holder stops existing — no corpse, no staleness rule, nothing to
  clean by hand — and `CERT_BUNDLE_WAIT=1` waits for it (bounded poll, 90 min
  default, no ordering). Killing a bundle to free its lock achieves nothing the kernel
  wouldn't. Proven offline by `sandbox/tests/regress_certbundle_lock.sh`, both
  backends.
- **Posting content to any API from a shell:** never build the payload
  inside a double-quoted argument — double quotes do NOT suppress backticks
  or `$var` (backtick code spans in comment text got EXECUTED and blanked;
  `$path` expanded to the host `$PATH` inside a posted body; three artifacts
  corrupted, caught only by re-fetching). Write the payload to a spec FILE
  with the file tool (content never touches a command line), then POST the
  file (`python3 helper.py spec.json`), then **re-fetch what actually
  posted**
  — the write succeeding says nothing about what the shell did to the bytes.
- **Source-read any script before running it, every time it changed** —
  specifically its resource stanza (PAIR/ports/`reset`/`up`/`destroy`
  lines). An unread regress script from main hardcoded another actor's pair
  and an unconditional `pair.sh reset`: both DBs dropped, host siterepo
  trees rm -rf'd (DUO-3252; near-zero real loss only by luck). `reset`
  destroys more than containers; the mandate is per-run, not per-repo-trust.
- **Partial-scope closes/returns write the scope note into the issue
  DESCRIPTION (`## Scope note`), then comment** (Close gate step 6) — a
  comment-only note scrolls away while the description keeps claiming the
  original full scope, and the next claimer reads a lie (observed twice on
  one issue).
- **Never pipe a large variable into `grep -q` under `pipefail` — it is a
  RACE** that corrupts your own reproductions: with the marker inside the
  first 64KB pipe-buffer fill, grep can match and exit while echo still has
  bytes queued; echo dies by SIGPIPE (141) and `pipefail` fails the pipeline
  DESPITE the match. Whether it fires is scheduling — one context lost the
  race five-for-five while every standalone repro passed (a five-round
  mystery; DUO-3267/PR #63). Structural fix: herestrings
  (`grep -qE ... <<<"$VAR"`); convert every `echo "$BIGVAR" | grep -q` on
  sight. Corollary: when an in-script check contradicts your standalone
  reproduction, suspect a race in the CHECK before a mystery in the system.
  Same investigation: BRE `\|` alternation is a GNU-ism —
  this host's ugrep accepts it while plain BSD grep reads it literally
  (always `-E`);
  `curl -s` swallows mid-transfer truncation — assert a byte floor above
  the last needed marker's OFFSET, before any content grep, and print
  `${#VAR}` in every failure path; bare
  `?page_id=N` 301s under pretty permalinks (use `-L`).
- **A live check has three failure domains — premise, answer, observation —
  and must name the right one instead of accusing the engine.** Under
  multi-agent docker load a `compose run` can return EMPTY at exit 0
  (nothing for `set -e` to fire on), so: (a) a manufactured fixture can
  silently not land — read it back and assert its shape BEFORE the behavior
  assertion (`require_fixture_ids`/`require_fixture_values`/
  `require_fixture_state`, message prefix `fixture manufacture failed:`;
  DUO-3380/DUO-3381); (b) a captured duo invocation can die at the docker layer
  precisely because `|| RC=$?` / `|| fail` disables `set -e` for it (and
  RC-only variants are worse: ANY non-zero exit satisfies them, so a dead
  invocation reports GREEN) —
  assert there WAS an answer before asserting about the answer
  (`require_duo_answered <what> <human|json> <output>`, prefix
  `infrastructure failure:`; keep the "answered" marker BROAD — a narrow one
  would demote a real, differently-worded engine failure into an
  infrastructure signal; DUO-3391); (c) a hashed or compared observation of
  live target state can be empty — assert it carried bytes before
  hashing/comparing (`require_observed_nonempty`, same prefix; DUO-3413 —
  exit-code-gated where the read exits non-zero on genuine absence, so a
  real deletion still reaches the engine accusation; DUO-3401). All helpers
  live in `sandbox/conformance/asserts.sh`, the shared fragment BOTH
  hook-sourcing harnesses load (`conformance/run.sh`, which `export -f`s
  them to its child hooks, and `certify_version_matrix.sh` — a helper added
  to only one harness kills the other at bundle leg 12 with `command not
  found`, DUO-3408); the wiring is enforced by
  `sandbox/tests/regress_conformance_asserts.sh`.
- **CLOSED (DUO-3277):** the "bring pairs up only from the canonical
  checkout, never a worktree" discipline is now tooling-enforced —
  `pair.sh up` resolves the `agent`/`manifests` bind mounts via git's own
  common-dir from any invocation point, and `start` loudly names a dead
  mount source with its exact recovery. Kept as one note for why this once
  required operator discipline.
