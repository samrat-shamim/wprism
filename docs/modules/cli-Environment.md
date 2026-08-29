# cli: Environment

**Purpose.** The environment registry and lifecycle that materialize, bind and reap the environments the orchestrator drives.

**Directory** `cli/src/Environment/` &middot; **layer** `engine` &middot; **files** 2 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `Registry`, `EnvironmentLifecycle`.

**May depend on:** `Environment`, `Plan`, `Refresh`, `Transport`.

**Must not depend on.** Command, Recovery, Onboarding and Adapter.

**Known debts.**

- Only two files, but `EnvironmentLifecycle.php` is the provider seam (CommandEnvironmentProvider) that round 3's Rehearse module builds on.
- The `.wprism-envs.json` binding format is unversioned prose today.

**Sub-namespace plan.** Target `WPrism\Orchestrator\Environment\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
