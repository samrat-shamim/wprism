# cli: Rehearse

**Purpose.** Rehearsal mechanism — the plan preview of what a release would touch, and the containment disclosure. The verb boundary (`RehearseCommand`) lives in `cli/src/Command/` and drives Environment's materialize/reap there.

**Directory** `cli/src/Rehearse/` &middot; **layer** `engine` &middot; **files** 2 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `RehearsalPlanPreview`, `RehearsalDisclosure`.

**May depend on:** `Contract`, `Plan`, `Rehearse`, `Transport`, `agent:Kernel`.

**Ratified exceptions.** None, and none designed. `EnvironmentLifecycle` and the assess projection are composed at the surface and passed in.

**Must not depend on.** Recovery and Release. A rehearsal must not be able to promote or roll back production.

**What landed** (this module was reserved when the map was ratified; round 3's T3 filled it):

- `RehearsalPlanPreview` — "what a release would touch", built by joining two documents and **re-computing neither**: the target agent's own `wp wprism plan --format=json` envelope after convergence (with its additive `wprism-plan-category-summary/v1` projection) and the contract projection (`cli/src/Rehearse/RehearsalPlanPreview.php:16-27`).
- `RehearsalDisclosure` — two sentences that get their own class on purpose. The spec's rehearsal is a *sandbox* (credentials stripped before boot, outbound HTTP/mail/payment/webhook/queue default-denied, containment verified first); MUP ships none of that, so what this provides is a **preview**, and the gap is a safety property that must be stated rather than quietly deferred (`cli/src/Rehearse/RehearsalDisclosure.php:12-23`).

**Known debts.**

- The disclosure is the debt, written down. Until real containment exists, `wprism rehearse` must keep saying out loud that it is not a sandbox.

**Sub-namespace plan.** Target `WPrism\Orchestrator\Rehearse\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
