# agent: Adapter

**Purpose.** The plugin-facing boundary: manifest sources, adapter registry, observation, site adapter certification and the provider SDK the engine calls through.

**Directory** `agent/src/Adapter/` &middot; **layer** `adapter` &middot; **files** 10 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `AdapterSources`, `Providers`, `ProviderActionBatchBuilder`, `ActionProviderGrammar`, `AdapterContractGrammar`, `AdapterObservation`, `AdapterRegistry`.

**May depend on:** `Adapter`, `Kernel`, `Policy`, `Promotion`, `Rebuild`, `Repository`, `Review`.

**Must not depend on.** Command, and any engine module not already listed in depends_on. Per the engine-adapter ruling, no module below Adapter may dispatch on a plugin name.

**Known debts.**

- Zero exceptions outbound, but 21 of the repo's 38 ratified upward edges point *up* into it from Policy, Apply, Rebuild, Scope and Init — the inversion the engine-adapter proposal exists to fix.
- Providers/ProviderSdk are classified adapter in tools/layers.json and stay here; the engine reaches them only through ProviderActionBatchBuilder.
- `AdapterRegistry::report()` (`AdapterRegistry.php:319`) is the module's whole machine-readable capability view, `duo-capability-report/v1`, projected from the reviewed dispositions `agent:Policy` loaded plus per-adapter provenance. `TargetProbe::probe_target()` supplies the live-target facts it takes; with no target the report is the source/authorship gate only. There is no generated registry document behind it any more — the projection is computed on each call.

**Sub-namespace plan.** Target `Duo\Adapter\`. Not in this round: the move keeps `namespace Duo;` flat so that manifest interpreters/providers can keep naming `\Duo\Policy`, `\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN — those hook files are `hash_file`'d into every adapter's identity row (`ArtifactPolicyIdentity::manifest_rows()`), so renaming the namespace moves each `adapter_digest` and forces a recompile plus a reviewed re-pin on every deployed site. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a hook-file change.
