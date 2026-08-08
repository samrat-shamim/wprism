# Grind Round 3 — dual slate: new scenarios × engine round (tasks #88–#128)

Round 3 ran both halves of the grind loop at once, four parallel workstreams:
two scenario grinds on stacks the engine had never met (multilingual shop;
events + memberships), and two engine tasks closing round-1/2's sharpest
carried-forward gaps (post-field classification; dynamic taxonomies + the
shipping-zone stack). A fifth, uncoordinated workstream — a parallel session
working the same tree under DUO-NNNN tickets — landed real hardening
mid-round; it is inventoried here because the round's validation ran against
the integrated tree, not around it.

## Scenario A — multilingual shop (Polylang × WooCommerce, task #90)

docs/grind/r3a-multilingual-shop.md. Headlines:

- **description_refs handled WooCommerce entities with zero new code** —
  translation groups over products and product_cat/pa_color terms resolve to
  each environment's own local ids, live-verified both directions.
- **The free/pro boundary was established by reading Polylang's source, not
  assumed**: no license gates on marking WooCommerce CPTs/taxonomies
  translatable; the paid add-on sells data *sync*, not translation itself.
- **Menus have two mechanisms with opposite fates**: per-item auto-translate
  rides post_translations and just works; per-location menu *swapping* lives
  in the `polylang` option's `nav_menus` sub-key and never transfers — the
  concrete case that turned the long-deferred sub-key option classification
  into task **#121** (twin shape: Yoast's `wpseo` blob).
- **#121 (filed)**: Polylang's own post_types/taxonomies opt-in — inside the
  excluded `polylang` option — gates whether `language` relationships are
  written for a translated CPT at all. Deterministic on every fresh target;
  manual replication is the only mitigation. Not #92's territory (that fixes
  Duo's own taxonomy scope; this is the plugin's internal config).
- **#128 (later DUO-3300, resolved)**: a one-run front-end 500 was recorded
  with Polylang's language config empty. DUO-3300's fresh pinned-stack,
  action-boundary sweep found no command that reproduced the state: source
  and target locale descriptions remained valid and EN/DE renders returned
  200. The fixture's silent database repair was removed in favor of raw
  description invariants and hard HTTP checks, so any recurrence now stops at
  the nearest phase instead of losing the evidence.
- **#122 (filed and closed)**: `product_count_<taxonomy>` term-meta cache →
  `runtime` via the shared meta_patterns mechanism (which already covers
  term_meta — no new machinery needed).
- **Cross-validation**: after #88 landed mid-round, the r3a driver re-ran the
  corrected two-part proof on a rebuilt pair — plan silence under a forced
  hook-free title self-heal (#88's fourth independent fixture, a different
  attribute ordering than it was built against), and byte-identity everywhere
  except variation titles differing ONLY as same-words-reordered (**#123's
  signature, anagram-verified, now confirmed on a second fixture**). A third
  instance of Polylang's own same-request write-clobber pattern was found en
  route and folded into #121.

## Scenario B — events + memberships (TEC + Paid Memberships Pro, task #91)

docs/grind/r3b-events-memberships.md. Headlines:

- **Two new manifests** authored evidence-first; typed-snapshot stress-tested
  against schemas it was not designed around, which is exactly how it earned
  its three new gap filings:
  - **#124** — derived custom tables with a HARD per-entity query-availability
    dependency (a TEC event with no occurrence row is invisible to WP_Query
    itself; the grammar has no "regenerate or the entity is unusable"
    primitive, only blanket `rebuilders`).
  - **#125** — composite-primary-key join tables (PMPro's real restriction
    table) have zero representation; `authored_snapshot` hardcodes one pk.
  - **#126** — `authored_snapshot_meta` hardcodes the sidecar's own PK column
    name ('id'); PMPro uses `meta_id`. Smallest, likely first to fall.
- **PMPro is no longer on wp.org** (removed 2024-10-17 at its author's
  request; self-hosted, still free/GPLv2). Installed from the official GitHub
  release tag, pinned in the driver. This creates a real third distribution
  bucket — "free but not wp.org" — between the wp.org and premium/vendored
  cases DESIGN.md's code-half assumed; future rounds should sweep for it.
- **#73's gate boundary validated on a foreign plugin**: deliberately
  exercised on `pmpro_checkout_page_id` — unminted+unscoped aborts loudly
  naming the exact policy fix; already-minted resolves regardless of scope,
  matching the documented design line exactly.
- **Merge semantics proven on typed-snapshot tables themselves**: a
  membership-price conflict surfaced as a plain git conflict on the table
  entity's field, resolved editorially, both environments converged.

## Engine slate

- **#88 — post-field classification (spec v0.11)**: per-post_type `fields`
  grammar; v1 = `title: derived` only (slug loudly excluded — filenames and
  identity). Capture records observed values verbatim; apply bootstraps on
  create and never overwrites on update; `Canon::post_hash_basis()` excludes
  derived fields from the hash basis used by duo_state/plan/drift — divergence
  is structurally invisible, not hidden from one diff. Validated by
  grind-r1b end-to-end plus woocommerce+core conformance. En route it
  produced: two r1b fixture forensics (WooCommerce 11's double option-guard
  makes reset-with-surviving-options skip page creation — and the failure
  presented as **#73's gate catching a bug nobody planted**), the
  canonicalize-first hash fix (curing adopted-entity spurious drift
  everywhere), and the discovery of **#123** — Canon's alphabetical
  key-sorting silently reorders order-sensitive serialized meta
  (`_product_attributes`), a PERMANENT title divergence with causation-grade
  proof, tracked for round 4 with acceptance criteria including restoring
  true zero-exclusion byte identity. The interim r1b assertion is
  field-scoped with an anagram check: titles may differ only as the same
  words reordered.
- **#92 — taxonomy_patterns (spec v0.12)**: dynamic taxonomy scope by
  declared pattern, resolved against live term tables (not the mid-request
  registry — load-bearing for same-request typed-snapshot provisioning) with
  declared `object_type` fallback. Proven: a variable product whose pa_*
  taxonomies appear in no policy list round-trips to a fresh target with
  zero manual provisioning. The conformance woocommerce seed now carries a
  variable product with attribute taxonomies deliberately unlisted — every
  CI run re-proves the mechanism.
- **#93 — shipping-zone stack + option_name_refs (spec v0.12)**: five
  zones/tax tables graduated from honest-intent markers to real typed
  snapshots (all mapped identity — no column survived the natural-key
  argument), plus the fourth mechanism r1b characterized: options whose NAME
  embeds another table's id are pattern-discovered (anchored, named `id`
  group; real false-positive candidates proven excluded), tokenized in the
  key itself, re-materialized under the target's own instance ids.
  WooCommerce's own zone/tax APIs resolve on the target; zero literal `{{`
  in its options. Implementation surfaced and fixed a mint-gating false
  positive of exactly #73's documented class — the loud branch is now
  honestly a defensive invariant, stated as such.
- **#127 (filed)**: Capture's original secret-guard call sites are
  is_string()-gated — array-shaped authored values bypass deep scanning; the
  typed-snapshot side already has hard_match_deep (DUO-3214), the original
  path doesn't. Found by r3-eng-woo catching the same bug shape in its own
  draft first.

## The parallel workstream (DUO-NNNN tickets, same tree, same owner)

Attributed by elimination (four categorical agent denials) and confirmed by a
surgically-staged commit on main under the project owner's identity
(93632dd). Inventoried, reviewed on merit, and validated with the round:

- **DUO-3204**: `Snapshot::reconcile_meta()`'s delete loop had no ownership
  gate — every re-apply of a managed form silently wiped manifest-declared
  RUNTIME sidecar keys (canary-invisible; missed by round-2's sweep because
  "unchanged skips reprocessing" masked the path). Fixed + regression that
  constructs the firing case explicitly.
- **DUO-3212**: registered-but-unrewritten block refs kept raw env-local ids
  AND were lint-invisible (the two defects hid each other). Now: dangling
  drop per spec + `unrewritten_registered_ref` lint class + an offline
  regression harness (vendored block-parser stub, no docker) that this
  round's own regressions now imitate.
- **DUO-3214**: `Secrets::hard_match_deep()` + typed-snapshot secret guard +
  `Pending::current_value()` unserialize hardened with
  `allowed_classes=false` (object-injection fix on the review surface).
- **DUO-3221** (committed): `duo status` fails closed — code_mismatch, drift,
  blocked deletes now gate the exit code; CI can no longer get false-green
  promotion signals.
- **Conformance harness restructure**: the target env now receives plugin
  FILES only — `wp duo deploy` performs activation from canonical, so every
  conformance run exercises deploy reconciliation for real. All round-3
  conformance passes were under this stricter gate.

## Carried forward (round-4 backlog, in rough priority order)

- **#123** — order-preserving canonicalization for declared order-sensitive
  values + manifest-set audit (headliner; restores zero-exclusion identity).
- **#121** — sub-key option classification (polylang/wpseo blob shape); the
  per-language-menus and translated-CPT cases are its acceptance tests.
- **#124/#125/#126** — typed-snapshot grammar: derived tables with hard
  availability dependencies; composite PKs; sidecar PK name override.
- **#127** — deep secret scanning on the original capture path.
- **#128 / DUO-3300** — resolved by the pinned-stack fixture ruling and hard
  language-description/render regressions; no Duo mutator reproduced.
- Distribution sweep for "free but not wp.org" plugins (PMPro pattern).
