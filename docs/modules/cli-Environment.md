# cli: Environment

**Purpose.** The environment registry and lifecycle that materialize, bind and reap the environments the orchestrator drives.

**Directory** `cli/src/Environment/` &middot; **layer** `engine` &middot; **files** 7 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `EnvironmentLifecycle`, `EnvironmentTransportFactory`, `CloudPreviewTransport`, `PortablePreviewMaterializer`, `PreviewRunJournal`, `Registry`.

**May depend on:** `Environment`, `Plan`, `Refresh`, `Transport`, `agent:Kernel`.

**Must not depend on.** Command, Recovery, Onboarding and Adapter.

**Known debts.**

- `EnvironmentLifecycle.php` remains the provider seam; portable Cloud preview adds a distinct semantic materializer and its composition journal without fabricating physical snapshot evidence.
- `EnvironmentTransportFactory.php` composes the bottom-layer ordinary factory with the Cloud transport here, where the lifecycle and signed origin dependencies are already legal; `Transport` stays free of upward factory edges.
- The `.duo-envs.json` binding format is unversioned prose today.

**Sub-namespace plan.** Target `Duo\Orchestrator\Environment\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
