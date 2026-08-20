# Grind Round 2 — mitigation round (tasks #72–#88)

Round 1 (r1a-forms, r1b-shop, r1c-agency) was scenario exercise: three real-world
extension stacks driven end to end through the core loop, harvesting gaps. Round 2
is the other half of the grind directive: **mitigate what round 1 found**. Four
workstreams ran in parallel (r2-eng, r2-snapshot, r2-sandbox + one lint fix on
main), each gap traced to root cause before any fix — no symptom-patching.

## What round 1 escalated → what round 2 did

### #72 — variation `post_title` divergence (r1b) → root-caused, NOT an engine bug

The r1b round-trip left one anomaly: a variation's auto-generated title read back
with transposed attribute order on the target ("Red, Small" vs "Small, Red")
despite Apply writing the captured title verbatim and a clean canary. Round 2
traced it into WooCommerce's own source:
`WC_Product_Variation_Data_Store_CPT::read()` **silently self-heals** a
variation's `post_title` from the parent's `_product_attributes` order on every
`wc_get_product()` load, writing via raw `$wpdb->update()` specifically to dodge
`wp_update_post()`/`save_post` (WooCommerce's own comment explains the
infinite-loop avoidance) — which is exactly why the canary stayed clean and
`post_modified` never moved. Both sides converge to the identical self-healed
value on their own timelines; Duo's write path is verbatim-correct.

No fix belongs in the engine for this; the honest gap is that **post fields have
no classification mechanism** — `post_title`/`slug`/`status` have no manifest
hook today, unlike meta/options which support `class: derived`. Filed as **task
#88** with acceptance criteria (a deliberate refusal to hack an `eval`-style
rebuilder — `manifests/woocommerce.json`'s own `_price` note already rejected
that pattern for the same category of problem).

### #73 — unresolvable ref-typed options (r1c) → loud-and-blocking gate

r1c found `elementor_active_kit` (authored, `ref: post`) silently dropping from
canonical state when its target's post type (`elementor_library`) wasn't in
policy scope — a **silent authored-data loss**, the existential-risk category.
Capture now classifies every unmapped ref id as:

- **dangling** — no row exists anywhere (deleted target). Unchanged: warn, drop,
  exit 0 (spike A's `wp_page_for_privacy_policy` case still needs exactly this).
- **unscoped** — a real row exists, its type just isn't in policy scope. Now
  batches into a loud-and-blocking gate (the unclassified-meta gate's posture)
  that aborts capture naming the option, the raw id, the target's real type, and
  the policy key that fixes it. Escape hatch: `--force-unresolved-refs`, threaded
  through `capture`/`plan`/`apply` (the latter two hit the gate via their internal
  env snapshot). Array refs get identical per-element treatment.

One false positive was caught **by the conformance sweep, not by review**: scope
must be checked against policy membership, not inferred from minting state —
otherwise every fresh target env's first `apply` (unminted but correctly-scoped
rows, e.g. `default_category` on a new install) aborts spuriously. Regression:
`sandbox/tests/live/regress_option_ref_scope.sh` (7 assertions, including that exact
case). `sandbox/tests/grind/grind_r1c_agency.sh` step (1) now asserts the abort + the
escape hatch instead of the old quiet drop. Spec: "Unscoped references"
(spec v0.10).

### #75 — typed-snapshot custom tables (r1a + r1b, independently) → implemented

Two unrelated plugins hit the identical root gap in round 1 — Ninja Forms'
`nf3_forms`/`nf3_fields`/`nf3_actions` (+ EAV `_meta` sidecars) and WooCommerce's
`woocommerce_attribute_taxonomies` — the confirmation DESIGN.md §3.3's "middle
tier" needed. Now real (`agent/src/Repository/Snapshot.php` + manifest `"tables"` grammar,
spec v0.10):

- Two classes: `authored_snapshot` (row tables; **every live column must be
  declared** — pk/ref/columns — or capture refuses loudly) and
  `authored_snapshot_meta` (EAV sidecars folded into the owning row's file,
  reconciled like postmeta).
- Identity lives only in `duo_map` (no schema changes to plugin tables, ever):
  `mapped` (UUIDv7; ledger loss = identity loss, stated plainly) or
  `natural_key` (UUIDv5 derived from a stable unique column — self-heals a lost
  ledger; verified live by adopting r1b2's pre-existing attribute rows with
  *different* local ids).
- Structural row refs throw on unmapped ids (the `post_parent` category);
  optional sidecar refs drop-with-warning (the dangling category).
- Declarative per-row cache `invalidate` (r1a's stale `nf3_upgrades` bug,
  reproduced, poisoned deliberately, proven fixed live); blanket caches use the
  existing `rebuilders`.
- `block_attrs` can ref declared id_kinds: `ninja-forms/form`'s `formID` now
  tokenizes as `{{nf3_form:<uuid>}}` — closing r1a's PHP-fatal-on-apply hazard
  end to end.

Found along the way: `$wpdb`'s default `%s` format **silently corrupts writes to
`BIT(1)` columns** (both "0" and "1" land as 1) — fixed via `SHOW COLUMNS`-driven
`%d` casting before any wiring; a file-level byte-diff would never have seen it.
Also documented honestly: plugins that auto-seed content on activation (Ninja
Forms' "Contact Me") produce two mapped-identity rows that genuinely cannot be
recognized as "the same" — conformance seeds delete them before first capture,
and that limitation is inherent, not papered over. `nf3_submissions` stays
runtime; shipping zones/tax rates stay honest-intent markers (their instance
settings embed a table id in an **option name** — a fourth mechanism,
characterized in r1b's report, not attempted).

### #74 — sandbox resource bloat (user-flagged) → parameterized pairs + shared DB

The old model: one mega docker-compose.yml, ~10 profile pairs, each with its own
MariaDB. New model (`sandbox/pair.yml` + `sandbox/db.yml` + overlays +
`sandbox/bin/pair.sh`): **one shared MariaDB server**, per-pair databases
(`wp_<name>1`/`wp_<name>2`), one generic parameterized pair template
(`docker compose -p duo-<name>`), mem/cpu caps on every service, `list` warning
above 2 live pairs. Reset = `DROP/CREATE` both databases (~0.75s vs ~7.8s for
the old volume cycle, ~10×) with **DB-level readiness** (`SELECT 1` through each
side's cli container — `wp core version` reads a static file and passes before
the DB exists, the old wait-loop race). `sandbox/conformance/run.sh` migrated to
pair.sh; the checks/seeds' hardcoded ports/service names became
`CONF1_PORT`/`CONF2_PORT` parameters with byte-identical defaults. The migration
sweep itself caught a latent guard bug: `plugin is-installed` (files on disk)
short-circuited activation after a DB-only reset — now `is-active`. Legacy
docker-compose.yml stays until the spike scripts migrate (documented).

### #76 — lint's all-caps "ID" blind spot (r1a) → fixed on main

`looks_like_id_attr()`/`looks_like_id_key()` ended in case-sensitive
`/(Id|Ids)$/` — matched Gutenberg's camelCase (`mediaId`) but missed the equally
common all-caps convention (`formID`), which is how a raw `nf3_forms.id` in a
real page passed the hard lint gate AND byte-diff, then fataled on the target
(third independent proof that byte-diff alone cannot be the correctness gate).
Deliberately **not** fixed with `/i` — that would flag `grid`, `valid`, `bid` —
but with cased variants: `/(Id|ID)s?$/` and `/([-_][iI][dD]s?|(Id|ID)s?)$/`.
Offline unit sweep + full conformance rerun: zero new false positives.

## Validation

- **9-manifest conformance sweep green on the new plumbing** (core, acf,
  contact-form-7, elementor, fse, ninja-forms, polylang, woocommerce, yoast) —
  run on the fully integrated tree, doubling as the sweep that validated #73's
  false-positive fix (WooCommerce's Shop/Cart/Checkout adoption onto fresh
  targets) and #75 (the new ninja-forms target: byte-identical round-trip, 0
  lint findings, rendered form + server-side model resolution on the target).
- New regressions: `regress_option_ref_scope.sh`; nf3 end-to-end on r1a incl.
  the poisoned-cache proof; woo-attrs adoption on r1b; grind_r1c step (1)
  updated to the new gate semantics.

## Carried forward

- **#88** — post-field-level derived classification (the #72 characterization):
  engine capability, next engine round.
- Shipping-method instance settings (`woocommerce_flat_rate_<id>_settings`):
  ref-in-option-NAME discovery — no mechanism exists; characterized in r1b.
- `Policy::taxonomies()` has no pattern mechanism (`pa_*` dynamic taxonomies
  need manual pre-provisioning on fresh targets; registration lives in a custom
  table now capturable via #75 — a candidate wiring for round 3).
- Legacy mega-compose deletion once spike scripts migrate to pair.sh.
