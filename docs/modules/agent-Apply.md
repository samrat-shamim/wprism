# agent: Apply

**Purpose.** Plans and executes writes of repository state into a live site: apply planning, services, the authored transaction and the per-entity materializers.

**Directory** `agent/src/Apply/` &middot; **layer** `engine` &middot; **files** 27 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `ApplyPlanner`, `Apply`, `MenuMaterializer`, `RelationshipMaterializer`, `TypedTableMaterializer`.

**May depend on:** `Apply`, `Code`, `Grammar`, `Kernel`, `Policy`, `Repository`.

**Ratified exceptions** (same-layer or upward edges that exist today; ratchet — may shrink, never grow):

- `Adapter` (upward, 4 edges)
  `ApplyPlanEnvironment.php -> ProviderActionBatchBuilder.php`; `ApplyRequestCoordinator.php -> Providers.php`; `ApplyServices.php -> ProviderActionBatchBuilder.php`; `AuthoredTransactionExecutor.php -> ProviderActionBatchBuilder.php`
- `Capture` (intra-layer, 3 edges)
  `ApplyPlanBuilder.php -> Capture.php`; `ApplyPreparationCoordinator.php -> Capture.php`; `ApplyRequestCoordinator.php -> Capture.php`
- `Delete` (intra-layer, 10 edges)
  `ApplyPlanBuilder.php -> DeleteGuardEvaluator.php`; `ApplyPlanBuilder.php -> DeleteGuardReferenceScanner.php`; `ApplyPlanBuilder.php -> Deletion.php`; `ApplyPreparationCoordinator.php -> DeleteGuardLockCoordinator.php`; `ApplyRequestCoordinator.php -> DeleteGuardLockCoordinator.php`; `ApplyRequestCoordinator.php -> DeletionAuthority.php`; …
- `Promotion` (intra-layer, 6 edges)
  `ApplyPlanBuilder.php -> Deploy.php`; `ApplyPlanBuilder.php -> PromotionLock.php`; `ApplyPreparationCoordinator.php -> PromotionLock.php`; `ApplyPreparationCoordinator.php -> ScopedPromotionAuthority.php`; `ApplyRequestCoordinator.php -> PromotionLock.php`; `ApplyRequestCoordinator.php -> ScopedPromotionAuthority.php`
- `Rebuild` (intra-layer, 13 edges)
  `ApplyPlanEnvironment.php -> RebuildSelection.php`; `ApplyPlanEnvironment.php -> RegenerationContextStore.php`; `ApplyPreparationCoordinator.php -> RebuildSelection.php`; `ApplyRebuildCoordinator.php -> RebuildRequest.php`; `ApplyRebuildCoordinator.php -> RebuildSelection.php`; `ApplyRequestCoordinator.php -> RebuildRequest.php`; …
- `Review` (intra-layer, 6 edges)
  `ApplyRequestCoordinator.php -> Canary.php`; `ApplyRequestCoordinator.php -> ConvergenceVerifier.php`; `ApplyRequestCoordinator.php -> PlanCategorySummary.php`; `ApplyRequestCoordinator.php -> PlanExplanation.php`; `ApplyRequestCoordinator.php -> PlanView.php`; `AuthoredTransactionExecutor.php -> Canary.php`
- `Scope` (intra-layer, 12 edges)
  `ApplyLedgerFinalizer.php -> ScopedApply.php`; `ApplyLedgerFinalizer.php -> ScopedApplySession.php`; `ApplyPlanBuilder.php -> ScopedApply.php`; `ApplyPreparationCoordinator.php -> ScopedApply.php`; `ApplyPreparationCoordinator.php -> ScopedApplySession.php`; `ApplyPreparationCoordinator.php -> ScopedApplyWorkProjector.php`; …

**Must not depend on.** Command. Adapter is reached today only through the four ratified ProviderActionBatchBuilder/Providers edges; new provider work belongs in Rebuild.

**Known debts.**

- Largest engine module (27 files; only `Kernel`'s 28 is bigger) and the largest fan-out in the repo: `ApplyRequestCoordinator` carries 50 `require_once` lines, `ApplyServices` 27.
- 12 edges into Scope and 13 into Rebuild: the apply/scope/rebuild triangle is the densest part of the engine SCC.

**Sub-namespace plan.** Target `Duo\Apply\`. Not in this round: the move keeps `namespace Duo;` flat so that manifest interpreters/providers can keep naming `\Duo\Policy`, `\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN — those hook files are `hash_file`'d into every adapter's identity row (`ArtifactPolicyIdentity::manifest_rows()`), so renaming the namespace moves each `adapter_digest` and forces a recompile plus a reviewed re-pin on every deployed site. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a hook-file change.
