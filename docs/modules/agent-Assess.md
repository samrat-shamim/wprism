# agent: Assess

**Purpose.** RESERVED (round 3): read-only inventory and projection commands answering 'what is here, and what can Duo do with it' before any write.

**Directory** `agent/src/Assess/` &middot; **layer** `surface` &middot; **files** 0 &middot; **status** reserved

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): _none yet_.

**May depend on:** `Adapter`, `Assess`, `Code`, `Grammar`, `Kernel`, `Policy`, `Repository`, `Review`.

**Must not depend on.** Anything that writes. Assess is read-only by construction: no locks, no publication, no apply, no promotion.

**Known debts.**

- Empty in this round. The projections it will compose (status, coverage, pending, capabilities, adapter doctor) exist today only as Cli.php verbs over Review and Adapter.

**Sub-namespace plan.** Target `Duo\Assess\`. Not in this round: the move keeps `namespace Duo;` flat so that manifest interpreters/providers can keep naming `\Duo\Policy`, `\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN — those manifest bytes are digest-bound and renaming them is a certification round of its own. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a manifest-bytes change.
