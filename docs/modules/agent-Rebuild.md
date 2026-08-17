# agent: Rebuild

**Purpose.** Derived-state regeneration: negotiating, selecting and dispatching native or provider rebuild actions after a write.

**Directory** `agent/src/Rebuild/` &middot; **layer** `engine` &middot; **files** 9 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `RebuildSelection`, `NativeActions`, `RegenerationContextStore`, `RebuildRequest`, `DependencyRegenerator`, `NativeRebuildExecutor`, `RebuildActionDispatcher`, `RebuildActionNegotiator`, `RegenerationContext`.

**May depend on:** `Kernel`, `Policy`, `Rebuild`, `Repository`.

**Ratified exceptions** (same-layer or upward edges that exist today; ratchet — may shrink, never grow):

- `Adapter` (upward, 6 edges)
  `DependencyRegenerator.php -> ProviderActionBatchBuilder.php`; `NativeActions.php -> Providers.php`; `RebuildActionDispatcher.php -> ProviderActionBatchBuilder.php`; `RebuildActionDispatcher.php -> Providers.php`; `RebuildActionNegotiator.php -> Providers.php`; `RebuildSelection.php -> Providers.php`
- `Scope` (intra-layer, 3 edges)
  `RebuildActionDispatcher.php -> ScopedApplyCoordinator.php`; `RebuildActionDispatcher.php -> ScopedApplySession.php`; `RebuildRequest.php -> ScopedApplySession.php`

**Must not depend on.** Command. Rebuild is the only engine module allowed to talk to Providers, and only through ProviderActionBatchBuilder/ProviderSdk.

**Known debts.**

- Six upward edges into Adapter (Providers/ProviderActionBatchBuilder). These are the legitimate provider seam; the engine-adapter proposal wants them expressed as an injected port rather than a static call.

**Sub-namespace plan.** Target `Duo\Rebuild\`. Not in this round: the move keeps `namespace Duo;` flat so that manifest interpreters/providers can keep naming `\Duo\Policy`, `\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN — those manifest bytes are digest-bound and renaming them is a certification round of its own. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a manifest-bytes change.
