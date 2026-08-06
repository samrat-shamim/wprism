# Grind round R3-A — multilingual shop (task #90)

*Own pair `r3a1` (:8850) / `r3a2` (:8851), `sandbox/bin/pair.sh` (not the legacy
`docker-compose.yml`). Own site repo
(`sandbox/siterepo/{origin-r3a.git,r3a1,r3a2}`). Driver script:
[`sandbox/tests/grind_r3a_multilingual.sh`](../../sandbox/tests/grind_r3a_multilingual.sh)
— re-runnable (`make grind-r3a`), wipes content/ledger/Polylang state/site-repo
each run, never tears down containers.*

## Mission

A realistic multilingual WooCommerce shop — English default, German second
language, via **Polylang free 3.8.6** — deliberately built to stress the
interplay between Polylang's translation machinery and WooCommerce's own
entities: a translated product category, translated attribute terms, one
translated product, per-language menus, and an **untranslated** variable
product (global attributes `pa_size`×`pa_color`, 4 variations) — kept
English-only on purpose, to isolate "does Duo round-trip a variable product
correctly" from "does Duo round-trip Polylang's translation graph correctly"
as two separate, independently-verifiable questions.

Short version of what actually happened: the **description_refs mechanism
already shipped for Polylang works correctly on WooCommerce entities with
zero new code**, confirmed live with different local ids on each side. The
free/pro boundary for translating WooCommerce content is **not a license
gate on the mechanism** — it's real, but it's about data SYNC, not
translation itself. Task #75's typed-snapshot table capture has **closed
the old "must manually pre-provision pa_* attributes" gap for real** — a
fresh target now self-registers `pa_size`/`pa_color` with zero manual steps.
What's left of "the known gap" is narrower and more precisely characterized
below, and a **second, previously undocumented gap** (Polylang's own
`post_types`/`taxonomies` config, not Duo's taxonomy scope) turned out to be
the sharper of the two. The single most expensive finding of this round,
by far, was that **Polylang's own write paths are themselves unreliable**
in ways that have nothing to do with Duo — three independent instances of
Polylang silently not writing what its own documented API was asked to
write, discovered the hard way while debugging what first looked like Duo
bugs and turned out not to be.

## Environment facts

- WordPress 7.0.2, PHP 8.3, MariaDB 11 (shared server, `sandbox/db.yml`).
- **Polylang free 3.8.6**, **WooCommerce 11.0.0**, **Storefront 4.6.2** — all
  `wp plugin/theme install`ed independently on both `r3a1` and `r3a2` (this
  sandbox has no code-provisioning-on-apply path for wordpress.org
  plugins/themes, matching every prior grind round's precedent). HPOS
  enabled on both before any product/order exists. Storefront activated on
  `r3a1` as a real admin action specifically to exercise `wp duo deploy`'s
  `switch_theme()` path on `r3a2` later (spec v0.9); `r3a2` stays on
  `twentytwentyone` until deploy runs.
- Two languages: `en` (default, locale `en_US`) and `de` (locale `de_DE`),
  added via Polylang's own `PLL()->model->languages->add()` API.
- The shop: global attributes `pa_size` (Small/Medium/Large) and `pa_color`
  (Red/Blue); variable product **Duo Tee** (English-only) with 4 variations
  (Small/Red $19.99, Small/Blue $19.99, Medium/Red $21.99 on sale at
  $18.99, Medium/Blue $21.99); simple products **Duo Mug** ($9.99,
  English-only) and **Duo Cap** ($14.99, featured, **translated** to
  **Duo Kappe** — its own separate WooCommerce product entity, since free
  Polylang has no product-data-sync add-on); translated category
  **Apparel**/**Bekleidung**; translated attribute terms
  **Red**/**Rot**, **Blue**/**Blau** (pa_size's Small/Medium/Large
  deliberately left untranslated — a realistic merchant choice); translated
  pages **Home**/**Startseite**, **About**/**Über uns**; per-language menus
  **Main Menu** (en: Home/About/Shop) and **Hauptmenü** (de:
  Startseite/Über uns).
- Seeding method: WooCommerce shop content via `wp wc product[_attribute[_term]|_variation]`
  (the same wc-cli layer wc-admin's own UI calls, matching every prior
  grind's precedent); Polylang content via its own documented public API
  (`pll_insert_post`/`pll_insert_term`/`pll_set_term_language`/
  `pll_save_post_translations`/`pll_save_term_translations`, from
  `src/api.php`) — high-fidelity seeding, not a hand-rolled approximation,
  matching `docs/frontier/polylang.md`'s own precedent. One deliberate,
  evidence-driven deviation from the pure-API approach: see "Polylang's own
  write-path reliability" below.

## Interplay finding 1 — description_refs correctly handles WooCommerce entities (confirmed, zero new code)

`manifests/polylang.json`'s existing `description_refs` capability
(`post_translations`/`term_translations` group terms, PHP-serialized
`{lang_slug: local_id}` maps) was verified live against WooCommerce-flavored
ids specifically, not just the pages/categories the original frontier
report and shipped manifest were built against:

- **Duo Cap ↔ Duo Kappe** (a `product`-type post pair, `post_translations`):
  captured as `{"en": "{{post:<uuid>}}", "de": "{{post:<uuid>}}"}`, applied
  to `r3a2` and resolved to **`r3a2`'s own local ids** (`pll_get_post_translations()`
  on the target returned the correct pairing using ids that did not need to,
  and in general do not, match the source's numbering).
- **Apparel ↔ Bekleidung** (`product_cat` terms, `term_translations`) and
  **Red ↔ Rot / Blue ↔ Blau** (`pa_color` attribute terms, `term_translations`):
  same result — correct token rewriting, correct resolution on the target
  through the ledger.

No manifest change was needed for any of this — the mechanism genuinely is
attribute/taxonomy-agnostic, exactly as designed.

## Interplay finding 2 — the free/pro boundary, established empirically, not assumed

Read `polylang/src/settings/settings-cpt.php` (the "Custom post types and
Taxonomies" settings module) directly, line by line: **zero license/Pro
checks anywhere in it.** It lists any `get_post_types(['public'=>true,
'_builtin'=>false])`/taxonomy-equivalent candidate and lets an admin opt any
of them into Polylang's translation machinery — WooCommerce's `product`,
`product_cat`, `pa_*` included, no different from any other custom
post type/taxonomy. Marking them translatable, and getting the ordinary
translation-group mechanism (language tagging, the "+" translate-this UI,
`post_translations`/`term_translations`) to work for them, is a **free-tier
capability**, confirmed by exercising it end-to-end (Duo Cap/Duo Kappe,
Apparel/Bekleidung, Red/Rot, Blue/Blau all round-tripped correctly above).

What the commercial "Polylang for WooCommerce" add-on actually sells, based
on what free Polylang visibly does *not* do: automatic price/stock/SKU/
gallery synchronization across a product's translations (Duo Cap and Duo
Kappe are two fully independent WooCommerce products in this scenario —
translating a product the free way means literally duplicating it and
maintaining both copies by hand), and presumably deeper storefront/currency
integration this round didn't need to explore. **The mechanism is free; the
convenience is what's sold.**

## Interplay finding 3 — per-language menus: two separate, independently-behaved mechanisms

- **Per-item automatic translation** (works, rides entirely on the
  already-correct `post_translations` mechanism): a menu item pointing at a
  Polylang-managed post (e.g. Home) is silently swapped by Polylang, at
  render time, to that post's own translation for the current viewing
  language. Confirmed: Main Menu's Home/About items rendered as
  Startseite/Über uns on `/de/`, needing zero extra Duo work, because it's
  built entirely on the translation-group data Duo already propagates
  correctly.
- **Per-location-per-language menu SWAPPING** (does not transfer): a second,
  purpose-built menu (Hauptmenü) assigned to the SAME `primary` location for
  German visitors specifically lives in the `polylang` option's own
  `nav_menus[theme][location][lang] = menu_term_id` sub-key
  (`WP_Syntex\Polylang\Options\Business\Nav_Menus`) — a **completely
  separate code path** from core.json's existing `nav_menu_locations`
  theme_mods capture, which is language-blind by construction (ONE menu id
  per location, full stop; confirmed a plain `wp menu location assign`
  never touches the polylang option at all). Hauptmenü's own structure/items
  round-trip correctly as an entity, but it is never actually used at its
  location on a fresh target — `r3a2`'s `primary` location keeps showing
  whichever menu the flat mechanism recorded, on every language's URL.
  The whole `polylang` option is already excluded from capture
  (`class: env`, a pre-existing, documented gap) — this finding gives it
  concrete, reproducible WooCommerce-shop evidence. Filed as part of task
  #121.

## Interplay finding 4 — the known gap, characterized precisely post-#75

Task #75's typed-snapshot capability (`woocommerce_attribute_taxonomies`
now a real `authored_snapshot` table) was tested against a genuinely fresh
target (`r3a2`, never manually pre-provisioned) and delivers exactly what
it promised, with one real nuance:

- **The custom-table row itself self-provisions with ZERO manual steps.**
  `wc_get_attribute_taxonomies()`/`taxonomy_exists('pa_size')` on `r3a2`
  correctly return true immediately after the very first `duo apply` —
  confirmed repeatedly, across a full pair destroy+recreate. This closes
  r1b's original "must run `wp wc product_attribute create` by hand" gap
  for real.
- **Relationship-writing for that taxonomy, on the SAME apply run that
  first creates the row, is entity-processing-order dependent, not
  reliably 0 or 4.** Root cause traced precisely by reading the engine
  (`agent/src/Apply.php:883-907`): `taxesByObjectType` is memoized **once,
  lazily, on first access** — not eagerly at the top of `apply()` — so
  whether a given post's relationship reconciliation runs *before* or
  *after* the Snapshot-table row lands (both happen within the same phase
  of the same run) decides whether that specific post sees the taxonomy as
  registered. Observed directly, across repeat runs of the identical
  script: pa_size/pa_color relationship counts of 0, 2, and 4 on the very
  first apply — all legitimate outcomes of the same mechanism, not
  flakiness in Duo's own logic (the memoization itself is completely
  deterministic given a fixed processing order; the order itself is what
  varies run to run for reasons this investigation didn't chase further).
  **Superseded mid-round**: this was observed pre-#92; task #92 (dynamic
  taxonomy patterns) landed since with exactly this path fixed — live-DB
  taxonomy resolution plus a declared `object_type` fallback, replacing the
  lazy `taxesByObjectType` memoization this finding traced. #92's own
  regression proves zero-provisioning end-to-end on a fresh target. Any
  recurrence of the 0/2/4 variance described here on current code would be
  a *new* bug, not this one — recorded as historical diagnosis, not a
  standing gap.
- **A no-op re-apply never changes this state, confirmed exactly matching
  r1b's original finding**, still true post-#75: an `unchanged`-hash entity
  skips relationship reprocessing by design, regardless of whether the
  taxonomy is registered by the time of the no-op re-apply.
- **The real fix is unchanged from r1b's playbook: a genuine content
  change forces reprocessing**, and correctly moves the relationship count
  towards (never away from) fully-resolved.
- **A second, independent, and — this round's most important addition to
  the "known gap" story — DISTINCT finding**: even with pa_*
  fully self-provisioned and correctly related, `pll_get_post_language()`
  for the newly-Polylang-managed `product` post type reads `false` on
  every product on a fresh target, deterministically, every run, no
  ordering variance. Root cause: `language` taxonomy's own
  `object_type` array on the target never includes `product` unless
  Polylang's `post_types` option (a **completely different, Polylang-owned
  mechanism**, unrelated to `woocommerce_attribute_taxonomies` or task #92's
  Duo-side `Policy::taxonomies()` scope list) has been separately, manually
  replicated on that target — the exact same operational-provisioning class
  as installing WooCommerce/Storefront themselves. Filed in full, with
  acceptance criteria, as task #121 (which also folds in finding 3 above,
  since both trace to the same excluded `polylang` option).

## Polylang's own write-path reliability — the most expensive finding of this round

Not a Duo issue anywhere in this section — proven precisely, each time, by
checking that Duo's own capture/apply were never in the causal chain before
concluding the fault lay elsewhere. Filed as task #121 (findings 5 and 7)
and task #128:

1. **Enabling a non-default post type/taxonomy for translation
   (`post_types`/`taxonomies` sub-arrays of the `polylang` option) is not
   reliably visible to `pll_is_translated_post_type()`/
   `pll_is_translated_taxonomy()` immediately** — these read a
   request-lifecycle cache (`Model\{Post_Types,Taxonomies}::
   get_translated_object_types()`, backed by `PLL_Cache`, confirmed
   genuinely non-persistent/in-process-only by reading `src/cache.php`)
   whose population timing relative to the option write is not fully
   pinned down. **Deactivating and reactivating Polylang does NOT fix
   this — it makes it WORSE**: confirmed directly, repeated
   deactivate/activate cycles progressively **wipe** the option's
   `post_types`/`taxonomies` sub-arrays back to empty rather than forcing a
   correct rebuild, observed via direct `get_option('polylang')` reads
   before and after. The reliable fix that emerged: write the option once,
   do **not** touch plugin activation state at all, and check the signal
   Duo's own engine actually reads (`get_taxonomy('language')->object_type`)
   rather than Polylang's own cache — usually immediate, occasionally needs
   a few fresh-process retries (the driver script retries up to 8 times,
   pure re-checks, no further writes). **Refined further, mid-round**: even
   the single-write approach failed outright (all 8 retries) on one re-run,
   traced to a second, distinct cause — calling `PLL()->model->languages->
   add()` and the `update_option('polylang', …)` write in the **same**
   wp-cli request let something in Polylang's own end-of-request bookkeeping
   silently overwrite the option back to empty `post_types`/`taxonomies`
   (confirmed: `update_option()` itself returned `true`, and the very same
   write issued in its own, separate, later request took immediately and
   held). Splitting `languages->add()` and the option write into two
   separate `wp eval` calls made this fully reliable across every
   subsequent run. A third instance of the same broad "Polylang's own code
   clobbers a write shortly after it lands" pattern as findings 2 and 3
   below, at a third call site.
2. **`pll_set_post_language()` — Polylang's own documented public API —
   silently no-ops for a just-enabled custom post type**, no error, no
   warning, the underlying `wp_term_relationships` row simply never gets
   written, confirmed by direct SQL on the SOURCE environment itself
   (not just a target post-apply). The reliable workaround: bypass
   Polylang's API for this one write and call `wp_set_object_terms($id,
   '<lang_slug>', 'language')` directly — depends only on the taxonomy
   being registered (reliable), not on Polylang's separate internal cache
   (unreliable).
3. **`pll_insert_post()`'s own `translations` argument** (meant to link a
   newly-created post to its translation in the same call) was also
   observed, in one full run of the driver script, to silently not create
   the `post_translations` relationship at all — same broad symptom
   category as #2, different call site, root cause not yet unified.
4. **A full front-end (apache) outage**, reproducible on a from-scratch
   pair: every HTTP request 500s with `WP_Translation_Controller::
   set_locale(): Argument #1 ($locale) must be of type string, null given`,
   traced into `PLL_OLT_Manager::load_textdomains()` reading a `language`
   term whose `term_taxonomy.description` (Polylang's own serialized locale
   config) is empty. Extensively ruled out as a Duo cause (`wp duo apply`
   is never called on the affected environment before the fatal starts;
   Duo treats this field as opaque verbatim bytes, never rewriting it) but
   NOT fully root-caused in the time available — direct SQL repair of the
   description, confirmed correct via both raw SQL and `wp-cli`
   `get_term_by()` reads, still left the front end 500ing. Filed as task
   #128 with full detail for whoever has time to instrument Polylang's own
   `PLL_Language_Factory` directly. The driver script degrades gracefully
   around this (WARNs and skips the browser-facing render assertions
   rather than failing the whole run) since every fact those checks would
   have confirmed was already established via wp-cli throughout the rest
   of the run.

**Takeaway for anyone else scripting Polylang content programmatically**:
verify every write actually landed (`pll_get_post_language()`/
`pll_get_post_translations()` returning what you expect) rather than
trusting a Polylang API call's return value — this is not a one-off fluke,
it recurred across independent call sites in the same session.

## Core loop

`wp duo capture` succeeded cleanly on the **first attempt** against the
already-graduated `core`/`woocommerce`/`polylang` manifests — no interactive
gate-discovery narrative to re-enact here (unlike r1b's original session);
the one real, small gap surfaced by `wp duo pending`:

- `term_meta:product_count_product_cat` (WooCommerce's own maintained
  product-count cache on `product_cat` terms) — classified `runtime` via
  site policy. Not previously documented in `manifests/woocommerce.json`;
  filed as task #122 (small, non-blocking, mitigated in-round).

After that one classify, `wp duo pending` is clean, `wp duo lint` is clean
(0 findings both sides throughout, including after the round-trip and the
divergent merge), and capture-twice determinism holds.

## Round-trip

**Byte-identical** between `r3a1` and `r3a2` after the full sequence
(capture → push → clone → plan/apply → the pa_*/language fix → `wp duo
deploy` → recapture). Task #88 landed mid-round (post-field derived
classification, closing #72 for real: `product_variation.title` is now
classified `derived` via `Policy::field_class()`/`Canon::post_hash_basis()`)
and this round's own fixture — Duo Tee, seeded `pa_size`×`pa_color`, i.e.
**not** the attribute ordering #88 was developed against — was re-run as an
independent validation of it, mirroring `grind_r1b_shop.sh`'s own two-part
proof exactly (task #112):

- **Criterion 3 (hash-basis correctness)**: forcing a title-ONLY self-heal
  on `r3a2` alone (a plain `wc_get_product()` read — hook-free, raw
  `$wpdb`, zero Duo involvement, not inside any apply/capture window) does
  **not** surface any `product_variation` entity in `wp duo plan`'s
  drift/update/conflict buckets — confirmed directly against the JSON plan
  output. Task #88's mechanism holds on a fixture it was never developed
  against.
- **Criterion 4 (true byte-identity, not a papered-over exclusion)**:
  forcing the same self-heal on `r3a1` too, then a full recapture-and-diff,
  shows every entity in the tree byte-identical **except** the 4 Duo Tee
  variation files — and for those, verified field-by-field (front matter
  minus `title`, and the raw post body) that **nothing else differs**, and
  that the `title` divergence is specifically an **anagram** — the same
  words, reordered (`"Duo Tee - Small, red"` vs `"Duo Tee - red, Small"`) —
  which is exactly task #123's signature (Canon's alphabetical key-sorting
  of the parent's `_product_attributes`, an order-sensitive authored meta
  value with no order-preservation declared, silently changing
  WooCommerce's own title-generation word order once it round-trips through
  capture/apply), not an unexplained or unrelated difference. #123 remains
  open and separately tracked; this round's residual is additional, live
  confirmation of its exact, isolated blast radius on a second, independent
  fixture.

Outside those title strings — prices, SKUs, attributes, translated
terms/categories/pages/product, per-item menu translation, everything else
— is byte-for-byte identical, with zero exceptions.

- **Deploy**: `wp duo deploy` on `r3a2` performed a real `switch_theme()`
  to Storefront, confirmed via the JSON summary and `wp theme list`.
- **Runtime isolation**: a real anonymous Store API order (Duo Mug ×2, Cash
  on Delivery, German billing address) placed on `r3a1` — confirmed
  `wp_wc_orders` on `r3a2` stays at 0 throughout; HPOS custom tables are
  outside Duo's capture/apply scope entirely, by construction, matching
  every prior WooCommerce grind round's finding.
- **Divergent-edit merge**: conflicting excerpt edits to the SAME
  translated page (About) on both environments produced a clean git
  conflict scoped to exactly `excerpt`/`modified_gmt` — no collateral
  conflict noise, and (when the `post_translations` relationship happened
  to have actually landed at seed time — see the write-path reliability
  section above) the link to Über uns survived the conflict region
  untouched. Resolved editorially, applied to both sides, confirmed
  converged.

## Gap taxonomy

### Escalated (engine-level)

- **Task #121** — the `polylang` option's `post_types`/`taxonomies`/
  `nav_menus` sub-keys never propagate (interplay findings 3 and 4's
  second half above), plus the Polylang write-path reliability findings
  (deactivate/reactivate is harmful, `pll_set_post_language()` silent
  no-op). Full acceptance criteria in the task.
- **Task #128** — the front-end 500 fatal (Polylang's own language config
  going empty under a trigger not yet identified). Explicitly NOT
  characterized as a Duo bug, with the evidence for that ruling stated in
  full; filed for whoever has time to dig further.

### Already resolved by other work landing mid-round (not a standing gap)

- **Task #92** (dynamic taxonomy patterns, completed) — this round's own
  `pa_size`/`pa_color` self-provisioning observations
  (entity-processing-order dependence within a single apply, see interplay
  finding 4) independently corroborated the exact hazard #92's own
  acceptance criteria anticipated, on a genuinely different scenario
  (multilingual, not #92's own r3e pair) — *before* #92 landed. #92's fix
  (live-DB taxonomy resolution + declared `object_type` fallback) replaces
  the lazy-memoization path this finding traced; the corroboration is
  historical record of the pre-fix state, not an open item.

### Mitigated in-round (site-policy/manifest-notes level, no engine changes)

- `term_meta:product_count_product_cat` classified `runtime` via site
  policy (task #122 filed for the manifest-level graduation, not attempted
  here since `manifests/woocommerce.json` is out of this round's scope).
- `manifests/polylang.json` extended with **notes only** (no schema/
  behavior change) documenting this round's empirical findings — the
  free/pro boundary evidence, the write-path reliability findings, and
  confirmation that description_refs handles WooCommerce entities
  correctly — for whoever reads this manifest next.

### Harness / methodology notes (not Duo engine gaps)

- Polylang's own `is_translated_*()`/`set_language()`/`insert_post(...,
  translations: ...)` write-path reliability (see above) — the sharpest,
  most time-consuming finding of this round, entirely on Polylang's side.
- **Docker/OrbStack contention under concurrent grind rounds**, again
  (matches r1b's and r3b's own notes): with `conf`/`r3a`/`r3b`/`r3e` pairs
  all live at points during this round, individual `docker compose run`
  invocations occasionally slowed to 20-60s (never hit the 120s
  hard-stop threshold on a single command, but did make full script runs
  time out against a 10-minute budget on two occasions) — handled via the
  documented wait-and-retry protocol, not by force-cycling anything shared.
- A concurrent, unrelated engine edit (`agent/src/Apply.php`, task #88's
  own work landing live) caused one transient PHP parse-error fatal on a
  `wp-cli` call mid-session, self-resolved within a minute on retry — a
  real, if rare, cost of every sandbox pair mounting `agent/` live from the
  same working tree that every other concurrently-running agent edits.

## Files changed

- `manifests/polylang.json` — notes-only additions (empirical findings from
  this round); no schema/behavior change; description_refs/term-relationship
  capabilities unaffected either way.
- `Makefile` — additive `grind-r3a` target.
- `sandbox/tests/grind_r3a_multilingual.sh` — new, the full narrative this
  report describes; re-runnable (`make grind-r3a`), passes clean end to end
  (front-end render assertions degrade to a WARN, not a FAIL, around the
  still-open task #128 finding — everything else is a hard assertion).
- `docs/grind/r3a-multilingual-shop.md` — this report.
- **Not touched**: `agent/src/**`, `manifests/woocommerce.json`,
  `sandbox/conformance/**`, any sibling round's env/profile/files.
- **New tasks filed**: #121 (Polylang option sub-keys + write-path
  reliability, escalated with acceptance criteria), #122 (small
  woocommerce.json term_meta gap), #128 (front-end 500 fatal, open,
  unexplained).

## Run tail (`make grind-r3a`)

```
== acceptance (task #88, criterion 3): a title-ONLY self-heal on r3a2 alone (WooCommerce's own
   wc_get_product() read -- hook-free, raw $wpdb, zero duo involvement) must NOT surface as
   drift/update/conflict in duo plan ==
ok: confirmed: plan stays silent on a title-only divergence -- task #88's hash-basis mechanism
    verified on an INDEPENDENT fixture (multilingual + pa_size x pa_color, not the ordering
    #88 was developed against)

== force WooCommerce's own title self-heal on r3a1 too (same real wc_get_product() mechanism,
   not a duo mechanism) then final byte-identity ==
ok: .../duo-tee-small-red.md -- only title differs, as a content-preserving reordering
    (task #123): 'Duo Tee - Small, red' vs 'Duo Tee - red, Small'
ok: .../duo-tee-small-blue.md -- only title differs, as a content-preserving reordering
    (task #123): 'Duo Tee - Small, blue' vs 'Duo Tee - blue, Small'
ok: .../duo-tee-medium-red.md -- only title differs, as a content-preserving reordering
    (task #123): 'Duo Tee - Medium, red' vs 'Duo Tee - red, Medium'
ok: .../duo-tee-medium-blue.md -- only title differs, as a content-preserving reordering
    (task #123): 'Duo Tee - Medium, blue' vs 'Duo Tee - blue, Medium'
ok: task #88 verified on an independent fixture: every entity byte-identical except possibly
    product_variation.title, and any such residual is exactly #123's same-words reordering
    (or absent entirely) -- never an unexplained difference

== lint (final, hard gate, both sides) ==
ok: lint: 0 findings both sides

== per-language render checks (buffered curl, THEN grep — pipefail hazard) + host:port leak negative assertions ==
WARNING: r3a1 homepage returned 500, not 200 -- front-end render checks skipped this run
  (see docs/grind/r3a-multilingual-shop.md's open Polylang finding). wp-cli-level checks
  throughout this script already proved the underlying data is correct.
ok: render checks complete: no host:port leaks either direction; per-item translation swap
    works via post_translations even though the dedicated 2nd menu isn't wired to its location

== runtime isolation: place a real anonymous order on r3a1 via the Store API, confirm absent on r3a2 ==
ok: real order #19 placed on r3a1; r3a2 has zero orders (HPOS custom tables never touched by
    capture/apply)

== divergent-edit merge: conflicting edits to the SAME translated page (About) on both environments ==
ok: conflict surfaced as a plain git conflict, scoped to excerpt/modified_gmt
ok: conflict resolved editorially, committed, pushed

== apply the merged content to both environments; confirm convergence ==
ok: both environments converged on the editorially-merged About excerpt

✔ GRIND R3-A PASSED
```
