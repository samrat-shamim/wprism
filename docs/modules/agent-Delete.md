# agent: Delete

**Purpose.** Deletion authority, deletion guards, orphan repair and the executors that remove owned entities and record tombstones.

**Directory** `agent/src/Delete/` &middot; **layer** `engine` &middot; **files** 10 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `Deletion`, `DeleteExecutor`, `DeleteGuardLockCoordinator`, `DeleteGuardReferenceScanner`, `DeletionAuthority`, `DeleteGuardEvaluator`, `ExecutableOwnerBoundary`, `Orphans`.

**May depend on:** `Delete`, `Kernel`, `Policy`, `Repository`.

**Ratified exceptions** (same-layer or upward edges that exist today; ratchet — may shrink, never grow):

- `Apply` (intra-layer, 2 edges)
  `DeleteExecutor.php -> MenuMaterializer.php`; `DeleteExecutor.php -> RelationshipMaterializer.php`

**Must not depend on.** Adapter and Command. The two Apply materializer edges are ratified debts.

**Known debts.**

- `DeleteExecutor.php` requires MenuMaterializer/RelationshipMaterializer — deletion re-entering apply.
- `Deletion.php` is referenced upward from Policy (ScopeContract), Repository (RepositoryDeletionParser) and Review; it is a shared vocabulary masquerading as an engine class.

**Sub-namespace plan.** Target `WPrism\Delete\`. Not in this round: the move keeps `namespace WPrism;` flat so that manifest interpreters/providers can keep naming `\WPrism\Policy`, `\WPrism\ProviderSdk`, `\WPrism\Providers` and `\WPrism\Canon` by FQCN — those hook files are `hash_file`'d into every adapter's identity row (`ArtifactPolicyIdentity::manifest_rows()`), so renaming the namespace moves each `adapter_digest` and forces a recompile plus a reviewed re-pin on every deployed site. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a hook-file change.
