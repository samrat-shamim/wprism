# cli: Rehearse

**Purpose.** RESERVED (round 3): rehearsal mechanism — the plan preview of what a release would touch, and the containment disclosure. The verb boundary (`RehearseCommand`) lives in `cli/src/Command/` and drives Environment's materialize/reap there.

**Directory** `cli/src/Rehearse/` &middot; **layer** `engine` &middot; **files** 0 &middot; **status** reserved

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): _none yet_. Planned: `RehearsalPlanPreview`, `RehearsalDisclosure` (see [round-3 MUP §4.4](../proposals/round-3-minimum-usable-platform.md)).

**May depend on:** `Contract`, `Plan`, `Rehearse`, `Transport`, `agent:Kernel`.

**Ratified exceptions.** None, and none designed. `EnvironmentLifecycle` and the assess projection are composed at the surface and passed in.

**Must not depend on.** Recovery and Release. A rehearsal must not be able to promote or roll back production.

**Known debts.**

- Empty in this round.

**Sub-namespace plan.** Target `Duo\Orchestrator\Rehearse\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
