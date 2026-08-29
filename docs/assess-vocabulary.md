# The assess vocabulary — the normative projection

Six product words describe every surface WPrism reports on: `state_class`,
`handling`, `readiness`, `certification_provenance`, `effect_containment`,
`effect_recovery_semantics`. They appear in `wprism assess`, in
`.wprism/contract/projection.json`, in the frozen authorization plan, and in the
text of a `wprism release` refusal. **The tables below are their only definition.**

They are a *projection*, not a mechanism: each word is computed from data that
does exist in code, and a wrong word is still a valid string, so nothing fails
to run when a cell drifts. `sandbox/tests/offline/assess-contract/regress_assess_projection.php`
is the gate that turns a drifted cell into a failure — one fact vector per
table cell, asserting the exact projected string. Change a cell here and change
it there, or the offline corpus refuses the commit.

*Provenance: these tables originated as §1 of the round-3 minimum-usable-platform
proposal and were promoted to canonical documentation on 2026-08-21, unchanged
in numbering so existing `§1.1`–`§1.6` citations keep resolving. Where the
original wrote "MUP", it is written here as the shipped behaviour it became.*

---

## 1. The vocabulary projection (the load-bearing table)

The spec's six per-surface dimensions do not exist in code. They are
*projected* from data that does. This is the complete mapping; it is
implemented once, in `\WPrism\Orchestrator\ProjectionVocabulary`
(`cli/src/Contract/ProjectionVocabulary.php`), and nowhere else. It lives in the
policy-layer Contract module, not in Assess, because Assess, Release and
Rehearse are all engine-layer siblings: policy is the lowest layer all three can
read, so one implementation costs zero intra-layer edges
([module map](modules/cli-Contract.md), rule 9).

### 1.1 State class

| Source fact | Where it comes from | Projected `state_class` |
|---|---|---|
| `authored` | `Policy::CLASSES` | `authored` |
| `runtime` | `Policy::CLASSES` | `runtime` |
| `derived` | `Policy::CLASSES` | `derived` |
| `env` | `Policy::CLASSES` | `environment-bound` |
| `managed` | `Policy::CLASSES` (`active_plugins`, `template`, `stylesheet`) | `authored` — see handling below |
| no rule matches; `Capture::gate_scan()` aborts / `wp wprism pending` row | `Pending::scan()` | `unclassified` |
| named in `Coverage::report()` as invisible (no discovery path at all) | `Coverage` | `unclassified` |
| a manifest declares a provider action whose declared effects reach a system outside this WordPress install | manifest `providers` + declared effects | `external` |

`external` is emitted **only** from a declaration. The projection never infers
that a surface is externally owned from observation; an unmodelled integration
lands in `unclassified` / `block`, which is the spec's required behaviour.

### 1.2 Handling

| Condition | Projected `handling` |
|---|---|
| `authored` and inside the captured scope | `manage` |
| `managed` (lifecycle options) | `manage` — annotated `via the code half's lifecycle reconciliation, never the generic state path` |
| `runtime` | `preserve local` |
| `derived` **and** a declared regenerator/native rebuild action or the engine's core rebuild pass covers it | `rebuild` |
| `derived` **and** no declared repair path (e.g. a surface listed in the claim's `unsupported[]` as outside the automatic verified guarantee) | `rebuild`, readiness forced to `Not qualified`, with the claim's own `unsupported[].reason` quoted verbatim |
| `env` | `rebind` |
| `external` with a declared re-sync action | `re-synchronize` |
| `unclassified`, or any surface whose containment is `unknown` for the requested operation | `block` |

`re-synchronize` requires a declared action; no generic one ships, so in
practice `external` surfaces project `block` until a manifest declares
otherwise. That is stated in the output, not hidden.

### 1.3 Technical readiness

Computed from one `AdapterRegistry::report()` call (named
`CapabilityRegistry::report()` when this was written) for the exact
`operation` × `surface` × `revision` × target probe.

| Registry evidence | Projected `readiness` |
|---|---|
| `verdict.status == certified` and no re-checkable target condition applies | `Ready` |
| `verdict.status == certified` and at least one condition is re-evaluated at the mutation gate (`plugin_version_mismatch`, `plugin_not_active` re-run against the live target; any `env_missing` plan row) | `Ready with conditions` (conditions listed by code) |
| blocker `evidence_not_current` | `Requalification required` |
| claim `status == experimental` | `Experimental` |
| blocker `adapter_source_uncertified`, `missing_disposition_entry` (out-of-tree), `surface_not_registered` | `Not qualified` |
| claim `status` in {`excluded`, `unsupported`}, blocker `surface_explicitly_unsupported` or `deletion_unsupported`, or the surface named in `deletion_semantics.unsupported` for a delete operation | `Unsupported` |
| a `Providers::diagnose()` negotiation problem (`missing_plugin`, `inactive_plugin`, `missing_plugin_provider`, `missing_capability`, `undeclared_provider`, `provider_code_unavailable`, `outside_version_range`, `identity_mismatch`, `contract_shape`, …) | `Ready with conditions` whose condition is **unmet** — blocks, and names the negotiation code |

The mutation-gate re-run in that second row is code, not a promise:
`AdapterRegistry::target_reasons()` attaches `subject`, `check` and `observed`
to each condition reason; `SurfaceCatalog::conditionRows()` mints the row
`{check, code, manifest, observed, rechecked_at, satisfied, subject}` from
them; the frozen authorization plan carries those rows and digests them as
`inputs_digest.conditions_sha256`; and `AuthorizationPlan::recheckConditions()`
re-observes every one against a fresh `wp wprism capabilities` read immediately
before the mutating call (`cli/src/Command/ReleaseCommand.php`, step 6). A
condition that MOVED refuses `release_condition_changed`; a condition that
cannot be re-observed at all — its claim absent from the fresh report, or the
row carrying no subject — refuses `release_condition_uncheckable`. Both carry
the failure class `capability_expired` and the next action `requalify`. An
uncheckable condition blocks; it is never treated as satisfied.

*Six codes in the rows above were written against the generated evidence record
and left with it (#477–#480); the table now names what the shipped vocabulary
actually holds, and `cli/src/Contract/ProjectionVocabulary.php:216-245` carries
the reason for each removal.* `wordpress_version_mismatch`,
`php_version_mismatch`, `database_version_mismatch` and `theme_version_mismatch`
were measured platform axes no document reports any more — `target_reasons()`
raises `plugin_version_mismatch` and `plugin_not_active` and nothing else
(`agent/src/Adapter/AdapterRegistry.php:559-579`). `revision_not_certified` and
`profile_evidence_not_current` left `BLOCKERS_REQUALIFICATION`, which now holds
exactly `evidence_not_current`, synthesized by
`ContractProjection::withStaleEvidence()` into the surfaces the observed drift
reaches — every surface when the moved subject cannot be attributed to a pinned
adapter, and only the surfaces that adapter governs when it can
(`ContractProjection::invalidation()`). `multisite_unsupported` left the
`Unsupported` row because topology is judged once, by `AssessCommand::assess()`
refusing the whole assessment, not per surface. `missing_registry_entry` is now
`missing_disposition_entry`. The `Experimental` row lost its second route for a
structural reason, not a cosmetic one: a claim's evidence is the citation its
reviewed disposition carries verbatim, and a citation has no status that could
be `candidate` (`sandbox/tests/offline/assess-contract/regress_assess_projection.php:207-213`).
That suite is the gate on this table, one fact vector per cell.

### 1.4 Certification provenance

| Source fact | Projected `certification_provenance` |
|---|---|
| `source.source == shipped` **and** claim `status == certified` | `Platform-certified`. The `evidence.status == current` conjunct this row also required is gone: a shipped claim now carries its authored citation with no status at all, so keeping the conjunct would report every platform-reviewed adapter as `Uncertified` (`cli/src/Contract/ProjectionVocabulary.php:788-794`, `:822`) |
| a site adapter with signed evidence **and** an explicit repository pin binding source `site` + certificate digest | `Site-certified` — **emitted since the adapter walk's §3.2 certification path landed** (this row once read "never emitted" while no operator could complete a certification; `wprism adapter certify` is that path). The projection also exposes `principal` and `trust_root`; the contract's attestation placeholder stays `unsigned` |
| everything else | `Uncertified` |

### 1.5 Effect containment

The contract projection has no runtime egress-control fact, so only one
containment value is structurally provable and only one is declarable.

| Condition | Projected `effect_containment` |
|---|---|
| the surface is mutated exclusively inside `apply`'s hook-free window (direct low-level writes; no WordPress hooks fire, therefore no mail/webhook/payment re-fire — DESIGN.md §3.4) **and** the plan touches no declared provider action for it | `prevented`, with the literal basis string `no WordPress hooks fire in the apply window` |
| anything in the lifecycle window (deploy → retire → activate → finalize, where hooks *do* fire), any declared provider action, any regenerator | `unknown — not enforced in this profile` |
| the reviewed contract declares a live external effect for this surface | `live` |
| — | `sandboxed` is **never emitted by this projection**. A rehearsal may carry a separate provider-bound runtime receipt, but that evidence does not rewrite a surface's reviewed contract facts |

### 1.6 Effect recovery semantics

| Condition | Projected `effect_recovery_semantics` |
|---|---|
| the surface's bytes are inside the selected recovery profile's covered inventory (database checkpoint, code release, upload bundle, effect bundle) | `provider-state restorable`, naming the exact bundle |
| no external effect exists for the operation | `not applicable` |
| a delete/tombstone with no restore coverage, or a surface in `deletion_semantics.unsupported` | `irreversible` |
| containment is `unknown` and an effect may exist | `unknown` — and the operation blocks per the spec |
| — | `compensatable` is **never emitted**: it would need a declared compensation action, and that mechanism does not exist |

**Consequence — the spec's hardest line, stated before it is designed around.**
*"Unknown containment or recovery semantics blocks any operation capable of
reaching a live system"* (product spec, *External-effect containment and
recovery semantics*; safety invariant *"unknown containment or recovery
semantics never reach live systems"*). The whole lifecycle window is declared
`unknown`, so a release whose plan contains a code lifecycle phase would be
blocked outright. It is not exempted; it is **declared**: `wprism release` refuses
until the contract carries an `external_effects[]` entry for that window naming
the surfaces, `containment: "live"`, an explicit recovery-semantics value, and a
reviewed reason. The declaration contains nothing — it converts an *unknown*
into a *known, bounded live effect*, which the spec permits "only with
plan-bound authority", i.e. the frozen plan's `authority_still_required`
confirmation. This is the one place the honesty rule costs the operator real
work, and it is deliberate: the alternative is a silent unknown reaching
production.

---

## Three things this vocabulary still does not claim

Said plainly, because the words above are easy to over-read:

1. **A rehearsal receipt does not rewrite contract facts.** Rehearse requires
   provider-proven server-side containment before restore, but §1.5 still
   never emits `sandboxed`: the assess row records reviewed surface facts, not
   the runtime environment in which evidence happens. Containment permits the
   evidence run; disposition and certification decide its authority.
2. **`Site-certified` is your organization's word, not WPrism's.** It is emitted
   only on a verified Ed25519 signature over an adapter's exact bytes under a
   trust root the repository or the agent owns. It means customer-organization
   approval, explicitly not a WPrism endorsement; the signed bundle records
   `exercised: false` beside its grammar verdict, so it never implies the
   adapter was tested against a live site. The **contract's** attestation is a
   separate signature under a separate, operator-provisioned trust root, and
   until one exists the human view says so on the same line: `certified by
   <principal> (<root> trust root); contract attestation unsigned`. After `wprism
   contract <env> attest` the same line reads `…; contract attested by
   <principal> (site trust root, expires <when>)`.
3. **Rollback restores bytes, not consequences.** The list of what a profile
   does *not* restore is printed before you authorize and again before you
   recover, and it is the literal truth — which is what `irreversible` in §1.6
   means when you read it.
