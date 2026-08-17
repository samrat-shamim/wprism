# cli: Plan

**Purpose.** The plan wire contract and its operator/JSON renderings, with no I/O of its own.

**Directory** `cli/src/Plan/` &middot; **layer** `kernel` &middot; **files** 3 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `PlanContract`, `PlanSummary`, `PlanView`.

**May depend on:** `Plan`.

**Must not depend on.** Everything. Plan is pure: it parses and renders the plan wire format and performs no I/O.

**Known debts.**

- Duplicates agent-side plan vocabulary (agent Review also has PlanView/PlanExplanation/PlanCategorySummary). One of the two should become the wire contract and the other its renderer.

**Sub-namespace plan.** Target `Duo\Orchestrator\Plan\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
