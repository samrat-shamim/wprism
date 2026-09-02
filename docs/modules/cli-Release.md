# cli: Release

**Purpose.** The durable inert source-stage receipt and exact prepare subject consumed by externally authorized execution, followed by the frozen authorization plan, its renderer, the monotonic operation-status projection, the closed next-action set and the journey oracle. The verb boundaries (`StageSourceCommand`, `ReleaseCommand`, `VerifyCommand`, `RecoverCommand`) live in `cli/src/Command/`, which composes them with Authority, Environment, Recovery and the existing `promote`/`deploy` handlers.

**Directory** `cli/src/Release/` &middot; **layer** `engine` &middot; **files** 8 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `AuthorizationPlan`, `AuthorizationPlanRenderer`, `ReleaseOutcome`, `ReleaseOperationStatus`, `NextAction`, `JourneyOracle`, `SourceStageReceipt`, `ReleasePrepare`.

**May depend on:** `Contract`, `Plan`, `Release`, `Transport`, `agent:Kernel`, `agent:Policy`.

**Ratified exceptions.** None, and none designed. Recovery-profile selection, the checkpoint catalog and the recovery claim live in `cli/src/Recovery/`; the claim reaches `AuthorizationPlan` as a canonical array, the way every other document crosses a module line in `cli/src`. Release therefore never references a Recovery, Environment, Assess or Rehearse class.

**Must not depend on.** agent modules other than Kernel/Policy; and never `Command` — the surface composes this module, not the reverse.

**What landed** (this module was reserved when the map was ratified; round 3's T3 filled it):

- `AuthorizationPlan` — `wprism-authorization-plan/v1`, frozen before any production-visible mutation. `build()` is deliberately pure: no clock (`frozen_at` is an argument) and no I/O, so the same inputs freeze the same plan (`cli/src/Release/AuthorizationPlan.php:15-24`).
- `AuthorizationPlanRenderer` — the human page, whose six sections are the product spec's six *Release and verify* bullets **in that order, because the order is the argument** (`cli/src/Release/AuthorizationPlanRenderer.php:12-21`).
- `ReleaseOutcome` — `wprism-release-outcome/v1`, holding the invariant that a pre-freeze refusal carries an assessment **gap action** while a post-freeze failure carries a **next action**; the two vocabularies never mix (`cli/src/Release/ReleaseOutcome.php:15-25`).
- `ReleaseOperationStatus` — `wprism-release-operation-status/v1`, a read-only four-step projection over the target's durable election, consumption and completion evidence. Its `sequence` is bounded to 0–3 rather than pretending WPrism has a general event stream.
- `NextAction` — the closed, total set `resume/reconcile/retry/recover/requalify/escalate` and the deterministic failure-class mapping into it (`cli/src/Release/NextAction.php:11-20`).
- `JourneyOracle` — `wprism-verify-report/v1`: convergence proves the bytes landed and is explicitly *not sufficient*, so a declared affected-journey oracle is required beside it (`cli/src/Release/JourneyOracle.php:13-22`).
- `SourceStageReceipt` — `wprism-source-stage-receipt/v1`, binding the stable target, base commit/tree, advertised source commit/tree and persistent inert Git ref/worktree to one operation lineage. The command boundary resolves split locked code into that stage and re-proves its digests without adding ignored bytes to the receipt. Target-side publication is atomic and fsync/readback proven; exact retry preserves its bytes.
- `ReleasePrepare` — `wprism-release-prepare/v1`, binding the unchanged authorization-plan/v1 semantic digest, the exact standalone plan bytes, authority-policy and capability-library digests, required grants, the stage receipt and every explicit request fact into one `subject_sha256`.

**Known debts.**

- The promotion state machine is still inside `cli/wprism` (~1,605 lines, `:1558`–`:3162`). Absorbing it was this module's stated reason to exist and has not happened; `ReleaseCommand` composes `promote` unchanged rather than replacing it.

**Sub-namespace plan.** Target `WPrism\Orchestrator\Release\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
