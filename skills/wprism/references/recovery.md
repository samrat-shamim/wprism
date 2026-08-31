# Recovery

Use this reference only after preserving the failed release evidence and
establishing which public recovery profile applies. Recovery restores bounded
resources; it never erases already delivered email, payment, webhook,
third-party, order, session, or other post-checkpoint consequences.

## First establish truth

Do not begin with restore. Preserve the release outcome/status, then inspect:

```sh
wprism release <env> status \
  --prepare=release-prepare.json \
  --expected-subject-sha256=<subject-sha256> \
  --format=json
wprism recover <env> --list --format=json
wprism status <env>
```

Select a receipt only from the target's current public catalog and bind its
exact identity, generation, state, kind, coverage, checkpoint evidence, and
claim. Never infer the correct checkpoint from timestamps, filenames, or the
latest-looking row. A newer begun release can make an older retained checkpoint
unsafe even while the file still exists.

Read the literal `restores`, `does_not_restore`, and maximum-loss boundary to
the human responsible for the incident. Do not soften or summarize away an
uncovered resource or external consequence.

## Actor-authorized recovery

The current asynchronous prepare/execute seam is deliberately narrow. Prepare
is read-only:

```sh
wprism recover <env> prepare \
  --restore=<receipt-id> \
  --operation-id=<incident-operation-id> \
  --format=json > recovery-plan.json
```

It supports only the receipt/profile states admitted by the installed CLI.
Retained, scoped, terminal, expired, identity-incomplete, or otherwise
unsupported receipts must refuse; do not route around that refusal with a
different profile.

An independently enrolled actor system reviews and signs the exact
`wprism-recovery-plan/v1` subject. The rollback key, adapter/contract keys, and
the agent are not actor authority. Preserve the canonical plan and returned
authorization bytes, then execute only on explicit incident authorization:

```sh
wprism recover <env> execute \
  --plan=recovery-plan.json \
  --authorization=recovery-authorization.json \
  --format=json > recovery-outcome.json
```

Exact completion replay may return the stored outcome after authorization
expiry. Consumption without completion, a partial election, or evidence that
is not an exact resumable prefix requires reconciliation. Never start a second
recovery or substitute another authorization.

## Legacy operator-directed restore

The one-call path remains separate:

```sh
wprism recover <env> --restore=<receipt-id> --writers-excluded --format=json
```

`--writers-excluded` asserts a real external maintenance window excluding
every WPrism writer, package manager, updater, shell automation, and other
administrator for the entire import. A database-local lock cannot protect a
database replacement. The agent must not infer this condition; the operator or
provider that established the exclusion must state it.

The selected profile determines what actually runs. `--operator-directed`
forces the weaker database-focused path and is not a refusal bypass. Code-first
ordering is enforced: when the checkpoint covers a code phase, reconcile the
exact pre-release code revision before database import if the profile does not
restore code itself.

The operator-directed path's final abort is mandatory even after import
failure because it releases the lease row restored by the dump. Use the public
command; never reproduce its internal rollback-control steps manually.

## Pruning and aftermath

Checkpoint pruning is destructive and unrelated to incident recovery. Do not
use `--confirm-prune` unless the user separately requested retention cleanup
after reviewing exactly which retained checkpoint files will be removed.

After a terminal recovery:

```sh
wprism status <env>
wprism assess <env> --format=json
```

Then reconcile every item in `does_not_restore` through its owning human or
external system. Report any maximum-loss-window writes as lost or requiring
manual reconciliation; do not claim the site is restored merely because the
database import exited zero.

An `incomplete_lifecycle` or `incomplete_apply` receipt is a recovery case, not
a force/retry case. An obsolete or superseded checkpoint refusal is terminal
for that receipt; use the current release's recovery evidence or the provider
that owns backups.

## Canonical repository guide

For the matching WPrism checkout, read `docs/guides/recovery.md` before any
recovery mutation. It is authoritative for eligible receipts, current formats,
profile coverage, refusal remedies, and enforced ordering.
