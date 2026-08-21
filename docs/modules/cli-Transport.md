# cli: Transport

**Purpose.** Carries bytes and commands to a target environment: the transport implementations, the environment driver handle and code deployment.

**Directory** `cli/src/Transport/` &middot; **layer** `kernel` &middot; **files** 6 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `EnvironmentDriver`, `ProviderLeaseBoundEnvironmentDriver`, `Transport`, `CodeDeploy`, `SshTransport`, `LocalTransport`.

**May depend on:** `Transport`.

**Must not depend on.** Every other cli module and every agent module. Transport is the bottom of the cli ladder.

**Known debts.**

- `Transport.php` selects only its three same-module ordinary implementations; environment-level construction adds Cloud without importing lifecycle or refresh authority into this kernel module.
- `CodeDeploy.php` is placed here on evidence (its only dependency is EnvironmentDriver; six modules call it); it reads like an Environment class and is the placement most likely to be revisited.

**Sub-namespace plan.** Target `Duo\Orchestrator\Transport\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
