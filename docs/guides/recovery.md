# Recovering a failed release

A failed release is not a mystery to be poked at with SQL. It is a documented
sequence with one entry point:

```sh
duo recover production --list
duo recover production --restore=<receipt-id> --writers-excluded
```

`duo recover` is a thin, literal front end over the recovery runtime. The
runtime is unchanged — the same rollback authority, the same profiles, the
same transitions in the same order. What changed is who types it. Driving
`recovery/rollback-control.php` by hand is no longer an operator path; the
raw actions are named in [internals.md](internals.md) for the people who
maintain the runtime, and `duo` never needs them.

This guide is what to do *after* a release told you `recover`. If it told you
`resume`, `reconcile`, `retry`, `requalify` or `escalate`, read
[release.md](release.md#when-release-fails-after-the-plan-is-frozen) instead —
those are different failures with different answers, and running a recovery
against one of them makes it worse.

## Before anything: exclude every other writer

Every successful checkpoint deliberately contains the temporary promotion
lease row. It had to: the lease existed before the export. So importing that
checkpoint replaces the very row that would otherwise serialize the import,
which means **recovery cannot be protected by a lock stored inside the
database being imported**.

The protection has to be external to that database: a real maintenance window
that keeps every Duo writer, every package manager, every self-updater and
every piece of shell automation out of the site for the whole recovery window.
`--writers-excluded` is your assertion that such a window exists. Without it
`duo recover` refuses before the first transition, and that refusal is the
feature.

## List what the target actually holds

```sh
duo recover production --list
```

```text
checkpoints: 1
  4b1f0c92e7a3  committed  verified-promotion  generation 7  expires 2026-08-17T10:44:02Z
    covers: database checkpoint, code release, upload bundle, effect bundle
note: the rollback authority holds one generation at a time: this is the active receipt, not a history
```

Read that note as written. The rollback runtime is a single-active-generation
state machine: there is one active receipt, not a log. A catalog that printed
one row while looking like a history would teach you that older checkpoints
exist and are selectable. They do not, and they are not.

Two further absences are stated rather than smoothed over. A full promotion
receipt publishes no creation timestamp, so the row shows `claim_expires_at`
instead of an age — deriving an age from the claim epoch would be fabrication,
because keepalive moves that epoch. And `covers[]` is derived from the
receipt's own evidence keys, not from the profile you asked for: a scoped
receipt covers the database checkpoint and nothing else, because the scoped
profile never prepares the other providers.

The receipt states, in lifecycle order, are `prepared`, `promoting`,
`verifying_new`, `committed`, `rollback_pending`, `rolling_back`,
`verifying_prior`, `rolled_back`.

The receipt id prints in the human view because a documented command consumes
it: `--restore=<id>` takes exactly this string. The lease owner and the
artifact hash are deliberately absent from this line — nothing you can type
consumes them — and stay in `--format=json`.

### Retained release checkpoints — the rows every target has

The signed receipt above is one source of rows. The other is the plain
database checkpoint a release **retained**: `promote`
exports the pre-release database to `.duo/checkpoints/promote-<owner>.sql`
right after taking its lease and prints `database checkpoint retained: …` on
success. A standalone `duo deploy` does the same under its own lease, at
`.duo/checkpoints/deploy-<owner>.sql` (`duo deploy --no-checkpoint` opts out).
That file is what the frozen authorization plan's `operator-directed`
claim (`restores: database checkpoint`) refers to, so `duo recover` lists it
and restores it on **every** transport — local, docker and SSH alike:

```text
checkpoints: 2
  promote-20260817-091402-0123456789abcdef0123456789abcdef  retained  retained-release-checkpoint  600s old
    covers: database checkpoint
  deploy-20260817-085500-abcdefabcdefabcdefabcdefabcdefab  retained  retained-release-checkpoint  3200s old
    covers: database checkpoint
note: this transport carries no rollback authority runtime, so only the database checkpoints its releases retained are listed
note: retained release checkpoints are the plain database checkpoints promote and deploy kept under .duo/checkpoints; restoring one drives the operator-directed path (abort, begin, isolated import, final abort)
note: a retained checkpoint older than the target's latest begun promotion session is refused at step 1 with promotion_abort_session_superseded; an obsolete checkpoint is not a safe recovery source, so recover that release through the provider that owns the target's backups instead
```

The id is the file name the release verb wrote, and it is what
`--restore=<id>` takes. A retained checkpoint prints no generation because it
has none: it is a file, not a signed receipt. Its lease identity — the owner
and the artifact hash the release used — is read from the retained compiled
artifact beside it, the file that shares its stem
(`.duo/artifacts/promote-<owner>.json`, or `deploy-<owner>.json`); a checkpoint
whose artifact is gone is still listed, with a note that it cannot be restored
by this command. Both rows restore identically: the prefix names which verb
wrote the dump and nothing else, and the four ordered steps below are the same
four steps for either.

Two things need an adopted target with a **configured rollback authority**,
and say so rather than improvise: reading the **signed** catalog (the rollback
authority runtime the adoption installed) and driving a **signed** rollback.
The requirement is the configuration, not the transport — an `ssh` environment
has it, and so does a `local` one that set `rollback_key_id`,
`rollback_signing_key` and `rollback_recovery` in its machine-local
`.duo-envs.json`. Anywhere else a signed rollback refuses with
`recovery_authority_unavailable` and points you at `--restore=<retained id>` or
the provider that owns that environment's backups. On a `local` target the
signing key lives on the target machine, so the signature proves the runtime
and the journal were not tampered with, but not the controller —
[recovery-runtime.md](../recovery-runtime.md) says exactly what that costs.

## The claim is printed before anything happens

Every restore prints the recovery claim verbatim before acting, and again in
the outcome:

```text
recovery profile: verified-automatic
  restores:
    database checkpoint
    code release
    upload bundle
    effect bundle
  does NOT restore:
    emails already sent
    payment captures or refunds already made
    webhooks already delivered
    third-party systems that observed the change
    orders and sessions written by live traffic after the checkpoint
  covered on this target:
    database checkpoint
    code release
  writer exclusion: not asserted by the operator — the rollback authority reserves and releases the exclusion for the window itself; the operator asserts nothing
  maximum loss boundary: writes committed after checkpoint 2026-08-17T09:14:02Z
```

That `writer exclusion` line answers a narrower question than the flag does:
it names who holds the exclusion for *this profile's own* mechanism. Under
`operator-directed` the same line reads:

```text
  writer exclusion: required — an external maintenance window: exclude every other writer, then assert it with --writers-excluded (a lock inside the database being imported cannot protect the window)
```

Either way, `--writers-excluded` is required by the command on every path,
because the checkpoint contains its own lease row whichever profile restores
it.

When the checkpoint's artifact hash matches a frozen authorization plan in
your site repository, the claim printed here is **that plan's claim, byte for
byte**. The claim you read at authorization is the claim you read at recovery;
that is a checkable property, not a coincidence, and it is why the frozen plan
under `.duo/releases/` is worth committing.

### `restores` versus `does_not_restore`

`restores` is the covered inventory the selected profile actually operates on:

| Profile | Restores |
|---|---|
| `verified-automatic` | database checkpoint, code release, upload bundle, effect bundle |
| `operator-directed` | database checkpoint |
| `none` | nothing |

`does_not_restore` is never empty — not even for `verified-automatic`. Five
rows are true of every profile this platform can select, because each names
something that has already left this WordPress install: emails already sent,
payment captures or refunds already made, webhooks already delivered,
third-party systems that observed the change, and orders and sessions written
by live traffic after the checkpoint.

A weaker profile adds rows on top of that floor, each stating the manual
remedy rather than only the absence. Under `operator-directed`: code releases
(reconcile code by hand to the pre-release revision before importing the
checkpoint), uploaded media bundles (the target keeps whatever the release
wrote), and provider effect bundles (no inverse effect operation is authorized
here). Under `none`, database state joins them — no checkpoint is taken, so
nothing bounds the loss.

Rollback restores bytes, not consequences. That sentence is the whole design
of this list.

### The maximum loss boundary

`maximum loss boundary` is the literal answer to "what will I lose". It names
the checkpoint instant, and everything committed to the target after that
instant — every order, every session, every comment written by live traffic
while the release was failing — is inside the boundary and does not survive
the restore.

That is not a caveat printed to cover Duo. It is the number to plan the
maintenance window around, and it is the assertion an end-to-end recovery test
checks literally: the post-checkpoint row's fate has to match this line
exactly, or the claim is wrong and the test fails.

## Restore

```sh
duo recover production --restore=4b1f0c92e7a3 --writers-excluded
```

Which path runs is a fact about your controller, not a preference. A
`verified-promotion` receipt on a controller holding the rollback signing key
runs the profile's own signed rollback, which restores effects, uploads, code
and the database in its own proven order. Anything else runs the
operator-directed path — the same four ordered steps a human used to type,
driven by Duo instead: abort the old owner/artifact pair, begin the same pair
again, perform the isolated database import, and abort the row the import
restored.

`--operator-directed` forces that second path explicitly on a target that
could have used the first. Use it when you know the signed path is not what
you want; do not use it to route around a refusal.

**The final abort is mandatory, including when the import fails.** It releases
the temporary lease row that the imported dump reinstated. Skipping it leaves
the target holding a lease no process owns, which is the one outcome worse
than a failed recovery. It runs in a `finally`, so no branch of this code can
skip it, and the outcome prints every step with its result whether or not the
recovery succeeded.

Exit 0 means the recovery reached its terminal state. Exit 1 means a refusal,
or a recovery that did not — and in that case the printed steps are the
evidence of where it stopped.

## An older checkpoint after a later release

**The first abort refuses if a newer session has superseded this checkpoint,
rather than presenting an obsolete dump as a safe recovery source.** That is
the same sentence [code-updates.md](code-updates.md) states about the code
path, and it is the one property of this verb that surprises people: the
checkpoint you want is still listed, still has its artifact, still has its
file — and step 1 stops anyway.

It stops because the lease `promotion-begin` takes is bound to one
`(owner, artifact hash)` pair, and post-begin phases are continuations of the
target's **latest begun** promotion session, never fresh locks. If a later
release began its own session on this target, an abort naming the older pair is
not this release's cleanup to run, and the database underneath it is no longer
the database that checkpoint describes.

The refusal names itself:

```text
recover production: not recovered
  abort: FAILED — promotion abort refused: a newer promotion session superseded the one this abort names
    reason: promotion_abort_session_superseded
    remedy: restore or recover the release that owns the latest begun promotion session; an obsolete checkpoint is not a safe recovery source, so recover this target through the provider that owns its backups instead
```

Nothing else ran. Step 1 stands outside the mandatory-final-abort guarantee on
purpose: nothing has been reinstated, so there is no lease row for a fourth
step to release, and the recovery window was never opened.

The remedy is the last line, and it is not a retry — the gate is deterministic
and a second run refuses identically. Either recover the release that owns the
latest begun session (its own checkpoint is the one that matches the current
database), or, if what you need is the state this obsolete checkpoint holds,
recover this target through the provider that owns its backups. `duo recover`
deliberately has no flag that forces past this.

`--format=json` carries the same three facts on the failed step —
`reason_code`, the public `detail`, and `remediation` — inside
`duo-recovery-outcome/v1`, so a pipeline reads the reason rather than a
generic failure. The target's own sentence names the superseding lease owner
and artifact hash; those are internal identifiers no `duo` command consumes,
so they stay in the target's private operator evidence rather than in either
view.

The sibling refusal is `promotion_abort_lock_not_owned`: a *live* lease row on
the target that belongs to a different owner and artifact. Both are listed with
their remedies in
[capabilities-and-limits.md](capabilities-and-limits.md#refusal-to-remedy).

`duo recover <env> --list` prints this property as a note beside the retained
rows. It is a note and not a per-row marker for a reason: the durable session
row lives on the target and no host verb reads it, so the listing cannot say
*which* checkpoint is superseded without inventing an answer — and an older
checkpoint is still perfectly restorable when no later session was begun. Step
1 is the authority.

## Code first, enforced rather than advised

If the checkpoint was taken around a code phase, importing its database before
code is reconciled would leave a database describing one code revision running
underneath another. That is the state nobody can reason about afterwards, and
it is how a bad release becomes a worse one.

So the operator-directed path refuses:

```text
recover_code_not_reconciled: this checkpoint was taken around a code phase, so importing its
database before code is reconciled would leave a database describing one code revision
underneath another
next: reconcile or restore the target code to e2f1a09c4b2d first, confirm with duo status,
then re-run duo recover with the same --restore id
```

The refusal names the exact revision when the receipt or the frozen plan
records it, and says where to find it when it does not. Reconcile the code
through the path that owns it — see [code-updates.md](code-updates.md) — then
confirm with `duo status` and re-run the identical `--restore` command.

The signed profile does not need this gate, because it restores code itself,
in its own order, before the database. That is not an exception to the rule;
it is the rule being satisfied by the profile instead of by you.

## After the recovery

```sh
duo status production
duo assess production
```

`duo status` is the readiness question: a clean plan is the receipt that code
and state agree again. `duo assess` is the honesty question: the post-recovery
projection should match the projection you had before the release, and a
surface that moved is a surface worth understanding before you try again.

Then reconcile the ledger of what did *not* come back. Everything on the
`does NOT restore` list is now a task for a human: mail that went out, payments
that were taken, webhooks that fired, and the writes inside the maximum loss
boundary. Duo told you that list twice on purpose.

## An `incomplete_lifecycle` receipt is the strict case

A hook window that failed after its durable pre-hook boundary may have
committed canonical state through a hook that then threw. No force flag
bypasses it, none exists, and the absence is the design. The only exit is
restoring the exact pre-lifecycle database checkpoint — which is exactly what
this guide describes, and why `incomplete_lifecycle` always maps to the
`recover` next action rather than to `retry`.

## Where to go next

- [release.md](release.md) — the six post-freeze next actions, and which
  failures are *not* recoveries.
- [assess.md](assess.md) — re-reading the site once it is stable again.
- [internals.md](internals.md) — the raw runtime actions this verb drives, for
  the people who maintain it.
- [capabilities-and-limits.md](capabilities-and-limits.md#refusal-to-remedy) —
  every refusal bucket and its one remedy.
