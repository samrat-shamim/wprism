# cli: Command

**Purpose.** The `duo` verb handlers, agent passthrough, option parsing, preflight and operator output. Composition across engine modules happens here: a `*Command` calls each engine module downward and hands the result to the next, which is why every populated cli module needs no intra-layer exception. The round-3 verb boundaries (`AssessCommand`, `ContractCommand`, `RehearseCommand`, `ReleaseCommand`, `VerifyCommand`, `RecoverCommand`) land in this directory, not in their mechanism modules.

**Directory** `cli/src/Command/` &middot; **layer** `surface` &middot; **files** 19 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `PassthroughCommand`, `CommandOutput`, `EnvironmentCommandPreflight`, `EnvironmentCommandOptions`.

**May depend on:** `Adapter`, `Assess`, `Command`, `Contract`, `Environment`, `Onboarding`, `Plan`, `Recovery`, `Refresh`, `Rehearse`, `Release`, `Transport`, `agent:Kernel`, `agent:Policy`.

**Must not depend on.** Nothing below it is forbidden, but Command must hold no mechanism: cli/duo's promotion state machine is the standing counter-example and is a debt, not a pattern.

**Known debts.**

- `cli/duo` itself stays at `cli/duo`: 2,690 lines, 72 global functions, a `match($verb)` with 17 arms and a ~1,588-line promotion state machine. It is not a module; it is the debt the Release module is meant to absorb.
- Nineteen files here, one writer — the most likely contention point during the move, and rounds 3's T2/T3 both add verb boundaries here. Sequence them; do not parallelise.

**Sub-namespace plan.** Target `Duo\Orchestrator\Command\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
