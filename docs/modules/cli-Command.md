# cli: Command

**Purpose.** The `duo` verb handlers, agent passthrough, option parsing, preflight and operator output. Composition across engine modules happens here: a `*Command` calls each engine module downward and hands the result to the next, which is why every cli module needs no intra-layer exception. The round-3 verb boundaries (`AssessCommand`, `ContractCommand`, `RehearseCommand`, `ReleaseCommand`, `VerifyCommand`, `RecoverCommand`) landed in this directory, not in their mechanism modules — all six are here today.

**Directory** `cli/src/Command/` &middot; **layer** `surface` &middot; **files** 27 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `PassthroughCommand`, `CommandOutput`, `EnvironmentCommandPreflight`, `EnvironmentCommandOptions`, `OriginCommand`, `PreviewCommand`.

**May depend on:** `Adapter`, `Assess`, `Command`, `Contract`, `Environment`, `Onboarding`, `Plan`, `Recovery`, `Refresh`, `Rehearse`, `Release`, `Transport`, `agent:Kernel`, `agent:Policy`.

**Must not depend on.** Nothing below it is forbidden, but Command must hold no mechanism: cli/duo's promotion state machine is the standing counter-example and is a debt, not a pattern.

**Known debts.**

- `cli/duo` itself stays at `cli/duo`: 3,247 lines, 79 global functions, a `match ($verb)` dispatch at `:1130` and a ~1,604-line promotion state machine (`cmd_promote_scoped` at `:1624` through `promote_failed`, ending `:3227`). It is not a module; it is the debt the Release module is meant to absorb — and round 3 grew it rather than shrinking it, because `release`/`verify`/`recover`/`rehearse` each added a dispatch arm on top of the machine nobody has moved yet.
- Twenty-seven files here, one writer — the most likely contention point in the whole tree. Round 3's T2/T3 landed six verb boundaries here, and Cloud preview added `OriginCommand` and `PreviewCommand`. Sequence work on this directory; do not parallelise it.

**Sub-namespace plan.** Target `Duo\Orchestrator\Command\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
