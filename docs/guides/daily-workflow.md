# The daily workflow

This is the loop a WordPress team runs once Duo is installed:

**branch → materialize → capture → refresh/rebase → plan/status → promote → recover/reap.**

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
[capabilities-and-limits.md](capabilities-and-limits.md).

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
that demand a decision — drift, conflict, collision, blocked or conflicted
deletes — so the name shows up exactly there, while a clean create/update batch
still renders as counts alone. Rows with no authored name (options, sidebars,
typed tables, tombstones) look the same as they always did; the name is never
guessed or derived.

The entity-action explanation surface has shipped. Human `duo plan` prints a
hash-safe `EXPLAIN` selector beneath each itemized row. `duo explain` follows
that one row through the compiled source shape, winning policy and manifest
rules, declared outbound references, selected structured actions, and the
verification apply would require—without exposing values or running anything.
Field-level *value* diffs and guided conflict resolution remain planned. For
`duo refresh` specifically, five counts plus one reason line per conflict is
still the complete surface for deciding whether to rebase.

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

## Plan and status

```sh
duo status stage
```

This is the question "is this environment safe to promote?", and its **exit
code is the answer**. Non-zero means no. It renders counts per plan bucket,
drift paths, blocked-delete reasons, code findings, lifecycle receipts, and any
plan-level warnings — warnings alone never flip the exit code. On the rows it
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
genuine gap.

`duo status` parses and reformats; it never prints raw JSON. For that, and for
scripting, use `duo plan <env> --format=json`.

To inspect one itemized action without applying it, copy the selector printed
directly below that row:

```sh
duo explain stage update:sha256:<entity-identity-hash>
duo explain stage update:sha256:<entity-identity-hash> --format=json
```

The result is a strict observation of the current plan, not a cached receipt.
It performs no identity repair, target write, provider negotiation, or native
action. A declared action is reported as selected/not checked; `duo apply`
still owns capability negotiation, force/delete gates, mutation, and value-level
readback. Values, raw selectors, repository paths, target-local ids, action
arguments, and provider receipts are omitted.

## Promote

```sh
duo promote stage
```

Promotion is the fail-closed code-and-state path. It compiles the repository
once into an immutable artifact, acquires a target-database lease bound to that
artifact's hash, exports a database checkpoint, and then runs the phases in
order — code staging, lifecycle retirement, a fresh-process lifecycle
activation, code finalization, and finally the hook-free state apply. It stops
at the first failed phase and prints recovery guidance rather than continuing.

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

For a lifecycle-and-code move with no database checkpoint and no state apply,
`duo deploy <env>` is the standalone path; see
[code-updates.md](code-updates.md).

## Recover

A failed promotion is not a mystery to be poked at with SQL. It is a documented
sequence, and the ordering matters more than the individual commands.

First, **if the failure happened after a code phase, reconcile or restore code
to its known pre-promotion revision before importing any database checkpoint**.
A database import alone is not a code-and-state rollback, and restoring a
pre-migration database under post-migration code is how sites break in ways
that are worse than the original failure.

Second, every successful checkpoint deliberately contains the temporary
promotion lease row — the lease existed before the export, so it had to. A
database import can therefore replace the very row that would otherwise
serialize it, which means recovery **cannot** be protected by a lock stored
inside the database being imported. You must establish external
maintenance/exclusion for every Duo writer across the whole recovery window
first. `duo promote` prints the four ordered commands to run after that:
an exact abort of the old owner/artifact pair, a re-begin of the same pair, a
fatal-safe isolated `wp db import <checkpoint>`, and a final abort of the row
the import restored. **Run that last step even if the import fails.**

An `incomplete_lifecycle` receipt is the strictest case: a hook window failed
after its durable pre-hook boundary, so canonical state may already have been
committed by a hook that then threw. No force flag bypasses it. The only exit
is restoring the exact pre-lifecycle database checkpoint.

Forced overrides, where they exist at all, disclose their consequences and
always leave an exit path through `duo` — never through operator SQL.

## A realistic week

- **Monday** — `duo doctor` each environment; `duo status production` to
  confirm you are starting from a clean baseline.
- **During the week** — branch; optionally `duo env materialize preview --from
  production --branch=<branch> --ttl=86400`; author on the branch environment;
  `duo capture preview`; review the state diff in the pull request like any
  other diff.
- **Before merging** — `duo refresh production --production-ref=<ref>`; rebase
  if production moved; `duo status stage` after applying to staging.
- **Release** — `duo promote production`, then `duo status production` once
  more. A clean plan after promotion is the receipt that the two halves agree.
- **When something is red** — go to
  [capabilities-and-limits.md](capabilities-and-limits.md#refusal-to-remedy)
  and match the bucket name. Every refusal in this system has exactly one
  documented remedy, and reaching for a force flag before reading it is how
  teams lose the guarantees they installed Duo for.
- **End of branch** — `duo env reap preview`; verify the receipt says
  `destroyed` for an explicitly created target or `detached` for an attached
  target.
