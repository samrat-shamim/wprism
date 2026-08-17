# cli: Assess

**Purpose.** RESERVED (round 3): the assess mechanism — stack inventory, surface catalog, gap actions and the assess report — over agent Assess projections and driver capabilities. The verb boundary (`AssessCommand`) lives in `cli/src/Command/`; `Doctor`, the environment registry and the adapter catalog are called there and passed in as data.

**Directory** `cli/src/Assess/` &middot; **layer** `engine` &middot; **files** 0 &middot; **status** reserved

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): _none yet_. Planned: `StackInventory`, `SurfaceCatalog`, `GapActions`, `AssessReport`, `AssessRenderer` (see [round-3 MUP §4.1](../proposals/round-3-minimum-usable-platform.md)).

**May depend on:** `Assess`, `Contract`, `Plan`, `Transport`, `agent:Adapter`, `agent:Assess`, `agent:Kernel`.

**Ratified exceptions.** None, and none designed. `Doctor`/`BootstrapEligibility` (Onboarding), the environment registry (Environment) and `AdapterCatalog` (Adapter) are all reached from the surface, not from here — that is what keeps this module free of intra-layer and upward edges.

**Must not depend on.** Anything that writes to a target.

**Known debts.**

- Empty in this round.

**Sub-namespace plan.** Target `Duo\Orchestrator\Assess\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
