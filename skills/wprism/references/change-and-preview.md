# Change and preview

Use this reference for ordinary branch work, capture, planning, preview, and
rehearsal. Git remains the branch authority; WPrism owns the boundary between
canonical repository state and a WordPress environment.

## Establish the current state

Before choosing an action, inspect:

```sh
git status --short --branch
wprism envs
wprism doctor <env>
wprism status <env>
```

For a machine-readable plan use `wprism plan <env> --format=json`; `status`
renders a readiness view and its nonzero exit means the environment is not
ready. Use `wprism explain <env> <selector> --format=json` to inspect one
itemized action without applying it.

## Branch and environment lifecycle

Create and merge branches with Git. When a provider-backed branch environment
is available, `wprism env materialize` binds one coherent production
database/media snapshot to the exact clean branch. `--create` is separate
infrastructure authority; omit it unless the provider advertises receipt-backed
create and destroy and the user requested creation.

`wprism env reap <env>` is the explicit, identity- and lease-fenced cleanup.
Created targets are destroyed; attached targets are detached. A stale resource
identity refuses. Do not replace reap with provider-specific deletion.

The higher-level preview aliases are `wprism preview create` and
`wprism preview remove`; `wprism rehearse` additionally exercises the closed
rehearsal contract. A rehearsal is evidence about the exercised candidate and
provider proof. It does not certify an adapter, accept a contract, or authorize
a production release.

## Capture and classification

`wprism capture <env>` reads authored state from that environment and publishes
canonical repository files. It may mint or repair entity identity. Run it only
against the explicitly selected authoring environment and review the resulting
Git diff.

On an unclassified surface:

1. inspect `wprism pending <env>`;
2. present the proposed classification and secret flags;
3. obtain the human's exact decision; and
4. use interactive classify or an exported, queue-bound batch.

Never automatically accept all proposals when any decision affects an
authored/runtime/environment/derived/managed boundary. Secret-authored rows
require the command's explicit human gate and must not be exposed in chat.

## Refresh, rebase, and merge

`wprism refresh <production-env> --production-ref=<ref>` reads production truth
and writes a local immutable B/P/W plan. The Git ref proves topology, not live
database truth. Optional `--field-diff` is value-free and non-authorizing.

`wprism rebase` re-reads production and creates a new branch atomically through
a disposable worktree. It does not rewrite the source branch. Conflict choices
are authority: use a reviewed resolution document or an explicitly requested
trusted-terminal interactive session. Interactive field views may contain
personal or secret values; never capture them into model-visible logs.

`wprism merge-check` is offline and read-only. Exit 3 with a valid
`wprism-merge-check/v1` document means coherent inputs with human conflicts; it
is not a refusal and does not authorize a merge or release.

## Apply boundaries

`apply`, `deploy`, and `promote` mutate an environment. Use them only for the
environment and purpose the user selected, after a fresh plan/status read.
They are lower-level operational verbs, not substitutes for the signed
production release sequence. For production, follow
[release](release.md).

Deletions are separate authority. Do not add `--with-deletes` merely to turn a
nonzero status green. Review the exact tombstones and affected surface first.

## Canonical repository guides

For the matching WPrism checkout, consult:

- `docs/guides/daily-workflow.md`
- `docs/guides/release.md` for preview/rehearsal containment
- `docs/guides/capabilities-and-limits.md`
- `docs/guides/code-updates.md` for plugin/theme code changes
