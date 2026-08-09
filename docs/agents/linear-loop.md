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

- **Budget:** at most **one running pair per agent** at a time (relaxes
  to two only while both are actively executing independent live suites —
  see Work and verify's Evidence scoping, "Parallel pairs"). The
  host-wide budget is dynamic — **1 docker core per running pair**,
  RAM-guarded (~2 GiB per actively-verifying pair), computed from the
  machine's actual resources by `pair_budget()` in `sandbox/bin/pair.sh`
  and **enforced by `pair.sh up`**: a new pair over budget refuses, with
  `DUO_PAIR_BUDGET_OVERRIDE=1` as the named report-not-hide escape hatch —
  never set it unless the dispatch prompt explicitly says so. Check
  `pair.sh list` before every `up`; need a second env outside the
  active-parallel-suites case below? Stop or destroy your first.
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
   `make regress-offline-all` green is part of this bar too (DUO-3285) —
   the full offline (no-docker) suite corpus, cheap enough to run every
   time; a change to `agent/src`/`Policy.php`/a manifest can break a
   mechanism whose own proof exists but sits outside the specific
   suite you happened to think to run by name.
5. Out-of-scope discoveries: report on the issue (or file a new one), never
   fix silently.

### Evidence scoping

- **Layer triggers, by diff.** `make regress-offline-all` (~5 min) is
  unconditional for every issue (DUO-3285). Live-pair regressions run only
  for suites whose covered mechanism the diff touches — consult each
  suite's header, not habit. Conformance sweeps run for CHANGED manifests
  and for harness changes (`sandbox/conformance/`, `sandbox/bin/` — the
  code that produces the evidence needs at least one real sweep of its
  own); a change to `agent/src`/`Policy.php` engine internals with no
  manifest edit still needs at least one representative sweep, using the
  cheapest affected manifest — and never fewer sweeps than Work and
  verify step 4 already requires where manifests or the harness changed
  (this subsection scopes WHEN layers apply; it never lowers step 4's
  bar). The reference certification bundle
  (`make certify-reference-bundle`) is REQUIRED per-issue only when
  manifest/adapter bytes changed (`manifests/*.json`, `providers/`,
  `interpreters/`, `regenerators/`, dispositions): those digests are what
  Policy load-time validation binds, so a stale registry refuses at
  runtime. The certification ATTESTATION additionally binds ~120 other
  inputs (engine, cli, spec, harness bytes — see
  `manifests/capabilities/evidence.json`), which such changes expire
  WITHOUT any runtime refusal; refreshing that is batched maintenance
  (run `php scripts/capability-registry.php check`; refresh when it
  reports expiry), not a per-issue gate — that batching is the ratified
  scoping decision this subsection records, not an oversight.
- **Exact-source gate: bind every live run to its commit (DUO-3377).** A
  pair's `agent`/`manifests` bind mounts resolve to the CANONICAL checkout,
  not to whichever checkout ran `pair.sh` (DUO-3277, so a persistent pair
  survives its worktree's removal) — so a live suite or sweep launched from
  your issue WORKTREE exercises the canonical checkout's bytes, and its
  verdict, green or red, is about code you did not write (observed live on
  DUO-3316: worktree at 3ae1ea5, pair mounted canonical b69fdf; the stale-
  code warnings read as a candidate regression for a day). `pair.sh
  up|reset|start` now always prints the mounted source path and HEAD; set
  `DUO_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD)` (conformance:
  `CONF_EXPECTED_SOURCE_SHA=...`, which `run.sh` exports as that) and any
  other source — wrong commit, or uncommitted `agent`/`manifests` bytes —
  refuses BEFORE the budget reservation, the database drop/create, and any
  container start. The remedy the refusal names is the one the bundle
  already uses: a standalone clone at the candidate HEAD (`git clone
  --branch {branch} {canonical} ../duo-wp-live-DUO-XXXX`), run from there.
  `stop`/`destroy`/`list` are deliberately ungated — cleanup must never be
  blocked by a variable left exported in your shell.
- **Ordering: the bundle is always LAST.** Dispatch the independent review
  before launching the bundle and land every finding first — a single
  manifest-byte fix from review invalidates a running bundle wholesale
  (observed live on DUO-3338: a review finding moved the elementor digest
  and cost a full bundle restart). Re-fetch and rebase onto `origin/main`
  immediately before launching, too: the certification attestation binds
  digests of agent/cli/spec bytes as well as manifests, so ANOTHER agent's
  merge to any bound input invalidates a running bundle just as thoroughly
  (also observed live on DUO-3338 — four upstream merges landed mid-run
  and cost the second restart). The bundle itself refuses linked
  worktrees and dirty trees: run it from a clean standalone clone at the
  branch's exact HEAD (the `duo-wp-cert-<issue>` pattern), never from
  your issue worktree, with `CERT_BUNDLE_PAIR`/`CERT_BUNDLE_PORT1`/
  `CERT_BUNDLE_PORT2` allocated from your `PORT_BASE` (its defaults —
  `certbundle`, 8880/8881 — collide on a shared host). It also serializes
  itself host-wide: a second launch refuses by naming the holder, or with
  `CERT_BUNDLE_WAIT=1` polls until the lock frees and takes it then (a
  bounded poll, not a queue — several waiters are not served in arrival
  order). Never clear the way by killing — see the pattern-kill field note
  below (DUO-3382). "The independent
  review" here is the dispatch protocol's pre-merge review-only subagent
  pass in its own checkout, recorded in the PR. Sequence: implement →
  offline-all → targeted live suites → targeted sweeps → review → fixes +
  registry regenerate → rebase onto fresh `origin/main` → bundle (clean
  clone) → generated-evidence commit → merge promptly (Close gate order
  unchanged).
- **Registry regenerate after ANY manifest/provider byte change**
  (`php scripts/capability-registry.php generate`, candidate state) before
  running anything live — provider/interpreter file bytes are digest-bound
  into adapter identity, so a stale registry refuses every `duo` command
  (observed live, twice, on DUO-3338).
- **Parallel pairs are safe and encouraged when the host budget allows**:
  independent live suites may run concurrently on DISTINCT pairs (names/
  ports parameterized from your `PORT_BASE`). The single-writer rule
  applies only to two writers on ONE pair. The Resource lifecycle budget
  of at most **one running pair per agent** (above) relaxes to two only
  while both are actively executing suites, never while idle.
- **Keep the standalone sweeps for changed manifests even though the
  bundle re-runs them**: a sweep failure costs a ~2-minute re-run; the
  same failure discovered inside the bundle costs the whole bundle.
  Sweeps are discovery; the bundle is evidence generation.
- **Cost yardstick (2026-08-09, this host):** offline-all ~5 min; one
  live-pair suite 6–15 min (pair boot ~2–3 min of that); one conformance
  sweep ~2–3 min; full bundle ~50–70 min. A worst-case blast radius
  (engine + six manifests, DUO-3338) is ~2 h serial; a typical bounded
  issue is 15–25 min.

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

## Field notes — shell & process hygiene (hard-won, 2026-08-06)

Lessons from live multi-agent operation on this repo; each one cost real
debugging time. Follow them; extend this list when you pay for a new one.

- **Checking for a running conformance/sweep process:** naive
  `pgrep -f "conformance/run.sh"` matches YOUR OWN watcher/daemon process
  (its command line contains the pattern) and any other agent's watcher —
  two watchers see each other forever (a self-sustaining false-busy
  deadlock, observed live). Use both defenses: the bracket trick AND a
  start-anchor — `pgrep -f "^[b]ash sandbox/conformance/run.sh"` — the
  character class can't match its own literal text, and the anchor
  excludes wrapper shells that merely EMBED the string (also observed
  live: an unanchored bracket pattern still matched a `bash -lc` watcher
  whose body quoted the plain literal).
- **Kill only a PID your own launcher recorded — never by pattern.** The
  bullet above is a pattern matching too much while READING the process
  table; this is the same defect with a signal attached, and it costs more.
  A launcher preamble of the `pkill -f certify_reference_bundle` shape
  matched three OTHER agents' bundle runs on 2026-08-09 — parent and
  wrapper both — orphaning each one's conformance child and destroying its
  work root (~40 minutes lost per victim, three victims, none of them the
  process the launcher meant to clean up after). A pattern cannot tell your
  run from anyone else's, and `-f` widens it to every wrapper and watcher
  whose command line merely QUOTES the string. If your launcher started the
  process it knows the PID, so kill that; if it didn't start it, it has no
  business killing it. And when the real intent is "don't start a second
  one," say that to the tool rather than to the process table:
  `certify_reference_bundle.sh` now takes a per-host lock
  (`/tmp/duo-certbundle.lock`) before its preflight and refuses by naming
  the holder's pid, pair, checkout, and how long it has held, with the exact
  `CERT_BUNDLE_WAIT=1` command to wait for it instead (a bounded poll, 90 min
  default, no ordering guarantee between waiters). The lock is an flock(2)
  held by a helper process that owns the descriptor — the same primitive
  `pair.sh` uses for the pair budget — so the KERNEL releases it when its
  holder stops existing. There is no corpse to detect, no staleness rule to
  get wrong, and nothing to clean up by hand after a crash or a kill: the
  next invocation simply acquires, within about a second. Killing a bundle
  to free its lock accomplishes exactly nothing the kernel would not have
  done, and costs whatever that run had built (DUO-3382;
  `sandbox/tests/regress_certbundle_lock.sh` proves each of those offline
  with real racing processes, on both lock backends).
- **Posting content to Linear (or any API) from a shell:** never build the
  payload inside a double-quoted shell argument. Double quotes do NOT
  suppress backticks or `$var` — backtick-quoted code spans in comment
  text get silently EXECUTED and blanked, and `$path` in a code sample
  expanded to the entire host `$PATH` inside a posted issue body (observed
  live; three artifacts corrupted, caught only by re-fetching). Pattern
  that is safe by construction: write the payload to a JSON spec FILE with
  the file tool (content never touches a command line), then POST the file
  (`python3 helper.py spec.json`). And always **re-fetch what actually
  posted** — the write succeeding says nothing about what the shell did to
  the bytes first.
- **Source-read any test script before running it** — even "just a regress
  script from main." A script may hardcode ANOTHER actor's pair name,
  fixed ports, or an unconditional `pair.sh reset` (observed live: an
  unread script reset a foreign pair — both DBs dropped and its host
  siterepo trees rm -rf'd; near-zero real loss only because the trees were
  the script's own regenerable fixtures and the issue had already merged;
  filed as DUO-3252). `reset` destroys more than containers. The mandate
  is per-run, not per-repo-trust: read the resource stanza (PAIR/ports/
  reset/up/destroy lines) of anything you invoke, every time it changed.
- **Close-gate scope notes go in the issue DESCRIPTION, not comments**
  (protocol step 6). A comment-only scope note scrolls away and the
  description keeps claiming the original full scope — the next claimer
  reads a lie (observed live: two consecutive Backlog returns of the same
  issue carried evidence-complete comments and an untouched description).
  At every partial-scope close/return: append `## Scope note` to the
  description itself stating delivered vs deferred, then comment.
- **Never pipe a large variable into `grep -q` under `pipefail` — it is
  a RACE, and races make your own reproduction attempts lie to you.**
  CONFIRMED root cause of a five-round live mystery (DUO-3267/PR #63): a
  render check `echo "$HTML" | grep -qiE ... || fail` failed five
  consecutive in-script runs against a healthy, full-size, marker-bearing
  133KB page — while the byte-identical assertion passed EVERY standalone
  reproduction, including under the same background runner and an
  explicit SIGPIPE-disposition test. Mechanism: the first marker sat at
  offset ~29KB, inside the first 64KB pipe-buffer fill, so grep could
  match and exit while echo still had ~69KB queued; echo dies by SIGPIPE
  (141); `pipefail` reports the PIPELINE as failed despite the match.
  Whether grep drains the stream first or exits early is scheduling —
  the script's context lost the race five-for-five, every ad-hoc probe
  won it. Two consequences: (a) the fix is structural, not statistical —
  herestrings (`grep -qE ... <<<"$VAR"`) have no pipe to break; convert
  every `echo "$BIGVAR" | grep -q` on sight; (b) when an in-script
  check contradicts your standalone reproduction, suspect a race in the
  CHECK before a mystery in the system — five theories (BSD grep,
  truncation, two plugin-cache mechanisms, execution context) were
  chased and killed before the race was caught, each "refuted" partly
  by probes the race itself was corrupting. Still-real secondary
  hazards from the same investigation: `grep "a\|b"` BRE alternation is
  a GNU-ism (this host's ugrep accepts it; plain BSD grep reads it
  literally — always `-E`); `curl -s` swallows mid-transfer truncation,
  so assert a byte floor ABOVE the last needed marker's offset before
  any content grep, and print `${#VAR}` in every failure path; `?page_id=N`
  301s under pretty permalinks, so bare curl without `-L` sees an empty
  body.
- **Assert the premise before the behavior in a live check.** A check that
  manufactures its own fixture and then asserts the engine's reaction has
  two failure domains, but its failure MESSAGE is written for only one of
  them — the engine's. `conformance/postdeploy/core.sh` manufactured a
  duplicate adoption key with five `docker compose run` calls and asserted
  the refusal; under five-agent docker load one of those returned empty
  with exit 0 (nothing for `set -e` to fire on), so the refusal
  legitimately did not fire and the sweep reported "duplicate full
  hierarchical adoption key was not rejected" — a false engine-regression
  scare plus a full certification-bundle restart (~1h, DUO-3380; the
  identical sweep standalone passed). Same family as DUO-3267: the harness
  lying about the system. Fix: read the manufactured state back and assert
  its exact shape BEFORE the behavior assertion, and say which domain
  failed — `conformance/run.sh` exports `require_fixture_ids` /
  `require_fixture_values` / `require_fixture_state` to every seed/
  postdeploy/check hook, and every message they emit carries the grep-able
  `fixture manufacture failed:` prefix (DUO-3381).
- **An assertion about an answer must first assert there WAS one.** The
  sibling half of the bullet above, and the residual path it left open: a
  refusal check neutralizes its own invocation's exit status on purpose —
  `OUT=$(wp_conf2 duo plan ... 2>&1) || RC=$?` so it can grep `$OUT`, or
  `|| fail` so it can name the engine — which is precisely what disables
  `set -e` for that call. When the `docker compose run` then dies at the
  DOCKER layer (container creation refused, daemon saturated by parallel
  agents), the check still runs, over a capture holding nothing but
  compose's container-creation chatter: the refusal grep legitimately does
  not match and the sweep accuses the ENGINE for a command that never
  reached it (that chatter pasted into the message from `$OUT` — exactly
  what DUO-3380 archived). The RC-only variants are worse: any non-zero
  exit satisfies them, so a dead invocation reports GREEN. Fix:
  `conformance/run.sh` exports `require_duo_answered <what> <human|json>
  <output>`, called BETWEEN the invocation and the assertion, prefix
  `infrastructure failure:` — a deliberate sibling of `fixture manufacture
  failed:` above, distinct because the domains differ (never built the
  premise vs. never got an answer). Keep the "answered" marker BROAD
  (wp-cli's `Success:`/`Error:`/`Warning:` framing, duo's own `duo:`
  prefix, PHP's fatal framing): a narrow one would demote a real,
  differently-worded engine failure into an infrastructure signal, which is
  the one thing such a helper must never do (DUO-3391).
- **CLOSED (DUO-3277) — the "bring up shared pairs from `duo-wp-main`,
  never a worktree" discipline this bullet used to require is now
  enforced by the tooling itself, not by remembering to follow it.**
  `pair.sh up` (any invocation, from any checkout) resolves the
  `agent`/`manifests` bind-mounts against the repo's canonical checkout
  via git's own common-dir — never wherever `pair.sh`'s own script file
  happened to be invoked from — so a pair brought up from a worktree no
  longer pins a mount that dies when that worktree is cleaned up at
  close-gate (observed live twice on r3b before this fix; see the issue
  for the live proof this closes it, including a pair recovering cleanly
  after its own origin worktree was removed out from under it). `pair.sh
  start` also now detects and loudly names any dead bind-mount source
  still baked into an existing container (a pair created before this fix
  shipped, or broken by any other means), naming the exact recovery
  instead of docker's own opaque failure. Kept here, in the past tense,
  as the record of why this used to require operator discipline at all.
