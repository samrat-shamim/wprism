# Adapter rollout: remaining work after the first checkpoint

PR [#583](https://github.com/duotronic-ai/wprism/pull/583) was merged at the
owner's request as an interim checkpoint, not as completion of the adapter
rollout. Its squash commit is `4f1cb6765040864da6f5fb0798f0bdfee27b819c`.
This work plan carries the unfinished criteria into the follow-up; it is not
a capability declaration, disposition, generated inventory, or evidence of a
passing run.

At that checkpoint the catalog has **17 product adapters: 16 plugins plus
core**. The regression-only `wprism-agency-cpt` capsule does not count toward
twenty. Three additional product adapters remain to be selected, authored,
reviewed and exercised through the product path.

## First: finish the existing integration evidence

- [x] Close the shared Python pair-lock startup cancellation race found during
  the follow-up's offline gate. A controlled real-launcher regression queues
  TERM before the helper opens its reader: the prior library discards the
  cancellation byte when it closes the last FIFO endpoint, leaving parent
  and helper waiting on each other. The shared library now opens the reader
  before closing its inherited endpoint; the same regression passes three
  consecutive focused runs without changing cancellation deadlines or lock
  authority. This is shared test infrastructure, not an adapter/engine change.
- [x] Make the shared publication crash probe prove its staging premise. Its
  parent-side 300ms kill could precede child startup; a controlled delayed
  launch reproduces the same missing-staging failure. The test child now
  injects real SIGKILL after its first staged write, and the observer requires
  the signal, exact partial bytes, unchanged published state, released lock
  and successful recovery in both immediate and delayed-startup cases.
  Counterfactual timed killing, normal exit and killing before the write all
  fail the actual observer. Publication runtime code is unchanged.
- [x] Diagnose the complete retry-isolation comparison in
  `integration-scenarios/rank-math-commerce-multilingual/`. The exact
  `db96ab403ca9ac879f38c6088bbc3664c1e5c0f1` run on `rmcomboacf01` passed native
  source premises, Capture, host refusal/recovery/settlement, initial Apply,
  native and rendered convergence, redirect telemetry, and both hostile ACF
  collision refusals with checked cleanup and byte-identical recapture.
  It then reached a successful provider-retry receipt but failed the complete
  native retry-isolation assertion. A fresh exact
  `c76eb5806c9e61b5abf2e2bc67b17377dce33547` run retained every phase across
  teardown: after the intended content/edge/count changes, the only difference
  was removal of the owned redirect's derived cache. The manifest already
  declares that invalidation by `redirection_id`. The scenario now requires
  that precise effect and a subsequent native 302/cache refill. A successful
  command receipt is not evidence that a failed native assertion passed.
- [ ] Complete fresh live evidence for the corrected custom-CPT deletion
  boundary. No pinned adapter grants `post:rmcombo_book` deletion: source
  Capture must refuse `unsupported_deletion` without publishing a tombstone,
  and an otherwise valid synthetic target intent must fail Plan/Apply
  compilation without canonical or native mutation. This does not establish
  signed deletion support for that CPT.
- [ ] Complete all four scenario lanes: independent and synchronized metadata,
  each with opposed source/target plugin load orders. The first checkpoint's
  partial runs do not stand in for fixed-point or later-lane evidence.
- [ ] Close four-plugin SSH adoption/readiness and signed-deletion evidence.
  Diagnose native capability inventory and the loaded generation through the
  normal product path, retaining `DISALLOW_FILE_MODS` and external writer
  exclusion. Do not manually load missing classes or bypass permissions.
- [ ] Correct WooCommerce variation-deletion evidence. The shipped policy
  deliberately withholds `post:product_variation` deletion until parent
  regeneration has a reversible boundary. The current conformance and version
  fixtures incorrectly expect a supported tombstone or a clean deletion plan.
  Prove missing-source Capture refuses `unsupported_deletion` without canonical
  publication, and a well-formed synthetic tombstone fails compilation before
  Plan/Apply can mutate the target. Preserve and verify the native variation,
  parent and complete relevant lookup rows. Product deletion is a different,
  supported boundary; do not remove its proof or enable variation deletion
  merely to satisfy the old fixture.

For every repair, first execute a deterministic regression through the actual
caller and make it fail against the prior defect. A pass-text grep or a test
of an independent reimplementation is not enough. Retain complete transports,
native premises and canonical byte comparisons. Git status equality alone
cannot detect a second edit to an already-dirty file.

## Then: author three more product adapters

- [ ] Investigate WPForms Lite as the next candidate, following
  [the authoring guide](../guides/adapter-authoring.md) and
  [production-readiness requirements](adapter-production-readiness.md).
  Prior source inspection is preparation only. Resolve native form-body ID
  types, references, authored settings, submissions/PII boundaries, templates,
  derived location state and lifecycle before making a support claim.
- [ ] Select the remaining two adapters on useful product coverage and an
  honest support boundary, not ease of increasing the count. Each must own its
  capsule-local declaration, reviewed disposition, exact official artifacts,
  offline controls, native end-to-end evidence and relevant combinations.
- [ ] Obtain independent subagent review of both the checkpoint's newest
  unreviewed deltas and the follow-up changes. Earlier reviews do not certify
  later changes. Address findings before treating the rollout as complete.

## Architectural ownership and authoring feedback

| Concern | Correct owner |
| --- | --- |
| Bounded process execution, filesystem observation, data parsing, reference grammar, work authority and transaction/recovery mechanisms | Generic engine machinery, with no plugin-name branch |
| Meaning of native fields, native reconstruction, eligibility and plugin-specific derived state | The owning adapter capsule |
| Pair leases, exact-source mounts, private captures and test-only lifecycle controls | Shared test infrastructure |
| Cross-plugin semantics, hostile interactions and load-order evidence | An explicit participant-declared integration scenario |
| Repeatable authoring pitfalls and missing instructions | The authoring guide, alongside the regression that proves the lesson |

Continue evaluating executable code for reuse. The checkpoint extracted generic
process lifecycle/I/O, filesystem snapshots, executable identity and data-only
PHP parsing. Twelve hash-frozen legacy executable-debt rows remain; they are
not implicitly cleared by those extractions. A further extraction needs a
real shared contract and regression proof, not a compatibility wrapper or a
new plugin dispatch switch in the engine.

Package bytes remain adapter identity. Runtime or manifest changes require
recompilation and reviewed re-pinning; manifest mismatch must remain a hard
refusal. Keep dependency-free shipped code and explicit dependency loading.
Report any finding that invalidates the platform architecture instead of
masking it with a local workaround. No such finding was established at the
interim checkpoint.

## Evidence and completion

The checkpoint passed `make regress-offline-all` (355 suites), `composer check`
(1,253 tests / 33,668 assertions), `make release-gate`, changed-file syntax and
diff checks. Those are commit-bound historical results, not automatic evidence
for future changes. Its final combination attempt exited 1; owned pair
resources were cleaned up and private captures retained separately.

For each follow-up change, run the full offline gate and the minimal live set
that executes the changed paths, as required by
[the dispatch and evidence protocol](linear-loop.md). Use exact candidate
mounts, fresh owned pairs, complete warning-free observations and verified
teardown. Keep PR descriptions explicit about incomplete lanes and historical
versus current evidence. The goal completes only when twenty product adapters
have the required evidence, review and merged implementation.
