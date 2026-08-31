# Onboarding and assessment

Use this reference for first contact with WPrism, adoption, assessment, and the
application-contract review boundary. Bind command details to the installed
CLI's `--help`; the examples show the current workflow shape.

## Choose the entry point

| Situation | Entry point | Boundary |
| --- | --- | --- |
| Learn on disposable local sites | `wprism demo start --scenario=woocommerce` | Creates disposable containers and repositories; never production evidence |
| Existing SSH site with no workspace | `wprism connect …`, then `wprism onboard …` | `connect` inspects and creates a local workspace; `onboard` adopts, assesses, initializes, and may publish the initial Git baseline |
| Existing local site with authorized bootstrap | `wprism adopt <env>`, then `wprism init <env>` | Installs the control plane and establishes baselines only through the explicit local bootstrap contract |
| Existing Docker or already adopted target | `wprism assess <env>` | Docker never infers delivery authority from shell or bind-mount access |

The demo surface is intentionally human-oriented. Its public verbs emit prose,
`demo apply` accepts no expected candidate digest and may commit the current
dirty source tree, and interrupted cleanup has no machine-readable terminal
result or public reconciliation token. Treat demo output as diagnostic text,
never claim that it applied an exact previously reviewed candidate, and report
cleanup as `outcome unknown` if `demo stop` is interrupted or ownership cannot
be reconciled through the public command.

Do not invent `site.wprism.json` or silently add environment authority. The
committed site file and the untracked, machine-local `.wprism-envs.json` have
different trust roles. An overlay entry replaces a committed entry whole; it
does not merge secret fields into it.

`connect` performs no explicit target write before creating the workspace, but
its WordPress topology probe boots WordPress with `wp eval`; site startup code
can have effects. Describe that boundary before treating it as a passive HTTP
read.

`onboard` is a composed mutation path:

```text
adopt -> assess -> init -> optional initial Git publication/handoff
```

Use it only when the user requested onboarding. Without a Git URL it stops
after init and prints a handoff-only continuation; do not improvise repository
publication. The remote must be empty and independently reachable by both the
controller and target. Success and handoff are prose-only: there is no canonical
receipt binding target, repository, environment generation, capability or
contract evidence, and a release-preparation next action. Re-observe those facts
through their public commands before later work, and never carry onboarding
prose forward as release readiness or authority.

## Assessment observation

Start with the two observations that do not write the site repository:

```sh
wprism doctor <env>
wprism driver-capabilities <env> --operation=<workflow> --format=json
```

`driver-capabilities` is local configuration admission and contacts no target.
`doctor` contacts the environment. Before assessment, inspect Git status and
`.wprism/contract/<env>/proposed.json` plus
`.wprism/contract/projection.json`. `assess` writes a fresh local proposal and,
when an accepted contract exists, rewrites the local projection. It does not
write the target, but it can overwrite an in-progress proposal for the same
environment. If either local file contains review work that has not been
preserved by the responsible reviewer, stop before assessment.

Then capture the authoritative complete report:

```sh
wprism assess <env> --format=json
```

The inventory/readiness projection can be substantially larger than ordinary
command results. Capture its complete stdout without a generic small ceiling.
If it is truncated, reject it as evidence and do not infer omitted rows. Exit
`3` is a complete assessment with one or more red readiness rows; it is not a
refusal. Exit `1` means the assessment refused.

Assessment next actions are a closed vocabulary. Follow the exact action and
operation named by the document:

- `classify`: inspect `wprism pending <env>`; classification is a reviewed
  policy mutation, not an automatic cleanup.
- `declare in contract`: review the proposed boundary below.
- `install adapter` or `certify adapter`: enter the adapter workflow; rehearsal
  cannot manufacture certification.
- `provision env value`: use `wprism env-set <env> --name=<name> --stdin` only
  with an authorized value source, never by placing the value in arguments or
  chat.
- `exclude`: keep the named operation or surface out of scope.
- `nothing — supported`: no other action won for the projected operation.

Do not translate `qualify in rehearsal` or `attest contract` into an automatic
step. In this profile those values exist for format closure, but ordinary
assessment does not emit them as executable advice.

## Application-contract review

The contract sequence is:

```sh
wprism contract <env> propose --format=json
# human reviews and edits .wprism/contract/<env>/proposed.json
wprism contract <env> accept
wprism contract <env> show
```

Inspect and preserve any existing proposal before `propose`, because proposing
re-assesses and replaces that repository-local file. The JSON form returns the
same new proposal for review. `show` is not a proposal viewer: it reads only the
already accepted site-level contract and refuses with `contract_missing` on a
fresh site. The proposal deliberately contains unresolved decisions and is
never authority. Present its exact bytes, unsupported boundaries, journeys,
and external effects to the human reviewer. Do not invent `decided_by`, a
reason, or a journey on their behalf.

`accept` is appropriate only after that review. It re-assesses, refuses a stale
or cross-environment proposal, writes canonical site-level `contract.json` and
`projection.json`, and stages them. It never commits. The commit is the
organization's review signature, so the responsible human makes it. The agent
never makes this commit on the reviewer's behalf, even when it performed the
mechanical proposal or acceptance steps.

An adapter certificate and a contract attestation are different claims under
different trust roots. Neither means WPrism tested the site, and neither may be
inferred from an accepted proposal.

## Canonical repository guides

When a matching WPrism source checkout is available, cross-check:

- `docs/guides/quickstart.md`
- `docs/guides/assess.md`
- `docs/adoption.md`
- `cli/README.md`

These checkout guides add version-specific examples; their absence from an
installed-CLI environment does not weaken or replace the safety boundaries in
this skill. Bind every executable detail to the installed command's help and
validated output format.
