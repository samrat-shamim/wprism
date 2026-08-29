# cli: Onboarding

**Purpose.** Adopting, initializing, diagnosing and triaging a site — the first-run and health mechanics behind adopt/init/doctor/classify/pending.

**Directory** `cli/src/Onboarding/` &middot; **layer** `engine` &middot; **files** 7 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `Doctor`, `Adopt`, `BootstrapEligibility`, `ClassificationBatch`, `Init`, `Pending`, `Triage`.

**May depend on:** `Onboarding`, `Transport`.

**Must not depend on.** Command, Environment, Recovery, Refresh and Adapter.

**Known debts.**

- `Adopt.php` assembles the adapter library into staging and tars exactly
  `agent recovery`; tests, fixtures, evidence, and source capsules never ship.
- Doctor/Triage/Pending overlap with agent Review and with `tools/doctor.sh` — three doctors, no shared vocabulary.

**Sub-namespace plan.** Target `WPrism\Orchestrator\Onboarding\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
