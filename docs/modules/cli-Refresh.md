# cli: Refresh

**Purpose.** The B/P/W refresh planner, field diff and local state materializer that reconciles git, production and working state.

**Directory** `cli/src/Refresh/` &middot; **layer** `repository` &middot; **files** 8 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `CloudCommittedOriginExport`, `CloudOriginExportClient`, `Refresh`, `RefreshFieldDiff`, `ProductionSnapshotSource`, `RefreshProductionSource`.

**May depend on:** `Refresh`, `Transport`, `agent:Code`, `agent:Kernel`, `agent:Policy`, `agent:Repository`, `agent:Scope`.

**Must not depend on.** Environment, Recovery, Onboarding, Adapter and Command. Refresh is a planner over snapshots.

**Known debts.**

- `RefreshPlan::loadCompiler()` requires 39 agent classes by path at runtime — the single largest cli->agent coupling in the repo and the biggest single edit in the move codemod.

**Sub-namespace plan.** Target `Duo\Orchestrator\Refresh\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
