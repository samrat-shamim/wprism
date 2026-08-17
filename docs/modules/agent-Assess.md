# agent: Assess

**Purpose.** Read-only inventory and projection commands answering 'what is here, and what can Duo do with it' before any write.

**Directory** `agent/src/Assess/` &middot; **layer** `surface` &middot; **files** 1 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `AssessInventory`.

**May depend on:** `Adapter`, `Assess`, `Code`, `Grammar`, `Kernel`, `Policy`, `Repository`, `Review`.

**Must not depend on.** Anything that writes. Assess is read-only by construction: no locks, no publication, no apply, no promotion.

**Known debts.**

- `AssessInventory` (round-3 MUP §4.5) is the module's only file: one read-only pass emitting `duo-assess-inventory/v1` (stack probe, installed plugins/themes, media, pinned manifests, policy surface groups, `Coverage::report()`, `Pending::scan_read_only()`, the adapter survey). It composes existing projections rather than owning any, so the remaining Assess projections (status, capabilities, adapter doctor) still exist only as Cli.php verbs over Review and Adapter.
- Its `report()` reads the target live; `from_facts()` is the composition seam that takes the stack probe and the three quoted projections as data, because `CapabilityRegistry::probe_target()`'s `SELECT VERSION()` and `Pending`'s gate walk are not offline-drivable.
- The no-plugin-slug grep gate over this directory lives in `sandbox/tests/regress_assess_inventory.php` and derives its forbidden set from the shipped manifest library.

**Sub-namespace plan.** Target `Duo\Assess\`. Not in this round: the move keeps `namespace Duo;` flat so that manifest interpreters/providers can keep naming `\Duo\Policy`, `\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN — those manifest bytes are digest-bound and renaming them is a certification round of its own. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a manifest-bytes change.
