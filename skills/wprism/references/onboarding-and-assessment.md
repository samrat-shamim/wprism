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
controller and target.

## Read-only assessment

Start with:

```sh
wprism doctor <env>
wprism driver-capabilities <env> --operation=<workflow> --format=json
wprism assess <env> --format=json
```

`driver-capabilities` is local configuration admission and contacts no target.
`doctor` contacts the environment. `assess` is the authoritative read-only
inventory/readiness projection and can be substantially larger than ordinary
command results. Capture its complete stdout without a generic small ceiling.
If it is truncated, reject it as evidence and do not infer omitted rows.

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
wprism contract <env> propose
wprism contract <env> show
# human reviews and edits .wprism/contract/<env>/proposed.json
wprism contract <env> accept
wprism contract <env> show
```

`propose` re-assesses and writes a repository-local proposal. It deliberately
contains unresolved decisions and is never authority. Present the exact
proposal, unsupported boundaries, journeys, and external effects to the human
reviewer. Do not invent `decided_by`, a reason, or a journey on their behalf.

`accept` is appropriate only after that review. It re-assesses, refuses a stale
or cross-environment proposal, writes canonical site-level `contract.json` and
`projection.json`, and stages them. It never commits. The commit is the
organization's review signature, so leave it for the responsible human unless
they explicitly instruct the exact commit after reviewing the staged bytes.

An adapter certificate and a contract attestation are different claims under
different trust roots. Neither means WPrism tested the site, and neither may be
inferred from an accepted proposal.

## Canonical repository guides

For the matching WPrism checkout, consult:

- `docs/guides/quickstart.md`
- `docs/guides/assess.md`
- `docs/adoption.md`
- `cli/README.md`
