# Thread 2 — Host CLI and environment orchestration

*Status: proposed for owner approval.*

*Depends on: [Thread 0](thread-0-foundation.md).*

*Primary rule: make the host application modular while preserving its current command contract.*

## Goal

Turn `cli/duo` into a composition-only root and separate command parsing,
application orchestration, transport, environment lifecycle, and presentation.
The host should depend on stable component facades and versioned target bytes,
not reach into WordPress-agent implementation classes.

This is a structural round. Existing commands such as `init`, `env
materialize`, `promote`, `refresh`, and `rebase` keep their post-Thread-0 safety
baseline names and semantics. New product-level Assess, Rehearse, Release,
Verify, and Recover operations come later.

## Why this thread exists

- `cli/duo` is roughly 2,690 lines, manually loads dozens of classes, owns
  dispatch and substantial orchestration, and contains global helpers.
- Host code directly requires agent source classes. `RefreshPlan` builds a
  second hand-maintained agent load graph.
- `EnvironmentLifecycle.php` is more than 2,200 lines and combines provider
  protocol validation, process execution, journaling, Git operations,
  materialization, reap, retries, and presentation-ready result construction.
- Host and agent plan rendering are intentionally maintained “in lockstep,”
  making divergence likely.
- Several commands parse raw arrays repeatedly and mix validation, remote
  invocation, decisions, and terminal rendering.

## Owned surface

Thread 2 exclusively owns the host composition root and general host
application files:

```text
cli/duo
cli/README.md
cli/src/Adopt.php
cli/src/AdoptCommand.php
cli/src/BootstrapEligibility.php
cli/src/CaptureCommand.php
cli/src/ClassificationBatch.php
cli/src/ClassifyCommand.php
cli/src/CommandOutput.php
cli/src/DockerTransport.php
cli/src/Doctor.php
cli/src/DoctorCommand.php
cli/src/DriverCapabilitiesCommand.php
cli/src/EnvironmentCommand.php
cli/src/EnvironmentCommandOptions.php
cli/src/EnvironmentCommandPreflight.php
cli/src/EnvironmentDriver.php
cli/src/EnvironmentLifecycle.php
cli/src/EnvironmentListCommand.php
cli/src/Init.php
cli/src/InitCommand.php
cli/src/LocalTransport.php
cli/src/PassthroughCommand.php
cli/src/Pending.php
cli/src/PendingCommand.php
cli/src/RebaseCommand.php
cli/src/Refresh.php
cli/src/RefreshCommand.php
cli/src/RefreshFieldDiff.php
cli/src/RefreshPlan.php
cli/src/RefreshPlanCompile.php
cli/src/Registry.php
cli/src/ScopeCommand.php
cli/src/SshTransport.php
cli/src/StatusCommand.php
cli/src/Transport.php
cli/src/Triage.php
docs/adoption.md
```

Thread 4 owns host adapter/catalog authoring modules. Thread 5 owns host
planning/promotion/rollback modules. Thread 2 alone wires all of them into
`cli/duo`; those owners do not edit the root dispatch table.

Thread 1 supplies the generated host bootstrap/build artifact. Thread 2 lands
the small entrypoint integration after the interface is stable.

The Thread 0 ledger assigns future `Application/`, `Environment/`,
`Transport/`, and `Refresh/` host prefixes to Thread 2. Adapter/catalog and
mutation/promotion host prefixes belong to Threads 4 and 5 respectively.

## Target internal shape

```text
cli/duo
  -> ConsoleApplication
       -> CommandRegistry
       -> Input parser
       -> Command handler/use case
       -> Output presenter

Command handler
  -> EnvironmentRepository
  -> AgentGateway
  -> Transport
  -> Git/Process ports
  -> feature facade from Thread 4 or 5
```

Arrays and strings remain acceptable at wire and configuration boundaries.
Inside the host application, new request/result objects should make required
fields and state transitions explicit.

## Deliverables

### 1. Characterize the current CLI contract

Before extraction, create a command matrix covering:

- command and subcommand;
- argument/option grammar and defaults;
- whether a repository or environment is required;
- allowed transports;
- exit codes;
- stdout/stderr ownership;
- JSON and human output fixtures;
- documented recovery/resume path;
- side effects and preconditions.

Golden command tests must normalize genuinely volatile values only. They may
not make tests pass by deleting identifiers, hashes, refusal codes, or ordering
that consumers rely on.

### 2. Thin entrypoint and command registry

Move routing from the procedural script into a `ConsoleApplication` and
explicit command registry. The executable should perform only:

1. compatibility/bootstrap guard;
2. dependency composition;
3. input handoff;
4. exit-code return.

Extract one command family at a time. Keep a temporary legacy handler when a
safe mechanical move is not yet ready. Remove each legacy branch only after
its contract fixtures and focused regressions pass.

Command handlers must not print directly except through the current output
abstraction. Human and JSON views consume the same result object so decisions
are not recomputed by presenters.

### 3. Agent gateway and transport boundary

Create one host-side `AgentGateway` responsible for:

- target command construction;
- environment and transport selection;
- an invocation timeout/cancellation port while preserving current default
  behavior until a separately characterized timeout policy is approved;
- stdout/stderr capture;
- agent-version observation;
- current JSON decoding and validation;
- conversion to a typed host result or current refusal.

Do not introduce a new wire envelope in this round. The gateway must read the
current protocol exactly. Its boundary makes a future dual-reader migration
possible.

Centralize all direct `agent/src` compatibility imports behind one named
legacy bridge. Prevent new imports with the boundary checker. Remove a breach
only when the host can validate the same contract without copying agent
business logic. The goal is one visible compatibility debt point, then zero;
not parallel implementations of canonicalization or policy.

The transitional host artifact either packages the bridge's exact files and
binds them in its artifact/evidence manifest or eliminates the bridge before
final dist. Checkout-relative imports are forbidden in a shipped artifact.

### 4. Environment registry and lifecycle decomposition

Split environment behavior into independently testable roles:

- environment registry parser/validator;
- provider capability client;
- lifecycle journal store;
- immutable operation context;
- materialization coordinator;
- reap coordinator;
- snapshot/fence/TTL phase services;
- Git workspace/ref service;
- lifecycle result projector.

Keep the existing phase ordering, provider actions, journal records, operation
IDs, replay/retry rules, receipts, and refusal behavior byte-compatible.

The current environment registry does not prove the finalized spec's role,
data-minimization, credential, egress, retention, or containment requirements.
Do not add a `disposable: true` shortcut or treat provider booleans as trusted
attestation. Thread 0 first restricts production-derived materialization to
approved synthetic fixtures or a loud refusal. The refactor preserves that
post-safety-gate behavior and exposes a neutrally named
`CurrentEnvironmentContext` seam. The enhancement round defines and proves the
future environment profile/containment contract.

### 5. Refresh/rebase decomposition

Separate:

- observation acquisition;
- repository/worktree management;
- three-way planning;
- field-level decision calculation;
- interactive input;
- candidate publication;
- run journaling and recovery;
- presentation.

The semantic planner is pure where possible. Git/process/filesystem behavior
is behind ports. Preserve clean-worktree checks, exact refs, cancellation,
field policy, stable plan/receipt hashes, and current conflict output.

`RefreshPlan` must stop assembling an independent agent runtime. Until the
proper wire or repository-contract boundary exists, put the current behavior
behind the single legacy bridge and characterize it.

### 6. Adoption and bootstrap composition

Separate artifact selection, target eligibility, transfer, install, version
verification, and rollback into explicit steps. Consume Thread 1's verified
release-family and target release-set manifests, component payloads, and
composite target-install archive rather than reconstructing source-tree
membership locally.

Preserve target paths, atomic swap/rollback protocol, compatibility checks,
declared artifact contents, version proof, and public behavior. The agent,
recovery runtime, immutable declarations, review bundle, and
derived projection pack are one version-consistent target install unit;
adoption must never mix components from different manifests. The target archive
does not carry host bytes, but the host CLI must verify its own artifact digest
and protocol tuple against the selected release family before mutation. Byte
identity with the old source archive is not required after the approved
generated-loader build change. Adoption must work from an explicit composite
bundle outside the source checkout and must not fall back to source bytes.

Ratify an immutable internal `ReleaseSelection` input with: composite bundle
path, expected release-family digest, expected target release-set digest,
trusted current-review Ed25519 public keys indexed by authority/key IDs, and expected
host/agent/recovery-protocol tuple. Thread 2 owns the input contract and injects
it at `cli/duo` and owns the trusted-selection reader. Thread 1 owns only
manifest format/assembly. Thread 0 owns the selection-pin schema/update policy,
and an approved release authority or operator-controlled configuration supplies
the expected family digest outside the artifact producer's control. Trust is
obtained from that non-co-located pin (or an explicit internal caller input),
never inferred from a manifest beside the archive. Existing public options
remain compatible; any new public selector needs the separately reviewed
artifact-migration command-contract amendment.

Adoption verifies the complete selection, review authority, every expected
payload/overlay digest, recomputed projection agreement, and actual bytes
before staging. It does so through Thread 4's pure host-safe artifact-trust
verifier, packaged and closure-bound in the host artifact by Thread 1; host code
does not import agent/WordPress implementation. A fixture modifies an archive
and its adjacent manifests consistently and proves rejection because neither
matches the independently pinned release family.

### 7. Host presentation boundary

Consolidate terminal width/color/interaction and JSON presentation without
changing current output. Presenters may translate existing decisions into
human language; they may not decide readiness, mutation authority, recovery
coverage, or success.

Thread 5 owns plan/promotion decision production. Thread 2 owns consistent
top-level rendering and process exit behavior.

## Interfaces exported

- `ConsoleApplication` and command registration contract.
- `AgentGateway` current-protocol facade.
- transport result and process-execution ports.
- environment registry repository and current lifecycle facade.
- Git/worktree/process ports.
- host output/presenter contract.
- one documented legacy agent-contract bridge with a shrinking import list.
- stable HostContracts consumed by Thread 5.
- host composition for Thread 5's byte-compatible recovery protocol client,
  used to remove root imports of recovery internals.
- internal `ReleaseSelection` trust input consumed at the composition root.
- host-side reader for Thread 0's independently controlled release-selection
  pin, consuming Thread 4's pure artifact-trust verifier.

## Constraints

- No new public command or renamed engine verb.
- No output, exit-code, registry, lifecycle journal, or provider schema change.
- No hidden target mutation in observation helpers.
- No removal or bypass of Thread 0's production-snapshot quarantine.
- No capability/readiness/authorization decisions inside presenters.
- No new direct host import of an agent implementation class.
- No fallback that converts malformed target output into success.
- No shell-command string construction when an argv/process abstraction can
  preserve exact argument boundaries.
- No broad rewrite of Git, transport, or recovery behavior.

## Required verification

At minimum, run the catalog-selected suites for:

- CLI command parsing/output;
- local, Docker, and SSH transport contracts;
- doctor/adoption/init/capture/status/pending/classify;
- environment materialize/reap/recovery;
- refresh/rebase and field conflicts;
- plan/promotion/rollback host integration supplied by Thread 5;
- built-artifact adoption;
- post-evidence release validation against the retained exact
  release-family/target-set/composite bytes;
- the complete offline corpus before integration.

Use the common commands from Thread 1, including
`make test-component COMPONENT=host-cli` and explicit catalog IDs for
integration/conformance lanes. Thread 2 owns the host-cli catalog/component
profile fragment; Thread 1 validates and aggregates it.

Live/SSH suites run where the foundation impact map requires them. All command
fixtures must pass against both the legacy root and the extracted root during
migration through a test-only composition selector. The same vector compares
exit code, stdout, and stderr bytes. No production fallback flag selects the
legacy root.

Pre-evidence adoption uses Thread 1's explicitly non-authorizing candidate
profile only. Final adoption proof comes solely from the post-evidence
release-validation profile, which also exercises recovery/rollback on its
independently authorized qualification target without rebuilding the artifact.

## Done means

- An architecture check permits `cli/duo` to perform only compatibility guard,
  composition, input handoff, and exit return; it has no workflow imports or
  direct process/filesystem/network mutation.
- Every existing command is registered explicitly and retains its contract.
- Remote agent interaction passes through one gateway.
- Direct agent-source imports are eliminated or isolated in one shrinking,
  tested legacy bridge.
- Environment and refresh workflows consist of named stages with injected
  side-effect ports and exactly their current retry/resume behavior.
- Human/JSON presenters do not recompute domain decisions.
- Built host artifacts run without a source checkout.

## Explicitly deferred

- `duo assess` and its public schema.
- Rehearse containment enforcement or new environment-role semantics.
- Product Release/Verify/Recover commands.
- New refusal/envelope/framing protocols.
- Authentication, organization policy, or frozen product authorization plans.
- Multisite support, UI, or remote service APIs.
