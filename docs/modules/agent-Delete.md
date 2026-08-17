# agent: Delete

**Purpose.** Deletion authority, deletion guards and the executor that removes owned entities and records tombstones.

**Directory** `agent/src/Delete/` &middot; **layer** `engine` &middot; **files** 7 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `Deletion`, `DeleteExecutor`, `DeleteGuardLockCoordinator`, `DeleteGuardReferenceScanner`, `DeletionAuthority`, `DeleteGuardEvaluator`.

**May depend on:** `Delete`, `Kernel`, `Policy`, `Repository`.

**Ratified exceptions** (same-layer or upward edges that exist today; ratchet — may shrink, never grow):

- `Apply` (intra-layer, 2 edges)
  `DeleteExecutor.php -> MenuMaterializer.php`; `DeleteExecutor.php -> RelationshipMaterializer.php`

**Must not depend on.** Adapter and Command. The two Apply materializer edges are ratified debts.

**Known debts.**

- `DeleteExecutor.php` requires MenuMaterializer/RelationshipMaterializer — deletion re-entering apply.
- `Deletion.php` is referenced upward from Policy (ScopeContract), Repository (RepositoryDeletionParser) and Review; it is a shared vocabulary masquerading as an engine class.

**Sub-namespace plan.** Target `Duo\Delete\`. Not in this round: the move keeps `namespace Duo;` flat so that manifest interpreters/providers can keep naming `\Duo\Policy`, `\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN — those manifest bytes are digest-bound and renaming them is a certification round of its own. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a manifest-bytes change.
