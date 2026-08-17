# cli: Contract

**Purpose.** RESERVED (round 3): the per-site application contract as one object — manifests, site policy, evidence pins, bindings and capability report composed and addressable — plus `ProjectionVocabulary`, the single implementation of the spec-word projection (state class, handling, readiness, certification provenance, effect containment, effect recovery semantics) that Assess, Release and Rehearse all read.

**Directory** `cli/src/Contract/` &middot; **layer** `policy` &middot; **files** 0 &middot; **status** reserved

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): _none yet_. Planned: `ApplicationContract`, `ContractStore`, `ContractProposal`, `ContractProjection`, `ProjectionVocabulary` (see [round-3 MUP §4.2](../proposals/round-3-minimum-usable-platform.md)).

**May depend on:** `Contract`, `Plan`, `agent:Adapter`, `agent:Kernel`, `agent:Policy`.

**Must not depend on.** Transport, Environment and every engine module. The contract is a document, not a session. That is also why `ProjectionVocabulary` lives here rather than in Assess: policy is the lowest layer all three round-3 engine modules can read, so the projection is implemented once without a single intra-layer edge.

**Known debts.**

- Empty in this round. Its parts exist and disagree: manifests, `site.duo.json`, `evidence.json`/`registry.json`, `.duo-envs.json` and `CapabilityRegistry::report()`.

**Sub-namespace plan.** Target `Duo\Orchestrator\Contract\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
