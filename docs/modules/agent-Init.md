# agent: Init

**Purpose.** First-contact onboarding of a site: probing, planning, confirming, journalling and recovering the initial owned baseline.

**Directory** `agent/src/Init/` &middot; **layer** `engine` &middot; **files** 13 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `Init`, `InitProtocol`.

**May depend on:** `Code`, `Init`, `Kernel`, `Policy`, `Repository`.

**Ratified exceptions** (same-layer or upward edges that exist today; ratchet — may shrink, never grow):

- `Adapter` (upward, 1 edge)
  `InitPlanner.php -> AdapterSources.php`
- `Capture` (intra-layer, 2 edges)
  `InitConfirmation.php -> Capture.php`; `InitRecovery.php -> Capture.php`
- `Publication` (intra-layer, 7 edges)
  `InitAttemptJournal.php -> Publish.php`; `InitCodeBaseline.php -> PublicationJournal.php`; `InitCodeBaseline.php -> Publish.php`; `InitConfirmation.php -> PublicationJournal.php`; `InitConfirmation.php -> Publish.php`; `InitOwnedArtifacts.php -> Publish.php`; …

**Must not depend on.** Adapter and Command. The one InitPlanner->AdapterSources edge is a ratified debt.

**Known debts.**

- Seven edges into Publication and two into Capture: Init is a second, parallel capture/publish path rather than a caller of the first.
- `InitPlanner.php -> AdapterSources.php` is a ratified upward edge.

**Sub-namespace plan.** Target `Duo\Init\`. Not in this round: the move keeps `namespace Duo;` flat so that manifest interpreters/providers can keep naming `\Duo\Policy`, `\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN — those manifest bytes are digest-bound and renaming them is a certification round of its own. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a manifest-bytes change.
