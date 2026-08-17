# agent: Review

**Purpose.** Read-only projections over plans and state — lint and its reference scanners, coverage, pending, journal, orphans, canary, convergence verification and refresh export.

**Directory** `agent/src/Review/` &middot; **layer** `engine` &middot; **files** 17 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `Canary`, `ConvergenceVerifier`, `Journal`, `Lint`, `Pending`, `PlanCategorySummary`, `PlanExplanation`, `PlanView`, `Coverage`, `Orphans`, `RefreshExport`.

**May depend on:** `Code`, `Grammar`, `Kernel`, `Policy`, `Repository`, `Review`.

**Ratified exceptions** (same-layer or upward edges that exist today; ratchet — may shrink, never grow):

- `Capture` (intra-layer, 3 edges)
  `ConvergenceVerifier.php -> Capture.php`; `Pending.php -> Capture.php`; `RefreshExport.php -> Capture.php`
- `Delete` (intra-layer, 2 edges)
  `PlanExplanation.php -> Deletion.php`; `RefreshExport.php -> Deletion.php`
- `Scope` (intra-layer, 3 edges)
  `ConvergenceVerifier.php -> ScopedApply.php`; `ConvergenceVerifier.php -> ScopedApplySession.php`; `RefreshExport.php -> ScopedStateOverlay.php`

**Must not depend on.** Adapter and Command. Review must stay read-only: no writes, no locks, no publication.

**Known debts.**

- Review is the module the reserved Assess module will draw from; today its projections are reachable only through Cli.php, and several (verify-canonical, journal-report, orphans) are undocumented Phase A leaks.
- `Lint.php` owns the five reference scanners, which were moved here from Capture on the evidence that Lint is their only caller.

**Sub-namespace plan.** Target `Duo\Review\`. Not in this round: the move keeps `namespace Duo;` flat so that manifest interpreters/providers can keep naming `\Duo\Policy`, `\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN — those manifest bytes are digest-bound and renaming them is a certification round of its own. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a manifest-bytes change.
