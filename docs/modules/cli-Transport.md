# cli: Transport

**Purpose.** Carries bytes and commands to a target environment: the transport implementations, the environment driver handle and code deployment.

**Directory** `cli/src/Transport/` &middot; **layer** `kernel` &middot; **files** 6 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `EnvironmentDriver`, `Transport`, `CodeDeploy`, `SshTransport`, `LocalTransport`.

**May depend on:** `Transport`.

**Must not depend on.** Every other cli module and every agent module. Transport is the bottom of the cli ladder.

**Known debts.**

- `Transport.php` is a factory that references its own three implementations, which reference it back — an intra-module cycle, harmless but worth an interface.
- `CodeDeploy.php` is placed here on evidence (its only dependency is EnvironmentDriver; six modules call it); it reads like an Environment class and is the placement most likely to be revisited.

**Sub-namespace plan.** Target `WPrism\Orchestrator\Transport\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
