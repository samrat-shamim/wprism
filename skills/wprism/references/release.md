# Release

Use this reference for the approval-safe public release seam. The supported
control-plane sequence is:

```text
stage source -> prepare read-only -> external review/sign -> execute once
             -> read-only status/reconciliation -> verify
```

Do not replace it with the legacy interactive or `--yes` release path.

## Preconditions

Before staging, establish and record:

- the exact environment selected by the user;
- a clean named local source branch or advertised tag and its commit/tree;
- a current accepted application contract and assessment;
- a clean target base and supported driver/provider capabilities;
- the operation-authority policy identity already enrolled on the target; and
- a new immutable operation ID for this release intent.

`authority-policy status` is read-only. `authority-policy sync` is a separate
trust-root mutation and must not be performed merely to make release proceed.

## Stage and prepare

Save canonical stdout directly to files:

```sh
wprism stage-source <env> --from=<advertised-ref> \
  --operation=<operation-id> --format=json > stage-receipt.json

wprism release <env> prepare \
  --stage-receipt=stage-receipt.json \
  --expected-stage-receipt-sha256=<receipt-sha256> \
  --format=json > release-prepare.json
```

Staging retains an inert source ref/worktree under the target's private Git
directory; it does not move target `HEAD`. Reusing the operation ID with
different source inputs must refuse.

Preparation is byte-read-only for the target and local site repository. It
must validate the staged source, target, contract, capability library,
conditions, and authority policy. Preserve the resulting
`wprism-release-prepare/v1` bytes. The semantic plan digest, exact presentation
digest, and complete subject digest are distinct identities.

## External authority

Present the exact prepare document and its recovery claim to the responsible
actor. WPrism intentionally provides no actor-signing helper. The skill must
not create a signing key, sign the statement, choose the actor, lifetime or
grants, or copy a private key into the model context.

Accept only a canonical `wprism-operation-authorization/v1` envelope from the
separately enrolled authority system. Preserve its exact bytes and separately
compute or receive their `sha256:` digest. Do not rerun preparation after the
actor approves; a new presentation with the same semantic plan is still a
different subject.

## Execute

Copy every expected digest from the two preserved documents and supply them
explicitly:

```sh
wprism release <env> execute \
  --prepare=release-prepare.json \
  --authorization=authorization.json \
  --expected-authorization-sha256=<authorization-sha256> \
  --expected-subject-sha256=<subject-sha256> \
  --expected-presentation-sha256=<presentation-sha256> \
  --expected-plan-digest=<plan-digest> \
  --expected-stage-receipt-sha256=<receipt-sha256> \
  --format=json > release-outcome.json
```

Execution is the mutation boundary. Immediately before consumption it must
revalidate target/source facts, the complete reviewed evidence set, policy,
signature, subject, grants, and target-clock lifetime. A changed or
uncheckable fact refuses; never weaken the command to fit the approval.

## Replay and reconciliation

Use read-only status whenever execution was interrupted or its result was not
captured completely:

```sh
wprism release <env> status \
  --prepare=release-prepare.json \
  --expected-subject-sha256=<subject-sha256> \
  --format=json > release-status.json
```

Interpret only the closed durable sequence:

| State | Sequence | Meaning |
| --- | ---: | --- |
| `prepared` | 0 | No election is durable; an exact execute may revalidate and proceed |
| `elected` | 1 | Partial election is ambiguous; reconciliation required, no mutation retry |
| `consumed` | 2 | Authority was consumed without completion; reconciliation required, no mutation retry |
| `completed` | 3 | Return and validate the stored terminal outcome |

Do not delete target-private control evidence to clear sequence 1 or 2. Do not
submit a replacement authorization for the same tuple. An exact execute replay
is allowed only according to the public contract; never use it as a guessed
retry after an ambiguous boundary.

## Verify and report

Run `wprism verify <env> --plan=<plan-digest> --format=json` after a completed
release. Verification requires both a fresh convergence read and every journey
declared in the accepted contract. An empty journey set means byte-level
verification only; report that limitation.

Preserve the frozen plan for recovery matching. Report the terminal outcome,
conditions rechecked, verification format/result, and any declared external
effects that recovery does not undo.

Follow the release document's exact next action: `resume`, `reconcile`,
`retry`, `recover`, `requalify`, or `escalate`. Only the documented transient or
pre-consumption cases earn retry; ambiguous commitment never does.

## Canonical repository guide

For the matching WPrism checkout, read `docs/guides/release.md` before executing
a production release. It is authoritative for the installed command's current
formats, flags, refusal families, and recovery-profile semantics.
