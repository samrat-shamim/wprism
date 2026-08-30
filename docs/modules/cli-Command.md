# cli: Command

**Purpose.** The `wprism` verb handlers, agent passthrough, option parsing, preflight and operator output. Composition across engine modules happens here: a `*Command` calls each engine module downward and hands the result to the next, which is why every cli module needs no intra-layer exception. `StageSourceCommand` owns inert target-side source staging; `ReleaseCommand` retains read-only legacy plan preview and owns the read-only prepare/one-time signed execute composition. Legacy interactive/`--yes` mutation is unreachable.

**Directory** `cli/src/Command/` &middot; **layer** `surface` &middot; **files** 38 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `PassthroughCommand`, `CommandOutput`, `EnvironmentCommandPreflight`, `EnvironmentCommandOptions`.

**May depend on:** `Adapter`, `Assess`, `Authority`, `Command`, `Contract`, `Environment`, `Onboarding`, `Plan`, `Recovery`, `Refresh`, `Rehearse`, `Release`, `Transport`, `agent:Kernel`, `agent:Policy`.

**Must not depend on.** Nothing below it is forbidden, but Command must hold no mechanism: cli/wprism's promotion state machine is the standing counter-example and is a debt, not a pattern.

`AuthorityPolicyCommand` is the explicit target-control enrollment surface:
read-only `status`, or durable compare-and-swap `sync`. It never runs as an
implicit side effect of prepare or execute. `StageSourceCommand` establishes
target identity; `ReleaseCommand` requires an already enrolled target policy,
binds its digest into prepare, and delegates signature revalidation plus the
winner election to Authority.

**Known debts.**

- `cli/wprism` itself stays at `cli/wprism`: 3,179 lines, 78 global functions, a `match ($verb)` with 22 arms (`:1077`) and a ~1,605-line promotion state machine (`cmd_promote_scoped` at `:1558` through `promote_failed`, ending `:3162`). It is not a module; it is the debt the Release module is meant to absorb — and round 3 grew it rather than shrinking it, because `release`/`verify`/`recover`/`rehearse` each added a dispatch arm on top of the machine nobody has moved yet.
- Twenty-five files here, one writer — the most likely contention point in the whole tree. Round 3's T2/T3 both landed verb boundaries here (`+6` files since the move). Sequence work on this directory; do not parallelise it.

**Sub-namespace plan.** Target `WPrism\Orchestrator\Command\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
