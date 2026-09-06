# Dispatch Loop (`LINEAR-LOOP`) — wprism

Applies only when the dispatch prompt contains the literal token
`LINEAR-LOOP` (accept `LINEAR_LOOP` as the same token). Re-read this file at
the start of the run, after every close, and at every polling resume. If the
dispatch prompt conflicts with this file, prompt parameters win; this
procedure still wins over improvisation.

Shipping model: branch per issue → PR → squash-merge → verified close gate,
against `github.com/duotronic-ai/wprism` (squash-only merges; branches
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
- `PROJECT_URL(s)` — default when omitted: the "WPrism WP Branchability —
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
git clone https://github.com/duotronic-ai/wprism wprism-<AGENT_NAME> && cd wprism-<AGENT_NAME>
bash scripts/agent-bootstrap.sh
```

The clone directory is yours alone (one checkout per actor, above). If the
host already has a clone that other sessions use, do NOT work in it — make
your own clone. The clone's default checkout stays on `main` and is never
edited directly: every issue is worked in its own worktree from
`origin/main` (Work and verify step 1), living beside the clone as
`../wprism-wt-<issue>` and removed after the issue closes.

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
  `WPRISM_PAIR_BUDGET_OVERRIDE=1` as the named report-not-hide escape hatch —
  never set it unless the dispatch prompt explicitly says so. Check
  `pair.sh list` before every `up`; a pair with no suite actively running
  against it does not qualify — stop it (Release when idle, below).
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
   git worktree add ../wprism-wt-{issue-key} origin/main -b {branch}
   cd ../wprism-wt-{issue-key}
   ```

   `{branch}` is the issue's own `gitBranchName` from Linear. Always from
   freshly-fetched `origin/main`, never a stale local `main`; never work in
   the clone's own checkout. Re-entering an interrupted issue whose worktree
   or branch already exists: reuse the existing branch's worktree
   (`git worktree add <path> <branch>` if only the branch survives). Rebase the
   interrupted branch onto `origin/main` — never reset it to `origin/main`,
   which would discard the earlier work; after that rebase, pushes need
   `--force-with-lease`.
2. Fix the **root cause** within the pinned architecture — no quick fixes,
   silent fallbacks, or compat shims. Match the codebase's comment style
   (rationale-dense docblocks stating constraints and evidence).
3. Every fix ships with regression coverage that fails against the prior
   defect. Adapter-owned coverage stays in
   `adapter-packages/<slug>/tests/`; shared engine/product coverage goes in
   `sandbox/tests/offline/<domain>/`, and cross-adapter behavior goes in an
   explicit `integration-scenarios/<name>/`. Tests use the product path, not
   shortcuts.
4. Verify per the issue's Evidence section, plus mechanically: `php -l` every
   touched PHP file, `bash -n` every touched script, and — first and densest,
   because there is NO CI and local evidence is the whole merge gate — `make
   regress-offline-all` green: the full offline (no-docker) aggregate — shared
   leaves plus dynamic discovery of every adapter capsule — catching mechanisms
   whose own proof sits outside the suite you'd think to run by name. Live
   conformance is then
   scoped to the MINIMAL reasonably-safe set per Evidence scoping below —
   usually zero or one sweep, never the full manifest matrix by habit.
   Warnings are not green: human output, machine output, and exit status must
   agree.
5. Out-of-scope discoveries: report on the issue (or file a new one), never
   fix silently.

### Evidence scoping

- **Minimal reasonably-safe live set, by diff — scope it, never run the matrix
  by habit.** `make regress-offline-all` is unconditional;
  nothing below reduces it. The unit of scoping is the set of live surfaces
  that actually EXECUTE the changed code — that set can be empty, one, or
  several, and the cases below are how you compute it. Run that smallest set,
  before the PR; it is a requirement, not a suggestion:
  - **Every changed code path executes offline** (`docs/`, `Makefile`, offline
    suites and their fixtures) → **no sweep**; the offline corpus is the gate.
    `sandbox/tests/` is split by execution class — `offline/<domain>/` is what
    the merge gate runs, while `live/`, `grind/`, `certify/` and `spike/` are
    LIVE-only. Adapter capsules use the same class names under their own
    `tests/` directories; the fixed `regress-adapter-packages` row discovers
    their offline suites. An edit to live-only evidence therefore executes
    nowhere offline: prove its edited logic offline instead (a static pin or a
    simulated input driving the same jq/shell logic, the issue #3362/issue #3406
    pattern) or run the edited script's own path once.
  - **`agent/src`/`Policy.php` engine internals** → **one sweep of the
    cheapest manifest that executes the changed path** (`core` for generic
    capture/apply/publish/lint paths; a plugin's manifest when the change is
    that plugin's surface — typed tables → woocommerce, json_refs/blobs →
    elementor, serialization → polylang). If independent review PROVES no live
    path reaches the change (a direct-call-only surface, a WordPress-free host
    verb), the affected set is empty: no sweep, with that proof recorded in
    the PR.
  - **A package-local conformance asset or shared `sandbox/bin/` harness
    change** → one real sweep that EXECUTES the edited file: for a capsule's
    check/seed/postdeploy that means THAT adapter's own sweep (an unrelated
    sweep never runs the edited file); for shared harness (`run.sh`, `asserts.sh`,
    `pair.sh`) any cheapest sweep exercises it. The code that produces
    evidence needs at least one real run of itself.
  - **An `adapter-packages/<slug>/package/` edit** → first run that capsule's
    validator and offline tests, then one live sweep of EACH changed adapter's
    own fixture — minimality means skipping unaffected adapters, never skipping
    changed ones.
  - **A flaky / timing-sensitive assertion** → N-consecutive sweeps (typically
    3) of the ONE relevant manifest — not the matrix.
  - **A live-pair `regress-*` suite** runs only when the diff touches the
    mechanism its own header names — not by habit.
  - **A new WP extension joins through one capsule** — its manifest,
    disposition, runtime hooks, fixtures, and tests live under
    `adapter-packages/<slug>/`. Regenerate the derived runtime identity-name
    inventory; add a hand-reviewed permanent identity floor only when the
    capsule introduces a new `id_kind`. There is no hand-maintained adapter
    name allowlist, Makefile leaf, corpus row, or plugin dispatch switch. Its
    own sweep is the evidence.
  - **Safety floor:** an engine change you genuinely cannot bound to specific
    surfaces gets a small representative SUBSET — `core` plus the richest
    affected adapter surface(s) — never a silent skip, and never an unrelated
    sweep as a guess. Unknown → conservative subset, and say so in the PR.
- **Reproduce the mechanism offline first — the highest-leverage habit, and
  what keeps "one sweep" reasonably safe.** Where the mechanism admits one, a
  deterministic, mutation-proven offline reproduction of the exact failure
  (e.g. priming PHP's stat cache to force the stale-stat path; a stubbed
  compose-death to force the infrastructure-vs-engine branch) is the PRIMARY
  proof. Once that pin bites, the live sweep only CONFIRMS the real
  environment still passes — so ONE representative sweep suffices instead of
  a matrix, and the proof holds even when a shared, contended docker host
  makes live runs slow or flaky.
- **Exact-source gate: bind every live run to its commit (issue #3377).** A
  pair's `agent`/`adapter-packages`/`platform` bind mounts resolve to the
  CANONICAL checkout,
  not to whichever checkout ran `pair.sh` (issue #3277 — a persistent pair
  survives its worktree's removal), so a live run launched from your issue
  worktree exercises the canonical checkout's bytes and its verdict, green or
  red, is about code you did not write (a stale-code verdict once read as a
  candidate regression for a day — issue #3316). `pair.sh up|reset|start` always
  prints the mounted source path and HEAD; set
  `WPRISM_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD)` (conformance:
  `CONF_EXPECTED_SOURCE_SHA=...`, which `run.sh` exports as that) and any
  other source — wrong commit, or uncommitted
  `agent`/`adapter-packages`/`platform` bytes —
  refuses BEFORE the budget reservation, the database drop/create, and any
  container start. Run from a clean issue worktree at the candidate HEAD and
  make the live harness mount that exact worktree; never move the canonical
  checkout to make a test pass.
  `stop`/`destroy`/`list` are deliberately ungated — cleanup must never be
  blocked by a variable left exported in your shell.
- **Ordering: review the candidate against its base once, late.** Work in the
  issue worktree, land every review finding, record candidate `C` and base `B`.
  The maximal sequence is: implement → offline-all → targeted live suites →
  targeted sweeps → review/fixes → rebase once → final review of `C` over `B`
  → merge promptly.
- **An adapter package manifest / provider / interpreter / regenerator byte
  change moves adapter identity — recompile and re-pin, do not work around
  it.** Those file bytes
  are folded into the per-manifest row that `manifest_hash()` and each
  adapter's `digest` are hashes of
  (`agent/src/Policy/ArtifactPolicyIdentity.php:60-147`). A target still
  holding the previously compiled artifact refuses with
  `compiled_artifact_manifest_mismatch` — "compiled manifest/interpreter set
  does not match active pins"
  (`agent/src/Repository/CompiledArtifactReader.php:39-42`) — and a site repo
  pinned by content stops matching until the reviewed pin is updated
  (`wp wprism manifest-pin` prints the object). Expect this before a live run
  against a pair whose repo was compiled earlier; it is the mechanism working,
  not a defect to route around.
- **Cost yardstick (2026-08-19, this host):** `php tools/offline.php -j8`
  108.65 s wall over 251 suites (499.68 s of suite time); one live-pair suite
  6–15 min (pair boot ~2–3 min of that); one conformance sweep ~2–3 min. The
  offline number is a third of the pre-teardown ~5 min: `Policy::load()` no
  longer re-verifies nine certification closures per process, which used to
  dominate the corpus (see docs/dev-setup.md §Measured wall times).
- **Cost yardstick (2026-08-20, same host, post-restructure):** `php
  tools/offline.php -j8` 112.54 s wall over 252 suites (516.47 s of suite
  time). Moving the corpus into `offline/<domain>/` changed no runtime; the
  live-pair and conformance figures above are unchanged.

## Close gate (strict order)

1. Push the branch; open a PR titled `{issue-key}: {summary}` whose body carries
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
   `git worktree remove ../wprism-wt-{issue-key}`, and `git branch -D {branch}`
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
WPRISM refs where given.

- **Reading the process table for a running sweep:** naive
  `pgrep -f "conformance/run.sh"` matches your own watcher and every other
  agent's (a self-sustaining false-busy deadlock, observed live). Use BOTH
  defenses — bracket trick AND start anchor:
  `pgrep -f "^[b]ash sandbox/conformance/run.sh"`. The character class can't
  match its own literal; the anchor excludes wrapper shells that merely
  embed the string.
- **Kill only a PID your own launcher recorded — never by pattern.** A broad
  `pkill -f` can match other agents' parent and wrapper processes as well as
  yours, orphaning their runs and deleting their work. A pattern cannot prove
  ownership; record the exact child PID when launching a long test and stop
  only that process (issue #3382).
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
  trees rm -rf'd (issue #3252; near-zero real loss only by luck). `reset`
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
  mystery; issue #3267/PR #63). Structural fix: herestrings
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
  issue #3380/issue #3381); (b) a captured wprism invocation can die at the docker layer
  precisely because `|| RC=$?` / `|| fail` disables `set -e` for it (and
  RC-only variants are worse: ANY non-zero exit satisfies them, so a dead
  invocation reports GREEN) —
  assert there WAS an answer before asserting about the answer
  (`require_wprism_answered <what> <human|json> <output>`, prefix
  `infrastructure failure:`; keep the "answered" marker BROAD — a narrow one
  would demote a real, differently-worded engine failure into an
  infrastructure signal; issue #3391); (c) a hashed or compared observation of
  live target state can be empty — assert it carried bytes before
  hashing/comparing (`require_observed_nonempty`, same prefix; issue #3413 —
  exit-code-gated where the read exits non-zero on genuine absence, so a
  real deletion still reaches the engine accusation; issue #3401). All helpers
  live in `sandbox/conformance/asserts.sh`, the shared fragment BOTH
  hook-sourcing harnesses load (`conformance/run.sh`, which `export -f`s
  them to its child hooks, and `certify_version_matrix.sh` — a helper added
  to only one harness kills the other when it reaches that test with `command not
  found`, issue #3408); the wiring is enforced by
  `sandbox/tests/offline/guards/regress_conformance_asserts.sh`. For machine
  output, use `capture_wprism_json_success` or
  `capture_wprism_json_refusal` according to the expected exit class. They
  retain the merged stream long enough to distinguish an engine answer from a
  dead Compose invocation, publish only the last JSON line, and leave preceding
  diagnostics visible on stderr so warning gates still see them. Caller output
  variables may use any shell identifier except the reserved
  `__wprism_capture_` prefix. Calling
  `require_wprism_answered` directly validates that last line but deliberately
  does not rewrite the caller's mixed-stream variable; passing that variable
  to `jq` parses Compose's `Container ... Creating` prelude instead of the
  refusal envelope.
- **CLOSED (issue #3277):** `pair.sh up` defaults to the canonical checkout but
  accepts an exact related worktree through `WPRISM_SOURCE_ROOT`; the source must
  share the Git common directory and satisfy `WPRISM_EXPECTED_SOURCE_SHA` before
  any mutation. `start` still names a dead mount source with its exact
  recovery.
