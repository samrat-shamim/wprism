# agent: Repository

**Purpose.** The durable repository of owned state: compiled artifacts, identity registry, ledger, reference graph, snapshots and their validators.

**Directory** `agent/src/Repository/` &middot; **layer** `repository` &middot; **files** 24 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `Ledger`, `CompiledArtifact`, `Snapshot`, `RepositoryCompiler`, `SidebarState`, `CanonicalSurfaces`, `ReferenceGraph`, `Identity`, `StateTreeWalker`, `IdentityBackup`, `IdentityNotes`, `RepositoryAuthorization`.

**May depend on:** `Grammar`, `Kernel`, `Policy`, `Repository`.

**Ratified exceptions** (same-layer or upward edges that exist today; ratchet — may shrink, never grow):

- `Apply` (upward, 1 edge)
  `Snapshot.php -> TypedTableMaterializer.php`
- `Capture` (upward, 1 edge)
  `Snapshot.php -> TypedTableCapture.php`
- `Code` (intra-layer, 6 edges)
  `CompiledArtifact.php -> Code.php`; `CompiledArtifactReader.php -> CodeStateContract.php`; `RepositoryCompiler.php -> Code.php`; `RepositoryCompiler.php -> CodeCompatibility.php`; `RepositoryCompiler.php -> CodeDescriptorCompiler.php`; `RepositoryCompiler.php -> CodeStateContract.php`
- `Delete` (upward, 1 edge)
  `RepositoryDeletionParser.php -> Deletion.php`

**Must not depend on.** Engine modules and Adapter. Repository is storage; it must not know how state is captured, applied or promoted.

**Known debts.**

- `Snapshot.php` reaches into TypedTableCapture/TypedTableMaterializer — one file holding both storage and engine roles; it is the sole reason Repository sits in the engine cycle.
- Repository<->Code is a 2-cycle (6 down, 4 back).

**Sub-namespace plan.** Target `Duo\Repository\`. Not in this round: the move keeps `namespace Duo;` flat so that manifest interpreters/providers can keep naming `\Duo\Policy`, `\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN — those manifest bytes are digest-bound and renaming them is a certification round of its own. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a manifest-bytes change.
