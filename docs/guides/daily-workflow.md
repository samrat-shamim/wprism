# The daily workflow

This is the loop a WordPress team runs once WPrism is installed:

**branch → rehearse → capture → refresh/rebase → plan/status → release → verify
→ recover/reap.**

Two of those steps are composed verbs that sit on top of the older ones.
`wprism release` composes `wprism promote`; `wprism recover` drives the recovery runtime
you would otherwise type by hand. Both lower-level paths are still documented
and still supported, and both are below.

Every verb below is documented flag-by-flag in
[cli/README.md](../../cli/README.md). This guide is the connective tissue: what
each step is *for*, when it is the wrong step, and what the output means when
it stops you.

## The mental model, in one paragraph

Git stays git. The site repo is an ordinary repository with ordinary branches,
and WPrism adds no branching model of its own. What WPrism owns is the boundary
between that repository and a live WordPress install: `capture` reads an
environment into canonical state, `apply` writes canonical state into an
environment, `deploy` moves code and runs the lifecycle window where activation
hooks fire, and `promote` sequences code and state together under one lease and
one database checkpoint. Runtime data — sessions, orders, caches, submissions —
never enters the repository at all. That asymmetry is the design, not a gap:
see [DESIGN.md](../../DESIGN.md).

## Start of day: know your environments

```sh
wprism envs
wprism doctor stage
```

`wprism envs` lists the merged registry with a transport summary per environment;
an individually broken entry prints as an inline `ERROR:` row rather than
failing the whole listing. `wprism doctor <env>` runs nine gated checks — each one
after a failure is reported as failed rather than run, because a broken
transport makes every later check meaningless noise.

Two checks are worth knowing by name. `DISALLOW_FILE_MODS` is advisory but
load-bearing: it closes the wp-admin file-modification UI, which is the single
most common way installed code drifts out from under git (see
[code-updates.md](code-updates.md)). And the PHP and database version checks
compare against `docs/compatibility-baseline.json`, so an environment outside
the certified range tells you before a capture does.

If you are about to run something destructive against an environment you have
not touched in a while, `wprism driver-capabilities <env>` answers "will this
workflow even be attempted here" without contacting the target at all. Every
verb except `envs` and `driver-capabilities` runs that preflight for you first;
running it by hand is for when you want the answer before you commit to the
command.

## Branch

Branch the site repo the way you would branch any repository:

```sh
git checkout -b feature/pricing-page
```

Then make the change *on an environment* — a developer's local install, a
sandbox pair, whatever your team uses — because WordPress content is authored
in WordPress, not in a text editor over canonical JSON. WPrism's job starts when
you want that change to become a reviewable diff.

When the host exposes a machine-local environment provider, materialize the
branch from one coherent production cut:

```sh
wprism env materialize preview --from production --branch feature/pricing-page --ttl 86400
```

The branch ref must resolve to the clean branch currently checked out. WPrism
freezes production while it reads semantic truth, binds that export to one
immutable database/media snapshot set, attaches the named target, restores the
physical baseline, materializes the rebased candidate commit, and promotes the
exact compiled code-and-state release. Attach is the default because many
hosts provision environments outside WPrism. Use `--create` only when the target
provider explicitly advertises both create and receipt-backed destroy; WPrism
never guesses that attach implies provisioning.

`--ttl` publishes observable expiry metadata. It does not grant a provider
permission to delete the environment when the clock passes that time. Cleanup
is always the explicit, identity- and lease-fenced command:

```sh
wprism env reap preview
```

A target whose resource identity, ownership lease, mutation fence, or TTL
generation changed is refused before destroy or detach. Created targets are
destroyed; attached targets are detached and never destroyed. Repeating reap
returns the recorded absence proof without another provider mutation.

Hosts without the provider contract keep the existing workflow: point an
already-provisioned environment at the branch checkout, then use the ordinary
capture/deploy/apply/promote commands. The transport and provider remain
separate contracts—being able to run WP-CLI over local, Docker, or SSH does not
claim infrastructure authority.

## Capture

```sh
wprism capture dev
```

Capture is the only command that mints or repairs entity identity and
publishes canonical state. Everything else in the system consumes what capture
produced, which is why `wp wprism refresh-export` — the read-only production
export used by `refresh` below — is deliberately *not* a capture substitute.

Capture refuses rather than guesses. If it finds an unclassified surface, a
whole entity type with live rows and no disposition, or secret/credential/PII
shapes anywhere in the canonical candidate, it aborts before publication and
names the surface without echoing the value. Structured false positives need
an exact reviewed `allow_secret`/`allow_pii` rule; prose must be redacted or its
owning type excluded. Repository authorization repeats the clearance after Git
review, so a hand edit cannot route around capture. That is the loud-and-blocking posture the whole
classification pipeline is built on; the remedy is `wprism pending` and
`wprism classify`, covered in
[capabilities-and-limits.md](capabilities-and-limits.md). Expect that queue to
hold genuinely undeclared plugin options — the rows a fresh WooCommerce or
Yoast install adds — and not WordPress's own bookkeeping: every
`widget_<type>` row, `sidebars_widgets`, and every `theme_mods_*` row belongs
to a dedicated engine mechanism that already handles it, so `wprism pending` does
not ask you to classify them (their writes stay visible in `wp wprism journal-report`,
and a widget type with live instances that no manifest declares still refuses
capture, loudly).

Then review the diff as a diff. Canonical state is entity-per-file, sorted, and
deterministically serialized precisely so that `git diff` is legible to a human
reviewer who is not a WordPress database expert.

## Refresh and rebase against production

Production keeps moving while your branch sits in review. `wprism refresh` answers
"what actually changed underneath me", without pulling production's database
into your repository:

```sh
wprism refresh production --production-ref=v2026.03.1
```

It fetches a read-only production export, compiles the base and branch commits
locally, and writes an immutable semantic plan under the repository's Git
common directory — so orchestration evidence never dirties canonical state and
needs no `.gitignore` rule. The Git ref proves code *topology*; it never stands
in for production database truth, and the two are checked against each other
before the plan is written.

The output is a base/production/branch commit triple, a plan hash, five
category counts, and one line per conflict:

```text
categories: unchanged=812 production-only=14 branch-only=6 compatible=3 conflicting=1
conflict posts/page/019f…-pricing: semantic divergence
```

Read those categories as: untouched on both sides; changed only in production;
changed only on your branch; changed on both in ways that compose; and changed
on both in ways that do not. Only the last one needs a decision from you.

Plan rows already speak WordPress, not just repository paths: an entity with an
authored display name — a post's title, a term's or menu's name — carries it as
the row's `title` in plan JSON, and both renderers print it in single quotes
after the path. The two differ in *where* you see it, deliberately.
`wp wprism plan` prints it on every itemized row; `wprism status` itemizes only rows
that demand a decision — drift, conflict, collision, pending, blocked or
conflicted deletes — so the name shows up exactly there, while a clean
create/update batch still renders as counts alone. Rows with no authored name (options, sidebars,
typed tables, tombstones) look the same as they always did; the name is never
guessed or derived.

The entity-action explanation surface has shipped. Human `wprism plan` prints a
hash-safe `EXPLAIN` selector beneath each itemized row. `wprism explain` follows
that one row through the compiled source shape, winning policy and manifest
rules, declared outbound references, selected structured actions, and the
verification apply would require—without exposing values or running anything.

For a value-free/redacted field-level change and conflict projection, opt in
explicitly:

```sh
wprism refresh production --production-ref=v2026.03.1 --field-diff
wprism refresh production --production-ref=v2026.03.1 --field-diff --format=json
```

This is not a literal before/after display. It names only an opaque record
selector, a closed entity/field label, a change category, B/P/W
presence/equality relations, and whether the row is field-eligible or
record-atomic; values, paths, titles, bodies, metadata, options, and per-value
hashes are omitted. It decomposes only ordinary B/P/W plan entries already
classified as `conflicting`; branch-only, production-only, and compatible rows
remain in the ordinary private plan/counts because they need no field choice.
The JSON result is one immutable
`wprism-refresh-field-diff/v1` object that is display-only/non-authorizing and is
bound to the ordinary plan and production snapshot without changing the plan
hash. Field mode is unavailable for scope contracts and policy-evidence skew.
For eligible fields, `production-only` means B=W≠P, `branch-only` means B=P≠W,
`compatible` means P=W≠B, and `conflicting` means all three verified groups
differ. Scalar equality is canonical comparison evidence (`"base"` equals
`"\\u0062ase"`, and `1` equals `1.0`), while verified source tokens remain
private exact-byte materialization input; object/list values are not
normalized. Record-atomic rows use the same closed presence/equality vocabulary
without publishing their semantic hashes.

The narrow v1 field surface covers ordinary post scalar groups, term
name/description/parent, and whole top-level blocks of a post body. An
attachment/media record, menu/sidebar/options,
user metadata, typed table, tombstone, or opaque container remains a single
record decision, so one interaction can include both field and record-atomic
conflicts.

A post body composes only when both sides left the block sequence alone —
same block count, same block name at each position, same bytes between the
blocks — and only by swapping whole top-level blocks. Two editors on different
blocks of one page therefore keep both edits with nothing to answer. Anything
else is one record decision under its own reason, so you can tell which
happened: `body_changed` (not a block document), `body_structure_changed` (a
block was added, removed, moved, or retyped), or `body_block_overlap` (you both
changed the same block). Nothing is ever merged inside a block. A live B record with an absent P or W side is unavailable to field
mode before any diff or choice is published; use the legacy whole-record
resolver for that absence.

When you are ready to move the branch onto current production:

```sh
wprism rebase production --production-ref=v2026.03.1 --new-branch=feature/pricing-page-2
```

`rebase` re-exports production immediately before materializing and refuses if
the snapshot hash moved in between. It works in a disposable local worktree and
**atomically creates a new ref** after semantic validation — it never resets,
checks out, or overwrites your source branch, so a failed rebase costs you
nothing. Conflicts stay explicit by default (`--strategy=manual`); resolving
them wholesale (`--strategy=ours|theirs`) or per entity
(`--resolve=<stable-id>=ours|theirs`, repeatable) is a journal-bound choice
that is recorded, not inferred. An unresolved planner receipt creates no
branch. If a run is interrupted and leaves a candidate worktree behind,
`wprism rebase production --abort=<run-id>` removes exactly that worktree and
nothing else.

If the redacted field diff is eligible, resolve it through the explicit local
TTY reveal surface:

```sh
wprism rebase production --production-ref=v2026.03.1 \
  --new-branch=feature/pricing-page-2 --interactive
```

Choose `b`/`branch` for the branch (`ours`) or `p`/`production` for production
(`theirs`). `--interactive` requires TTY stdin and stdout. For every changed
leaf it shows base, branch, and production state, exact path, and each source
value as a terminal-safe preview capped at 4 KiB (paths at 512 bytes), with the
exact byte count and SHA-256 even when truncated. Scalar fields, complete
record-atomic documents, and whole block values all use that same comparison,
so the choice is tied directly to the opaque record and field selectors. It
also shows a bounded C0/DEL-safe authored title/name or path fallback.
This is privileged output: run it only in a trusted terminal because values
may contain secrets or personal data and a terminal transcript can retain
them. The comparison and label are transient local context only: they never
enter the value-free diff, JSON, resolution, run, or receipt. Before prompting, it
previews every automatic branch/production decision (including changed
non-conflicting plan rows) and each manual conflict. `production-only` selects
production; `branch-only` and `compatible` retain the exact branch-byte
scaffold. Only conflicts need a choice. `q` or EOF cancels with exit 2 before
any run record, candidate worktree, branch, or ref exists; the ordinary private
plan and value-free redacted diff may already exist as immutable planning
artifacts. Automation may instead provide a non-empty, at-most-1 MiB regular
non-symlink canonical JSON local `--field-resolution=<path>`. These field
modes cannot be combined with even an explicit `--strategy=manual` or any
legacy `--resolve`; use one resolver
contract per run. The materializer copies exact selected source token bytes
into a branch-byte scaffold, then strict-compiles in the disposable worktree;
it does not decode/re-encode a mixed post or term document.

## Merge, with no environment at all

`git merge` is still git's job. What WPrism adds is the question you can only ask
afterwards — *is the tree I now have coherent, and does it disagree with the
other branch anywhere that matters?* — and `wprism merge-check` answers it without
an environment, a registry, or a transport. That matters most at exactly the
moment every other verb refuses: you have just merged, production is behind a
VPN you are not on, and the tree in front of you is the thing you need judged.

```sh
git merge feature/pricing-page
wprism merge-check
```

That is validation mode. It compiles the committed tree with the same
repository compiler `wprism capture` and `wprism deploy` use, and refuses structural
incoherence by name — two files claiming one uuid, a typed reference pointing
at nothing, a media catalog entry that does not verify, a code lock digest that
does not match. It prints the compiler's own diagnostic; nothing is
paraphrased.

Point it at a second ref to get the conflict report:

```sh
wprism merge-check --ref=HEAD --against=origin/production
```

It compiles both refs and their merge base, runs the same B/P/W planner
`wprism refresh` runs, and prints the same five category counts and the same
conflict rows — `--ref` is the "branch" side and `--against` is the
"production" side, so `branch-only` still reads "changed only on mine".
`--base=<ref>` overrides the computed merge base when the two branches have
no useful common ancestor.

### The exit-code contract

This is the part a CI job binds to, so it is fixed:

| code | meaning |
| --- | --- |
| 0 | compiled and coherent; with `--against`, zero conflicting entries |
| 1 | refusal — the tree does not compile, or a ref does not resolve |
| 2 | usage error |
| 3 | the tree compiled and the plan is valid, and conflicts need a human |

3 is an **answer**, not a failure. Under `--format=json` it emits the
`wprism-merge-check/v1` success document with `verdict: "conflicts"` — never a
refusal envelope — so a pipeline can separate "a person owes me a merge
decision" from "my checkout is broken" without reading prose. Every refusal
does emit `wprism-command-refusal/v1` under `--format=json`, so there is exactly
one parseable document for every outcome.

### Cross-branch plugin version skew

Locked component versions are overwritten, never merged, so two branches can
disagree about which WooCommerce a merged state tree was captured against.
merge-check reads each ref's `code/wprism-code.lock.json` and reports the
difference in `code_skew[]`. Any skew is blocking: exit 3 with
`verdict: "code_skew"` even when the state plan has no editorial conflict.
The remedy is ordering: merge code first, run migrations, re-capture, then
merge state.

### What it deliberately does not do

- **It never authorizes anything.** Its plan is marked advisory inside the
  bytes its own `plan_hash` covers, so it cannot be confused with a refresh
  plan and cannot drive `wprism rebase`. Materializing against production stays
  `wprism rebase <production-env> --production-ref=<ref>`, which is authorized by
  a live production read for a reason.
- **It validates a committed ref.** With a dirty canonical partition it refuses
  and tells you to commit the merge — compiling the last commit while your
  `state/` holds uncommitted bytes would be a confidently wrong answer. A
  completed `git merge` leaves you clean anyway.
- **No field-level diff, and no scoped mode.** Both need evidence only a live
  production export can produce. `wprism refresh --field-diff` remains the field
  surface.
- **Conflicts are presented by WordPress identity, entity type and reason.**
  Presenting them by URL and business consequence is not shipped.

## Plan and status

```sh
wprism status stage
```

This is the question "is this environment safe to promote?", and its **exit
code is the answer**. Non-zero means no. It renders counts per plan bucket,
drift paths, pending and blocked deletions, code findings, lifecycle receipts,
and any plan-level warnings — warnings alone never flip the exit code. On the rows it
itemizes, each entity's authored WordPress display name follows its repository
path in single quotes, so you are reading "the Pricing page", not a UUID.

The buckets that make it non-zero and the exact remedy for each are tabulated
in
[capabilities-and-limits.md](capabilities-and-limits.md#refusal-to-remedy).
`wprism status` is a *readiness* probe, and the mutation boundary now agrees on
the two stale/partial cases: ordinary drift and unauthorized deletions both
refuse in preparation. A missing required `env` value remains a visible
runtime gap rather than authored state apply can write. A planned deletion
this environment still holds performs *no* deletion without
`--with-deletes` — it refuses before any authored mutation, so it cannot write
the rest of the plan or record a revision while the tombstone stays pending
until somebody authorizes it, and status keeps saying no until then.

Ordinary drift refuses during apply preparation before any authored mutation.
It names the preserved entities and points at `wprism capture`; capture and
reconcile them, then apply a newly built plan. The one exception is the
externally checkpointed scoped-promotion profile, whose signed selection is
explicit authority to replace only its selected drift.

`wprism status` parses and reformats; it never prints raw JSON. For that, and for
scripting, use `wprism plan <env> --format=json`.

To inspect one itemized action without applying it, copy the selector printed
directly below that row:

```sh
wprism explain stage update:sha256:<entity-identity-hash>
wprism explain stage update:sha256:<entity-identity-hash> --format=json
```

The result is a strict observation of the current plan, not a cached receipt.
It performs no identity repair, target write, provider negotiation, native
action, or attachment-offload hook. If media is not already local, use the
ordinary capture/provider workflow first. A declared action is reported as selected/not checked; `wprism apply`
still owns capability negotiation, force/delete gates, mutation, and value-level
readback. Values, raw selectors, repository paths, target-local ids, action
arguments, and provider receipts are omitted.

## Release

```sh
wprism stage-source stage --from=main --operation=change-1842-stage --format=json > stage-receipt.json
wprism release stage prepare --stage-receipt=stage-receipt.json \
  --expected-stage-receipt-sha256="$(jq -r .receipt_sha256 stage-receipt.json)" \
  --format=json > release-prepare.json
# Present and externally sign release-prepare.json, then run release execute
# with its exact authorization, subject, presentation, plan and stage digests.
```

Signed `wprism release … execute` is the composed release: it authorizes in
front of promotion and verifies behind it. It **composes** `wprism promote`
rather than forking it —
deploy-before-apply ordering, the promotion lease, the target fence, the
database checkpoint and the verified/scoped rollback selection are all
promote's, byte for byte — and it adds the two things that were previously in
somebody's head.

In front: a frozen **authorization plan**. Before a single target byte moves,
release loads the accepted application contract, reads the target, regenerates
the per-site projection from current facts, refuses on any surface in scope
that is `Experimental`, `Not qualified`, `Unsupported` or `Requalification
required`, refuses on unknown effect recovery semantics, refuses on a code
lifecycle window with no reviewed live external effect declared in the
contract, and refuses on deletions you did not authorize with `--with-deletes`.
Then it prints one page — requested scope, capabilities and their conditions,
what may change, the recovery claim, effects, and the authority still required
— ending in a single question. `--plan-only` prints exactly that and exits
having mutated nothing, including the site repository.

That deletion refusal now holds at every mutation entry point. `wprism release`,
`wprism promote`, and direct `wp wprism apply` all require `--with-deletes` before a
plan containing live tombstones can mutate anything. Release additionally
freezes that authority in its reviewed plan; the lower-level verbs enforce the
same no-partial-success invariant at apply preparation. The flag alone is not
writer-exclusion authority: an executable deletion runs through automatic
verified promotion, whose signed v3 recovery receipt binds that delete intent.
Direct apply remains a useful refusal/diagnostic surface but cannot mint the
external recovery witness required to delete.

Behind: `wprism verify`, which pairs a fresh read-only convergence re-read with
the HTTP journey oracles your contract declares. Both must pass. If you
declared no journeys, verify says so rather than letting a green tick imply a
business check nobody wrote.

Two flags are worth knowing before you need them. `--from=<ref>` selects and
delivers one exact advertised branch/tag: an executing release fetches through
the target's `origin`, verifies the local and fetched commit hashes match, and
fast-forwards only a clean named target. Read-only `--plan-only` reports a
mismatch instead of delivering. And `--profile` may
only strengthen silently — anything weaker than what the target can prove,
`none` included, additionally requires `--accept-weaker-recovery` and prints a
warning beside the plan.

A failure after the plan is frozen carries exactly one next action from the
closed set `resume | reconcile | retry | recover | requalify | escalate`. An
interrupted lifecycle is always `recover`; an ambiguous commitment is always
`reconcile` and never `retry`. The full walkthrough is
[release.md](release.md).

Before releasing to a shared environment, rehearse the change on a disposable
one:

```sh
wprism rehearse preview --from production --branch feature/pricing-page
wprism rehearse preview --reap
```

`wprism rehearse` is `wprism env materialize` plus a preview of what a release
would touch. Before provider contact it states that containment is required;
before restoring production-derived bytes it requires an exact
`environment.containment.verify` receipt. The ordinary local reference pair
withholds that capability. Its opt-in standalone `contained_preview` mode is
the development example: isolated lease DB/volumes and credentials, internal-
only WordPress/CLI, mail refusal, cron/workers off, and loopback ingress only
through a credential-free proxy. A hash-pinned machine-local policy must also
assert the exhaustive credential inventory and enumerate the exact supported
database rebinds/media removals before sanitized snapshot admission; built-in
WordPress passwords, activation keys, sessions and application passwords are
disabled/removed exhaustively. Setup and trust boundaries are in
[the provider guide](../branch-environment-provider.md#enabling-the-contained-preview).
Contained evidence gathering still does not turn an `Experimental` or
`Uncertified` capability into a qualified one.

### `wprism promote` — the lower-level verb

```sh
wprism promote stage
```

`wprism promote` remains exactly what it was and is not deprecated: the
fail-closed code-and-state path. It compiles the repository once into an
immutable artifact, acquires a target-database lease bound to that artifact's
hash, exports a database checkpoint, and then runs the phases in order — code
staging, lifecycle retirement, a fresh-process lifecycle activation, code
finalization, and finally the hook-free state apply. It stops at the first
failed phase and prints recovery guidance rather than continuing.

Two profiles exist, and WPrism selects between them from declared capabilities and
runtime preflight, never from the host's filesystem layout. A production SSH
target configured with the complete verified-rollback capability prepares a
signed, provider-backed claim and converges to either `committed` or verified
`rolled_back`. Every other target warns loudly and uses the operator-directed
lease-plus-checkpoint path. A configured-but-failing preflight refuses; it does
not quietly degrade.

Apply flags are forwarded verbatim. Repository, artifact, materialization,
handoff, and lease flags are owned by promote and rejected from caller input —
that boundary is what lets one artifact hash mean one thing all the way
through.

Reach for `promote` directly when you want the mutation without the
authorization document: a scoped promotion, an environment with no application
contract, or automation that has already produced its own record of what it
intends. Everywhere else, `wprism release` gives you the same mutation plus the
frozen plan and the verification, and the frozen plan is what makes recovery's
claim literal later.

For a lifecycle-and-code move with no state apply, `wprism deploy <env>` is the
standalone path; it takes and retains its own database checkpoint under the
same lease, and `--no-checkpoint` skips it. See
[code-updates.md](code-updates.md).

## Recover

```sh
wprism recover production --list
wprism recover production --restore=<receipt-id> --writers-excluded
```

A failed release is not a mystery to be poked at with SQL, and it is no longer
a sequence of raw commands to be typed either. `wprism recover` is the operator
verb over the recovery runtime: `--list` prints the active checkpoint receipt —
id, state, kind, generation, owner, covered inventory, age — and `--restore=`
performs the profile's own rollback, or drives the four ordered operator-directed
steps, including the mandatory final one that people skip when they type them
by hand.

Three rules it enforces rather than advises, each of which used to be a
paragraph you had to remember:

1. **External writer exclusion first.** Every checkpoint contains the temporary
   promotion lease row — the lease existed before the export, so it had to — so
   a database import can replace the very row that would otherwise serialize
   it, and recovery cannot be protected by a lock stored inside the database
   being imported. Establish real external exclusion for every writer across
   the whole window, then assert it with `--writers-excluded`. Without that
   flag, recover refuses before the first transition.
2. **Code first.** If the failure happened after a code phase, a database
   import is refused until code is reconciled to the pre-release revision — and
   the refusal names that exact revision, read out of the frozen authorization
   plan. Restoring a pre-migration database under post-migration code is how
   sites break in ways that are worse than the original failure.
3. **The claim is printed before it acts.** What this profile restores and,
   literally, what it does not — emails already sent, payments already
   captured, webhooks already delivered, third-party systems that observed the
   change, orders and sessions written by live traffic after the checkpoint —
   plus the one-sentence maximum loss boundary. When a frozen plan matches the
   checkpoint, that is the plan's own claim, unchanged: the claim you saw at
   authorization is the claim you see at recovery.

An `incomplete_lifecycle` receipt is the strictest case: a hook window failed
after its durable pre-hook boundary, so canonical state may already have been
committed by a hook that then threw. No force flag bypasses it. The only exit
is restoring the exact pre-lifecycle database checkpoint, which is what
`wprism recover` does.

`wprism recover` lists two sources: the signed receipt of the adopted rollback
authority, which an adopted target has once its rollback authority is
configured, and the plain database checkpoint every operator-directed release
retained under `.wprism/checkpoints/`, which every target has — restored through
the same four ordered steps. A signed rollback needs that configured authority;
without one it says so and stops rather than improvising. The raw runtime actions it drives are named in [internals.md](internals.md) as
internals — `wprism` never needs you to type them, and running them directly is
outside the supported workflow. The complete narrative is
[recovery.md](recovery.md).

Those retained checkpoints are whole-database dumps and **nothing removes them
on its own** — WPrism has no automatic retention anywhere. Periodic housekeeping
is an explicit verb: `wprism recover <env> --prune-retained=<keep-n>` prints what
it would delete and deletes nothing, and the same command with
`--confirm-prune` removes exactly those rows. It keeps the newest `keep-n` of
each verb, so the most recent before-image is never deletable, and it removes
only the `.sql` — the compiled artifact and the frozen plan beside it stay.
See ["Pruning retained checkpoints"](recovery.md#pruning-retained-checkpoints).

Forced overrides, where they exist at all, disclose their consequences and
always leave an exit path through `wprism` — never through operator SQL.

## A realistic week

- **Monday** — `wprism doctor` each environment; `wprism status production` to
  confirm you are starting from a clean baseline. After a plugin update, a
  certification refresh, or anything that moved the stack, `wprism assess
  production` as well: readiness is recomputed from evidence, so it is the
  cheapest way to find out that a surface you rely on now reads
  `Requalification required`.
- **During the week** — branch; `wprism rehearse preview --from production
  --branch=<branch> --ttl=86400` (or `wprism env materialize` when you want the
  environment without the preview); author on the rehearsal environment;
  `wprism capture preview --target-branch=<branch> --format=json`; retain the
  digest-bound capture receipt, then review and commit the state diff in the
  pull request like any other diff.
- **Before merging** — `wprism refresh production --production-ref=<ref>`; rebase
  if production moved; `wprism status stage` after applying to staging.
- **Release** — stage the exact advertised ref with a new operation id, save
  the receipt, run read-only `release prepare`, present and externally sign its
  exact subject, then run `release execute` with every expected digest.
  Interactive and `--yes` mutation refuse before promote. Verification runs
  behind signed execute; an exact execute retry returns the stored outcome.
- **When a release fails** — take the one next action it printed. `recover`
  means `wprism recover production --list` and then a restore under a real
  maintenance window; `reconcile` never means retry.
- **When something is red** — go to
  [capabilities-and-limits.md](capabilities-and-limits.md#refusal-to-remedy)
  and match the bucket name. Every refusal in this system has exactly one
  documented remedy, and reaching for a force flag before reading it is how
  teams lose the guarantees they installed WPrism for.
- **End of branch** — `wprism env reap preview`; verify the receipt says
  `destroyed` for an explicitly created target or `detached` for an attached
  target.
