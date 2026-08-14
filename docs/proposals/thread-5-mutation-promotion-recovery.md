# Thread 5 — Mutation, promotion, and recovery

*Status: proposed for owner approval.*

*Depends on: [Thread 0](thread-0-foundation.md).*

*Primary rule: expose the existing safety state machines without changing their authority or claims.*

## Goal

Modularize repository planning, code/state mutation, promotion coordination,
external-effect execution, and the standalone recovery runtime into explicit,
testable state machines and ports.

Duo's mutation/recovery kernel is the strongest part of the implementation.
This thread preserves it and makes its ordering, authority inputs, durable
state, retry behavior, and failure boundaries easier to reason about. It does
not yet implement the finalized product Release, Verify, Recover, rehearsal
containment, or actor authorization contracts.

## Why this thread exists

- Apply recently became a thin facade, but its service graph still relies on
  static/global collaborators and broad policy/provider arrays.
- Planning, scoped authority, deployment, lifecycle, apply, regeneration,
  convergence, promotion, and rollback are spread across agent, host, and
  recovery files with important ordering encoded by call sites.
- `Code.php`, `Providers.php`, `Snapshot.php`, `Deletion.php`, and plan
  rendering have historically combined decisions and effects.
- Recovery has strong signed protocol behavior, but `rollback-control.php` and
  several bundle/executor classes remain large composition and state-machine
  surfaces.
- Host plan presentation currently computes some readiness/authorization-like
  booleans instead of only presenting engine decisions.
- The finalized spec requires additional product authority and semantic
  verification later. Those must attach outside this kernel rather than be
  improvised inside existing `promote` flags.

## Owned surface

Thread 5 owns the standalone recovery runtime and host mutation modules:

```text
recovery/**
cli/src/CodeDeploy.php
cli/src/DeployCommand.php
cli/src/PlanContract.php
cli/src/PlanSummary.php
cli/src/PlanView.php
cli/src/PromoteCommand.php
cli/src/RollbackAuthority.php
cli/src/ScopedRollbackProfile.php
cli/src/VerifiedRollbackProfile.php
sandbox/bin/ssh-rollback-certification.php
docs/checkpoint-bundle.md
docs/code-release-runtime.md
docs/effect-bundle.md
docs/ssh-rollback-certification.md
docs/upload-bundle.md
docs/recovery-runtime.md
docs/guides/code-updates.md
```

Its initial `agent/src` allowlist is:

```text
Apply.php
ApplyFieldMaterializer.php
ApplyLedgerFinalizer.php
ApplyPlanBuilder.php
ApplyPlanEnvironment.php
ApplyPlanner.php
ApplyPreparationCoordinator.php
ApplyPreparationRequest.php
ApplyRebuildCoordinator.php
ApplyRequestCoordinator.php
ApplyServiceCallbacks.php
ApplyServices.php
ApplyWorkset.php
AttachmentMaterializer.php
AuthoredTransactionExecutor.php
AuthoredTransactionRequest.php
Canary.php
Code.php
CodeMaterializer.php
CodeOwnershipPruner.php
CodeStageTransaction.php
ConvergenceVerifier.php
DeleteExecutor.php
DeleteGuardEvaluator.php
DeleteGuardLockCoordinator.php
DeleteGuardReferenceScanner.php
DeleteGuardValueCodec.php
DeletionAuthority.php
DependencyRegenerator.php
Deploy.php
DeployPlanner.php
EntityAdopter.php
LifecycleExecutor.php
LifecycleJournal.php
LifecyclePlanner.php
MenuMaterializer.php
NativeActions.php
NativeRebuildExecutor.php
OptionsMaterializer.php
Orphans.php
PlanCategorySummary.php
PlanExplanation.php
PlanView.php
PostMaterializer.php
PreparedApply.php
ProcessFence.php
PromotionLease.php
PromotionLock.php
PromotionSessionJournal.php
ProviderActionBatchBuilder.php
RebuildActionDispatcher.php
RebuildActionNegotiator.php
RebuildRequest.php
RebuildSelection.php
RegenerationContext.php
RegenerationContextStore.php
RelationshipMaterializer.php
ScopedApply.php
ScopedApplyCoordinator.php
ScopedApplySession.php
ScopedApplyWorkProjector.php
ScopedApplyWorkflow.php
ScopedPromotionAuthority.php
ShortcodeAlternateRegistrar.php
StateHandoffVerifier.php
StateTransitionJournal.php
TaxonomyApplyContext.php
TermMaterializer.php
TypedTableMaterializer.php
UserMetaMaterializer.php
```

Thread 4 owns `Policy`, provider catalog/execution facade, grammar, capability,
and evidence. Thread 3 owns repository/capture/read-model source. Thread 2
alone wires Thread 5's host command modules into `cli/duo`; Thread 3 alone wires
agent handlers into `agent/src/Cli.php`.

The `NativeActions.php` allowlist entry covers only the compatibility facade and
WordPress executor remaining after Thread 0 extracts Thread 4's inert native
action catalog/grammar. New declaration vocabulary does not belong to Thread 5.

The Thread 0 ledger assigns future `Mutation/`, `Promotion/`, and `Lifecycle/`
agent prefixes and the recovery implementation prefix exclusively to this
thread. The allowlist above covers the current flat tree only.

## Target internal shape

```text
Current plan request
  -> immutable preparation context
  -> plan/precondition decision
  -> code lifecycle coordinator
  -> authored-state transaction coordinator
  -> rebuild/effect coordinator
  -> convergence verifier
  -> ledger/receipt finalizer

Host promotion coordinator
  -> target lease/fence
  -> checkpoint/recovery client
  -> code deploy
  -> state apply
  -> current verification
  -> durable receipt/resume result

Recovery controller
  -> protocol validator
  -> state transition
  -> resource strategy
  -> verification
  -> signed receipt
```

## Deliverables

### 1. Freeze mutation and recovery contracts

After Thread 0's safety disposition, pin:

- plan/precondition hashes and bucket ordering;
- apply/deploy requests, results, refusals, and current summaries;
- code descriptor/stage/finalization bytes;
- scoped authority/session/work projection;
- locks, leases, fences, lifecycle and transition journals;
- regeneration/provider-effect requests and receipts;
- promotion resume/reconcile behavior;
- the versioned recovery-decision session/precondition record, exact drift
  comparison, and recovery-only treatment of legacy unbound sessions;
- checkpoint, code, upload, effect, and rollback protocol bytes;
- signature verification and failure output.

Add transition-table tests for success, every resumable interruption point,
stale inputs, duplicate/reordered messages, corrupt journals, provider failure,
and crash recovery. Existing fault-injection hooks are reused.

Archive current v1 request/receipt/signature fixtures with fixed test keys and
verify them using the refactored runtime. A new-runtime round trip alone does
not prove old receipts remain verifiable.

### 2. Explicit apply composition

Keep `Apply` as the stable facade and simplify `ApplyServices` into the
composition root for one request. Separate:

- request validation;
- repository/policy preparation;
- plan and workset construction;
- scoped/full authority projection;
- transaction execution;
- deletion and reference guards;
- derived rebuild/provider action execution;
- convergence verification;
- ledger and receipt publication.

Application services consume Thread 4's immutable policy/provider facades and
Thread 3's repository/read-model facade. They do not access the internals of
those modules.

Thread 5 consumes Thread 3's pure scoped-option projection. Capture-facing cron
suppression remains behind Thread 3's WordPress port; Thread 5 does not expose
`ScopedApply`, `Canary`, or lifecycle implementations to Capture.

Introduce database, clock, filesystem, and process ports only where they make
an extracted stage independently testable. Existing `Db`/`Ledger` static APIs
remain compatibility adapters until the foundation grants one writer; do not
run a repository-wide injection sweep.

### 3. Plan and precondition boundary

Separate plan calculation from rendering and mutation. The engine produces one
current immutable decision/result containing all facts already used today.
Host and agent presenters display it without recomputing whether apply or
promotion may proceed.

Preserve the current plan-precondition hash. Before this refactor freezes
behavior, Thread 0 adds a narrowly versioned safety record that durably binds
the selected current recovery provider/profile and relevant configuration
identity to the promotion session/precondition. Reselect and compare that exact
decision at the last pre-mutation boundary and on forward resume; mismatch
refuses. Legacy unbound records remain readable for recovery/reconciliation but
cannot silently authorize a new forward mutation. This does not add actor
identity or claim a spec-compliant ReleasePlan. Binding the full recovery/actor
contract into the future product plan belongs to the enhancement round.

### 4. Code lifecycle decomposition

Split current code behavior behind stable facades:

- consumption of Thread 3's immutable descriptor/schema/compatibility result;
- target compatibility observation;
- staging and ownership validation;
- lifecycle planning;
- filesystem materialization/pruning;
- activation/retirement execution;
- state-handoff verification;
- completion/rollback publication.

Preserve exact descriptor bytes, ownership rules, deploy-before-apply ordering,
outgoing-code lifecycle behavior, revision publication, and crash recovery.

Thread 3 owns the pure descriptor/compiler and code/state contract; it does not
call target materializers. `Code.php` remains a compatibility facade over the
two sides until all callers are migrated.

### 5. Mutation and effect execution boundary

Separate current action vocabulary/selection from execution:

- Thread 4 owns declaration grammar and provider contract.
- Thread 5 owns batches selected for a concrete mutation, execution ordering,
  heartbeat/lease renewal, and batch-level resume/reconciliation over Thread
  4-validated provider results. Per-provider receipt storage and reconciliation
  remain inside Thread 4's facade.

Keep current external-effect modes and receipt schemas. Do not mechanically
map them to the finalized containment and recovery axes: `restorable` is not
automatically proof of provider-state restoration, and `reversible` is not
automatically a proven compensating action.

Unknown or malformed provider/effect results retain current fail-closed
behavior. A provider declaration is not an attestation.

### 6. Promotion coordinator

Extract host promotion from procedural/global code into a coordinator over:

- frozen current inputs;
- target compatibility and drift preflight;
- lease/fence acquisition and renewal;
- checkpoint/recovery-profile selection using current semantics;
- code deployment;
- state application;
- current convergence/canary verification;
- receipt publication;
- resume, reconcile, and rollback orchestration.

Thread 5 exports a host command module. Thread 2 owns top-level registration,
common argument handling, and final output plumbing.

Keep all post-safety-gate flags, warnings, fallback behavior, ordering, and exit
codes. Do not remove or bypass the last-moment recovery-profile comparison.
Do not add actor identity, org policy, `none` acknowledgment, a product
authorization artifact, semantic journey verification, or a public `recover`
verb in this round.

### 7. Recovery runtime decomposition

Make `rollback-control.php` a standalone composition/dispatch-only root over:

- protocol decoder/validator;
- lock and durable state repository;
- checkpoint, code, upload, and effect resource strategies;
- recovery executor/state machine;
- provider client;
- verification and receipt signer.

Each state transition must state:

- allowed prior states;
- immutable identity/preconditions;
- durable write before/after the side effect;
- idempotent retry behavior;
- verification requirement;
- terminal receipt or public failure.

Preserve all current protocol formats and signature bytes. Old receipts remain
verifiable. Recovery stays independent of WordPress, agent, and host
implementation classes.

Maintain a machine-readable transition/fault matrix. Every durable transition
must reference before/after interruption, retry/idempotency, corruption, and
terminal-verification tests or an explicit reviewed deferral; a check fails on
an unclassified transition. A deferral names its owner, exact transition,
rationale, risk, compensating gate, and expiry. Authority, signature, writer
exclusion, and durable side-effect transitions permit no deferral at the final
Done gate.

### 8. Failure and idempotency harness

Expand focused tests around existing behavior rather than source layout:

- failure before and after every durable transition;
- repeated request/retry/resume;
- stale plan, lease, fence, generation, or claimant epoch;
- lost provider response and receipt reconciliation;
- partial code/filesystem/database/effect completion;
- corrupt/tampered state and signatures;
- concurrent promotion/recovery contenders;
- exact old-or-new visibility at publication boundaries.

Test helpers use Thread 1's resource catalog and unique temp roots. They must
not depend on source-text assertions or unsafe default parallelism.

## Interfaces exported

- Current plan/precondition result facade.
- Apply and deploy application facades.
- Target code lifecycle facade consuming Thread 3's descriptor contract.
- Host promotion command/coordinator module.
- Current promotion result and receipt projection.
- Standalone recovery protocol/controller facade plus a byte-compatible
  `RecoveryProtocolCodec/Client` consumed by the host without importing
  recovery implementation classes.
- Mutation/effect execution boundary consuming Thread 4's provider facade.

## Constraints

- No mutation-authority expansion or new force hatch.
- After the foundation's versioned recovery-decision safety record is frozen,
  no further plan, descriptor, journal, receipt, signature, or recovery byte
  change.
- No reordering of deploy, checkpoint, apply, rebuild, convergence, or receipt
  publication.
- No weakening of locks, leases, fencing, stale-code refusal, deletion guards,
  reverse-reference checks, provider validation, or recovery verification.
- No claim that database import alone is verified rollback.
- No new product Release/Verify/Recover terminology for current engine facades.
- No dependency from recovery to WordPress, agent, or CLI implementation.
- No presenter deciding authority or success.

## Required verification

At minimum, run catalog-selected suites for:

- plan/apply/deploy and scoped equivalents;
- code staging/materialization/lifecycle/state handoff;
- deletion and reference guards;
- regeneration/provider action/effect behavior;
- promotion leases, locks, fencing, canaries, resume, and reconciliation;
- recovery provider/profile drift before initial mutation and forward resume,
  plus safe recovery/reconciliation of legacy unbound sessions;
- recovery protocol, executor, checkpoint, code, upload, and effect bundles;
- rollback authority and SSH rollback certification;
- crash/fault/concurrency matrices;
- host and agent presentation integration;
- the complete offline corpus before integration.

Per-slice development runs focused recovery regressions and a
`make test-component COMPONENT=mutation-recovery` gate. Evidence-producing
`certify-ssh-rollback` and subject certification run only against Thread 0's
frozen candidate. Thread 5 owns the mutation-recovery catalog/component-profile
fragment; Thread 1 validates and aggregates it. Recovery/adoption/SSH
verification must execute the built recovery artifact outside the checkout and
have the runner verify provisioning/target role, approved synthetic or
minimized data, non-production credential realm, default-deny egress/effect
policy, sandbox/no-effect proof, and enforced non-authorizing/non-adoptable
output bindings against the separately issued authority record. Unique resource
IDs, cleanup-on-failure, and concurrency/resource locks remain in the fragment.
A `disposable` label alone cannot authorize execution; missing, stale, or
mismatched proof refuses frozen-candidate or evidence-producing runs.

Thread 5 contributes recovery/rollback suites to the post-evidence
`release-validation` profile. They consume the retained exact
release-family/target-set/composite bytes, perform no rebuild/signing/Git write,
and must emit a passing receipt before merge or release.

`make recovery-transition-check` validates the transition/fault matrix and
fails on an unclassified, expired, or final-gate-forbidden deferral.

The final evidence-impact report determines live/conformance/recertification
requirements. Every production-byte change occurs before the candidate commit
is frozen.

## Done means

- Apply, code lifecycle, promotion, and recovery are explicit coordinators over
  named stages and side-effect ports.
- Current plan/mutation-precondition decisions are produced once and only
  presented elsewhere.
- An architecture check permits Recovery's root file only protocol bootstrap,
  dependency composition, dispatch, and terminal result emission; resource
  strategy and state-transition bodies live behind the exported controller.
- Current state transitions, hashes, receipts, and signatures are fixture- and
  fault-test compatible.
- No authority, signature, writer-exclusion, or durable-side-effect transition
  has a deferred test obligation.
- Agent/host/recovery components interact only through their exported current
  contracts.
- No product authority, readiness, containment, or recovery claim was upgraded.

## Explicitly deferred

- Frozen product ReleasePlan and actor/org-policy authorization.
- New product recovery-profile enum or changed plan-hash format beyond the
  foundation's additive current-decision digest and last-moment drift refusal.
- Affected-journey semantic Verify.
- Public `duo recover`.
- Rehearsal containment, data minimization, credential stripping, and egress
  contracts.
- Split target effect-containment/recovery semantics.
- Site qualification, attestation, and bounded invalidation.
