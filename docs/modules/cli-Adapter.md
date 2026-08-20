# cli: Adapter

**Purpose.** Authoring-side adapter tooling: catalog, certification, draft, observation and manifest validation against the agent's policy.

**Directory** `cli/src/Adapter/` &middot; **layer** `adapter` &middot; **files** 5 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `AdapterCatalog`, `AdapterCertify`, `AdapterDraft`, `AdapterObservation`, `ManifestValidate`.

**May depend on:** `Adapter`, `Transport`, `agent:Adapter`, `agent:Delete`, `agent:Kernel`, `agent:Policy`, `agent:Rebuild`, `agent:Repository`.

**Must not depend on.** Command. It may read the agent's policy and adapter modules; it must not drive an environment lifecycle.

**Known debts.**

- Reaches six agent modules directly (Adapter, Policy, Kernel, Delete, Rebuild, Repository) — authoring tooling reading engine internals.

**Sub-namespace plan.** Target `Duo\Orchestrator\Adapter\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
