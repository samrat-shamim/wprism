# agent: Scope

**Purpose.** Scoped (partial-site) execution — the state overlay, session and work projection that let apply and capture run over a subset of the repository.

**Directory** `agent/src/Scope/` &middot; **layer** `engine` &middot; **files** 7 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `ScopedApply`, `ScopedApplySession`, `ScopedStateOverlay`, `ScopedApplyWorkflow`, `ScopedCaptureProjector`, `ScopedApplyCoordinator`, `ScopedApplyWorkProjector`.

**May depend on:** `Kernel`, `Policy`, `Repository`, `Scope`.

**Ratified exceptions** (same-layer or upward edges that exist today; ratchet — may shrink, never grow):

- `Adapter` (upward, 1 edge)
  `ScopedApplyCoordinator.php -> Providers.php`
- `Apply` (intra-layer, 5 edges)
  `ScopedApply.php -> ApplyPlanner.php`; `ScopedApplyCoordinator.php -> ApplyPlanner.php`; `ScopedApplyCoordinator.php -> ConvergenceVerifier.php`; `ScopedApplyWorkProjector.php -> ApplyPlanner.php`; `ScopedApplyWorkProjector.php -> ConvergenceVerifier.php`
- `Promotion` (intra-layer, 1 edge)
  `ScopedApplyCoordinator.php -> PromotionLock.php`
**Must not depend on.** Adapter and Command. Scope narrows work; it must not learn plugin names.

**Known debts.**

- Scope<->Apply is a 2-cycle (Scope->ApplyPlanner/ConvergenceVerifier, Apply->ScopedApply*). Whether Scope survives as a module or dissolves into Apply is the first question the Apply owner should answer with a measurement, not a preference.
- `ScopedApplyCoordinator.php -> Providers.php` is the module's only upward edge and violates the engine-adapter ruling in spirit.

**Sub-namespace plan.** Target `Duo\Scope\`. Not in this round: the move keeps `namespace Duo;` flat so that manifest interpreters/providers can keep naming `\Duo\Policy`, `\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN — those hook files are `hash_file`'d into every adapter's identity row (`ArtifactPolicyIdentity::manifest_rows()`), so renaming the namespace moves each `adapter_digest` and forces a recompile plus a reviewed re-pin on every deployed site. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a hook-file change.
