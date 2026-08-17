# agent: Promotion

**Purpose.** Deploy, lifecycle and promotion mechanics — planners, leases, locks, session journals and state handoff between environments.

**Directory** `agent/src/Promotion/` &middot; **layer** `engine` &middot; **files** 11 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `PromotionLock`, `Deploy`, `ScopedPromotionAuthority`.

**May depend on:** `Code`, `Kernel`, `Policy`, `Promotion`, `Repository`.

**Ratified exceptions** (same-layer or upward edges that exist today; ratchet — may shrink, never grow):

- `Capture` (intra-layer, 1 edge)
  `StateHandoffVerifier.php -> Capture.php`
- `Review` (intra-layer, 1 edge)
  `Deploy.php -> Canary.php`

**Must not depend on.** Adapter and Command. Promotion sequences environments; it must not decide ownership.

**Known debts.**

- The operator-facing promotion state machine is not here: it is ~1,588 lines inside `cli/duo`. Round 3's cli Release module is where that logic is meant to land.
- `StateHandoffVerifier.php -> Capture.php` and `Deploy.php -> Canary.php` keep Promotion inside the engine SCC.

**Sub-namespace plan.** Target `Duo\Promotion\`. Not in this round: the move keeps `namespace Duo;` flat so that manifest interpreters/providers can keep naming `\Duo\Policy`, `\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN — those manifest bytes are digest-bound and renaming them is a certification round of its own. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a manifest-bytes change.
