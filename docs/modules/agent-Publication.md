# agent: Publication

**Purpose.** Atomic publication of a state tree to disk plus the journal that makes an interrupted publication recoverable.

**Directory** `agent/src/Publication/` &middot; **layer** `engine` &middot; **files** 3 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `Publish`, `PublicationJournal`.

**May depend on:** `Kernel`, `Publication`.

**Must not depend on.** Everything above Kernel. Publication is a leaf: it publishes bytes and journals the attempt.

**Known debts.**

- None outstanding. Publication is the only agent module with zero exceptions — it is the shape every other engine module should be aiming at.

**Sub-namespace plan.** Target `WPrism\Publication\`. Not in this round: the move keeps `namespace WPrism;` flat so that manifest interpreters/providers can keep naming `\WPrism\Policy`, `\WPrism\ProviderSdk`, `\WPrism\Providers` and `\WPrism\Canon` by FQCN — those hook files are `hash_file`'d into every adapter's identity row (`ArtifactPolicyIdentity::manifest_rows()`), so renaming the namespace moves each `adapter_digest` and forces a recompile plus a reviewed re-pin on every deployed site. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a hook-file change.
