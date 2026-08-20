# Round 3 — the Minimum Usable Platform (MUP)

*Proposal — 2026-08-17. Owner-directed for round 3 of the dev-loop program.
Companion to the layout move (same round, separate train). The product
authority is [docs/product-spec.md](../product-spec.md); the normative wire
contract is [spec/repo-format.md](../../spec/repo-format.md); the boundary
doctrine is [docs/proposals/engine-adapter-boundary.md](engine-adapter-boundary.md).
Nothing here creates a capability claim — the adapter manifests plus the
hand-reviewed `manifests/dispositions.json`, projected by
`tools/capability-doc.php` into [docs/capabilities.md](../capabilities.md),
remain the only source of those, and `duo capabilities` is how the same
question is answered against a live target.*

> **Read this proposal as a round-3 record, not as current mechanism.** It was
> written on 2026-08-17 against the certification-evidence apparatus that the
> teardown train (#477–#480) then removed. Two things moved under it and are
> corrected inline below: `CapabilityRegistry` is now
> `AdapterRegistry` (`agent/src/Adapter/AdapterRegistry.php`, emitting
> `duo-capability-report/v1`) with the target probe split out into
> `TargetProbe::probe_target()`, and "the generated registry" as a source of
> claims is now the manifests + `manifests/dispositions.json` pair projected
> into the generated capability document. §7's certification-train process is
> retired outright and is bracketed there. Everything else — §0's scope, the
> §1 vocabulary tables, §2–§6's mechanism and acceptance — shipped and stands.

## 0. What MUP is, and what it deliberately is not

MUP is the smallest surface on which an agency operator can run the spec's
full customer loop end to end on one eligible single-site WooCommerce
installation:

```
assess -> rehearse -> capture/merge -> release + verify -> recover
```

plus a **composed per-site application contract** that the loop reads and
writes. Everything else the spec demands is explicitly deferred and named in
§7.

Three honesty rules govern every new surface:

1. **No new wire formats for existing surfaces.** `plan`, `capture`, `apply`,
   `promote`, `pending`, `coverage`, `capabilities` keep byte-identical
   output. MUP composes them and adds new documents with their own `format`
   keys.
2. **A projected word is never a new evidence word.** MUP prints the spec's
   product vocabulary, derived from shipped facts by the exactly-stated
   mapping in §1. It never mints a status word into `dispositions.json`, the
   registry, or a manifest. Where the shipped facts cannot support a spec
   word, MUP prints the honest degraded value and says why — most visibly
   `containment: unknown — not enforced in this profile`.
3. **Engine core still does not dispatch on plugin names.** Everything
   WooCommerce-specific in this document arrives as manifest declarations,
   registry surfaces, or reviewed contract declarations. `cli/src/Assess/`
   and `cli/src/Release/` contain no plugin slug.

---

## 1. The vocabulary projection (the load-bearing table)

The spec's six per-surface dimensions do not exist in code. They are
*projected* from data that does. This is the complete mapping; it is
implemented once, in `\Duo\Orchestrator\ProjectionVocabulary`
(`cli/src/Contract/ProjectionVocabulary.php`), and nowhere else. It lives in the
policy-layer Contract module, not in Assess, because Assess, Release and
Rehearse are all engine-layer siblings: policy is the lowest layer all three can
read, so one implementation costs zero intra-layer edges
([module map](../modules/cli-Contract.md), rule 9).

### 1.1 State class

| Source fact | Where it comes from | Projected `state_class` |
|---|---|---|
| `authored` | `Policy::CLASSES` | `authored` |
| `runtime` | `Policy::CLASSES` | `runtime` |
| `derived` | `Policy::CLASSES` | `derived` |
| `env` | `Policy::CLASSES` | `environment-bound` |
| `managed` | `Policy::CLASSES` (`active_plugins`, `template`, `stylesheet`) | `authored` — see handling below |
| no rule matches; `Capture::gate_scan()` aborts / `wp duo pending` row | `Pending::scan_read_only()` | `unclassified` |
| named in `Coverage::report()` as invisible (no discovery path at all) | `Coverage` | `unclassified` |
| a manifest declares a provider action whose declared effects reach a system outside this WordPress install | manifest `providers` + declared effects | `external` |

`external` is emitted **only** from a declaration. MUP never infers that a
surface is externally owned from observation; an unmodelled integration lands
in `unclassified` / `block`, which is the spec's required behaviour.

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

`re-synchronize` requires a declared action; MUP ships no generic one, so in
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
`ContractProjection::withStaleEvidence()`. `multisite_unsupported` left the
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
| a site adapter with signed evidence **and** an explicit repository pin binding source `site` + certificate digest | `Site-certified` — **emitted since round-3 T6 §3.2** (this row read "MUP never emits this" while no operator could complete a certification; `duo adapter certify` is that path). The projection also exposes `principal` and `trust_root`; the contract's attestation placeholder stays `unsigned` |
| everything else | `Uncertified` |

### 1.5 Effect containment

MUP has no egress control, so only one containment value is structurally
provable and only one is declarable.

| Condition | Projected `effect_containment` |
|---|---|
| the surface is mutated exclusively inside `apply`'s hook-free window (direct low-level writes; no WordPress hooks fire, therefore no mail/webhook/payment re-fire — DESIGN.md §3.4) **and** the plan touches no declared provider action for it | `prevented`, with the literal basis string `no WordPress hooks fire in the apply window` |
| anything in the lifecycle window (deploy → retire → activate → finalize, where hooks *do* fire), any declared provider action, any regenerator | `unknown — not enforced in this profile` |
| the reviewed contract declares a live external effect for this surface | `live` |
| — | `sandboxed` is **never emitted by MUP** (§7) |

### 1.6 Effect recovery semantics

| Condition | Projected `effect_recovery_semantics` |
|---|---|
| the surface's bytes are inside the selected recovery profile's covered inventory (database checkpoint, code release, upload bundle, effect bundle) | `provider-state restorable`, naming the exact bundle |
| no external effect exists for the operation | `not applicable` |
| a delete/tombstone with no restore coverage, or a surface in `deletion_semantics.unsupported` | `irreversible` |
| containment is `unknown` and an effect may exist | `unknown` — and the operation blocks per the spec |
| — | `compensatable` is **never emitted by MUP** (needs a declared compensation action; §7) |

**Consequence — the spec's hardest line, stated before it is designed around.**
*"Unknown containment or recovery semantics blocks any operation capable of
reaching a live system"* (product spec, *External-effect containment and
recovery semantics*; safety invariant *"unknown containment or recovery
semantics never reach live systems"*). MUP declares the whole lifecycle window
`unknown`, so a release whose plan contains a code lifecycle phase would be
blocked outright. It is not exempted; it is **declared**: `duo release` refuses
until the contract carries an `external_effects[]` entry for that window naming
the surfaces, `containment: "live"`, an explicit recovery-semantics value, and a
reviewed reason. The declaration contains nothing — it converts an *unknown*
into a *known, bounded live effect*, which the spec permits "only with
plan-bound authority", i.e. the frozen plan's `authority_still_required`
confirmation. Absent the declaration the refusal's next action is `declare in
contract`. This is the one place MUP's honesty rule costs the operator real
work, and it is deliberate: the alternative is a silent unknown reaching
production.

---

## 2. Command surface

Six host verbs are added — `assess`, `rehearse`, `release`, `verify`,
`recover`, `contract` — plus one new agent command (§4.5). Every one supports `--format=json`
returning a document with its own `format` key, canonicalised through
`\Duo\Canon`; every human view is a projection of the same document.

### 2.1 `duo assess <env>` — decision-first, read-only

Composes, in one run, with no mutation: `Doctor::run()` → adopt/init probe
(`BootstrapEligibility` + `wp duo init --dry-run` proposal read) →
`wp duo assess-inventory` (new, §3.4) → `AdapterRegistry::report()` per
requested operation → `Coverage::report()` → `Pending::scan_read_only()` →
`AdapterCatalog::run()` + `wp duo adapter-survey` (the third, plugin-bundled
adapter source is only reachable on the target).

Sections, in this order:

1. **Stack inventory** — WordPress/PHP/database versions, single-site check,
   active plugins and themes with versions, media count and storage, the
   transport and the authority Duo actually has.
2. **Per-surface table** — one row per *WordPress-language surface*
   (products, product categories/attributes, pages, posts, media, menus,
   forms, settings, plugins/themes, orders, sessions, customers, coupons,
   shipping/tax, custom tables), each carrying the six dimensions of §1 plus
   a one-line `meaning` sentence.
3. **Unknown / unclassified** — the `pending` queue and `Coverage`'s invisible
   names, counted and named (never valued), bounded (§4.4).
4. **Smallest safe next action per gap** — one action per row, drawn from a
   closed set: `classify`, `declare in contract`, `qualify in rehearsal`,
   `exclude`, `provision env value`, `install adapter`, `nothing — supported`.
   *(Round-3 T6 §3.6 adds `certify adapter` and stops emitting `qualify in
   rehearsal`, which rehearsal states it cannot do; the word stays in the set
   so an older stored projection still validates.)*
5. **Proposed application contract** — written to
   `.duo/contract/proposed.json`, never authoritative (§3 of the spec: a
   declaration cannot certify itself).

Surface rows are *derived from data*, never from a plugin name: the surface
label comes from the registry claim's `surfaces[]` and the manifest's declared
entity/option/table groups, projected through the contract's optional
`surface_labels` map. Engine core learns no slug.

```
$ duo assess production
stack: WordPress 7.0.3 · PHP 8.3.33 · MariaDB 11.8.8 · single-site
authority: ssh deploy@prod · read-only for this command · repo /srv/site

surface            class          handling        readiness              certification       containment  recovery
products           authored       manage          Ready                  Platform-certified  prevented    provider-state restorable
pages              authored       manage          Ready                  Platform-certified  prevented    provider-state restorable
store settings     authored       manage          Ready with conditions  Platform-certified  prevented    provider-state restorable
  condition: woocommerce 10.4.2 must stay inside 10.0.0-11.0.0 at the mutation gate
payment keys       environment-b  rebind          Ready with conditions  Platform-certified  unknown      not applicable
  condition: env_missing woocommerce_stripe_secret - duo env-set production --name=... --stdin
orders             runtime        preserve local  Unsupported            Platform-certified  prevented    not applicable
  meaning: live operational state is never copied
product lookup     derived        rebuild         Not qualified          Platform-certified  unknown      not applicable
  reason (registry): no bounded independent value oracle exists for this table
custom catalog tbl unclassified   block           Not qualified          Uncertified         unknown      unknown
  next action: qualify in rehearsal (duo rehearse preview --from production)

unknown: 41 option names invisible to every installed adapter (see --format=json)
         3 pending classifications (duo pending production)
proposed contract written: .duo/contract/proposed.json (accept with duo contract production accept)
```

Exit status: `0` when every requested operation has at least one Ready
surface and nothing blocked the assessment itself; `1` when the assessment
itself is a structured refusal (missing access, unsupported topology,
multisite) — a bounded assessment with blocked surfaces is a **success**, per
the spec's "assessment is not a completeness claim".

### 2.2 `duo rehearse <env> --from <production-env> [--branch <ref>] [--reap]`

Thin composition over the shipped lifecycle: `EnvironmentCommand::run()` →
`CommandEnvironmentProvider` (capability-negotiated, machine-local
`.duo-envs.json` only) → `EnvironmentMaterializer` → `deploy` → `apply`.

- Requires `snapshot.set.*`, `environment.attach|create`,
  `repository.materialize`, `environment.url.discover`, `operation.receipts`.
  Missing capability is a refusal with the capability id, never emulation.
- `--branch <ref>` selects the code+state ref materialized into the
  disposable environment.
- After convergence, prints **what a release would touch**: the same
  `PlanCategorySummary` categories the release plan will use, plus the surface
  rows from §2.1 restricted to the plan's actual scope. This is the rehearsal
  half of the spec's "expose the exact code, authored state, generated state,
  and effects a release would touch".
- `--reap` is `env reap` with the same exact resource/lease/ownership compare
  (idempotent; stale identity refuses).
- **Honest gap:** MUP does not strip/rebind production credentials, does not
  default-deny outbound HTTP/mail/payment/webhook, and does not verify
  containment before exercising workflows. `duo rehearse` prints, once, at
  the top of its report:
  `containment: unknown — not enforced in this profile; do not point this
  environment at live payment or mail credentials.`
  Consequently MUP's rehearsal **cannot** authorize an Experimental or
  Uncertified capability (the spec permits that only after containment is
  proven). Rehearsal in MUP is a preview and evidence-gathering environment,
  not a qualification environment.
- Reference provider: `tools/reference-env-provider.php` (free zone, dev-only)
  drives the `sandbox/bin/pair.sh` pair — snapshot prepare/create/restore via
  the shared MariaDB, `environment.attach`, `repository.materialize` via git,
  `environment.url.discover` from the pair's port map. It is derived from the
  proven `sandbox/tests/fixtures/duo3324-live-provider.php`. Customers supply
  their own: Duo orchestrates providers, it does not supply hosting.

### 2.3 `duo release <env> [--from <ref>] [--plan-only] [--profile=<p>] [--format=json]`

The composed release. Order is fixed:

1. **Freeze the authorization plan** (§2.3.1) and print it *before any
   mutation*. Persist it at `.duo/releases/<plan_digest>.json` — the spec's
   "durably bind and present".
2. **Bind the ref.** `--from <ref>` is a *binding assertion*, not a git
   transport: release resolves the ref locally, reads the target repo
   `HEAD`, and refuses on mismatch with next action `reconcile` and the
   literal command to run. MUP invents no code-shipping path that `promote`
   and the code-release provider do not already own.
3. **Confirm authorization.** Interactive confirmation, or `--yes`. Any
   difference between the frozen plan and a re-computed plan invalidates the
   authorization and refuses (`plan_changed`).
4. **Execute** through the existing `promote` machinery unchanged —
   deploy-before-apply, target fencing, lease, checkpoint, the
   `VerifiedRollbackProfile` / `ScopedRollbackProfile` selection.
5. **Verify** by invoking `duo verify <env>` (§2.4) with the plan digest.

`--profile` may only *strengthen* silently. Selecting a profile weaker than
the one the target can prove — including `none` — requires the explicit
`--accept-weaker-recovery` flag *and* a typed acknowledgement, per the spec's
"choose `none` or a weaker recovery profile than policy requires" human
authority. `DUO-3310`'s auto-selection (verified-rollback when the target
proves the capabilities) remains the default and is stated in the plan.

**Refusals before step 1** (no mutation, no frozen plan): the plan's scope
contains a surface projecting `Experimental`, `Not qualified`, `Unsupported` or
`Requalification required` — the spec makes Experimental unable to authorize
production "including through a conditional path", and the safety invariants
demand zero unsupported Ready claims; or a code lifecycle phase with no declared
external effect for the lifecycle window (§1.6); or `effect_recovery_semantics`
`unknown` for any surface the plan mutates. A **pre-authorization** refusal
carries a gap action from §2.1's closed set (`qualify in rehearsal`, `declare in
contract`, `exclude`, `install adapter`, `provision env value`, `classify`) —
the fix is an assessment fix, and the two closed sets are not interchangeable.
Only a failure **after** the plan is frozen carries a release next action.

Failure returns exactly one documented next action from the closed set
`resume | reconcile | retry | recover | requalify | escalate`, wired to the
existing receipt semantics: an `incomplete_lifecycle` receipt is always
`recover`; an ambiguous commitment is always `reconcile` (never `retry`); a
clean pre-mutation refusal is `retry` or `requalify`.

#### 2.3.1 The authorization plan

```json
{
  "format": "duo-authorization-plan/v1",
  "plan_digest": "sha256:7b1c…",
  "frozen_at": "2026-08-17T09:14:02Z",
  "environment": "production",
  "contract_digest": "sha256:2f90…",
  "artifact_hash": "sha256:9ac4…",
  "code_revision_from": "e2f1a09",
  "scope": {
    "surfaces": ["products", "pages", "product images"],
    "entities": {"create": 2, "update": 7, "delete": 0},
    "code": {"plugins_changed": 0, "themes_changed": 1, "lifecycle_phases": ["retire", "activate", "verify"]}
  },
  "capabilities": [
    {"name": "woocommerce", "operation": "promote", "readiness": "Ready with conditions",
     "certification_provenance": "Platform-certified",
     "conditions": [
       {"code": "plugin_version_mismatch", "check": "woocommerce in 10.0.0–11.0.0",
        "observed": "10.4.2", "rechecked_at": "mutation gate"}
     ]},
    {"name": "core", "operation": "promote", "readiness": "Ready",
     "certification_provenance": "Platform-certified", "conditions": []}
  ],
  "may_change": {
    "code": ["wp-content/themes/storefront-child"],
    "authored_state": ["products", "pages", "product images"],
    "runtime_adjacent": ["wc_product_meta_lookup (rebuild)", "taxonomy counts (rebuild)",
                         "object cache (flush)"],
    "external": []
  },
  "recovery_profile": {
    "selected": "verified-automatic",
    "selected_because": "target proved rollback signing key, recovery executor, checkpoint, code-release, upload and effect providers, verified_rollback policy",
    "restores": ["database checkpoint", "code release", "upload bundle", "effect bundle"],
    "does_not_restore": [
      "emails already sent", "payment captures or refunds already made",
      "webhooks already delivered", "third-party systems that observed the change",
      "orders and sessions written by live traffic after the checkpoint"
    ],
    "writer_exclusion": "external maintenance window required before any operator-directed import",
    "maximum_loss_boundary": "writes committed after checkpoint 2026-08-17T09:14:02Z"
  },
  "effects": {
    "containment": "prevented",
    "containment_basis": "no WordPress hooks fire in the apply window",
    "lifecycle_window": {
      "containment": "live",
      "declared_in": "contract.declarations.external_effects[0]",
      "note": "plugin/theme activation hooks run with normal WordPress semantics; MUP enforces nothing here",
      "reviewed_reason": "storefront-child activation runs no mail, payment or webhook code (reviewed 2026-08-17)",
      "effect_recovery_semantics": "provider-state restorable",
      "restored_by": "code release"
    },
    "known_irreversible": [],
    "unknown_blocking": []
  },
  "authority_still_required": [
    {"kind": "operator_confirmation", "reason": "production-visible mutation"},
    {"kind": "business_owner", "reason": "storefront pages visible to customers change"},
    {"kind": "declared_live_effect", "reason": "lifecycle window declared live in the contract; plan-bound authority required"}
  ]
}
```

The human rendering of that document is a page of WordPress language ending
in a single question. `--plan-only` prints it and exits 0 without mutating.

### 2.4 `duo verify <env> [--plan=<digest>] [--format=json]`

Post-release verification, two independent parts, both required for a pass:

1. **Convergence** — the existing internal `wp duo verify-canonical` path
   (`\Duo\ConvergenceVerifier`): a fresh re-read proving every entity in the
   immutable compiled tree landed byte-semantically. Absence of an error is
   never the proof.
2. **Declared affected-journey oracles** — minimal and contract-declared:
   HTTP probes of URLs named in the contract's `journeys[]`, each with an
   expected status and an expected substring, plus optional
   `render_contains` checks against a manifest-declared render path. Host-side
   only; no new agent code. An undeclared journey is not silently skipped —
   `verify` reports `journeys: 0 declared` and, when the plan's scope touched
   a surface with no journey declared, prints
   `verification is byte-level only for <surface>; declare a journey in the
   contract to make this a business check` (the spec's "round-trip equality is
   necessary but not sufficient").

```json
{"format":"duo-verify-report/v1","environment":"production","plan_digest":"sha256:7b1c…",
 "convergence":{"status":"pass","entities":213,"mismatched":0},
 "journeys":[{"id":"shop-index","url":"/shop/","expect_status":200,
              "expect_contains":"Ceramic Mug","status":"pass"},
             {"id":"product-detail","url":"/product/ceramic-mug/","expect_status":200,
              "expect_contains":"£24.00","status":"pass"}],
 "uncovered_surfaces":["store settings"],
 "verdict":"pass"}
```

### 2.5 `duo recover <env> [--list] [--restore=<checkpoint>] [--format=json]`

The operator verb that replaces raw invocation of
`recovery/rollback-control.php`. It is a thin, *literal* front end over
`RollbackAuthority` / `VerifiedRollbackProfile::rollback()` /
`ScopedRollbackProfile::rollback()` and the runtime's own actions
(`authority-status`, `active-evidence`, `audit`, `status`,
`checkpoint-request`, `code-release-request`, `upload-bundle-request`,
`effect-bundle-request`, `execute`, `exclusion-request`).

- `--list` prints the checkpoint/receipt catalog: receipt id, state
  (`prepared`/`promoting`/`verifying_new`/`committed`/`rollback_pending`/
  `rolling_back`/`verifying_prior`/`rolled_back`), owner, artifact hash,
  covered inventory, age.
  - **T5 addendum — two sources.** The signed receipt above exists only on an
    SSH-adopted target. The plain database checkpoint every operator-directed
    release *retains* under `.duo/checkpoints/promote-<owner>.sql` (what
    `promote` prints as `database checkpoint retained:`) is the second source,
    on every transport (`RetainedCheckpoints`, kind
    `retained-release-checkpoint`, state `retained`, id `promote-<owner>`,
    lease identity read from the retained artifact `.duo/artifacts/promote-<owner>.json`).
    It is restored through the same four operator-directed steps. Without it,
    the `operator-directed` claim a frozen plan prints on a local/docker
    target (`restores: database checkpoint`) named a resource no verb could
    reach — §6.1 step 11 found that live. Only a *signed* rollback stays
    SSH-only (`recovery_authority_unavailable`).
- `--restore=<id>` performs the profile's own rollback and, for the
  operator-directed profile, *drives* the four ordered steps the guide
  currently asks a human to type (abort → begin → isolated import → final
  abort), including the mandatory final abort even when the import fails, and
  refuses to start until external writer exclusion is asserted with
  `--writers-excluded` (the checkpoint contains the lease row; a lock inside
  the database being imported cannot protect the window).
- Every run prints the **recovery claim** verbatim before acting — the same
  `restores` / `does_not_restore` lists from the plan, so the claim the
  operator saw at authorization is the claim they see at recovery.
- Code-first ordering is enforced, not advised: if the failure was after a
  code phase, `recover` refuses a database import until code is reconciled to
  the pre-release revision, naming the exact revision.

### 2.6 `duo contract <env> show|propose|accept`

- `propose` — regenerates `.duo/contract/proposed.json` from a fresh assess.
- `show` — renders the accepted contract plus its generated projection.
- `accept` — validates the proposal, checks it still matches the site it was
  proposed from (an `assess_digest` bind), writes
  `.duo/contract/contract.json` + `.duo/contract/projection.json`, and stages
  them for commit. Accept refuses a stale proposal rather than reconciling it.

### 2.7 Existing verbs: capture / refresh / rebase — the version+merge step

These remain the version-and-merge step **unchanged**. Confirmations:

- **Conflict presentation by WordPress identity already exists.**
  `PlanSummary::render()` prints the versioned hash-only
  `conflict_view` (base/repository/target evidence + bounded choice model) with
  the row's `path`/`title`, and `duo explain <bucket>:<entity-key>` is
  `\Duo\PlanExplanation` — a value-free per-entity trace from canonical source
  through the winning policy/manifest rule, dependency edges, rebuild surfaces
  and the convergence verifier. No new conflict surface is needed.
- **One small addition, host-side only:** `duo status` and `duo explain`
  human output gain a `surface:` line resolved through the contract's
  `surface_labels`, so a conflict reads "products / Ceramic Mug / price"
  rather than a bucket name. This is a renderer change in `cli/src`; the
  agent JSON is untouched.
- **`refresh`/`rebase`** (the B/P/W resolver, `RefreshPlan`) are unchanged.
  MUP documents them as the merge step for a drifted production.
- **No change to `capture`.**

---

## 3. The application contract as a concrete artifact

### 3.1 Location

In the site repository, committed except where noted:

```
.duo/
  contract/
    contract.json      # part 1 + part 2 pins + part 3 placeholder + part 4 names   (committed)
    proposed.json      # assess output, never authoritative                          (committed or not, operator's choice)
    projection.json    # part 5, generated, regenerated by assess/contract show      (committed — it is a review artifact)
  releases/
    <plan_digest>.json # frozen authorization plans                                  (committed)
```

Environment binding **values** never appear here: they stay in
`.duo-envs.json` (machine-local registry / privileged provider config) and
`.duo-env-values.json` (per-environment provisioned values, which
`Doctor::run()` already fails when git-tracked).

All four documents are canonical JSON through `\Duo\Canon` (sorted keys,
pretty, LF) so they diff and merge like the rest of `state/`.

### 3.2 `contract.json`

*Shipped as **`duo-application-contract/v2`** — the version bumped after this was
written. v2 narrows `evidence_pins` to the two facts that still exist (the
content address of the reviewed dispositions a verdict was read from, and the
proposing host's own copy of that file); v1 additionally pinned a per-subject
`bundles[]` and two generator-input hashes belonging to the generated capability
registry and its evidence record, and pins nothing can re-observe are worse than
no pins. `ApplicationContract` refuses a v1 document by name rather than
migrating it (`cli/src/Contract/ApplicationContract.php:17-31`, `:370-390`). The
example below carries the v2 `evidence_pins` shape; the `format` string is left
at `v1` as the proposal wrote it.*

```json
{
  "format": "duo-application-contract/v1",
  "site": {"name": "example-shop", "spec_version": 2},
  "declarations": {
    "stack": {
      "wordpress": {"min": "6.8.0", "max": "7.1.0"},
      "php": {"min": "8.3.0", "max": "8.4.0"},
      "database": {"engine": "MariaDB", "min": "11.0.0", "max": "12.0.0"},
      "site_mode": "single-site",
      "plugins": [{"slug": "woocommerce", "min": "10.0.0", "max": "11.0.0"}]
    },
    "manifest_pins": [
      {"name": "core", "source": "shipped", "adapter_digest": "sha256:4d1e…"},
      {"name": "woocommerce", "source": "shipped", "adapter_digest": "sha256:c07a…"}],
    "surfaces": [
      {"id": "products", "label": "Products", "state_class": "authored", "handling": "manage",
       "operations": ["capture", "merge", "release", "verify"], "identity": "post uuid",
       "decided_by": "operator", "decided_at": "2026-08-17T09:02:11Z"},
      {"id": "orders", "label": "Orders", "state_class": "runtime", "handling": "preserve local",
       "operations": [], "decided_by": "platform-default"},
      {"id": "acme_catalog", "label": "Custom catalog table", "state_class": "unclassified",
       "handling": "block", "operations": [], "next_action": "qualify in rehearsal",
       "decided_by": "unresolved"}
    ],
    "external_effects": [
      {"id": "code-lifecycle-window", "surfaces": ["plugins/themes"],
       "containment": "live",
       "effect_recovery_semantics": "provider-state restorable", "restored_by": "code release",
       "reason": "activation/deactivation hooks run with normal WordPress semantics and Duo enforces no egress control in this profile; reviewed for this site's installed set",
       "decided_by": "operator", "decided_at": "2026-08-17T09:02:11Z"}
    ],
    "journeys": [
      {"id": "shop-index", "url": "/shop/", "expect_status": 200,
       "expect_contains": "Ceramic Mug", "affected_surfaces": ["products"]}
    ],
    "unsupported": [
      {"surface": "products", "operation": "delete",
       "reason": "the open extension graph is not enumerable; deletion refuses before repository mutation"}
    ]
  },
  "evidence_pins": {
    "registry_sha256": "sha256:8fa1…",
    "generated_from": {"dispositions_sha256": "sha256:9476…"}
  },
  "attestation": {
    "format": "duo-contract-attestation/v1",
    "state": "unsigned",
    "reason": "the certification gate is deferred for the minimum usable platform",
    "approving_principal": null, "policy_version": null, "signature": null, "expires_at": null
  },
  "environment_bindings": {
    "required": [
      {"name": "siteurl", "class": "env", "bound_in": ".duo-env-values.json"},
      {"name": "woocommerce_stripe_secret", "class": "env", "sensitivity": "secret",
       "bound_in": ".duo-env-values.json"}]
  },
  "contract_digest": "sha256:2f90…"
}
```

`contract_digest` is the SHA-256 of the canonical encoding of everything above
it. `attestation.state` is a closed enum `unsigned | signed`; MUP only ever
writes `unsigned`, and every projection derived from an `unsigned` contract
reports `certification_provenance: Uncertified` for any site-scoped claim.
That is the single most important honesty property of the whole round.

### 3.3 `projection.json` (part 5)

The generated per-site capability view — the same rows `duo assess` prints,
in machine form, with the vocabulary of §1 and nothing else:

```json
{"format":"duo-site-capability-projection/v1",
 "contract_digest":"sha256:2f90…","registry_sha256":"sha256:8fa1…",
 "generated_at":"2026-08-17T09:14:02Z","target_probe":{"wordpress":"7.0.3","php":"8.3.33"},
 "surfaces":[
   {"id":"products","label":"Products","state_class":"authored","handling":"manage",
    "operations":{"release":{"readiness":"Ready","certification_provenance":"Platform-certified",
      "effect_containment":"prevented","effect_containment_basis":"no WordPress hooks fire in the apply window",
      "effect_recovery_semantics":"provider-state restorable","conditions":[],
      "expiry_and_dependencies":["woocommerce 10.0.0–11.0.0","wordpress 7.0.3","php 8.3.x","MariaDB 11.x"],
      "remediation":null}}},
   {"id":"acme_catalog","label":"Custom catalog table","state_class":"unclassified","handling":"block",
    "operations":{"release":{"readiness":"Not qualified","certification_provenance":"Uncertified",
      "effect_containment":"unknown","effect_recovery_semantics":"unknown",
      "conditions":[],"remediation":"qualify in rehearsal, or declare it out of scope in the contract"}}}]}
```

### 3.4 Lifecycle

| Step | What happens |
|---|---|
| generate | `duo assess` composes proposal from live facts → `proposed.json` |
| review | a human edits `declarations.surfaces[].handling` / `journeys[]` / `unsupported[]` |
| accept | `duo contract <env> accept` validates, binds `assess_digest`, writes `contract.json` + `projection.json` |
| refresh | any `assess`, `status`, or `release` regenerates `projection.json` from current facts; a mismatch against pinned evidence flips affected surfaces to `Requalification required` |
| consume | `duo release` cites `contract_digest` in the authorization plan; `duo verify` reads `journeys[]`; `duo recover` reads `external_effects[]` for its does-not-restore list |

A declaration never grants authority: readiness is always recomputed from the
registry + live probe, never read from `contract.json`.

---

## 4. Module homes and class responsibilities

Consistent with the round-3 layout move: directories per module, namespaces
stay flat (`namespace Duo;` / `namespace Duo\Orchestrator;`). The classmap
maps FQCN → path, so directory ≠ namespace is legal and intended.

**Placement rule** ([module map](../modules/README.md) rule 9): a verb boundary
class lives in the surface module `cli/src/Command/`, next to `PromoteCommand`
and `StatusCommand`, and composes engine modules downward. The new modules hold
mechanism only. That is not a style preference — it is what keeps
`cli/src/{Assess,Contract,Rehearse,Release}/` free of intra-layer and upward
edges, so the map's "zero new layer debt" claim survives round 3. Concretely:
`AssessCommand` calls `Doctor` (Onboarding), the environment `Registry`
(Environment) and `AdapterCatalog` (Adapter) and hands their results to
`StackInventory`/`SurfaceCatalog`; `ReleaseCommand` calls
`RecoveryProfileSelection` (Recovery) and passes the resulting claim into
`AuthorizationPlan` as a canonical array; `RehearseCommand` drives
`EnvironmentLifecycle`.

New files in `cli/src/Command/` (T2 then T3): `AssessCommand.php`,
`ContractCommand.php`, `ReleaseCommand.php`, `VerifyCommand.php`,
`RecoverCommand.php`, `RehearseCommand.php`.

### 4.1 `cli/src/Assess/`

| File | Class | Responsibility |
|---|---|---|
| `StackInventory.php` | `StackInventory` | stack + authority facts from doctor/driver/probe (handed in by `AssessCommand`) |
| `SurfaceCatalog.php` | `SurfaceCatalog` | surface rows from registry `surfaces[]` + manifest groups + contract `surface_labels`. **No plugin slug appears here.** |
| `GapActions.php` | `GapActions` | the closed gap-action set, one per gap |
| `AssessReport.php` | `AssessReport` | the `duo-assess-report/v1` document + digest |
| `AssessRenderer.php` | `AssessRenderer` | human projection, bounded output |

The verb boundary is `cli/src/Command/AssessCommand.php` (flag grammar,
composition order, exit status); `ProjectionVocabulary` is in Contract (§4.2).

### 4.2 `cli/src/Contract/`

`ApplicationContract` (parse/validate/digest), `ContractStore` (atomic
read/write under `.duo/contract/`, refuses overwrite of a newer digest),
`ContractProposal` (assess → proposal, `assess_digest` bind),
`ContractProjection` (part 5 generation), and `ProjectionVocabulary` — the §1
mapping and only that, pure and offline-testable, here rather than in Assess
because policy is the lowest layer Assess, Release and Rehearse can all read.
Verb boundary: `cli/src/Command/ContractCommand.php`.

### 4.3 `cli/src/Release/`

`AuthorizationPlan` (build + freeze + digest + re-verify),
`AuthorizationPlanRenderer`, `ReleaseOutcome` + `NextAction` (the closed
next-action set), `JourneyOracle`.

Three classes go to `cli/src/Recovery/` instead, next to the profiles they wrap:
`RecoveryProfileSelection` (wraps `VerifiedRollbackProfile::select()` /
`ScopedRollbackProfile::select()` and produces the restores/does-not-restore
claim), `CheckpointCatalog`, `RecoveryClaim`. The claim reaches
`AuthorizationPlan` as a canonical array, so Release references no Recovery
class. Verb boundaries: `cli/src/Command/{ReleaseCommand,VerifyCommand,RecoverCommand}.php`.

### 4.4 `cli/src/Rehearse/`

`RehearsalPlanPreview` (what a release would touch), `RehearsalDisclosure`
(the containment-unknown banner). Verb boundary:
`cli/src/Command/RehearseCommand.php`, which drives `EnvironmentLifecycle`.

### 4.5 `agent/src/Assess/`

One new agent command only, to keep the drop-in's re-cert surface minimal:

| File | Class | Responsibility |
|---|---|---|
| `AssessInventory.php` | `\Duo\AssessInventory` | one read-only pass returning `duo-assess-inventory/v1`: stack probe (`TargetProbe::probe_target()`, `agent/src/Adapter/TargetProbe.php:22`), active plugins/themes, `Coverage::report()`, `Pending::scan_read_only()`, policy surface groups, and the adapter-survey source block. Names and counts only — no option values, no row contents, exactly `Coverage`'s existing discipline. |

Registered on `\Duo\Cli` (`agent/src/Command/Cli.php` after the move) as
`@subcommand assess-inventory`. It calls existing classes; it introduces no new
planner, no new gate, and never writes. The no-plugin-slug grep gate covers
`agent/src/Assess/` as well as `cli/src/Assess/` — the boundary doctrine's rule
("supporting another plugin must not require adding its name, schema, or
business rules to engine core") binds the drop-in first.

### 4.6 Bounded output rule (applies to all new renderers)

Every new human renderer bounds its listings the way `PlanView` already does:
default 50 rows per section, `--limit=1..200`, a `N more (use --format=json)`
tail line, and `Coverage::LARGE_LISTING_THRESHOLD`-style warnings retained.
No new command may print an unbounded list.

---

## 5. Phase-A leak closures included in MUP

### 5.1 Undocumented `wp duo` commands

| Command | MUP disposition |
|---|---|
| `verify-canonical` | **surfaced** as `duo verify <env>` (host verb); the agent command is documented as internal |
| `orphans` | **documented internal**; its findings are surfaced inside `duo assess`'s unknown section |
| `journal-report` | **documented internal**; evidence surfaced inside `duo assess` (classification evidence) |
| `journal-reset` | **documented internal**, operator-maintenance only, named in the guides with its consequence |
| `promotion-begin`, `promotion-begin-scoped`, `promotion-complete-scoped`, `promotion-abort` | **documented internal** — `duo release` and `duo recover` drive them; an operator never types them |
| `code-preflight`, `code-stage`, `code-finalize` | **documented internal** — `duo deploy` / `duo release` drive them |
| `identity-export`, `identity-import` | **documented internal** — environment cloning internals |
| `refresh-export` | **documented internal** — `duo refresh` drives it |
| `policy-to-manifest`, `manifest-pin` | **documented authoring internals**, already reachable through `duo adapter-draft` / manifest workflows; documented in `docs/guides/adapter-authoring.md` |
| `adapter-survey` | **already documented** in `duo adapter list`'s help as the third adapter source; `duo assess` now runs it automatically |

"Documented internal" means: named in a single `docs/guides/internals.md`
table with the sentence *"`duo` never needs this; running it directly is
outside the supported workflow"*. That satisfies the spec's "normal operation
never depends on private identifiers, undocumented commands, or raw database
surgery" without pretending they do not exist.

### 5.2 Internal-ID leaks

`PlanView` already bounds and de-values `duo status`. Remaining MUP rule: a
human view may print an internal identifier **only** when a documented
command consumes it. Concretely — `duo explain` keeps its `<bucket>:<uuid>`
selector (documented, consumed); `duo recover --list` prints receipt ids
(consumed by `--restore`); `duo release` prints `plan_digest` (consumed by
`duo verify --plan=`). Artifact hashes, lease owners, operation ids and
session ids move to `--format=json` only. `regress_mup_leak_audit.sh` (§6.2)
makes that mechanical.

### 5.3 Raw recovery

`recovery/rollback-control.php` stops being an operator entry point. The
guides' four-ordered-commands recipe is replaced by
`duo recover <env> --restore=<id> --writers-excluded`. The runtime is
unchanged; only who types it changes.

### 5.4 Guides and `check_guide_commands.sh`

- New: `docs/guides/assess.md`, `docs/guides/release.md`,
  `docs/guides/recovery.md`, `docs/guides/internals.md`.
- Updated: `quickstart.md` leads with `duo assess` before `adopt`/`init`;
  `daily-workflow.md`'s *Promote* and *Recover* sections are rewritten around
  `duo release` / `duo recover` (keeping `duo promote` documented as the
  lower-level verb); `capabilities-and-limits.md` gains the §1 projection
  table and the containment-unknown statement.
- `check_guide_commands.sh` resolves `duo <verb>` against `cli/duo`'s dispatch
  list and `wp duo <cmd>` against `Cli.php` public methods. Therefore **guide
  prose citing a new verb must land in the same train as the verb**, or carry
  the literal `**Planned (DUO-NNNN)**` label at that line. Documenting the
  internals costs nothing — they already resolve.

---

## 6. Acceptance

### 6.1 The end-to-end grind: `sandbox/tests/grind/grind_mup.sh` (`make grind-mup`)

Reuses `sandbox/conformance/run.sh`'s proven shape — `pair.sh reset` +
`pair.sh up`, side 1 as author, side 2 as target, a bare origin and two
clones — extended into the full loop. Dedicated pair `mup` with explicit
ports; never touches other agents' pairs.

| # | Step | Assertion |
|---|---|---|
| 1 | `pair.sh reset mup` + `up mup <p1> <p2>`; install WooCommerce + Storefront on both; seed a catalog on mup1 | pair healthy |
| 2 | `duo adopt mup1`; `duo init mup1` | baseline clean; `duo status mup1` clean |
| 3 | `duo assess mup1 --format=json` | document validates; `products` is `authored/manage/Ready/Platform-certified/prevented`; `orders` is `runtime/preserve local/Unsupported`; every unclassified row carries a next action; **no UUID in the human view** |
| 4 | `duo contract mup1 propose`, review the proposed `external_effects[]` code-lifecycle entry, then `accept` | `contract.json` + `projection.json` written; `contract_digest` stable across two runs; `attestation.state == "unsigned"`; the lifecycle window is declared `live` (§1.6) or step 9 must refuse |
| 5 | `duo rehearse preview --from mup1 --branch main` (mup2 is the preview side, driven by `tools/reference-env-provider.php`) | provider capabilities negotiated; preview converged; "what a release would touch" matches the plan's categories; containment banner present |
| 6 | edit one product price and one page body **on the preview**; `duo capture preview` | capture deterministic (capture twice, zero diff) |
| 7 | `git commit` + merge to `main` in the origin | ordinary git |
| 8 | `duo release mup2 --from main --plan-only` | authorization plan validates against `duo-authorization-plan/v1`; cites `contract_digest`; lists `does_not_restore`; names the recovery profile and *why* |
| 9 | `duo release mup2 --from main --yes` | deploy-before-apply ordering observed in receipts; exit 0 |
| 10 | `duo verify mup2` | convergence `pass`; both declared journeys `pass`; `uncovered_surfaces` reported |
| 11 | mutate an "order" row on mup2 *after* the checkpoint; `duo recover mup2 --list` then `--restore=<id> --writers-excluded` | recovery claim printed verbatim before acting; product/page restored to pre-release; **the post-checkpoint order row's fate matches the printed `maximum_loss_boundary` exactly** — the claim is literal or the test fails. (T5: the listed id is the retained checkpoint the step-9 release printed, restored on the pair's docker transport.) |
| 12 | `duo assess mup2` | post-recovery projection matches pre-release projection |
| 13 | `duo rehearse preview --reap` | receipt says `destroyed` or `detached`; repeated reap idempotent |

Step 11 is the point of the grind: it is thesis test #3 (live-writer
recovery) and #10 (recovery claims are literal) executed as a gate.

### 6.2 Offline suites (all wired into `regress-offline-corpus`)

| Suite | Proves |
|---|---|
| `regress_assess_projection.php` | the §1 mapping, table-driven over fixture registry+policy+coverage inputs; every cell of §1.1–§1.6 has a case |
| `regress_assess_composition.sh` | assess composes doctor/inventory/registry/coverage/pending/adapters and refuses (not partially succeeds) on missing access |
| `regress_assess_bounds.sh` | bounded output, `--limit` grammar, `N more` tail |
| `regress_contract_shape.php` | `duo-application-contract/v1` canonical JSON, closed keys, digest stability, `attestation.state` enum |
| `regress_contract_accept.sh` | propose → accept → refuse-stale-proposal; `.duo/` layout; refuses to overwrite a newer digest |
| `regress_contract_projection.php` | `projection.json` regenerates identically from identical inputs; evidence-pin mismatch → `Requalification required` |
| `regress_authorization_plan.php` | plan freeze + digest; `plan_changed` invalidation; `--plan-only` mutates nothing; `--profile` weaker requires the explicit flag |
| `regress_release_containment_gate.php` | §1.6's consequence as a gate: a plan with a code lifecycle phase and no declared `external_effects[]` entry refuses with `declare in contract`; a declared entry produces the `declared_live_effect` authority row; `Experimental`/`Not qualified`/`Unsupported`/`Requalification required` in scope refuses pre-freeze with a §2.1 gap action, never a release next action |
| `regress_release_next_action.sh` | every documented failure maps to exactly one of `resume/reconcile/retry/recover/requalify/escalate`; `incomplete_lifecycle` → `recover`; ambiguous commitment → `reconcile`, never `retry` |
| `regress_release_ref_binding.sh` | `--from <ref>` refuses on target HEAD mismatch with a `reconcile` action; invents no git transport |
| `regress_verify_oracles.php` | journey grammar, undeclared-journey disclosure, convergence+journey both required for `pass` |
| `regress_recover_claim.php` | the recovery claim printed at recovery is byte-identical to the one in the frozen plan; `does_not_restore` non-empty for every profile including `verified-automatic` |
| `regress_recover_ordering.sh` | code-first refusal; mandatory final abort even on import failure; `--writers-excluded` required |
| `regress_rehearse_provider.sh` | capability negotiation, missing-capability refusal, `--reap` idempotence, containment banner presence |
| `regress_mup_leak_audit.sh` | no internal identifier in the human view of assess/release/verify/recover unless a documented command consumes it; every `wp duo` command is either host-driven or named in `docs/guides/internals.md` |

---

## 7. Sequencing into certification trains

> **RETIRED PROCESS — kept as the record of how round 3 was sequenced.** The
> certification-train discipline this section defines was the scheduling
> consequence of the certification-evidence apparatus, and the teardown train
> (#477–#480) removed the apparatus. Nothing in the paragraph below is runnable
> today: there is no `tools/cert-impact.php`, no `make
> certify-subjects-parallel`, no `make capability-registry-generate`, no
> `scripts/capability-registry.php` and no
> `sandbox/bin/subject-certification-bundle.php` — verify with `ls`; none of the
> five exist. `make release-gate` survives, but it is now exactly
> `capability-doc.php --check` then `classmap-generate.php --check`.
>
> **What replaced it.** There is no per-train certification round and no
> closure to ask a tool about. The merge gate is unconditional and the same for
> every change: `make regress-offline-all`, whose `Makefile`-asserted
> `regress-offline-all: N offline suites green` line is the count of record
> (AGENTS.md, "The loop"; non-negotiable 4). Live evidence is scoped per change
> to the minimal reasonably-safe set rather than paid as a fixed per-train toll.
> The T1–T4 sub-sections below are kept because they are the record of what each
> train actually contained and how it exited; read their exit criteria as
> history, and the `Makefile` as the gate.

Every train touching `agent/`, `cli/`, `sandbox/bin/`, or `Makefile` pays one
round (docker, ~45 min: regenerate registry in candidate state →
`make certify-subjects-parallel` → import per subject →
`make capability-registry-generate` → `make release-gate`). Ask
`php tools/cert-impact.php`, never reason about the closure by hand.

### T1 — the layout move (behaviour-preserving)

**Files:** driven by one codemod, `tools/codemod/move-modules.php` — the
ordered steps and the four hand fixes it cannot reach are in
[the module map's Move mechanics](../modules/README.md#move-mechanics). Its own
`--plan` at `a6b0b9c` is the count of record: agent 224 files moved / 404
rewritten (1,499 literal paths, 92 loader requires, 4 dynamic requires, 4
scanner-recursion fixes, 16 in-test source assertions), cli 48 moved / 127
rewritten (260 literal paths, 47 loader requires). That covers `agent/duo.php`,
`cli/duo`, `RefreshPlan::loadCompiler()`'s 39 dynamic names,
`scripts/capability-registry.php`, `scripts/adapter-certification.php`,
`sandbox/bin/subject-certification-bundle.php`, `tools/{affected,cert-impact,classmap-generate,api-surface,offline}.php`,
`tools/doctor.sh`, `tests/**`, `phpstan-baseline.neon`,
`tools/layers.json` + `tools/layers-exceptions.json`, and the `sandbox/tests`
literals. `manifests/**` (23 files, 7,352 mentions) is deliberately untouched:
those paths are re-earned by re-certifying.

**Exit criteria:** `php tools/api-surface.php --check` green **unchanged** — the
fixture records no paths, so an unchanged snapshot is the behaviour-preservation
proof; `php tools/classmap-generate.php --check` green against the regenerated
maps (whose *values* all gained a module segment), `tests/Tooling/ClassmapTest.php`
green; the two non-recursive scanners the codemod does not fix
(`regress_manifest_validate.php`, `regress_woocommerce_contract.php`) proven
non-vacuous by a deliberate failing-fixture run; `make regress-offline-all`
green; `make release-gate` green; zero output-byte diffs on
`plan`/`capture`/`promote` fixtures. **No product code in this train.**

### T2 — assess + vocabulary + contract

**Files:** `agent/src/Assess/AssessInventory.php` (new), `agent/src/Command/Cli.php`
(one `@subcommand`), `cli/src/Assess/**` (new), `cli/src/Contract/**` (new),
`cli/src/Command/{AssessCommand,ContractCommand}.php` (new),
`cli/duo` (verbs `assess`, `contract`; usage; `EnvironmentCommandPreflight::ENVIRONMENT_VERBS`),
`Makefile` (6 new offline suites + the suite-count echo line),
`sandbox/tests/regress_assess_*.{php,sh}`, `sandbox/tests/regress_contract_*.{php,sh}`.

**Exit criteria:** the §1 table is fully covered by
`regress_assess_projection.php`; `duo assess` on a live sandbox pair produces
a contract that `duo contract accept` takes; no plugin slug in
`cli/src/Assess/`, `cli/src/Contract/` or `agent/src/Assess/`
(grep gate inside `regress_assess_projection.php`); `make regress-offline-all`
+ `make release-gate` green.

### T3 — release + verify + recover + rehearse

**Files:** `cli/src/Release/**` (new), `cli/src/Rehearse/**` (new),
`cli/src/Recovery/{RecoveryProfileSelection,CheckpointCatalog,RecoveryClaim}.php` (new),
`cli/src/Command/{ReleaseCommand,VerifyCommand,RecoverCommand,RehearseCommand}.php` (new),
`cli/duo` (verbs `release`, `verify`, `recover`, `rehearse`; usage; verb list),
`cli/src/Plan/PlanSummary.php` + `cli/src/Plan/PlanView.php` (the `surface:` label line only),
`Makefile` (9 new offline suites + suite-count line),
`sandbox/tests/regress_{authorization_plan,release_containment_gate,release_next_action,release_ref_binding,verify_oracles,recover_claim,recover_ordering,rehearse_provider}.*`.

**Exit criteria:** authorization plan is durably written before any mutation
(proved by a fault-injected suite); the §1.6 containment gate refuses an
undeclared live lifecycle window pre-freeze; recovery claim byte-identity
between plan and recovery; `duo release --plan-only` proven side-effect free; existing
`promote` output bytes unchanged; `make regress-offline-all` + `release-gate`
green.

### T4 — grind, leak closures, guides

**Files:** `sandbox/tests/grind/grind_mup.sh` (new), `Makefile` (`grind-mup` target,
`regress_mup_leak_audit.sh` wiring, suite-count line), bounded-output edits in
new renderers if the audit finds any, `docs/guides/**` (4 new + 3 updated),
`sandbox/tests/spike/check_guide_commands.sh` if the extractor needs the new verbs.

**Exit criteria:** `make grind-mup` green on a dedicated pair, with step 11's
literal-recovery assertion passing; `check_guide_commands.sh` green; every
`wp duo` command resolves as host-driven or internal-documented.

### Free zone between trains

`docs/**` (except guide prose citing an unlanded verb — label it
`**Planned (DUO-NNNN)**`), `tools/**` (including
`tools/reference-env-provider.php`), `tests/**` (PHPUnit tooling self-tests),
`phpstan.neon.dist` / `phpunit.xml.dist` / cs-fixer config, and
`tools/layers*.json` ratchet entries. New `sandbox/tests/` suite *files* are
free to write but must land with their `Makefile` wiring —
`regress_bundle_coverage.sh` catches an unwired suite — so they ride the
train that wires them.

**Minimum:** four rounds. T2 and T3 may fuse into one train if both reviews
are ready simultaneously, giving three; T1 must stay alone because its
behaviour-preserving posture is the only thing making a diff that size
reviewable.

---

## 8. Explicit non-goals for round 3

| Deferred item | What MUP does instead | Spec section that defers it |
|---|---|---|
| Containment enforcement (default-deny outbound HTTP/mail/payment/webhook/queue; verified containment before workflows) | prints `containment: unknown — not enforced in this profile`; never emits `sandboxed`; rehearsal cannot authorize Experimental/Uncertified capabilities | *Core product workflows → 2. Rehearse*; *Qualification and requalification* step 3 |
| Certification attestation (signing under a platform/customer trust root; named approving principal) | `attestation.state: "unsigned"` placeholder; every site-scoped claim projects `Uncertified`; `Site-certified` is never emitted. **Half-delivered by round-3 T6 §3.2**: an operator key signs a site adapter and the projection reads `Site-certified` naming the principal, so the *adapter* half is done; the *contract* attestation stays `unsigned` | *The application contract → 3. Certification attestation*; *Certification provenance* |
| Signed/attested site evidence and the registered certification gate | evidence **pins** only (digests, expiry, dependency sets); readiness always recomputed | *The application contract → 2. Generated evidence* |
| PII hardening beyond the shipped gates (scoped PII exceptions, redaction policy, egress declarations) | shipped secret/PII gates unchanged; assess prints names and counts only, never values | *Version and merge*; *Privacy and ecosystem evidence* |
| Snapshot minimization, encryption in transit/at rest, isolated access, log redaction, retention and secure deletion for rehearsal | provider-owned; MUP declares the requirement and does not claim it | *Core product workflows → 2. Rehearse* (first bullet) |
| Deliberately-different target-local URLs/identities/paths to expose hidden coupling | the sandbox pair already differs by port/URL; no systematic divergence injection | *Core product workflows → 2. Rehearse* (third bullet) |
| The resumable qualification workflow (the 12-step protocol) | `duo rehearse` is a preview environment; qualification remains manual + `duo adapter-draft` | *Qualification and requalification* |
| Automated invalidation precision / bounded requalification | evidence-pin mismatch flips the whole affected surface to `Requalification required` | *Invalidation*; *Phase C* |
| `compensatable` recovery semantics | never emitted; a declared compensation action does not exist | *External-effect containment and recovery semantics* |
| Fleet reuse of reviewed declarations | one site, one contract | *Fleet reuse*; *Phase C* |
| Agent machine-legibility polish (stable refusal-code catalogue across every new verb, agent skill pack, evaluation scenarios) | new verbs reuse `CommandRefusalException` + `duo-command-refusal/v1`; no catalogue document | *Product boundary → Target product includes* (reference operating pack) |
| Ecosystem evidence aggregation / remote evidence storage | everything local; no egress | *Privacy and ecosystem evidence*; *Phase D* |
| Deletion as a first-class customer operation | `promote --with-deletes` tombstones remain the only path; `duo release` refuses a plan containing deletes unless `--with-deletes` is explicit and the surface is not in `deletion_semantics.unsupported` | *Evidence profiles → Delete or destructive migration* |
| Drift monitoring outside a command | drift reported at assess/status/plan and at the mutation gate only | *Day-two operation* (monitoring is explicitly optional) |

## 9. What MUP still does not honestly deliver, said plainly

Three sentences an operator should be able to read in the guides:

1. **Rehearsal is a preview, not a sandbox.** Duo does not stop a plugin in
   your preview environment from sending mail, calling a payment API, or
   firing a webhook. Point it at test credentials.
2. **`Site-certified` is your organization's word, not Duo's.** *(Superseded
   by round-3 T6 §3.2, which built the operator-side certification path. The
   original MUP statement — "Site-certified does not exist yet; any capability
   not covered by the platform registry is `Uncertified`" — was true for the
   whole of MUP and is retained here as history.)* It is emitted now, and only
   on a verified Ed25519 signature over an adapter's exact bytes under a trust
   root the repository or the agent owns. It means customer-organization
   approval, explicitly not a Duo endorsement; the signed bundle records
   `exercised: false` beside its grammar verdict, so it never implies the
   adapter was tested against a live site. The **contract's** attestation is
   still `unsigned`, which the human view says on the same line: `certified by
   <principal> (<root> trust root); contract attestation unsigned`.
3. **Rollback restores bytes, not consequences.** The list of what a profile
   does *not* restore is printed before you authorize and again before you
   recover, and it is the literal truth.
