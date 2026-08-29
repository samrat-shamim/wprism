# agent: Code

**Purpose.** Ownership, staging, compatibility and materialization of code artifacts (themes, plugins, mu-plugins) as repository state.

**Directory** `agent/src/Code/` &middot; **layer** `repository` &middot; **files** 7 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `Code`, `CodeStateContract`, `CodeCompatibility`, `CodeDescriptorCompiler`.

**May depend on:** `Code`, `Kernel`.

**Ratified exceptions** (same-layer or upward edges that exist today; ratchet — may shrink, never grow):

- `Promotion` (upward, 1 edge)
  `Code.php -> PromotionLock.php`
- `Repository` (intra-layer, 4 edges)
  `Code.php -> CompiledArtifact.php`; `Code.php -> Ledger.php`; `CodeStageTransaction.php -> Ledger.php`; `CodeStateContract.php -> CompiledArtifact.php`

**Must not depend on.** Engine modules and Adapter. Code is a repository partition, not a deploy step.

**Known debts.**

- `Code.php -> PromotionLock.php` is the only repository->engine edge here and the reason Code is inside the SCC.
- `CodeConfigGrammar.php` is classified policy and lives in the Policy module while requiring Code — a ratified inversion.

**Sub-namespace plan.** Target `WPrism\Code\`. Not in this round: the move keeps `namespace WPrism;` flat so that manifest interpreters/providers can keep naming `\WPrism\Policy`, `\WPrism\ProviderSdk`, `\WPrism\Providers` and `\WPrism\Canon` by FQCN — those hook files are `hash_file`'d into every adapter's identity row (`ArtifactPolicyIdentity::manifest_rows()`), so renaming the namespace moves each `adapter_digest` and forces a recompile plus a reviewed re-pin on every deployed site. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a hook-file change.
