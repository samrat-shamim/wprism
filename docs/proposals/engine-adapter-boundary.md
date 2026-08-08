# Engine core, adapter packages, and the WordPress SDLC — boundary doctrine

*Owner ruling — 2026-08-09. Program tracked in the Linear project
"Engine/Adapter Boundary & WordPress SDLC" (DUO-3312…DUO-3327). Grounded in a
three-audit review of main @ 10a92b1: engine contamination (token-level,
comments excluded), extension-mechanism inventory, and SDLC surface map.*

## The three layers

**Engine core** (`agent/src`, `cli/src`, `recovery/`) ships mechanisms only.
It knows WordPress core's schema — that is platform, not extension. It must
know no plugin and no theme: no plugin-named branch, no shape assumption
sized to one plugin's observed data. Its knowledge of the ecosystem arrives
exclusively through declarations it validates loudly at load.

**Adapters** (`manifests/…`) are per-plugin packages: a declarative manifest
(the grammar) plus sidecar PHP in defined SDK slots — interpreters,
regenerators/rebuilders with a validated contract, conformance checks — and
certification inputs (dispositions, evidence citations). Sidecars may use the
documented engine helper surface (`Db`, `Canon`, `Tokens`); they run inside
the agent trust boundary, and installing an adapter is the same trust act as
installing a plugin.

**The SDK** is the versioned interface between them: the manifest grammar
(spec'd and machine-validatable offline), the sidecar entry-point contracts
(inputs, outputs, error posture — always throw, never degrade), the runtime
helper surface, and the certification pipeline (conformance → evidence bundle
→ registry). A third party with no core-repo access can author, validate,
ship, and *honestly label* an adapter — certified only through evidence,
loudly uncertified otherwise.

## What the audit actually found (honest state)

The engine is far cleaner than its grep suggests. Excluding comments, all of
engine core contains **one** genuine plugin branch (`Apply.php:4435`,
product/product_variation in delete receipts) and four diagnostic strings.
`cli/src` and `recovery/` are entirely clean. The ref-rewriting layer
(Blocks/Shortcodes/Tokens/JsonRefs/SidebarState) is genuinely registry-driven
— Ninja Forms' block refs resolve end-to-end with zero engine registration.
`WooCommerceContract.php` is real contamination by location but a sealed
island, substantially superseded by the manifest-owned sidecar regenerator;
its removal is a delete-and-absorb.

The real residual risk is **shape narrowing**: three primitives silently
sized to one plugin's data (`object_type === 'term'` sentinel, flat-map-only
`description_refs`, scalar-only attached-meta refs). These never appear in a
grep; they appear when the second plugin in each category fails.

The extension surface is one large well-validated declarative grammar plus
two real code contracts (interpreters spec'd; regenerators not), one WP
filter, and one unvalidated escape hatch (`rebuilders` command strings — the
only mechanism with zero load-time validation). Five missing extension
points keep the flagship adapter partly in engine core: no whole-site
rebuild+verify entry point, presence-only engine verification, the
unvalidated rebuilder string, four closed engine-side sets, and a
certification pipeline unreachable from outside this repo.

The SDLC verdict: a mature state-transfer engine and a credible operator CLI,
without the product path around them. Merge is real; *branch* is prose. No
scaffold, no site-repo CI packaging, no adapter-authoring guide, certified
rollback machinery not yet selected by promote (DUO-3310).

## Program map

Track A — boundary and SDK:
- DUO-3312 absorb WooCommerceContract into the adapter (blocked by 3313)
- DUO-3313 rebuilder contract v2: validated sidecar contract, value-level
  verification, whole-site regenerator shape
- DUO-3314 third-party adapter distribution: overlay loading, per-source
  dispositions, loud-uncertified degradation, registry provenance
- DUO-3315 declared CPT parent-child relations (removes the one engine branch)
- DUO-3316 generalize the three shape-narrowed primitives
- DUO-3317 SDK runtime helpers: checked reads in Db, declarative runtime
  assertion
- DUO-3318 rule on the closed engine sets (resolvers, derived fields,
  composite PKs)
- DUO-3319 snapshot table-name sanitization consistency
- DUO-3320 doc-debt and neutral-naming sweep

Track B — SDLC product surface:
- DUO-3321 site-repo CI as product (reusable workflow, PR plan preview,
  credentials)
- DUO-3322 duo init greenfield scaffold
- DUO-3323 docs pack (adapter authoring, code updates, team workflow)
- DUO-3324 branch environment materialization and TTL (design-first;
  provisioning boundary is an explicit owner ruling)
- DUO-3325 policy-to-manifest export ceiling (drafts-as-review)
- DUO-3326 Requires PHP / Requires at least enforcement
- DUO-3327 machine-readable manifest schema + offline validation

Adjacent, already filed: DUO-3306 (per-adapter conformance evidence into the
bundle), DUO-3310 (promote selects the verified-rollback profile; DUO-3307
blocks it).

## Sequencing constraints

1. `agent/`, `cli/`, `manifests/`, the Makefile, and the CI workflow are
   certification bound-inputs — any move or edit expires evidence. Boundary
   work lands in batches that END with re-certification and a fresh bundle
   import, never as lone edits.
2. DUO-3313 and DUO-3312 land after PR #116 (surface-scoped rebuilders)
   merges; its branch already removes whole-catalog rebuild as automatic
   Apply authority and adds `triggers`.
3. DUO-3314 coordinates with DUO-3306 (same evidence-pipeline files).
4. Nothing in this program relaxes the posture: unclassified data never
   silently enters or skips the repo; an uncertified adapter is *loudly*
   uncertified, never quietly degraded.
