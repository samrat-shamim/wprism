# The daily workflow

This is the loop a WordPress team runs once Duo is installed:

**branch → rehearse → capture → refresh/rebase → plan/status → release → verify
→ recover/reap.**

Two of those steps are composed verbs that sit on top of the older ones.
`duo release` composes `duo promote`; `duo recover` drives the recovery runtime
you would otherwise type by hand. Both lower-level paths are still documented
and still supported, and both are below.

Every verb below is documented flag-by-flag in
[cli/README.md](../../cli/README.md). This guide is the connective tissue: what
each step is *for*, when it is the wrong step, and what the output means when
it stops you.

## The mental model, in one paragraph

Git stays git. The site repo is an ordinary repository with ordinary branches,
and Duo adds no branching model of its own. What Duo owns is the boundary
between that repository and a live WordPress install: `capture` reads an
environment into canonical state, `apply` writes canonical state into an
environment, `deploy` moves code and runs the lifecycle window where activation
hooks fire, and `promote` sequences code and state together under one lease and
one database checkpoint. Runtime data — sessions, orders, caches, submissions —
never enters the repository at all. That asymmetry is the design, not a gap:
see [DESIGN.md](../../DESIGN.md).

## Start of day: know your environments

```sh
duo envs
duo doctor stage
```

`duo envs` lists the merged registry with a transport summary per environment;
an individually broken entry prints as an inline `ERROR:` row rather than
failing the whole listing. `duo doctor <env>` runs nine gated checks — each one
after a failure is reported as failed rather than run, because a broken
transport makes every later check meaningless noise.

Two checks are worth knowing by name. `DISALLOW_FILE_MODS` is advisory but
load-bearing: it closes the wp-admin file-modification UI, which is the single
most common way installed code drifts out from under git (see
[code-updates.md](code-updates.md)). And the PHP and database version checks
compare against `docs/compatibility-baseline.json`, so an environment outside
the certified range tells you before a capture does.

If you are about to run something destructive against an environment you have
not touched in a while, `duo driver-capabilities <env>` answers "will this
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
in WordPress, not in a text editor over canonical JSON. Duo's job starts when
you want that change to become a reviewable diff.

When the host exposes a machine-local environment provider, materialize the
branch from one coherent production cut:

```sh
duo env materialize preview --from production --branch feature/pricing-page --ttl 86400
```

The branch ref must resolve to the clean branch currently checked out. Duo
freezes production while it reads semantic truth, binds that export to one
immutable database/media snapshot set, attaches the named target, restores the
physical baseline, materializes the rebased candidate commit, and promotes the
exact compiled code-and-state release. Attach is the default because many
hosts provision environments outside Duo. Use `--create` only when the target
provider explicitly advertises both create and receipt-backed destroy; Duo
never guesses that attach implies provisioning.

`--ttl` publishes observable expiry metadata. It does not grant a provider
permission to delete the environment when the clock passes that time. Cleanup
is always the explicit, identity- and lease-fenced command:

```sh
duo env reap preview
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
duo capture dev
```

Capture is the only command that mints or repairs entity identity and
publishes canonical state. Everything else in the system consumes what capture
produced, which is why `wp duo refresh-export` — the read-only production
export used by `refresh` below — is deliberately *not* a capture substitute.

Capture refuses rather than guesses. If it finds an unclassified surface, a
whole entity type with live rows and no disposition, or a value that
hard-matches a secret pattern under an `authored` rule, it aborts and tells you
exactly which key. That is the loud-and-blocking posture the whole
classification pipeline is built on; the remedy is `duo pending` and
`duo classify`, covered in
[capabilities-and-limits.md](capabilities-and-limits.md). Expect that queue to
hold genuinely undeclared plugin options — the rows a fresh WooCommerce or
Yoast install adds — and not WordPress's own bookkeeping: every
`widget_<type>` row, `sidebars_widgets`, and every `theme_mods_*` row belongs
to a dedicated engine mechanism that already handles it, so `duo pending` does
not ask you to classify them (their writes stay visible in `wp duo journal-report`,
and a widget type with live instances that no manifest declares still refuses
capture, loudly).

Then review the diff as a diff. Canonical state is entity-per-file, sorted, and
deterministically serialized precisely so that `git diff` is legible to a human
reviewer who is not a WordPress database expert.

## Refresh and rebase against production

Production keeps moving while your branch sits in review. `duo refresh` answers
"what actually changed underneath me", without pulling production's database
into your repository:

```sh
duo refresh production --production-ref=v2026.03.1
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
`wp duo plan` prints it on every itemized row; `duo status` itemizes only rows
that demand a decision — drift, conflict, collision, pending, blocked or
conflicted deletes — so the name shows up exactly there, while a clean
create/update batch still renders as counts alone. Rows with no authored name (options, sidebars,
typed tables, tombstones) look the same as they always did; the name is never
guessed or derived.

The entity-action explanation surface has shipped. Human `duo plan` prints a
hash-safe `EXPLAIN` selector beneath each itemized row. `duo explain` follows
that one row through the compiled source shape, winning policy and manifest
rules, declared outbound references, selected structured actions, and the
verification apply would require—without exposing values or running anything.

For a value-free/redacted field-level change and conflict projection, opt in
explicitly:

```sh
duo refresh production --production-ref=v2026.03.1 --field-diff
duo refresh production --production-ref=v2026.03.1 --field-diff --format=json
```

This is not a literal before/after display. It names only an opaque record
selector, a closed entity/field label, a change category, B/P/W
presence/equality relations, and whether the row is field-eligible or
record-atomic; values, paths, titles, bodies, metadata, options, and per-value
hashes are omitted. It decomposes only ordinary B/P/W plan entries already
classified as `conflicting`; branch-only, production-only, and compatible rows
remain in the ordinary private plan/counts because they need no field choice.
The JSON result is one immutable
`duo-refresh-field-diff/v1` object that is display-only/non-authorizing and is
bound to the ordinary plan and production snapshot without changing the plan
hash. Field mode is unavailable for scope contracts and policy-evidence skew.
For eligible fields, `production-only` means B=W≠P, `branch-only` means B=P≠W,
`compatible` means P=W≠B, and `conflicting` means all three verified groups
differ. Scalar equality is canonical comparison evidence (`"base"` equals
`"\\u0062ase"`, and `1` equals `1.0`), while verified source tokens remain
private exact-byte materialization input; object/list values are not
normalized. Record-atomic rows use the same closed presence/equality vocabulary
without publishing their semantic hashes.

The narrow v1 field surface covers ordinary post scalar groups and term
name/description/parent. A post body, attachment/media, menu/sidebar/options,
user metadata, typed table, tombstone, or opaque container remains a single
record decision, so one interaction can include both field and record-atomic
conflicts. A live B record with an absent P or W side is unavailable to field
mode before any diff or choice is published; use the legacy whole-record
resolver for that absence.

When you are ready to move the branch onto current production:

```sh
duo rebase production --production-ref=v2026.03.1 --new-branch=feature/pricing-page-2
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
`duo rebase production --abort=<run-id>` removes exactly that worktree and
nothing else.

If the redacted field diff is eligible, resolve it through the explicit local
TTY reveal surface:

```sh
duo rebase production --production-ref=v2026.03.1 \
  --new-branch=feature/pricing-page-2 --interactive
```

Choose `b`/`branch` for the branch (`ours`) or `p`/`production` for production
(`theirs`). `--interactive` requires TTY stdin and stdout and may show a
bounded C0/DEL-safe authored title/name or path fallback beside the otherwise
closed selector. That label is transient local context only: it never enters
the value-free diff, JSON, resolution, run, or receipt. Before prompting, it
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

## Plan and status

```sh
duo status stage
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
The one distinction worth internalizing here: `duo status` is a *readiness*
probe, not a prediction of whether `duo apply` would refuse. Ordinary state
drift and a missing required `env` value are both cases apply happily proceeds
through, and both are still "not safe to promote" — because they mean this
plan's own comparison is already stale, or the environment is running with a
genuine gap. A planned deletion this environment still holds is the third and
the sharpest: an ordinary apply performs *no* deletion without
`--with-deletes` — it warns that it applied none of them, writes the rest of
the plan, and records the revision as applied — so the tombstone stays pending
until somebody authorizes it, and status keeps saying no until then.

Ordinary drift is worth one more sentence, because "proceeds through" means
apply's *pre-mutation* gates, not the whole run. Apply never overwrites an
entity that drifted out of band; it writes the rest of the plan, leaves those
rows exactly as the environment has them, and then fails the mandatory
post-apply convergence gate, which proves the whole compiled tree. That
refusal names the preserved entities, says plainly that the target was
mutated, and points at `duo capture`. Nothing is lost and nothing is silently
overwritten — but the promotion did not complete, and a retry will keep failing
the same way until the drift is captured.

`duo status` parses and reformats; it never prints raw JSON. For that, and for
scripting, use `duo plan <env> --format=json`.

To inspect one itemized action without applying it, copy the selector printed
directly below that row:

```sh
duo explain stage update:sha256:<entity-identity-hash>
duo explain stage update:sha256:<entity-identity-hash> --format=json
```

The result is a strict observation of the current plan, not a cached receipt.
It performs no identity repair, target write, provider negotiation, native
action, or attachment-offload hook. If media is not already local, use the
ordinary capture/provider workflow first. A declared action is reported as selected/not checked; `duo apply`
still owns capability negotiation, force/delete gates, mutation, and value-level
readback. Values, raw selectors, repository paths, target-local ids, action
arguments, and provider receipts are omitted.

## Release

```sh
duo release stage --from=main --plan-only
duo release stage --from=main --yes
duo verify stage
```

`duo release` is the composed release: it authorizes in front of promotion and
verifies behind it. It **composes** `duo promote` rather than forking it —
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

That deletion refusal is release-only. An ordinary `duo promote <env>` does not
refuse on an unauthorized deletion: it warns loudly that it performed none of
the planned ones, applies the rest, and leaves the tombstone pending for
`duo status` to keep reporting. Same fact, two postures — release freezes the
authorization in front of promotion, promote reports it and lets the readiness
probe hold the line.

Behind: `duo verify`, which pairs a fresh read-only convergence re-read with
the HTTP journey oracles your contract declares. Both must pass. If you
declared no journeys, verify says so rather than letting a green tick imply a
business check nobody wrote.

Two flags are worth knowing before you need them. `--from=<ref>` is a *binding
assertion*, not a git transport: the ref is resolved locally, compared with the
target's `HEAD`, and a mismatch refuses with `reconcile`. And `--profile` may
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
duo rehearse preview --from production --branch feature/pricing-page
duo rehearse preview --reap
```

`duo rehearse` is `duo env materialize` plus a preview of what a release would
touch, and it prints its containment disclosure before it contacts the
provider: Duo does not strip production credentials, does not default-deny
outbound HTTP, mail, payment or webhook traffic, and does not verify
containment. A rehearsal is a preview, not a sandbox — point it at test
credentials, and do not treat "I rehearsed it" as "I qualified it".

### `duo promote` — the lower-level verb

```sh
duo promote stage
```

`duo promote` remains exactly what it was and is not deprecated: the
fail-closed code-and-state path. It compiles the repository once into an
immutable artifact, acquires a target-database lease bound to that artifact's
hash, exports a database checkpoint, and then runs the phases in order — code
staging, lifecycle retirement, a fresh-process lifecycle activation, code
finalization, and finally the hook-free state apply. It stops at the first
failed phase and prints recovery guidance rather than continuing.

Two profiles exist, and Duo selects between them from declared capabilities and
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
intends. Everywhere else, `duo release` gives you the same mutation plus the
frozen plan and the verification, and the frozen plan is what makes recovery's
claim literal later.

For a lifecycle-and-code move with no state apply, `duo deploy <env>` is the
standalone path; it takes and retains its own database checkpoint under the
same lease, and `--no-checkpoint` skips it. See
[code-updates.md](code-updates.md).

## Recover

```sh
duo recover production --list
duo recover production --restore=<receipt-id> --writers-excluded
```

A failed release is not a mystery to be poked at with SQL, and it is no longer
a sequence of raw commands to be typed either. `duo recover` is the operator
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
`duo recover` does.

`duo recover` lists two sources: the signed receipt of the adopted rollback
authority, which only an SSH-adopted target has, and the plain database
checkpoint every operator-directed release retained under `.duo/checkpoints/`,
which every target has — restored through the same four ordered steps. Only a
signed rollback stays SSH-only; elsewhere it says so and stops rather than
improvising. The raw runtime actions it drives are named in [internals.md](internals.md) as
internals — `duo` never needs you to type them, and running them directly is
outside the supported workflow. The complete narrative is
[recovery.md](recovery.md).

Forced overrides, where they exist at all, disclose their consequences and
always leave an exit path through `duo` — never through operator SQL.

## A realistic week

- **Monday** — `duo doctor` each environment; `duo status production` to
  confirm you are starting from a clean baseline. After a plugin update, a
  certification refresh, or anything that moved the stack, `duo assess
  production` as well: readiness is recomputed from evidence, so it is the
  cheapest way to find out that a surface you rely on now reads
  `Requalification required`.
- **During the week** — branch; `duo rehearse preview --from production
  --branch=<branch> --ttl=86400` (or `duo env materialize` when you want the
  environment without the preview); author on the rehearsal environment;
  `duo capture preview`; review the state diff in the pull request like any
  other diff.
- **Before merging** — `duo refresh production --production-ref=<ref>`; rebase
  if production moved; `duo status stage` after applying to staging.
- **Release** — `duo release production --from=<ref> --plan-only`, read the
  frozen plan and its recovery claim out loud to whoever owns the outcome, then
  `duo release production --from=<ref> --yes`. Verification runs behind it;
  `duo status production` once more is still the receipt that the two halves
  agree.
- **When a release fails** — take the one next action it printed. `recover`
  means `duo recover production --list` and then a restore under a real
  maintenance window; `reconcile` never means retry.
- **When something is red** — go to
  [capabilities-and-limits.md](capabilities-and-limits.md#refusal-to-remedy)
  and match the bucket name. Every refusal in this system has exactly one
  documented remedy, and reaching for a force flag before reading it is how
  teams lose the guarantees they installed Duo for.
- **End of branch** — `duo env reap preview`; verify the receipt says
  `destroyed` for an explicitly created target or `detached` for an attached
  target.
