# Round 3 T6 — operator-authored adapters, and the walk that proves them

*Status: owner-directed build contract (2026-08-17). Owner ruling: users must be
able to author and override adapters for their published or custom plugins;
the walk exercises several scenarios and every gap it hits is fixed, not filed.*

This is the shared contract between the walk (`sandbox/tests/grind_adapter_walk.sh`)
and the product changes it exercises. Words, flags and file shapes below are
binding on both; the grind asserts them, the product emits them.

## 1. What is true today (verified on `21eb5ab`)

- `duo init` refuses any active plugin no installed manifest declares
  (`active_plugin_without_adapter`, exit 2), and an installed-but-uncertified
  site adapter blocks init too (`adapter_source_uncertified`).
- Capture is loud for an unmanifested plugin's public CPT/taxonomy
  (`incomplete_policy_scope` → `duo pending`/`duo classify`) and for meta it
  writes onto in-scope posts (`incomplete_state_discovery`), silent for its
  options (no namespace owner → skipped) and its custom tables (invisible).
- Assess is designed to mint `table:<name>  unclassified block Not qualified
  Uncertified unknown unknown` for an undeclared table, but
  `Coverage::tables_report()` drops `logical_name` from the published rows, so
  the row never appears on a live site (bug); the human next-actions block never
  counts undeclared tables; invisible options are counted under `classify` but
  are not in the queue `classify` works on; the gap action for an unclassified
  surface with unknown containment is `qualify in rehearsal`, and rehearsal
  states it cannot qualify anything.
- Site-installed adapters (`adapters/<name>.json`) and plugin-bundled adapters
  (`<plugin>/duo-adapter.json`) load and are usable for capture/plan/apply but
  are `uncertified`: readiness `Not qualified`, gap action `install adapter`
  (the thing just done), `duo release`/`duo promote` refuse ("only certified
  adapters may enter promotion"). The only certification path signs
  `adapters/certifications/<name>.json` under a key in the **agent-owned**
  `manifests/capabilities/adapter-authorities.json`, which ships empty; no
  operator can complete it. A site adapter may never shadow a shipped name.
- `Site-certified` is `NEVER_EMITTED`; every contract attestation is `unsigned`.

## 2. The ruling, in product terms

The customer organization is a legitimate certification authority for its own
site (product spec: *Site-certified* = "customer-organization approval through
Duo's certification protocol, explicitly not a Duo endorsement", signed "under a
platform **or customer-organization** trust root"). This profile makes that
concrete without signing the contract or building the 12-step qualification
workflow: an organization key, held by the operator, certifies a site adapter;
the projection then reads **`Site-certified`** with the principal named.
Production-grade key custody, machine-legible attestation of the contract, and
the resumable qualification workflow stay deferred (owner ruling, round 3).

## 3. Contract between the walk and the product

### 3.1 Files in the site repository

```
adapters/<name>.json                   # site adapter (existing)
adapters/authorities.json              # NEW: the SITE trust root, format duo-adapter-authorities/v1
adapters/certifications/<name>.json    # signed certificate (existing envelope)
site.duo.json  manifests[]: {name, source:"site"|"plugin", digest}   # existing pin object
```

- `adapters/authorities.json` uses exactly the shipped `duo-adapter-authorities/v1`
  shape (`keys.<key-id>` records: Ed25519, scope `site_adapter_certification`,
  status `trusted|revoked`, `adapter_names: [<name>...]`, `trust_tiers: [<tier>...]`, canonical public key). Keys
  here are trusted **only** for adapters in this repository. A key present in
  both the shipped file and the site file: shipped record wins.
- Private keys never live in the repository. `duo adapter keygen` writes the
  secret to a path the operator names (mode 0600) and refuses a path inside the
  site repository.

### 3.2 Certification words (agent `AdapterSources` catalog rows)

| certification word | meaning |
|---|---|
| `registry` | shipped adapter, reviewed registry (unchanged) |
| `uncertified` | out-of-tree, no valid certificate (unchanged) |
| `signed_unpinned` | valid certificate, pin not `{name,source,digest}`-exact (unchanged) |
| `third_party_signed` | valid certificate under a key in the **agent-owned** authorities file, exact pin (unchanged) |
| `site_signed` | **NEW** — valid certificate under a key in the site's `adapters/authorities.json`, exact pin |

Every catalog row additionally exposes `trust_root: platform|site|null` and
`principal: <key-id>|null`. `site_signed` and `third_party_signed` both produce a
**certified** capability claim (`status: certified`, evidence `current` while
the digest matches; `certification: {source:"site", trust_root, principal,
signed_at}` on the claim) — so `duo promote`'s existing gate ("capability
certified and evidence current") admits them without a second gate.

### 3.3 Overriding a shipped adapter

An explicit `{name:"<shipped-name>", source:"site", digest}` pin in
`site.duo.json` selects the site copy for that name (today: whole-source
refusal). Precedence stays `shipped > site > plugin` for name-only pins; the
explicit site pin is the override. The shipped copy is reported as
`shadowed_by_site` on every catalog row and in `duo adapter list`; the site copy
carries the site's certification words (a signed override is `Site-certified`,
never `Platform-certified`). `CrossManifestGuards` run over the loaded set only.
An override **inherits exactly the shipped executable grants**: an
`interpreter`, `regen_dependency.regenerator` or manifest-sourced `providers[]`
row is admitted when byte-identical to what `manifests/<name>.json` declares
and refused when added or edited (`AdapterSources::shipped_executable_grants`);
the override carries the shipped tier that code implies, and a site key may
certify `compatibility_shim`. `duo adapter pin --source=site` on a shipped
name writes the source statement first, loads, then completes the digest — one
command. A site-root certificate binds the key's identity and the record it
was signed over, never the record's growing `adapter_names`/`trust_tiers`
(those, and revocation, are enforced live), so certifying a second adapter
under the same key keeps the first certificate and its pinned digest intact.

### 3.4 Init

- `duo init <env> --allow-unmanaged-plugins`: proceed when active plugins have
  no owning adapter; each is printed as `UNMANAGED PLUGIN <slug>/<file>.php
  [active_plugin_without_adapter]` under an `advisories` heading instead of
  `unsupported`. Init selects no adapter and no authored scope for such a
  plugin; but init's own confirmation runs the baseline capture, whose scope
  gate refuses any plugin-registered type with rows that no rule names, so the
  decision carries through: every registered type with rows that is outside the
  proposed scope and undeclared by every selected adapter is left local with
  `policy.scope.<kind>.<name>: {class: runtime}` (the rule `duo classify` would
  write) and printed as `UNMANAGED SCOPE <kind>:<name>
  [unmanaged_scope_left_local]`. (Round-3 T7 A6 widened this to every such
  type, whether or not the registering plugin has an adapter — an adapter may
  deliberately leave a type to the site, as `manifests/elementor.json` does
  with `elementor_library` — so `duo init` always finishes and the operator's
  decision is one `duo classify` line.) Assess and the contract carry the plugin
  decision (§3.6). Without the flag the refusal is unchanged but its
  remediation now names the flag and `duo adapter certify`.
- An installed but uncertified adapter still blocks init; the blocker's
  remediation is `certify it with duo adapter certify <site-repo> --name=<n>, or
  remove it, then rerun duo init`. That order works because an adoption seed
  carrying explicit `{name, source:"site"|"plugin", digest}` pins (what
  `certify --pin` / `adapter pin` write) is still recognised as the seed;
  init recomputes and republishes those pins exactly. A hand-added name-only
  pin or a policy edit still reads `existing_configuration`.

### 3.5 Host verbs (cli, offline unless stated)

```
duo adapter keygen --out=<secret-key-file> [--key-id=<id>]
    # Ed25519; writes secret 0600 outside the repo; prints key-id + public key
duo adapter certify <site-repo> --name=<n> --secret-key-file=<f> [--key-id=<id>] [--reason=<text>] [--pin]
    # registers the public key in adapters/authorities.json if absent,
    # builds a duo-site-adapter-certification-bundle/v1 whose evidence is
    # {grammar: <manifest-validate verdict>, exercised: false, reason}, signs
    # adapters/certifications/<n>.json, verifies it, prints the pin object;
    # --pin writes/replaces the {name,source:"site",digest} pin in site.duo.json
duo adapter pin <site-repo> --name=<n> [--source=site|plugin]
    # writes/replaces the exact pin object offline (digest from AdapterCatalog);
    # for a shipped name with --source=site this is the override
duo adapter-draft <site-repo> --name=<n> [--match=<re>] [--evidence=<observe.json>] [--seed=<coverage.json>] [--out=<path>]
    # --out writes the draft (refuses to overwrite without --force);
    # --seed consumes duo coverage --format=json: option prefixes ->
    # option_namespaces + option_patterns proposals, undeclared tables ->
    # tables markers (class proposal runtime), pending scope CPTs -> post_types
```

`--evidence` stays accepted; if this train does not consume it, the draft says
so exactly as today.

### 3.6 Assess / contract vocabulary

- New surface kind `plugin:<slug>` for every active plugin with no owning
  adapter (inventory publishes `plugins.active_without_adapter[]`): projects
  `unclassified / block / Not qualified / Uncertified / unknown / unknown`, next
  action `install adapter`. The operator's decision for it in the contract is
  ordinary: `decided_by: operator` with `state_class: runtime`, `handling:
  preserve local` ("this plugin's state stays local; not branchable") — which
  projects `Unsupported`, prints the meaning line and no next action, and is
  outside every release gate (T5's rule).
- `Site-certified` is emitted when the governing claim is certified with
  `certification.source == "site"`; the projection exposes
  `certification_principal` and `certification_trust_root` beside
  `certification_provenance` (the catalog row's bare `principal` /
  `trust_root` are the same facts); the contract's `attestation.state` remains `unsigned` and the
  human view says so once (`certified by <principal> (site trust root); contract attestation unsigned`).
- Gap actions: **new closed-set word `certify adapter`** for readiness
  `Not qualified` caused by `adapter_source_uncertified` or `signed_unpinned`
  (the adapter exists; sign or re-pin it). Unclassified + containment `unknown`:
  `install adapter` when the surface has a probable owning plugin, else
  `classify`. `qualify in rehearsal` stays in the closed set but is not emitted
  by this profile (rehearsal cannot qualify).
- Undeclared tables: `logical_name` published (bug fix); the `unknown:` block
  prints `N undeclared table(s)`; the next-actions roll-up counts them.

### 3.7 The bugs this train fixes on sight

1. `Coverage::tables_report()` drops `logical_name` → assess `table:` rows never appear live.
2. `AssessRenderer::gapSection()` never counts undeclared tables; the `unknown:` block has no table line.
3. `assess.md`'s sample row prints `classify` where the rule returned `qualify in rehearsal`.
4. `duo manifest-pin` guide/code drift (`source` key in the printed object).
5. Anything else the walk hits.

## 4. The walk: `sandbox/tests/grind_adapter_walk.sh`

Shape: `grind_mup.sh`'s (say/pass/fail, `--self-check`, `--dry-run`, evidence
dir, exact PASS string, one dedicated pair, `DUO_EXPECTED_SOURCE_SHA`). Subject
plugin: **WPForms Lite 2.0.0.4** (pinned, `exercise-fixture`), CPT `wpforms`,
options `wpforms_*`, tables `wpforms_tasks_meta`/`wpforms_payments`/
`wpforms_payment_meta`/`wpforms_logs`/`wpforms_analytics_*`. Custom plugin:
`sandbox/fixtures/acme-catalog/` (walk-owned; CPT `acme_item`, taxonomy
`acme_kind`, options `acme_catalog_*`, table `acme_catalog_index`, bundled
`duo-adapter.json`). `WALK_SCENARIOS=S1,S2,S3,S4` (default all), each on a
fresh pair reset:

| # | scenario | must prove |
|---|---|---|
| S1 | published plugin, no adapter, kept unmanaged | `duo init` refuses (typed) → `--allow-unmanaged-plugins` proceeds; capture refuses `incomplete_policy_scope` → `classify` `scope:post_type:wpforms=runtime` → capture green; assess prints `plugin:wpforms-lite`, `table:wpforms_*` rows and `option-prefix:wpforms`, counts, next actions; contract decides the plugin `runtime/preserve local`; rehearse; release/verify/recover proceed with the plugin excluded and its tables restored by the DB checkpoint |
| S2 | published plugin + operator-authored site adapter | `duo adapter-draft --seed --out adapters/wpforms.json` → edit → `manifest-validate` → `keygen` → `certify --pin` → assess reads `Site-certified` for the wpforms surfaces; init/capture/rehearse/edit a form/capture/merge/release/verify/recover manage the forms |
| S3 | custom in-repo plugin with a bundled adapter | code half carries the plugin; bundled adapter reports `uncertified` + promotion path; `adapter certify` after promoting to `adapters/`; loop passes with the CPT+options managed |
| S4 | override a shipped adapter | copy `manifests/woocommerce.json` → `adapters/woocommerce.json`, add one authored option, `adapter pin --source=site` (override) → catalog shows `shadowed_by_site`; certify → `Site-certified` on woocommerce surfaces; capture/release still converge |

Each scenario writes its evidence under `<scratch>/evidence/<S>/`; a scenario
that hits a product refusal the walk did not expect FAILS naming the reason
code — the walk is the exercise, and its stops are the work list.

## 5. Certification

agent/, cli/, manifests are in the closure; the train pays one round after the
walk is green on the candidate, then the acceptance run repeats on the
certified tip (T5's pattern).
