# cli: Release

**Purpose.** The frozen authorization plan, its renderer, the closed next-action set and the journey oracle. The verb boundaries (`ReleaseCommand`, `VerifyCommand`, `RecoverCommand`) live in `cli/src/Command/`, which composes them with Environment, Recovery and the existing `promote`/`deploy` handlers.

**Directory** `cli/src/Release/` &middot; **layer** `engine` &middot; **files** 5 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `AuthorizationPlan`, `AuthorizationPlanRenderer`, `ReleaseOutcome`, `NextAction`, `JourneyOracle`.

**May depend on:** `Contract`, `Plan`, `Release`, `Transport`, `agent:Kernel`, `agent:Policy`.

**Ratified exceptions.** None, and none designed. Recovery-profile selection, the checkpoint catalog and the recovery claim live in `cli/src/Recovery/`; the claim reaches `AuthorizationPlan` as a canonical array, the way every other document crosses a module line in `cli/src`. Release therefore never references a Recovery, Environment, Assess or Rehearse class.

**Must not depend on.** agent modules other than Kernel/Policy; and never `Command` — the surface composes this module, not the reverse.

**What landed** (this module was reserved when the map was ratified; round 3's T3 filled it):

- `AuthorizationPlan` — `duo-authorization-plan/v1`, frozen before any production-visible mutation. `build()` is deliberately pure: no clock (`frozen_at` is an argument) and no I/O, so the same inputs freeze the same plan (`cli/src/Release/AuthorizationPlan.php:15-24`).
- `AuthorizationPlanRenderer` — the human page, whose six sections are the product spec's six *Release and verify* bullets **in that order, because the order is the argument** (`cli/src/Release/AuthorizationPlanRenderer.php:12-21`).
- `ReleaseOutcome` — `duo-release-outcome/v1`, holding the invariant that a pre-freeze refusal carries an assessment **gap action** while a post-freeze failure carries a **next action**; the two vocabularies never mix (`cli/src/Release/ReleaseOutcome.php:15-25`).
- `NextAction` — the closed, total set `resume/reconcile/retry/recover/requalify/escalate` and the deterministic failure-class mapping into it (`cli/src/Release/NextAction.php:11-20`).
- `JourneyOracle` — `duo-verify-report/v1`: convergence proves the bytes landed and is explicitly *not sufficient*, so a declared affected-journey oracle is required beside it (`cli/src/Release/JourneyOracle.php:13-22`).

**Known debts.**

- The promotion state machine is still inside `cli/duo` (~1,605 lines, `:1558`–`:3162`). Absorbing it was this module's stated reason to exist and has not happened; `ReleaseCommand` composes `promote` unchanged rather than replacing it.

**Sub-namespace plan.** Target `Duo\Orchestrator\Release\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
