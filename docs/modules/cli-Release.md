# cli: Release

**Purpose.** RESERVED (round 3): the frozen authorization plan, its renderer, the closed next-action set and the journey oracle. The verb boundaries (`ReleaseCommand`, `VerifyCommand`, `RecoverCommand`) live in `cli/src/Command/`, which composes them with Environment, Recovery and the existing `promote`/`deploy` handlers.

**Directory** `cli/src/Release/` &middot; **layer** `engine` &middot; **files** 0 &middot; **status** reserved

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): _none yet_. Planned: `AuthorizationPlan`, `AuthorizationPlanRenderer`, `ReleaseOutcome`, `NextAction`, `JourneyOracle` (see [round-3 MUP §4.3](../proposals/round-3-minimum-usable-platform.md)).

**May depend on:** `Contract`, `Plan`, `Release`, `Transport`, `agent:Kernel`, `agent:Policy`.

**Ratified exceptions.** None, and none designed. Recovery-profile selection, the checkpoint catalog and the recovery claim live in `cli/src/Recovery/`; the claim reaches `AuthorizationPlan` as a canonical array, the way every other document crosses a module line in `cli/src`. Release therefore never references a Recovery, Environment, Assess or Rehearse class.

**Must not depend on.** agent modules other than Kernel/Policy; and never `Command` — the surface composes this module, not the reverse.

**Known debts.**

- Empty in this round. It will absorb the promotion state machine now inside `cli/duo` and give recovery an operator verb.

**Sub-namespace plan.** Target `Duo\Orchestrator\Release\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
