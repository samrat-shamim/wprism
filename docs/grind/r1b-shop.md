# Grind round R1-B — a full WooCommerce shop (task #47)

*Own env pair `r1b1` (:8816) / `r1b2` (:8817), docker-compose profile `r1b`, journal on (`DUO_JOURNAL`). Own site repo (`sandbox/siterepo/{origin-r1b.git,r1b1,r1b2}`). Driver script: [`sandbox/tests/grind_r1b_shop.sh`](../../sandbox/tests/grind_r1b_shop.sh) — re-runnable, resets r1b1/r1b2 content + ledger + journal + attribute/shipping/tax tables each run; passes clean end to end (`make grind-r1b`). No `agent/src/` edits — every mitigation below is manifest/policy-level. `manifests/woocommerce.json` is this round's own manifest (extended honestly, not faked).*

## Mission

A realistic full shop: Storefront theme, a variable product with global attributes (`pa_size`, `pa_color`) and 4 variations, a grouped product, a shipping zone with two methods, two tax rates, a featured product, HPOS on. The deliberate stress points named in the brief: `woocommerce_attribute_taxonomies` (a custom table — the manifest already marked it `authored_typed_snapshot_post_v1`, unimplemented), the `pa_*` taxonomies it spawns (**dynamic taxonomy names**), variations (post_parent chains, meta-key names shared with simple products), and shipping-zone/tax-rate custom tables. Short version of what actually happened: **the sharpest finding wasn't any of those** — it was that `_price` is genuinely *multi-valued* on a variable product's parent (WooCommerce writes one row per distinct variation price), and the v0 engine hard-refuses multi-row `authored` meta. Every variable product on earth would have hit this. The named stress points were all real too, and are characterized precisely below, but they were the *second* layer of gate, reachable only after `_price` was fixed.

## Environment facts

- WordPress 7.0.2, PHP 8.3, MariaDB 11. WooCommerce 11.0.0, Storefront 4.6.2 — both `wp plugin/theme install` on **both** r1b1 and r1b2 independently (matching Spike D's precedent: Duo has no code-provisioning-on-apply path for wordpress.org plugins/themes, only for a site's own vendored `code/`). HPOS enabled on both before any product/order exists.
- Storefront activated on r1b1 as a real admin action (`wp theme activate`) specifically to exercise `wp duo deploy`'s `switch_theme()` path on r1b2 later (spec v0.9's code-half deploy section) — r1b2 stays on the default `twentytwentyfive` until deploy runs.
- The shop: global attributes `pa_size` (Small/Medium/Large) and `pa_color` (Red/Blue/Black); variable product **Duo Tee** with 4 variations (Small/Red $19.99, Small/Blue $19.99, Medium/Red $21.99 on sale at $18.99, Medium/Blue $21.99); grouped product **Duo Bundle** (children: Duo Mug $9.99, Duo Sticker Pack $4.99); featured simple product **Duo Cap** ($14.99); shipping zone **United States** (country match `US`) with `flat_rate` ($5.99, taxable) + `free_shipping` (min order $50); two tax rates (CA 7.25%, NY 4%, both shipping-taxable); Cash on Delivery enabled for checkout.
- Seeding method: `wp wc product_attribute[_term]`/`wc product[_variation]`/`wc shipping_zone[_method]`/`wc tax` (the REST-backed wc-cli, the same layer wc-admin's own UI calls) wherever it covers the field; direct `update_post_meta()`/`WC_Shipping_Zone`/`WC_Shipping_Zones::get_shipping_method()` PHP calls where the CLI doesn't expose a field (mirroring Spike D's established fallback pattern) — **with one seeding-method finding of its own**: `WC_Product_Grouped::set_children()` followed by `->save()` reliably no-ops in this environment (the in-memory object reflects the just-set children and `save()` reports success, but the persisted `_children` postmeta comes back `a:0:{}` on the very next read) — root cause not identified; a direct `update_post_meta($id, '_children', [...])` is 100% reliable and writes the exact same real shape, so that's what the seed and the report both use. Not escalated as an engine gap (it's WooCommerce's own object-model behavior, not Duo's), but noted for anyone else scripting grouped-product fixtures.

## The core finding: `_price` is multi-valued on a variable product, and v0 refuses multi-row authored meta

Confirmed empirically (`DESCRIBE`/raw `SELECT` against a live install, not assumed): a variable product's parent carries **one `_price` postmeta row per distinct variation price** — Duo Tee's 4 variations at {19.99, 19.99, 18.99, 21.99} produced exactly 3 rows (`{18.99, 19.99, 21.99}`). This is WooCommerce's own mechanism for letting its price-range catalog filter do a plain `meta_query` range match on `_price` without a dedicated min/max column — not a fixture artifact.

`manifests/woocommerce.json` (Spike D) classified `_price` `authored` for good, documented reasons — it's redundant with `_regular_price`/`_sale_price` but capturing it directly avoided writing a rebuilder. Spike D never exercised a variable product, so it never met the multi-row shape. `agent/src/Capture.php`'s authored-meta path assumes exactly one row per key and throws (`"duo: multi-value authored meta '_price' on post N unsupported in v0"`) the instant it meets a second one — the *exact* guard the manifest's own `_used_by` note already documents for coupon redemptions, just never triggered by `_price` until this round. **Consequence, stated plainly: no variable product could be captured at all, full stop, regardless of any other manifest work, while `_price` stayed authored.**

Classification isn't post-type-scoped in this engine (`Policy::rule()` never takes a post type — confirmed by reading it), so the fix has to be global. **`manifests/woocommerce.json` now classifies `_price` `derived`** — semantically correct (Spike D's own note already established it's fully computable from other authored fields) and exempt from the multi-row guard entirely (the guard only applies to `class === 'authored'`, confirmed by the exact line in `Capture.php`). Verified empirically that this doesn't quietly break anything: `WC_Product_Variable`'s displayed price range and each variation's own purchasability come from the variations' own (single-valued) price fields, live-queried — never from the parent's own `_price` meta, which is a catalog-search optimization the product/checkout pages don't read. No rebuilder was added for it — nothing in WooCommerce's own wp-cli-reachable surface forces that specific resync, and smuggling a PHP one-liner through a rebuilder's plain command string felt like exactly the "regex-on-strings" fragility `DESIGN.md` warns against for a payoff testing showed unnecessary. If a future scenario needs the parent's price-range meta_query to work (a shop price-filter widget, say), that's the trigger to revisit — not a hypothetical guarded against today.

**Current status:** that final sentence records the original R1-B discovery, not
the present contract. The WooCommerce 11.x manifest now has a synchronous,
bounded product/variation regenerator for effective `_price`, product and
global-attribute lookups, and per-product sale schedules. It deliberately does
not run the former whole-catalog projection command; `wc_category_lookup`
remains an explicit manual repair/verification boundary because Woo exposes no
public bounded regeneration method for it.

## The core loop, and why it isn't re-enacted verbatim in the script

The *discovery* sequence — `duo capture` hitting the loud gate on `_children`/`_default_attributes`/`_product_attributes` (grouped/variable-product meta), `wp duo pending` surfacing a real `ref_hint` for `_children` (a small number coinciding with Duo Mug's actual post id), `wp duo classify --set=...` resolving each deliberately, then a second wave on `attribute_pa_color`/`attribute_pa_size`/`_variation_description` once `product_variation` entered scope — happened once, interactively, against a manifest that didn't yet have these rules. That transcript:

```
Error: duo: multi-value authored meta '_price' on post 10 unsupported in v0
  [reclassify _price -> derived]
Error: duo: unclassified meta keys on in-scope entities (loud-and-blocking gate):
  - post_meta:_children
  - post_meta:_default_attributes
  - post_meta:_product_attributes
  [wp duo pending shows a post ref_hint for _children; wp duo classify resolves all three]
Error: duo: unclassified meta keys on in-scope entities (loud-and-blocking gate):
  - post_meta:_variation_description
  - post_meta:attribute_pa_color
  - post_meta:attribute_pa_size
  [wp duo classify resolves all three; capture succeeds]
```

Those decisions are now folded into `manifests/woocommerce.json` — the whole point of graduating a classify session into the shared manifest is that a *fresh* site never has to rediscover them. `sandbox/tests/grind_r1b_shop.sh` runs against that graduated manifest, so re-enacting the gate in the script would be fiction (capture just succeeds). What the script *does* verify, honestly: with `manifests/woocommerce.json` alone and an empty site policy, capture succeeds cleanly and `wp duo pending` shows **zero** outstanding items for any key this round introduced — proof the manifest, not a site override, is what closes the gap now.

## `attribute_pa_*`: solved cleanly by a pattern; `pa_*` taxonomies: not solvable the same way

Every used-for-variation global attribute mints a variation-level postmeta key named `attribute_<taxonomy>` (`attribute_pa_size`, `attribute_pa_color`, …) — dynamic per attribute. Confirmed empirically the value is always the term **slug** as a plain string (`small`, `red`) — no numeric id, ever, regardless of which attribute the key names. `manifests/woocommerce.json` now declares `"meta_patterns": [{"match": "^attribute_pa_", "class": "authored"}]` — one line covers every past and future global attribute's variation meta. This is the first use of `meta_patterns` in this manifest for a genuinely open-ended (not versioned-suffix) dynamic key family, and it works because `Policy::option_rule()`/`post_meta_rule()` *do* consult the pattern-fallback arrays.

The taxonomy itself has no equivalent escape hatch, and this is a real, load-bearing engine gap, not a manifest-authoring gap:

- `Policy::taxonomies()` returns `site.duo.json`'s `policy.taxonomies` as a **flat, exact-string list** — confirmed by reading it: `PATTERN_KEYS` (the map that gives `options`/`post_meta`/`term_meta` their regex fallback) has no `taxonomies` entry at all. There is no way to declare "any taxonomy matching `^pa_`" — every new global attribute needs a human to add its exact `pa_<name>` string to `site.duo.json`, forever, on every site that creates one.
- **Term DATA round-trips through the existing generic taxonomy machinery with zero new engine code** once the bare taxonomy name is in scope — confirmed: adding `pa_size`/`pa_color` to `policy.taxonomies` captured Small/Medium/Large and Red/Blue/Black exactly like `category`/`product_cat`, no special-casing needed anywhere.
- **The sharper failure is on the target, not the source.** `pa_size`/`pa_color` exist as *registered taxonomies* only because WooCommerce reads `wp_woocommerce_attribute_taxonomies` (a custom table — see below) on `init`. Nothing captures or applies that table. On a fresh target that has never had the attribute created through its own admin/wp-cli, `get_taxonomy('pa_size')` is `false`. `Capture.php`'s own `taxes_by_object_type()` already anticipates this for the *capture* side (a named warning, not a crash); the reproducible, precise finding here is what happens on **apply**:

  ```
  Warning: taxonomy 'pa_color' is in policy scope but not registered on this environment (plugin inactive?)
    — cannot determine which object type its relationships belong to, so its relationships
    are skipped for every post and term on apply
  Success: applied 23 entities (canary clean)
  ```

  Apply does **not** hard-fail. It warns and proceeds. Empirically confirmed the exact blast radius: `wp_terms`/`wp_term_taxonomy` rows for the actual terms (Small, Medium, Red, Blue, …) get created fine via apply's direct-SQL phase 1 (term creation doesn't check taxonomy registration at all) — but Duo Tee's **term_relationships** under `pa_size`/`pa_color` are silently skipped entirely. Confirmed via the real Store API: the parent read `"attributes": []` and `"is_purchasable": false`; a specific variation's own postmeta (price, SKU, `attribute_pa_color=red`) was already byte-correct — the damage is scoped *exactly* to the parent's own attribute-term associations, nothing else.
- **The mitigation, proven live, and its real limits**: pre-provisioning the same global attributes on the target (`wp wc product_attribute create` — the identical real admin action used to create them originally, same operational discipline as WooCommerce/Storefront themselves being installed independently on both environments rather than propagated through git) registers the taxonomy — but **does not retroactively fix anything by itself**. Proven by test: re-running `duo apply` immediately after registering the attribute, with no content change, applies **0 entities** and the relationships remain **0** — an entity whose captured hash is unchanged from the last successful apply is classified `unchanged` and skips relationship (re-)processing entirely, by design. The real fix needs the source entity to genuinely change (a real content edit, forcing re-capture and an `update` classification on the next apply) — which then *does* correctly write all 4 relationships. **Registering the taxonomy is necessary but not sufficient; something must also make the referencing entity look "changed" again.**

## Custom tables: honestly, there is no capture/apply path for any of them

Stated precisely, because this round's brief specifically asked not to fake coverage: **`Capture.php` has zero code path that reads any custom table into canonical state, for any manifest, ever.** `Policy::table_rule()` — the only consumer of a manifest's `"tables"` section — is called exactly once in the whole codebase, from `Journal.php`'s `ground_truth()`, purely to score provenance-journal proposals (any table classed anything other than `authored`/`runtime`/`derived`/`env` maps to verdict `managed`, i.e. deliberately-out-of-scope, never `unclassified`). It has **no effect whatsoever** on what `duo capture`/`duo apply` actually do. Every table below is added to `manifests/woocommerce.json` as an intent marker only, matching the exact convention the manifest already established for `woocommerce_attribute_taxonomies` before this round touched it:

```json
"woocommerce_shipping_zones": {"class": "authored_typed_snapshot_post_v1"},
"woocommerce_shipping_zone_locations": {"class": "authored_typed_snapshot_post_v1"},
"woocommerce_shipping_zone_methods": {"class": "authored_typed_snapshot_post_v1"},
"woocommerce_tax_rates": {"class": "authored_typed_snapshot_post_v1"},
"woocommerce_tax_rate_locations": {"class": "authored_typed_snapshot_post_v1"}
```

Column shapes below are `DESCRIBE`'d against a live WooCommerce 11.0.0 install, not assumed — precise enough to write acceptance criteria from (see next section).

| Table | Key shape | Cross-references |
|---|---|---|
| `woocommerce_attribute_taxonomies` | `attribute_id` PK auto_increment, `attribute_name` (bare, e.g. `"size"` — **not** `pa_size`), `attribute_label`, `attribute_type`, `attribute_orderby`, `attribute_public` | **None.** `attribute_id` is never stored anywhere else as a raw int — the only thing that survives elsewhere is the *derived* taxonomy name (`wc_attribute_taxonomy_name()` = `'pa_' . sanitize_title(attribute_name)`), a string. A natural key (`attribute_name`) exists and is stable. |
| `woocommerce_shipping_zones` | `zone_id` PK auto_increment, `zone_name`, `zone_order` | No FK columns of its own, but IS a referenced parent — 2 child tables point at `zone_id`. |
| `woocommerce_shipping_zone_locations` | `location_id` PK, `zone_id` FK → shipping_zones, `location_code` (semantic string, e.g. `"US"`/`"US:CA"` — not a ref), `location_type` | One real FK column: `zone_id`. |
| `woocommerce_shipping_zone_methods` | `zone_id` FK, `instance_id` PK auto_increment, `method_id` (plain string, `"flat_rate"`/`"free_shipping"`), `method_order`, `is_enabled` | `zone_id` FK; **and `instance_id` is itself embedded in an option NAME** (see below) — the sharpest sub-problem in this table. |
| `woocommerce_tax_rates` | `tax_rate_id` PK auto_increment, country/state/rate/name/priority/compound/shipping/order/class (all plain scalars) | No FK columns of its own; referenced parent for 1 child table. |
| `woocommerce_tax_rate_locations` | `location_id` PK, `tax_rate_id` FK → tax_rates, `location_code`, `location_type` | One real FK column: `tax_rate_id`. |

**The genuinely novel sub-problem**, sharper than "declare column-level refs on a custom table" (`DESIGN.md`'s own framing for typed snapshot): shipping method **instance settings** (title/cost/tax_status for flat_rate; min_amount for free_shipping) are not columns on `woocommerce_shipping_zone_methods` at all — they live in `wp_options`, under a name that bakes in the method instance's own auto-increment id: `woocommerce_<method_id>_<instance_id>_settings`. Confirmed live: creating a `flat_rate` method on a fresh zone minted the option `woocommerce_flat_rate_1_settings`; a `free_shipping` method on the same zone minted `woocommerce_free_shipping_2_settings` — `1` and `2` being *this environment's own* `instance_id` auto-increment sequence. Three compounding problems, not one:

1. The option's **name**, not just its value, contains an environment-local id — no `"ref"`-on-value declaration (the only kind this manifest format has) can fix a ref baked into the key itself.
2. That id is minted by a **different table's** auto-increment counter — resolving it needs a ledger lookup keyed by a different entity's identity, a cross-table dependency no current option rule expresses.
3. `Capture::build_options()` reads only `Policy::authored_options()`, which iterates manifests'/policy's **exact-match** `options` maps — confirmed by reading it directly, it never consults `option_patterns` at all. So even a matching `option_patterns` rule would never make `build_options()`'s SELECT scan discover and read the row (unlike `attribute_pa_*` post-meta above, where the pattern mechanism actually is wired into the discovery path). And a static exact-name rule can't work either, because the instance id is unpredictable in advance — minted per zone/method, per environment, in creation order.

This shape needs new engine capability on three axes simultaneously (id-in-option-name resolution, cross-table ledger dependency, and a discovery mechanism replacing the exact-key options whitelist) before it can even become a manifest-authoring problem. Not attempted here — characterized instead.

## Typed-snapshot acceptance criteria (combined with the sibling round's nf3_* findings)

Grind R1-A (task #46, `docs/grind/r1a-forms.md`) independently hit the identical root gap from Ninja Forms' `nf3_forms`/`nf3_fields`/`nf3_actions` family and wrote its own acceptance criteria (now task #75). Two independently-discovered plugins needing the exact same missing engine tier is exactly the confirmation `DESIGN.md`'s typed-snapshot design needed before being built. Combining both explorations, a typed-snapshot implementation needs:

1. **New `Ledger::KIND_*` id_kind labels per source table** (`shipping_zone`, `shipping_zone_method`, `tax_rate`, `attribute_taxonomy`, …, alongside R1-A's `nf3_form`/`nf3_field`/`nf3_action`) — `duo_map`'s schema already supports arbitrary `id_kind` strings (confirmed: no enum constraint), only the PHP call sites are hardcoded to post/term/term_taxonomy/user/comment.
2. **New `Tokens::KIND_MAP` entries + token forms** (`{{shipping_zone:<uuid>}}`, `{{attribute_taxonomy:<uuid>}}`, …) so a captured FK column (`zone_id` in `shipping_zone_locations`/`shipping_zone_methods`; `tax_rate_id` in `tax_rate_locations`) tokenizes/detokenizes exactly like `{{post:...}}` today.
3. **A UUID-minting side channel for rows with no meta table of their own.** Posts/terms get `_duo_uuid` via postmeta/termmeta; none of these six tables has an equivalent column, and adding one to WooCommerce's own schema is out of the question. Two candidates, same tension R1-A found independently: a new duo-owned mapping table (`uuid <-> table + local_id`), or a declared natural key where one demonstrably exists — **`woocommerce_attribute_taxonomies.attribute_name`** is exactly such a case (confirmed stable, human-chosen, unique per site), while `zone_id`/`instance_id`/`tax_rate_id` have no natural key at all (a zone is identified only by its auto-increment id and a mutable `zone_name`) and need the mapping-table approach.
4. **Column-level ref declarations + a declared apply ordering**, mirroring the existing `"phase": "early"` convention for ACF's definition CPTs: parent tables (`shipping_zones`, `tax_rates`, `attribute_taxonomies`) must resolve before the children that FK into them (`shipping_zone_locations`/`shipping_zone_methods`; `tax_rate_locations`) — a real two-level dependency, one level deeper than R1-A's `nf3_forms → {nf3_fields, nf3_actions}` single-parent case.
5. **A rebuilder that clears WooCommerce's own runtime caches after any typed-snapshot write** — mirroring R1-A's `nf3_upgrades` cache finding exactly: WooCommerce caches attribute taxonomy lookups in the `wc_attribute_taxonomies` transient (confirmed empirically: deleting the underlying table row without also clearing this transient left `wc_get_attribute_taxonomies()` serving the stale list, causing a real, reproducible "slug already in use" error against a table that was actually empty — this bit *this round's own test script*, not a hypothetical). A production typed-snapshot apply needs the equivalent of `wp transient delete wc_attribute_taxonomies` (and shipping/tax's own cache invalidation calls) in its rebuild pass.
6. **The option-name-embedded-ref problem is a genuinely new dimension R1-A's fixture never needed** (Ninja Forms has no analogous "this option's name contains another table's auto-increment id" shape) — items 1-4 above do not solve it. It needs a fourth mechanism: resolving an option's *name* against a ledger before the options-capture whitelist can even discover the row, not just its value. Flagged as the hardest open sub-problem of the two rounds combined.

**Proposed acceptance test** (mirrors `docs/frontier/polylang.md`'s format and R1-A's own criteria): reseed this round's exact fixture (the attribute + shipping-zone + tax-rate set above) on a fresh env pair. After capture → apply → re-capture: (a) byte-diff clean on `state/` (already true today for everything *except* these six tables, since nothing captures them — the real target metric is these tables' own captured representation, not the absence of a diff); (b) `wc_get_attribute_taxonomies()` on the target returns `pa_size`/`pa_color` correctly registered using the target's own local `attribute_id`, with **zero manual pre-provisioning step**; (c) a checkout against a variable product on the target correctly applies the target's own shipping zone (flat-rate $5.99 for a US address) and tax rate (CA 7.25%) with **zero manual zone/rate re-creation**; (d) the `woocommerce_<method>_<instance>_settings` option resolves correctly on the target even though its embedded instance id differs from the source's.

## Round-trip r1b1 → r1b2

- **Byte-identical, with one open, tracked exception.** `canonical(r1b1) == canonical(r1b2)` for every field of every entity — prices, SKUs, stock-management flags, `_children`, `_default_attributes`, `_product_attributes`, `attribute_pa_*`, pa_size/pa_color term data, options — **except** the 4 variations' auto-generated `post_title` (WooCommerce's own "Duo Tee - Small, Red"-style label), which reads in a **transposed attribute order** on the target (`"Duo Tee - Red, Small"`) despite `Apply.php` writing the captured file's `title` field verbatim (confirmed by reading both write sites — `ensure_post_row()` and `finalize_post()` — neither transforms it) and the canary reporting zero hook fires (ruling out a WooCommerce resave). Root cause **not** identified despite ruling out the three obvious candidates (source-file mismatch, a hook-driven resave, a transform in the write path) — escalated as task #72 rather than guessed at. Does not affect price/SKU/stock-flag/attribute-value correctness or purchasability, confirmed separately.
- **`wp duo deploy` on r1b2**: real `switch_theme()` call to Storefront (`{"theme_switched": "storefront"}`), confirmed by the rendered homepage actually carrying Storefront's own markup/asset paths — the theme half of spec v0.9's code-half deploy section, exercised for the first time in this round (Spike G exercised the plugin half).
- **Hard lint gate, including a deliberate before/after**: dropping `_children`'s `"ref": "post[]"` declaration and recapturing produced exactly 2 `bare_id` findings, correctly naming Duo Mug and Duo Sticker Pack by title (not just by number) via `wp duo lint`; restoring the declaration and recapturing returned to 0 findings. Final state: 0 findings.
- **Anonymous Store API session, browse-to-checkout, for real**: product-page GET → `GET /cart` (nonce) → `POST /cart/add-item` (a *variation* id) → `POST /checkout` (Cash on Delivery) produced a real order (`order_id`, `status: "processing"`) with WooCommerce's own tax/shipping engine correctly computing the configured flat-rate shipping and CA sales tax against the cart total — a more realistic runtime-data-creation path than Spike D's `wc_create_order()` PHP call, since it goes through the exact customer-facing REST surface Storefront/Blocks checkout actually uses.
- **A real, useful discovery about `_stock`, not just "it's runtime"**: a freshly-`duo`-applied stock-managed variation has **no `_stock` postmeta row at all** (absent, not zero — `_manage_stock=yes` applies correctly, `_stock`/`_stock_status` don't, because `_stock` is `runtime`-classified and therefore never in the captured file to apply in the first place). `is_purchasable` on the Store API reads `true` regardless — but the **cart-add endpoint's own stock check treats the absence as zero** and refuses with `woocommerce_rest_product_partially_out_of_stock` / *"0 remaining"* (confirmed live, deliberately reproduced before fixing it). This is the correct, intended consequence of `_stock` being env-local by design — every environment's inventory is genuinely its own fact, never something git should propagate — but it means a round-tripped stock-managed product is **not actually sellable until the target environment's own operations process sets a real count**, which is a materially stronger statement than "informational." Setting `_stock`/`_stock_status` locally on r1b2 (never through duo) is exactly that real-world step, and once done, a Store API add-to-cart of the round-tripped Small/Red **variation** succeeds cleanly (price $19.99, correct attributes).
- **Runtime isolation, confirmed at both the custom-table and post-meta grain**: r1b1's Store API order is a `wc_orders` row (HPOS) — `SELECT COUNT(*) FROM wp_wc_orders` is `0` on r1b2, not because anything actively blocked it but because nothing in the capture/apply pipeline ever looks at that table at all. The two environments' stock counts diverge independently and permanently (r1b1 = 14 after its own sale; r1b2 = 15, set by its own "ops team" step above) with neither ever overwriting the other via `apply`.

## Divergent-edit merge

Conflicting price edits to the **same variation** on both environments (r1b1: Small/Red → $17.99; r1b2: Small/Red → $22.99, independently). Git surfaced a genuine, precisely-scoped conflict: `_regular_price` inside the variation's own entity file (17.99 vs 22.99), plus a second, incidental conflict on `modified_gmt` in **both** the variation file and its **parent's** file — WooCommerce bumps the parent's own modified timestamp when a variation changes, so both environments' edits touched it differently too. Canonical JSON's one-key-per-line, sorted-key format kept both conflicts scoped to exactly the lines that actually differed — no collateral conflict noise anywhere else in either file. Resolved editorially (split the difference: $19.99 — a defensible "let's just agree" call, matching Spike B's own resolution style) and the later `modified_gmt` picked for both. Applied to both environments; both converged on $19.99, confirmed by direct meta read on each side.

## Gap taxonomy

### Escalated (engine-level)

- **Task #72 — a product_variation's `post_title` diverges from the captured value after apply, root cause unidentified.** See "Round-trip" above. Three obvious causes ruled out by direct code/behavior inspection; genuinely open.
- **Typed-snapshot capture/apply for authored custom tables** — this round's entire "Custom tables" + "Typed-snapshot acceptance criteria" sections above are the WooCommerce half of this; task #75 (filed by the sibling R1-A round from Ninja Forms) is the other half, and the two together now have two independently-confirmed real fixtures and a combined acceptance-criteria writeup good enough to build from.
- **Post-type and taxonomy scope have no gate at all — a silent-drop asymmetry with post_meta/options.** `Policy::post_types()`/`Policy::taxonomies()` are flat site-policy lists with no manifest-level default and no pattern fallback (unlike `option_patterns`/`meta_patterns`, which exist and are consulted for exact-match discovery of options/post_meta). Confirmed live in this round's own script: capturing with `product_variation`/`pa_size`/`pa_color` left out of scope produced **zero warnings** and silently excluded 4 variation posts and 6 terms entirely — capture just reports a smaller, quietly-incomplete number. Contrast the loud, blocking gate this exact shop hit for `_children`/`attribute_pa_*` etc. — those are post_meta keys on IN-scope entities, which *does* gate. A sibling round (R1-C) independently found the identical asymmetry from `elementor_active_kit` (an option-level dangling ref that drops silently rather than blocking) and escalated it as task #73; this round's finding is the same asymmetry at the *scope* layer rather than the *ref-resolution* layer — worth reading together when someone designs the fix, since "unclassified/unscoped data must never silently disappear" is the same principle in both cases and a single engine change (some form of scope-completeness check, analogous to the existing meta-key gate) could plausibly close both.

### Mitigated in-round (manifest/policy-level, no engine changes)

- `_price` reclassified `authored` → `derived` in `manifests/woocommerce.json` (the load-bearing fix — nothing else in this round mattered until this landed).
- `product_variation` added to the manifest's `post_types` (authored); `_children` (ref `post[]`), `_default_attributes`, `_product_attributes`, `_variation_description` added to `post_meta`; `meta_patterns` gained `{"match": "^attribute_pa_", "class": "authored"}`.
- Five WooCommerce custom tables added to the manifest's `tables` section as honest `authored_typed_snapshot_post_v1` intent markers (zero behavioral effect, as explained above — documentation of what's needed, not a claim of coverage).
- `pa_size`/`pa_color` added to a site's own `policy.taxonomies`, and (operationally, not automatically) the same global attributes pre-provisioned by hand on the target before its first apply that references them — the necessary manual step until typed-snapshot ships, documented precisely rather than hidden.

### Harness / methodology notes (not Duo engine gaps)

- **`WC_Product_Grouped::set_children()` + `save()` is unreliable immediately after `product create` in this environment** — see "Environment facts" above. Worked around with a direct `update_post_meta()` call, which produces the identical real data shape.
- **`wc_get_attribute_taxonomies()`'s transient cache survives a `DELETE FROM wp_woocommerce_attribute_taxonomies`** — a re-run of this round's own reset logic hit a real "slug already in use" error against a table that was actually empty, until `wp transient delete wc_attribute_taxonomies` was added to the reset. The exact same caching behavior is why a real typed-snapshot implementation needs a rebuilder, not just a table write (see acceptance criteria above) — this is the same finding surfacing twice, once as a test-harness footgun and once as a production requirement.
- **wp-cli's `WP_CLI::warning()` writes to STDERR, not STDOUT** — a `docker compose run ... | grep 'warning text'` misses it unless stderr is explicitly merged (`2>&1`) into the pipe. Bit this round's own driver script during authoring (a warning clearly visible in the terminal, invisible to a bare stdout-only pipe) — noted for the next person piping `wp duo apply`/`plan`/`deploy` output through a filter expecting to see its warnings.
- **Docker daemon contention across concurrently-running grind rounds** (also independently noted by R1-C): mid-round, the shared Docker daemon (OrbStack) became unresponsive for an extended period while r1a/r1b/r1c were all running their own multi-container stacks simultaneously; recovered after the team lead force-restarted the VM manager, with r1b1/r1b2's volumes intact. No data was at risk (nothing here writes outside `sandbox/siterepo/r1b*` and the ledger tables it owns); noted as a real operational cost of running several grind rounds' full WordPress+MySQL stacks concurrently on one host.

## Conformance extension: not attempted this round, by design

`sandbox/conformance/seeds/woocommerce.sh` (a single simple product + coupon) was **not** extended with a variable product/attributes/shipping/tax this round, deliberately. The conformance harness's `run.sh` is shared infrastructure across every pinned manifest (acf/core/elementor/fse/polylang/yoast, not just woocommerce) and this round's constraints explicitly reserve the `conf` pair (:8806/:8807) — I have no way to actually *run* the conformance suite to verify an extension stays green, and "extend only if it stays green" isn't satisfiable without being able to test it. Two structural reasons it likely would not stay green without also touching `run.sh` (out of this round's ownership) even if it were tested:

1. `install_env()`'s `case "$SETUP"` hook (`hpos`, `block-theme`) runs identically on both `conf1` and `conf2` *before* any seeding — the one place a symmetric "pre-provision the global attributes needed by the seed" step could live for both environments, and it doesn't exist as a generic capability today.
2. A variable-product seed would immediately hit the same taxonomy-registration gap this round characterized: `conf2` (the fresh target) has no `pa_size`/`pa_color` attribute rows until something creates them, and nothing in `run.sh`'s current flow does.

Recommendation for whoever owns the next `run.sh` change: add a manifest-declared `setup` case (e.g. `"setup": "woo-attrs"`) that runs a small, generic "pre-provision named global attributes" step identically on both conformance environments, mirroring how `hpos`/`block-theme` already work. Until then, this round's variable-product/attribute/shipping/tax coverage lives only in `sandbox/tests/grind_r1b_shop.sh`, run on its own dedicated pair — a real, honest gap in CI coverage, stated rather than hidden.

## Files changed

- `manifests/woocommerce.json` — `_price` reclassified `derived`; `product_variation` post type; `_children`/`_default_attributes`/`_product_attributes`/`_variation_description` post_meta rules; `meta_patterns` (new key) for `attribute_pa_*`; 5 custom tables added as typed-snapshot intent markers; extensive `notes` entries recording the empirical basis for every decision above.
- `sandbox/docker-compose.yml` — new `r1b` profile (`db`/`wp`/`cli` × `r1b1`/`r1b2`, journal on), anchored after the r1a block.
- `Makefile` — additive `grind-r1b` target.
- `sandbox/tests/grind_r1b_shop.sh` — new, the full narrative this report describes; re-runnable (`make grind-r1b`).
- `docs/grind/r1b-shop.md` — this report.
- **Not touched**: `agent/src/**`, `sandbox/conformance/**` (see above), any sibling's env/profile/files.
- **New tasks filed**: #72 (variation post_title anomaly, escalated), plus this round's contribution folded into #75 (typed-snapshot, shared with R1-A's nf3_* findings).

## Run tail (final `make grind-r1b`)

```
== round-trip: clone into r1b2, plan, apply (adopt the WooCommerce/core installer collisions) ==
Warning: taxonomy 'pa_color' is in policy scope but not registered on this environment (plugin inactive?)
  — cannot determine which object type its relationships belong to, so its relationships
  are skipped for every post and term on apply
Warning: taxonomy 'pa_size' is in policy scope but not registered on this environment (plugin inactive?)
Success: applied 23 entities (canary clean) — plan was: {"create":15,"update":1,...}
ok: apply succeeded (canary clean) but warned that pa_color/pa_size aren't registered yet — relationships
    skipped for every post/term, exactly as Capture::taxes_by_object_type()'s own docblock anticipates

== confirm the precise blast radius: Duo Tee's own pa_color/pa_size term relationships are missing on r1b2 ==
ok: confirmed via the real Store API: parent's attributes=[] / is_purchasable=false, while the VARIATION's
    own postmeta (price, sku, attribute_pa_color=red) is already byte-correct

== self-heal test: does simply re-running apply now (no content change) restore the relationships? ==
ok: confirmed: registering the taxonomy alone does NOT self-heal (0 entities applied, relationships still 0)
    — an 'unchanged'-hash entity skips relationship writes entirely, by design

== real fix: force a genuine content change on r1b1 so Duo Tee reprocesses ==
ok: relationships restored (4 rows); parent is_purchasable now: false (checkout targets variations, not the parent)

== discovered along the way: _stock absent means Store API cart-add refuses the round-tripped variation ==
ok: confirmed: is_purchasable=true but add-to-cart is genuinely refused (400, '0 remaining') until this
    environment has ITS OWN stock count — exactly the env-local inventory discipline _stock=runtime enforces

== acceptance: a VARIATION is genuinely purchasable on r1b2 via the real Store API ==
ok: Store API add-to-cart of the round-tripped Small/Red VARIATION succeeded on r1b2 — price $19.99

== wp duo deploy on r1b2: real switch_theme() to Storefront ==
ok: r1b2 switched to Storefront via a real wp duo deploy (switch_theme() fired for real, confirmed by
    rendered markup)

== final apply + byte-identity ==
ok: byte-identical except the 4 variation post_title word-order anomaly (task #72, root cause not
    identified — everything else including price/sku/stock-flags/attributes/terms/options is byte-for-
    byte identical)

== lint (final, hard gate) ==
ok: lint: 0 findings

== divergent-edit merge: conflicting price edits to the same variation on both environments ==
ok: conflict surfaced as a plain git conflict on the variation's _regular_price (plus the incidental
    modified_gmt bump on both the variation and its parent)
ok: conflict resolved editorially (split the difference: 19.99), committed, pushed

== apply the merged price to both environments; confirm convergence ==
ok: both environments converged on the editorially-merged price ($19.99)

✔ GRIND R1-B PASSED
```
