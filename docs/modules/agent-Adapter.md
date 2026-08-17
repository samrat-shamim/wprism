# agent: Adapter

**Purpose.** The plugin-facing boundary: manifest sources, adapter registry, observation, certification bundles, capability registry and the provider SDK the engine calls through.

**Directory** `agent/src/Adapter/` &middot; **layer** `adapter` &middot; **files** 11 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `AdapterSources`, `Providers`, `ProviderActionBatchBuilder`, `CapabilityRegistry`, `ActionProviderGrammar`, `AdapterContractGrammar`, `AdapterObservation`, `AdapterRegistry`.

**May depend on:** `Adapter`, `Kernel`, `Policy`, `Promotion`, `Rebuild`, `Repository`, `Review`.

**Must not depend on.** Command, and any engine module not already listed in depends_on. Per the engine-adapter ruling, no module below Adapter may dispatch on a plugin name.

**Known debts.**

- Zero exceptions outbound, but 40 ratified edges point *up* into it from Policy, Apply, Rebuild, Scope and Init — the inversion the engine-adapter proposal exists to fix.
- Providers/ProviderSdk are classified adapter in tools/layers.json and stay here; the engine reaches them only through ProviderActionBatchBuilder.

**Sub-namespace plan.** Target `Duo\Adapter\`. Not in this round: the move keeps `namespace Duo;` flat so that manifest interpreters/providers can keep naming `\Duo\Policy`, `\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN — those manifest bytes are digest-bound and renaming them is a certification round of its own. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a manifest-bytes change.
