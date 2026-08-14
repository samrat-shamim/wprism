# Thread 3 — WordPress agent and capture pipeline

*Status: approved for P0 inventory/test-only work; production execution follows Platform P0.*

*Depends on: [Thread 0](thread-0-foundation.md).*

*Primary rule: isolate WordPress and decompose read/capture behavior without changing repository bytes or authority.*

## Goal

Make the WordPress agent a thin command/composition layer over explicit
application services, and finish decomposing Capture, Init, observation, and
repository projection into testable stages.

The thread preserves current WordPress behavior, repository format, stable
identity, safety gates, and command outputs. It creates clean seams for a later
Assess workflow and richer product contracts; it does not implement them.

## Why this thread exists

- `agent/src/Cli.php` is roughly 2,950 lines and mixes WP-CLI registration,
  parsing, command orchestration, refusal handling, JSON output, and human
  presentation.
- Apply is now a thin facade and Init/Capture already have useful collaborators,
  but `CapturePublicationWorkflow::run()` still owns a long sequence of locks,
  ledger setup, candidate construction, publication, recovery, linting, and
  summary construction.
- WordPress globals and static database/filesystem calls make pure behavior
  difficult to exercise without custom global fakes.
- `Snapshot.php`, repository compilation, reference/identity logic, and
  canonical projection are consumed from several workflows without a clear
  read-model boundary.
- Host code currently imports selected agent classes because repository
  contracts are not exposed cleanly.

## Owned surface

Thread 3 owns:

- `agent/src/Cli.php` and agent-side command wiring;
- Init, observation, pending/classification, coverage, and agent lint command
  application services, including the agent-side `AdapterObservation.php`
  command/collector and its WordPress adapter;
- `Capture*.php`, `*Capture.php`, publication/publish collaborators, and
  capture safety orchestration;
- `Snapshot.php` and WordPress read-model extraction;
- canonical repository representation, parsing/validation/compiler, stable
  identity, tokens, references, media, and state-tree projection not assigned
  to Thread 4;
- repository/scope contract leaves (`ScopeContract`, `ScopeClosure`, current
  `RepositoryAuthorization`), tombstone representation/capture (`Deletion`),
  and pure code descriptor/compatibility contracts (`CodeDescriptorCompiler`,
  `CodeStateContract`, `CodeCompatibility`);
- `CanonicalSurfaces`, `Lint`, and `LintFinding` as repository/read-model and
  Capture safety behavior;
- agent command/refusal presentation while preserving the current refusal
  contract;
- semantic tests for those behaviors.

Operationally, this is every `agent/src/*.php` file not explicitly assigned in
Thread 4 or Thread 5's allowlists. Thread 0 has materialized that rule in the
machine-readable exhaustive ownership ledger for the current tree, assigned
every cross-domain file one writer, and resolved every overlap before fan-out.
This residual rule does not own future files: new code goes under the exclusive
`Agent/`, `Capture/`, `Repository/`, or `WordPress/` module prefix assigned in
the ledger. No production edit starts until the ledger check passes and the
Platform P0 production-edit lock below is released.

Thread 1 owns `agent/duo.php`, `agent/duo-loader.php`, and generated loading.
Thread 4 owns Policy/adapter/provider/capability/evidence behavior. Thread 5
owns mutation, deployment, promotion, lifecycle, and recovery behavior.

The authoritative path assignments are the ledger, not the residual rule:
future `agent/src/Agent/`, `Capture/`, `Repository/`, and `WordPress/`
prefixes, `docs/contracts/commands/agent/`, and
`sandbox/catalog/fragments/wordpress-agent/` belong to Thread 3. The
`CaptureMutationPort.php` leaf is Thread 3-owned, while
`CaptureMutationBridge.php` is Thread 5-owned. `Journal.php` is Thread 3-owned;
`Providers.php` and `ProviderSdk.php` are Thread 4-owned. The machine-readable
ownership ledger and `scripts/ownership-check` are binding for every other
tracked path.

### Foundation execution lock

Inventory and test work may begin from the published foundation tag. Production
edits remain locked until Thread 1's platform P0 has landed, the owner-authored
Thread 2–5 test fragments have been integrated, Thread 1 has generated the
aggregate and activated nonempty profiles, and every thread worktree has
rebased onto that P0 integration commit. Thread 3's required fragment path is
`sandbox/catalog/fragments/wordpress-agent/`.

The one-time published fan-out receipt at
`refs/tags/refactor-foundation-2026-08-14` binds
`refs/heads/refactor/integration` and the five thread refs to the same
foundation commit under `duo-foundation-fanout-receipt/v1`. Its publication and
unadvanced topology were verified and recorded before this charter was
approved. Subsequent thread refs advance as descendants; the one-time topology
check is not an ordinary thread gate or permission to bypass the P0
production-edit lock.

## Target internal shape

```text
WP_CLI registration
  -> AgentCommandRegistry
       -> Command handler
            -> read/capture application service
                 -> Policy/Capability facade       (Thread 4)
                 -> WordPress ports/adapters
                 -> Repository projection
                 -> Publication boundary
```

Only composition and infrastructure adapters know `$wpdb`, WP functions,
WP-CLI globals, process environment, or concrete filesystem layout. Pure
planning/projection code receives validated values.

## Deliverables

### 1. Characterize the agent command contract

Capture every current `wp duo` command's:

- registration name and synopsis;
- argument/option grammar;
- access and mutation boundary;
- exit/refusal behavior;
- human and JSON output;
- canonical/digested inputs and outputs;
- direct WordPress/global dependencies.

Add golden command fixtures and focused characterization around redaction,
unknown input, malformed repositories, unsupported topology, and interrupted
state. Do not normalize away stable fields or ordering.

### 2. Thin `Cli.php`

Introduce an agent command registry and one handler per existing command family.
The root remains responsible only for WP-CLI registration, dependency
composition, top-level exception/refusal conversion, and handing the result to
the current presenter.

Extract incrementally:

1. read-only/status/explain commands;
2. Init and classification commands;
3. Capture commands;
4. mutation/deploy commands as adapters to Thread 5's facade;
5. capability/adapter commands as adapters to Thread 4's facade.

Threads 4 and 5 export tested handlers/services; Thread 3 alone lands the
registration call. No other branch edits `Cli.php`. The merge train places the
Thread 3 agent/Capture core after Thread 4's stable facades and before Thread
5's mutation/recovery integration; Thread 3 performs the final narrow
`Cli.php` wiring only after Thread 5 exports its tested handlers.

### 3. WordPress infrastructure boundary

Define narrow ports around behavior actually needed by this thread:

- database reads and transaction observation;
- options, metadata, posts, terms, menus, users, and attachment reads;
- filesystem/path operations;
- WordPress/plugin/theme inventory;
- clock and identifier generation where nondeterminism exists;
- WP-CLI output and progress;
- Capture-facing cron suppression, exposed as a WordPress execution port rather
  than a dependency on Thread 5's mutation canary.

Wrap current static/global implementations rather than rewriting them at once.
New application code receives ports through constructors or request-scoped
composition. Do not introduce a global service locator.

The adapter boundary validates mixed WordPress values and maps platform errors
to the existing refusal/exception behavior. Pure code is strict and does not
call WordPress functions conditionally.

### 4. Finish Capture orchestration decomposition

Retain `Capture` as the public facade. Turn the publication workflow into an
explicit coordinator over existing and extracted stages:

- request and destination validation;
- topology and policy preflight;
- interrupted-init/publication recovery check;
- destination lock acquisition;
- coherent snapshot transaction;
- scoped/full capture selection;
- candidate construction;
- secret/PII and lint safety evaluation using the post-foundation safety
  baseline;
- repository compilation/verification;
- atomic tree publication;
- ledger and commit-marker finalization;
- cleanup/recovery;
- stable result projection.

Each stage has one side-effect boundary and focused tests. Ordering is part of
the contract: extractions must not move a read/write, shorten a lock, change a
transaction boundary, or alter recovery evidence without a separately approved
behavior change.

Continue using current collaborators rather than creating parallel versions.
Large methods should become readable orchestration over those collaborators,
not a chain of one-line indirection.

### 5. Repository and read-model boundary

Separate:

- WordPress observation;
- normalized in-memory repository model;
- canonical byte encoding/decoding;
- stable identity/reference resolution;
- repository validation/compilation;
- filesystem publication.

Pin all existing canonical formats with byte fixtures before moving code.
Agent repository bytes, `OrderPreserved` behavior, front matter, hashes,
compiled artifacts, and stable paths must be identical before and after.
Maintain a machine-readable matrix from every canonicalizing call-site domain
to its profile and fixture. `make canonical-contract-check` fails on a missing
call-site classification as well as a byte/digest difference.

`Snapshot.php` keeps a compatibility facade while read construction moves into
focused readers/projectors. Mutation helpers consumed by Thread 5 remain
unchanged or move only through a foundation-owned interface amendment. This
thread does not silently redefine Snapshot as a product assessment.

`Deletion` owns repository tombstone parsing/capture only; mutation execution
stays behind Thread 5's `DeleteExecutor` and guard services. Pure code
descriptor/schema compatibility remains separate from target staging,
materialization, lifecycle, and finalization, which stay in Thread 5.

`CompiledArtifact`, `RepositoryCompiler`, and other pure repository callers use
the Thread 3 code-contract leaf directly, never Thread 5's target-mutation
facade. Capture-time code baseline/version effects cross a Thread 3-owned port;
Thread 5 supplies the concrete adapter at the agent composition root.

Pure scoped-option projection is also a Thread 3 scope/read-model leaf. Capture
uses it directly and Thread 5 may consume it for mutation; Capture code never
imports `ScopedApply`. Together with the cron-suppression port, the boundary
check forbids every direct Capture-prefix dependency on Thread 5 mutation,
promotion, canary, or lifecycle implementations.

### 6. Init, observation, and pending decomposition

Continue the existing thin-facade work by isolating:

- site/code/storage inventory;
- read-only versus mutating probes;
- proposal construction;
- confirmation and ownership publication;
- attempt journaling and recovery;
- pending/classification projection;
- human/JSON presentation.

Every probe declares whether loading WordPress/plugins or executing it can
write caches, logs, cron, queues, or external effects. A method is not labeled
read-only merely because Duo issues no direct SQL write.

The adapter-observation collector owns Journal/Pending orchestration and all
`$wpdb` access. It passes a closed inert observation document to Thread 4's
catalog projector; the capability module never reaches back into WordPress or
Capture infrastructure.

Foundation has already resolved the known safety candidates into the frozen
baseline. Portable Capture and confirmed Init refuse with
`portable_capture_safety_unqualified` before repository, scope-file, lock,
ledger, identity, filesystem, or database access; Apply/Delete refuse with
`qualification_harness_required` before compilation, leases, canaries,
providers, or target mutation. Journal suspension prevents optional shutdown
writes on quarantined command paths. Read-only proposal/plan/explain/pending/
observation paths remain available. Preserve these refusal bytes and side-effect
boundaries. If characterization finds another unobserved sensitive surface or
unauthorized production mutation, stop the path; do not update a golden fixture
to bless it.

### 7. Composition and loading handoff

New files follow Thread 1's loading convention. Thread 3 provides a declarative
command/module map; it does not add manual requires to the bootstrap. The agent
must continue working both from the source tree and from the built artifact
during migration.

## Interfaces exported

- Agent command registry/handler contract.
- Current command result/presenter contract.
- WordPress read and filesystem ports used by agent/capture code.
- Capture facade and internal stage interfaces.
- Immutable repository/read-model facade with current canonical byte profiles.
- Pure code descriptor/config/state compatibility contract.
- Agent module map consumed by the generated loader.

## Constraints

- No new `wp duo` command or public command schema.
- No repository-format, canonical-byte, hash, identity, or path change.
- No change to the post-foundation classification, sensitivity, readiness, or
  certification baseline except through the stop-the-line process.
- No weakening of consistent-snapshot, publication-lock, atomic-tree, ledger,
  commit-marker, lint, or recovery behavior.
- No plugin/theme modification or activation for observation convenience.
- No product-level “read-only Assess” claim for an unproven booted probe.
- No host CLI source dependency introduced.
- No mass rewrite of `$wpdb` access; use adapters and migrate by stage.

## Required verification

At minimum, run catalog-selected suites for:

- Init planning, confirmation, interruption, and rollback;
- full and scoped Capture;
- publication atomicity and crash recovery;
- capture concurrency and consistent snapshots;
- secrets, PII known-gap/blocking fixtures, lint, and pending/classification;
- canonical repository/compiler/reference/identity behavior;
- options, posts, terms, tables, media, menus, widgets, and user metadata;
- agent command human/JSON/refusal output;
- source and built-artifact agent loading;
- the complete offline corpus before integration.

Use `make test-component COMPONENT=wordpress-agent` for the offline component
gate. Thread 3 owns the wordpress-agent catalog/component-profile fragment at
`sandbox/catalog/fragments/wordpress-agent/`; Thread 1 validates and aggregates
it. The component profile must be nonempty before the P0 production-edit lock
is released. Capture concurrency and
consistent-snapshot suites are cataloged as authorized live integration only
when the runner verifies exact source SHA and all provisioning, target-role,
data, credential, egress/effect, sandbox/no-effect, and enforced
non-authorizing/non-adoptable bindings against the separately issued authority
record. Unique resource IDs, cleanup-on-failure, and serialization/resource
locks remain mandatory. “Disposable” alone is not evidence of containment.
Missing, stale, or mismatched proof refuses frozen-candidate or
evidence-producing execution, and live suites never enter an unclassified
offline default.

`make thread-3-gate` is the authoritative Thread 3 acceptance target. It may
delegate to the focused component profile but must retain the structured result
at `artifacts/test-results/thread-3/result.json`.

Changes touching broad evidence inputs run the impact-selected conformance and
certification suites during the final cutover.

The foundation branch is intentionally non-releasable while source-bound v1
subjects are stale: do not regenerate or sign durable evidence on this thread.
Run component/profile and impact-selected suites; final release-gate and
evidence recertification use one frozen integrated candidate and the retained
exact runtime, declaration, test, harness, provider, environment, and receipt
inputs.

## Done means

- An architecture check permits `Cli.php` only WP-CLI registration,
  composition, top-level refusal conversion, and presenter handoff; it has no
  application workflow body or direct mutation dependency.
- Capture is a readable coordinator over explicit, independently tested stages.
- WordPress/global access is confined to named adapters for the refactored
  paths.
- Repository projection and filesystem publication are separate concerns.
- Canonical and compiled bytes are fixture-identical.
- Init/observation/pending code clearly declares read and mutation boundaries.
- New agent modules load through the generated artifact without manual
  bootstrap additions.

## Explicitly deferred

- Public `duo assess` or `wp duo assess`.
- New assessment coverage/readiness vocabulary.
- PII/product-policy expansion beyond the foundation safety disposition.
- New mutation authorization machinery beyond the foundation quarantine.
- Site application contracts and generated site projections.
- Repo-format v3.
- Product qualification, semantic oracles, or effect observation.
