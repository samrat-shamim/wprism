# agent: Review

**Purpose.** Read-only projections over plans and state — lint and its reference scanners, coverage, pending, effect-declaration coverage and plan explanation.

**Directory** `agent/src/Review/` &middot; **layer** `engine` &middot; **files** 15 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `EffectDeclarationCoverage`, `Lint`, `Pending`, `PlanCategorySummary`, `PlanExplanation`, `PlanView`, `Coverage`, `LintEnvironment`, `LintFinding`, `LintTrustGate`.

**May depend on:** `Code`, `Grammar`, `Kernel`, `Policy`, `Repository`, `Review`.

**Ratified exceptions** (same-layer or upward edges that exist today; ratchet — may shrink, never grow):

- `Capture` (intra-layer, 1 edge)
  `Pending.php -> Capture.php`
- `Delete` (intra-layer, 1 edge)
  `PlanExplanation.php -> Deletion.php`

**Must not depend on.** Adapter and Command. Review must stay read-only: no writes, no locks, no publication.

**Known debts.**

- Review's projections are composed by both the Assess module and Cli.php; neither composition may initialize or repair the evidence it reads.
- `Lint.php` owns the five reference scanners, which were moved here from Capture on the evidence that Lint is their only caller.

**Sub-namespace plan.** Target `WPrism\Review\`. Not in this round: the move keeps `namespace WPrism;` flat so that manifest interpreters/providers can keep naming `\WPrism\Policy`, `\WPrism\ProviderSdk`, `\WPrism\Providers` and `\WPrism\Canon` by FQCN — those hook files are `hash_file`'d into every adapter's identity row (`ArtifactPolicyIdentity::manifest_rows()`), so renaming the namespace moves each `adapter_digest` and forces a recompile plus a reviewed re-pin on every deployed site. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a hook-file change.
