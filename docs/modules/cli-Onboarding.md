# cli: Onboarding

**Purpose.** Adopting, initializing, diagnosing and triaging a site — including canonical connection and Git-handoff receipts at the first-run boundary.

**Directory** `cli/src/Onboarding/` &middot; **layer** `engine` &middot; **files** 10 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `Doctor`, `Adopt`, `Unadopt`, `BootstrapEligibility`, `ClassificationBatch`, `ConnectionReceipt`, `Init`, `OnboardingHandoffReceipt`, `Pending`, `Triage`.

**May depend on:** `Onboarding`, `Transport`, `agent:Kernel`.

`ConnectionReceipt` publishes the inspection-only connection boundary;
`OnboardingHandoffReceipt` binds the initialized target, controller checkout,
remote commit, assessment, contract status, and authority enrollment state.

**Must not depend on.** Command, Environment, Recovery, Refresh and Adapter.

**Known debts.**

- `Adopt.php` assembles the adapter library into staging and tars exactly
  `agent recovery`; tests, fixtures, evidence, and source capsules never ship.
- Doctor/Triage/Pending overlap with agent Review and with `tools/doctor.sh` — three doctors, no shared vocabulary.

**Sub-namespace plan.** Target `WPrism\Orchestrator\Onboarding\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
