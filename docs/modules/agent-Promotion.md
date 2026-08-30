# agent: Promotion

**Purpose.** Deploy, lifecycle and promotion mechanics — planners, leases, locks, externally-authorized repository binding, session journals and state handoff between environments.

**Directory** `agent/src/Promotion/` &middot; **layer** `engine` &middot; **files** 12 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `AuthorizedReleaseRepository`, `PromotionLock`, `Deploy`, `ScopedPromotionAuthority`.

**May depend on:** `Code`, `Kernel`, `Policy`, `Promotion`, `Repository`.

**Ratified exceptions** (same-layer or upward edges that exist today; ratchet — may shrink, never grow):

- `Capture` (intra-layer, 1 edge)
  `StateHandoffVerifier.php -> Capture.php`
**Must not depend on.** Adapter and Command. Promotion sequences environments; it must not decide ownership.

`AuthorizedReleaseRepository` is the target-local bridge from an externally
authorized source to promotion's existing lease. It holds the private Git
repository flock while it rechecks the exact commit, tree and clean tracked
bytes, and retains that kernel lock through `PromotionLock::begin()`. The
named lock path must still resolve to the acquired inode immediately before
lease election; replacement is a reconciliation refusal, never a second lock.
The promotion owner hashes operation id + commit + tree, while the lease's
existing artifact hash binds the exact staged compilation. Controller checks are only
admission evidence; the target-side check and lease election are one critical
section.

**Known debts.**

- The operator-facing promotion state machine is not here: it is ~1,588 lines inside `cli/wprism`. Round 3's cli Release module is where that logic is meant to land.
- `StateHandoffVerifier.php -> Capture.php` keeps Promotion inside the engine SCC.

**Sub-namespace plan.** Target `WPrism\Promotion\`. Not in this round: the move keeps `namespace WPrism;` flat so that manifest interpreters/providers can keep naming `\WPrism\Policy`, `\WPrism\ProviderSdk`, `\WPrism\Providers` and `\WPrism\Canon` by FQCN — those hook files are `hash_file`'d into every adapter's identity row (`ArtifactPolicyIdentity::manifest_rows()`), so renaming the namespace moves each `adapter_digest` and forces a recompile plus a reviewed re-pin on every deployed site. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a hook-file change.
