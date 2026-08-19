# agent: Capture

**Purpose.** Reads owned state out of a live site into repository shape behind safety gates, a capture identity and a capture transaction.

**Directory** `agent/src/Capture/` &middot; **layer** `engine` &middot; **files** 18 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `Capture`, `TypedTableCapture`.

**May depend on:** `Capture`, `Code`, `Grammar`, `Kernel`, `Policy`, `Repository`.

**Ratified exceptions** (same-layer or upward edges that exist today; ratchet — may shrink, never grow):

- `Delete` (intra-layer, 1 edge)
  `CapturePublicationWorkflow.php -> Deletion.php`
- `Init` (intra-layer, 1 edge)
  `InitialCaptureBoundary.php -> InitProtocol.php`
- `Promotion` (intra-layer, 1 edge)
  `CapturePublicationWorkflow.php -> Deploy.php`
- `Publication` (intra-layer, 6 edges)
  `CapturePublicationRecovery.php -> Publish.php`; `CapturePublicationWorkflow.php -> PublicationJournal.php`; `CapturePublicationWorkflow.php -> Publish.php`; `CaptureTransaction.php -> Publish.php`; `InitialCaptureBoundary.php -> PublicationJournal.php`; `InitialCaptureBoundary.php -> Publish.php`
- `Review` (intra-layer, 3 edges)
  `CapturePublicationWorkflow.php -> Canary.php`; `CapturePublicationWorkflow.php -> Lint.php`; `CaptureSnapshotService.php -> Canary.php`
- `Scope` (intra-layer, 4 edges)
  `Capture.php -> ScopedCaptureProjector.php`; `CapturePublicationWorkflow.php -> ScopedApply.php`; `CapturePublicationWorkflow.php -> ScopedCaptureProjector.php`; `CapturePublicationWorkflow.php -> ScopedStateOverlay.php`

**Must not depend on.** Adapter and Command. Capture reads the site through Policy/Grammar and writes Repository shape.

**Known debts.**

- `InitialCaptureBoundary.php -> InitProtocol.php` and `CapturePublicationWorkflow.php -> Deploy.php` bind capture to onboarding and promotion.
- CapturePublicationWorkflow is one of the 22 files over 800 lines and fans out to 24 other files.

**Sub-namespace plan.** Target `Duo\Capture\`. Not in this round: the move keeps `namespace Duo;` flat so that manifest interpreters/providers can keep naming `\Duo\Policy`, `\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN — those hook files are `hash_file`'d into every adapter's identity row (`ArtifactPolicyIdentity::manifest_rows()`), so renaming the namespace moves each `adapter_digest` and forces a recompile plus a reviewed re-pin on every deployed site. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a hook-file change.
