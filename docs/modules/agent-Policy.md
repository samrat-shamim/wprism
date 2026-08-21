# agent: Policy

**Purpose.** Loads, validates and pins the manifest and site policy that decides which WordPress state Duo owns, and answers every ownership question the engine asks.

**Directory** `agent/src/Policy/` &middot; **layer** `policy` &middot; **files** 19 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `Policy`, `ScopeContract`, `ManifestDispositions`, `ScopeClosure`, `ArtifactPolicyIdentity`, `ScopeDiscovery`, `PolicyRuleResolver`, `ScopeAdoption`.

**May depend on:** `Kernel`, `Policy`.

**Ratified exceptions** (same-layer or upward edges that exist today; ratchet — may shrink, never grow):

- `Adapter` (upward, 9 edges)
  `ArtifactPolicyIdentity.php -> AdapterSources.php`; `ManifestValidator.php -> ActionProviderGrammar.php`; `ManifestValidator.php -> AdapterContractGrammar.php`; `PinResolver.php -> AdapterSources.php`; `Policy.php -> ActionProviderGrammar.php`; `Policy.php -> AdapterRegistry.php`; …
- `Code` (upward, 1 edge)
  `CodeConfigGrammar.php -> Code.php`
- `Delete` (upward, 1 edge)
  `ScopeContract.php -> Deletion.php`
- `Grammar` (intra-layer, 30 edges)
  `DeletionCapabilityResolver.php -> OptionNameReferenceResolver.php`; `ManifestValidator.php -> AttributeGrammar.php`; `ManifestValidator.php -> FieldGrammar.php`; `ManifestValidator.php -> OptionGrammar.php`; `ManifestValidator.php -> OptionReferenceGrammar.php`; `ManifestValidator.php -> PostTypeGrammar.php`; …
- `Repository` (upward, 7 edges)
  `PinResolver.php -> RepositoryCompiler.php`; `ScopeClosure.php -> CompiledArtifact.php`; `ScopeClosure.php -> ReferenceGraph.php`; `ScopeClosure.php -> SidebarState.php`; `ScopeContract.php -> CanonicalSurfaces.php`; `ScopeContract.php -> CompiledArtifact.php`; …

**Must not depend on.** Engine modules (Capture/Apply/Scope/Promotion/Init/Review/Rebuild/Publication/Delete) and Command. The Adapter, Repository, Code and Delete edges it has today are ratified debts.

**Known debts.**

- Policy god object: fan-in 96 across agent/src, 34 outbound references, 30 of them into Grammar. Splitting the load path from the query path is the prerequisite for breaking the Policy<->Grammar cycle.
- 18 of the 38 ratified upward edges in the repo start in this module (9 into Adapter alone).

**Sub-namespace plan.** Target `Duo\Policy\`. Not in this round: the move keeps `namespace Duo;` flat so that manifest interpreters/providers can keep naming `\Duo\Policy`, `\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN — those hook files are `hash_file`'d into every adapter's identity row (`ArtifactPolicyIdentity::manifest_rows()`), so renaming the namespace moves each `adapter_digest` and forces a recompile plus a reviewed re-pin on every deployed site. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a hook-file change.
