# Grind round R3-B — events + memberships (task #91)

*Own pair `r3b1` (:8852) / `r3b2` (:8853), `sandbox/bin/pair.sh` (not the legacy
`docker-compose.yml`), journal on. Own site repo
(`sandbox/siterepo/{origin-r3b.git,r3b1,r3b2}`). Driver script:
[`sandbox/tests/grind/grind_r3b_events.sh`](../../sandbox/tests/grind/grind_r3b_events.sh)
— re-runnable, wipes content/ledger/custom-tables/site-repo each run, never
tears down containers.*

## Mission

A real little business site — a community makerspace, "Riverside Commons" —
built on **The Events Calendar** (free, wp.org) and **Paid Memberships Pro**
(free, but see the plugin-sourcing finding below) to stress-test the
brand-new typed-snapshot custom-table grammar (`agent/src/Repository/Snapshot.php`,
task #75, previously proven only on Ninja Forms' `nf3_*` and WooCommerce's
`woocommerce_attribute_taxonomies`) against two schemas it was never
designed around. The task brief's own expected shapes turned out to be
wrong on several specific points — corrected empirically below, not
patched over — and three distinct, previously-unseen engine gaps were
found. All three are characterized with acceptance criteria and escalated;
none was forced into a manifest.

> **Resolution update:** DUO-3234 closed gap 1 with the manifest-declared
> `regen_dependency` contract. DUO-3235 closed gaps 2 and 3 with
> `identity.mode=composite_ref` and the `authored_snapshot_meta.id_column`
> override. The re-runnable grind now proves all three fixes on the original
> live fixtures; the sections below retain the discovery evidence as history.

## Environment facts

- WordPress (current), PHP 8.3, MariaDB 11 (shared server, `sandbox/db.yml`).
- The final automated cold-start run of `sandbox/tests/grind/grind_r3b_events.sh`
  (the one whose tail is quoted in this round's close-out) took **~20+
  minutes** wall-clock — far above every earlier manual pass through the
  same steps, which each ran in low single-digit minutes. Root cause is
  environmental, not a script or engine defect: as many as 6 sandbox pairs
  were live fleet-wide at points during this round (confirmed via `bash
  sandbox/bin/pair.sh list`), matching task #74's own documented "OrbStack
  wedges under concurrent load" finding. Handled by monitoring the run to
  completion rather than killing/retrying it mid-flight (a kill during
  `reset_env_state()` risks a half-clean pair that's harder to diagnose
  than a slow one) — reported as a live timing update rather than silently
  waited out.
- **The Events Calendar 6.17.2** (`the-events-calendar`, wp.org), **Paid
  Memberships Pro 3.8.3** (`paid-memberships-pro`, installed from GitHub —
  see below), both `--activate`d independently on both sides (this sandbox
  has no code-provisioning-on-apply path, matching every prior grind's own
  precedent).
- Neither plugin ships wp-cli commands (`wp help tribe`/`wp help event`/
  `wp help pmpro` all fail) — content was seeded through each plugin's own
  real PHP API (`tribe_events()`/`tribe_venues()`/`tribe_organizers()`
  repositories, `pmpro_generatePages()`, direct `$wpdb->insert()` into
  `pmpro_membership_levels` matching `adminpages/levels/save-level.php`'s
  own write shape, `pmpro_update_post_level_restrictions()`,
  `pmpro_changeMembershipLevel()`) — the same "drive the plugin's own real
  code path, not a shortcut" discipline every prior grind round established.

## Plugin-sourcing finding, stated before anything else because it changes the task's own premise

**Paid Memberships Pro was permanently removed from the wp.org plugin
directory on 2024-10-17** — the author's own request, after a dispute with
Automattic over control of the wp.org listing; PMPro now self-hosts
distribution. `wp plugin install paid-memberships-pro` fails outright with
`Warning: paid-memberships-pro: closed`. Still genuinely free and GPLv2,
still officially maintained, at `github.com/strangerstudios/paid-memberships-pro`.
This round installed the official release tag directly:

```
wp plugin install https://github.com/strangerstudios/paid-memberships-pro/archive/refs/tags/3.8.3.zip --activate
```

wp-cli auto-detects the GitHub source and renames the extracted folder from
`paid-memberships-pro-3.8.3` to the clean `paid-memberships-pro` slug —
activated cleanly on both sides, no further adjustment needed. Worth a
flag for DESIGN.md's own code-half story: PMPro is now a **"free but not
wp.org"** plugin, a real middle case between the wp.org/composer path and
the fully premium/vendored `code/wp-content/plugins/` path the site-repo
layout already anticipates. Other free wp.org plugins may have quietly
made the same move; r3-eng-woo was given a heads-up in case it affects
their own plugin choices.

## Corrections to the task brief's own expected shapes (found empirically, not assumed)

The brief's shorthand descriptions were treated as **claims to verify**,
per its own explicit instruction — three turned out to be wrong:

1. **No `tribe_*` custom tables exist at all.** `SHOW TABLES LIKE 'wp_tribe\_%'`
   returns zero rows. Venues (`tribe_venue`) and organizers
   (`tribe_organizer`) are exactly what the brief separately predicted for
   them — plain CPTs, referenced from an event's postmeta via
   `_EventVenueID`/`_EventOrganizerID` — but the brief's own phrasing
   ("venue/organizer are post-ref meta") sat awkwardly next to a general
   expectation of `tribe_*` tables that simply don't exist in this version.
2. **There is no `pmpro_pages` option.** PMPro's nine system pages
   (account/billing/cancel/checkout/confirmation/invoice/levels/login/
   member_profile_edit) are each their own individual option,
   `pmpro_<name>_page_id`, holding one plain page id directly — confirmed
   by reading `includes/init.php`, where the PHP global `$pmpro_pages`
   array (the thing that actually resembles the brief's "serialized map")
   is assembled at *request time* from these nine options and never itself
   persisted. This is materially simpler to classify than the brief
   anticipated: nine ordinary `{"class": "authored", "ref": "post"}` rules,
   the exact shape `manifests/woocommerce.json` already uses for
   `woocommerce_checkout_page_id` — no `json_refs`/structured-value
   handling needed anywhere in this manifest.
3. **The real orders table is `pmpro_membership_orders`, not `pmpro_orders`.**
   DESCRIBE'd live; there is no bare `pmpro_orders` table. Its EAV sidecar
   is `pmpro_membership_ordermeta`.

## The three engine gaps (loudest first)

### Gap 1 — TEC's derived custom tables had a HARD per-entity query-availability dependency with no manifest primitive (closed by DUO-3234)

The central finding of this round. TEC 6.x's "Custom Tables v1"
architecture maintains `tec_events`/`tec_occurrences` alongside the classic
`_Event*` postmeta. Verified live, step by step:

- Creating one event via the real repository (`tribe_events()->set_args([...])->create()`)
  instantly populated matching rows in both tables with byte-identical
  dates to the classic postmeta — the textbook derived-cache shape.
- **First test (exactly as the task brief suggested): delete both rows for
  a throwaway event and see if TEC's own machinery rebuilds them.** It does
  **not** self-heal on any WordPress-native trigger. Neither an ordinary
  `wp post update` resave (fires `save_post`; TEC does not hook table
  regeneration to it) nor TEC's own repository query
  (`tribe_events()->where('id', $id)->first()`) could find the post
  afterward.
- **Second, sharper test:** `wp post list --post_type=tribe_events` — a
  plain `WP_Query`, nothing TEC-view-specific — returned **zero rows** for
  a post independently confirmed (via `wp post get`) to still exist with
  `post_type=tribe_events`. TEC's own `WP_Query\Provider` filters query SQL
  for this post type to require a matching `tec_occurrences` row; without
  one, the post is not merely poorly rendered on a calendar view, it is
  **invisible to `WP_Query` itself**, admin post list included. This is a
  materially harder dependency than any derived custom table this project
  has classified before — `nf3_upgrades`' stale-cache and
  `wc_product_meta_lookup`'s query-optimization role are both *soft*: the
  owning post/row stays fully findable either way.
- **A real regeneration path exists, and was verified working live.** TEC
  ships `TEC\Events\Custom_Tables\V1\Migration\Strategies\Single_Event_Migration_Strategy`,
  built for exactly this recovery scenario (a pre-6.0 site's events predate
  these tables — first-class supported case). Its two operative calls —
  `Event::upsert(['post_id'], Event::data_from_post($post_id))` then
  `Event::find($post_id, 'post_id')->occurrences()->save_occurrences()` —
  read straight from the post's own postmeta, bypassing the broken
  occurrence-dependent query path entirely. Confirmed live: fully restores
  both tables and re-establishes `wp post list` visibility.
- **Practical consequence, demonstrated live in the grind script exactly
  like `docs/grind/r1b-shop.md`'s pa_* pre-provisioning gap ("the honest
  mitigation... a manual step, not an automated one"):** applying a
  captured `tribe_events` post to a fresh target (posts + postmeta round-trip
  perfectly — that machinery is unaffected) leaves the event genuinely
  invisible on the target until the same manual regeneration is run by
  hand, once per event.

**Why this manifest classifies `tec_events`/`tec_occurrences`/`tec_kv_cache`
as `derived`/`runtime` (silently excluded — `Snapshot.php` gives no special
meaning to any class value besides `authored_snapshot`/`authored_snapshot_meta`,
the same choice `manifests/woocommerce.json` already made for
`wc_product_meta_lookup`) rather than declaring `authored_snapshot`:** there
is no independent authored content here to capture — every column mirrors
data that already round-trips through the ordinary post pipeline, so
`authored_snapshot`'s ledger/identity machinery would be pure duplication.
The genuine gap is that DESIGN.md's own vocabulary ("derived — rebuilt")
implies a rebuild pass leaves the target *correct*, and today's grammar has
no way to say **"this derived table has a hard per-entity query-availability
dependency; the rebuild pass MUST run a synthesis step for every entity of
this type before the applied result is usable, and must fail loudly if the
synthesis mechanism disappears in a future plugin version"** — as opposed
to the existing `rebuilders` mechanism's actual shape: one blanket wp-cli
command, no per-entity substitution, no version-compatibility guard.
Reaching TEC's fix from a manifest today would mean smuggling a
non-public, internal, unversioned namespaced class through a `wp eval`
string inside `rebuilders` — syntactically legal, but exactly the
fragile, plugin-internals-through-a-generic-string-field pattern
`manifests/woocommerce.json`'s own `_price` note already flagged as the
wrong instinct (there the payoff was unnecessary; here it's real and
necessary, which makes the fragility concern *sharper*, not milder — a
future TEC release could rename this class with zero warning and the
rebuilder would silently no-op or fatal uninformatively).

**Escalated as board task #124** (acceptance criteria: a manifest-declared,
per-post-type "derived row synthesizer" primitive distinct from the
existing blanket `rebuilders` and the row-keyed `invalidate` — something
that runs once per applied entity of a declared type, in the rebuild pass,
with an explicit existence check against the declared class/method that
fails the apply loudly rather than silently if a future plugin version
removes it).

### Gap 2 — composite-primary-key join tables had no representation in the typed-snapshot grammar (closed by DUO-3235)

Confirmed by reading `agent/src/Repository/Snapshot.php::assert_row_schema()`
directly: `$pk = (string) ($decl['pk'] ?? '')` reads and stores exactly
**one** column name, later cast straight to `(int) $row[$pk]` as the row's
scalar `local_id` for the `duo_map` ledger lookup. There is no path for a
multi-column primary key anywhere in this file.

`pmpro_memberships_pages` (`membership_id`, `page_id` — no surrogate `id`
column at all) is exactly this shape, and it is the table that matters
most in this round's scenario: it is PMPro's **real content-restriction
mechanism** (confirmed by reading `includes/metaboxes.php`'s
`pmpro_page_save()` → `includes/functions.php`'s
`pmpro_update_post_level_restrictions()`, the exact code path behind the
real wp-admin "Require Membership" meta box — driven directly in this
round's seed). This round's "Studio Members Only" page is genuinely
restricted to the "Studio Access" level (verified: an anonymous visitor is
blocked; see the render-check section) — a real authored fact this
manifest cannot capture **at all** today. `pmpro_memberships_categories`
(category-wide restriction, PMPro's other mechanism) and
`pmpro_discount_codes_levels` (which levels a discount code applies to)
share the identical composite-PK shape and the identical gap.

All three are marked with the pre-existing honest-intent marker
(`authored_typed_snapshot_post_v1`) — **escalated as board task #125**
(acceptance criteria: either a composite-key mode declaring an ordered
list of columns whose concatenated values form the ledger's `local_id`
key — e.g. a deterministic string composite, or a `natural_key`-style
derived uuid straight from the tuple, since a pure join table's identity
*is* the tuple, no surrogate needed — or an explicit "join table" third
class with its own, simpler capture semantics: no per-row uuid at all,
reconciled as an owned edge-set the way `authored_snapshot_meta` sidecars
already are, since a join row has no independent existence to identify in
the first place).

**Resolution:** DUO-3235 chose the composite-key mode: an ordered tuple of
reference columns derives a portable UUID from the referenced entities'
UUIDs, while the existing ledger stores a bounded packed local tuple. The
PMPro manifest now declares `pmpro_memberships_pages` with
`identity.mode=composite_ref`. The grind asserts that its one authored row
exists immediately after apply, resolves to the target's own level and page
IDs, survives byte-identical recapture, and enforces the restriction without
calling PMPro's repair API by hand.

`pmpro_discount_codes`/`pmpro_groups`/`pmpro_membership_levels_groups` are
a **different** situation, kept clearly apart in the manifest notes: each
has a normal single-column `id` PK (DESCRIBE'd live) and is structurally
capturable in principle — just not exercised with real content this round
(this scenario's two levels needed no discount codes or level-groups).
Marked with the same intent marker, for the opposite reason: not attempted,
not blocked — matching `docs/grind/r1b-shop.md`'s own shipping-zone
discipline of never claiming more than was actually round-trip-tested.

### Gap 3 — `authored_snapshot_meta`'s own primary-key column name was hardcoded to `'id'` (closed by DUO-3235)

Found live while attempting exactly the kind of declaration task #91 asked
for. `pmpro_membership_levelmeta` (PMPro's level-settings EAV sidecar,
attached to `pmpro_membership_levels`) DESCRIBEs as `meta_id`/
`pmpro_membership_level_id`/`meta_key`/`meta_value` — its own PK column is
`meta_id`, not `id`. Declaring it as `authored_snapshot_meta` threw
immediately:

```
duo: attached-meta table 'pmpro_membership_levelmeta' schema mismatch —
expected columns [id, meta_key, meta_value, pmpro_membership_level_id],
found [meta_id, meta_key, meta_value, pmpro_membership_level_id]
```

Confirmed by reading `Snapshot.php::assert_meta_schema()`:
`$expected = array_unique(array_filter(['id', $attachCol, $keyCol, $valCol, ...]))`
— the literal string `'id'` is baked in, with no manifest field anywhere
to override it. Ninja Forms' own `nf3_*_meta` tables happen to genuinely
use `id` as their PK column name, which is presumably why this was never
noticed before — the hardcoding was invisible until a *second* plugin's
meta table used a different name, exactly the kind of foreign-schema
stress this round's brief asked for.

No manifest-level workaround exists; downgraded to the honest-intent
marker instead of a real declaration. Two real keys were confirmed
empirically before hitting this wall, worth recording even though
uncaptured: `confirmation_in_email` (0/1 toggle) and
`membership_account_message` (free text), both written by the real
`save-level.php` admin handler, both genuinely authored.

**Escalated as board task #126** (acceptance criteria: an `id_column` manifest
field on `authored_snapshot_meta`, defaulting to `'id'` for exact backward
compatibility with the `nf3_*`/`woocommerce_attribute_taxonomies` fixtures,
overridable the same way `key_column`/`value_column` already are — a
small, mechanical fix, but past the ≤10-line bar this round's mandate set
for self-fixing, and touching `agent/src/` at all is out of scope for this
round regardless of size).

**Resolution:** DUO-3235 added the proposed `id_column` field with the
backward-compatible default of `id`; the PMPro declaration sets it to
`meta_id`, and the original fixture now round-trips the level metadata.

## The site

Built on r3b1: a venue ("Riverside Commons Workshop Hall," full address +
phone), an organizer ("Riverside Commons Events Team," email + phone),
three events on different dates — **Fall Open House** (venue + organizer,
the brief's required combination), **Community Meetup** (no venue or
organizer at all — confirmed both `_EventVenueID`/`_EventOrganizerID`
postmeta are genuinely *absent*, not empty, when unset), **Annual Gala**
(venue only). PMPro's nine system pages, seeded via the plugin's own real
`pmpro_generatePages()`. Two membership levels with real pricing and
distinct billing shapes — **Community** ($9.99/month recurring) and
**Studio Access** ($199 one-time, expires after 1 year) — each with
confirmation text containing a real internal link (the URL-tokenization
check: Community's confirmation links to the Community Meetup event page).
A genuinely new, non-system page, **Studio Members Only**, restricted to
Studio Access via the plugin's own real
`pmpro_update_post_level_restrictions()`. A real member signup — a new WP
user, "Dana Rivera," enrolled into Studio Access via
`pmpro_changeMembershipLevel()` — as **runtime** data on side 1 only.

Recurring events (Events Calendar PRO, paid) were **not** installed or
exercised; the free-tier boundary is confirmed from the source itself
rather than by installing Pro to test it — `Single_Event_Migration_Strategy`'s
own constructor explicitly throws `'Attempting to run Single Event
strategy for recurring event. Install and activate the latest version of
Events Calendar PRO.'` the moment `_EventRecurrence` postmeta carries any
`rules`; free TEC simply never writes that meta key at all.

**A minor, honestly-reported quirk, not a Duo bug:** TEC's venue/organizer
repository `create()` path leaves `post_name` genuinely empty (confirmed:
`wp post list` shows blank `post_name` for both), unlike a typical
`wp_insert_post()` call which auto-generates a slug from the title. Duo
faithfully reflects this — the captured filenames end `--.md` with nothing
after the double dash. Cosmetic only (per spec, "the uuid is identity; the
slug is a human affordance") — not patched around in the seed, reported as
found.

## The core loop: loud gate → classify → clean capture

Scoped `post_types: [post, page, attachment, tribe_events, tribe_venue,
tribe_organizer]`. First `wp duo capture` aborted loudly, naming 23 real
TEC postmeta keys. Classified in three batches (post_meta required to
unblock the gate; TEC options; PMPro options — kept in three separate
`--set` calls rather than one enormous string, each still internally
semicolon-joined per the documented wp-cli trap). One structured-option
finding surfaced along the way, worth its own callout:

**`tribe_events_calendar_options` bundles a live-looking secret with
genuinely authored config and runtime-computed fields — DESIGN.md's own
worked example, found empirically.** Read raw: a mix of real site-builder
settings (`tribeEnableViews`, `dateWithYearFormat`), env/version
bookkeeping (`schema-version`), fields that visibly changed as events were
added (`earliest_date`/`latest_date`/`earliest_date_markers` — a live
cache, not authored config) — and `google_maps_js_api_key`, holding a
value in the exact `AIzaSy...` format of a real Google API key, present on
a fresh install with zero Maps configuration ever performed. Whether this
specific key is TEC's own shared/rate-limited default was not
independently probed (out of scope to test a third party's live
credential) — the classification holds regardless: not site-builder
content, and committing it into git-tracked canonical state would leak a
credential-shaped value either way. Classified `env` wholesale, the same
call `manifests/polylang.json`/`manifests/yoast.json` already made for
their own mixed-content option blobs (no per-sub-key capture/exclude split
exists without full `json_refs` coverage of every position).

**Task #73's unscoped-ref gate, deliberately exercised as instructed:**
un-minted the checkout page's ledger identity while temporarily scoping
`page` out of policy, then attempted capture:

```
Error: duo: unresolvable ref-typed option(s) point at real, out-of-scope entities (loud-and-blocking gate):
  - option 'pmpro_checkout_page_id' references post id 14, which is a real 'page' — but 'page' is not
    in policy.post_types, so its identity was never tracked and the reference cannot resolve
```

Confirms the gate is keyed on genuine unminted-and-unscoped state, not
merely "scope changed" — an earlier attempt with the same posts *already*
minted from a prior capture resolved silently via the existing ledger
entry regardless of current scope, exactly as `Capture.php`'s own docblock
says it must (scope decides *unscoped* vs *unminted*, never the reverse).
Fixed scope, recaptured clean.

Capture succeeded (15 posts, 1 term, 1 options file, 2 `pmpro_membership_levels`
table rows). `wp duo lint` first reported 11 findings — all genuinely
non-ref small integers (`_EventShowMap`/`_EventShowMapLink` boolean flags
coinciding with post id 1; five PMPro boolean option toggles, same
coincidence) — reviewed and marked `lint_ok: true`, the same escape hatch
`manifests/ninja-forms.json`'s BIT(1) columns already established. Two
more findings surfaced once the `pmpro_membership_levels` table entered
scope (`cycle_number`/`expiration_number`, both plain "how many" counts) —
marked `lint_ok: true` reactively, plus `billing_limit`/`trial_limit`
proactively for the identical structural reason (confirmed via
`save-level.php`: all four are `intval()` reads of a plain numeric form
field, nothing FK-shaped about any of them) — the same "mark the whole
column, not today's specific collision" reasoning `manifests/ninja-forms.json`
already establishes. Final lint: **0 findings.** Capture-twice: **zero
diff** (determinism confirmed).

## Manifests shipped

**[`manifests/the-events-calendar.json`](../../manifests/the-events-calendar.json)**
— real, substantive: 23 post_meta keys (venue/organizer/event fields, all
`authored`, two `ref: post`), 13 options (TEC's own bookkeeping, all
`env`/`runtime`), and the honest `tec_events`/`tec_occurrences`/`tec_kv_cache`
classification with the full empirical finding recorded in notes (gap 1
above).

**[`manifests/paid-memberships-pro.json`](../../manifests/paid-memberships-pro.json)**
— real, substantive: 33 options (the nine page refs, pricing/email/
telemetry config, all evidence-based), a genuine `authored_snapshot`
declaration for `pmpro_membership_levels` (13 columns, mapped identity —
verified no natural-key candidate exists, neither a DB-level unique
constraint nor an application-layer duplicate check on `name`, unlike
`woocommerce_attribute_taxonomies`'s `attribute_name`), an
`authored_snapshot_meta` declaration for `pmpro_membership_levelmeta`, and
an `identity.mode=composite_ref` declaration for
`pmpro_memberships_pages`. Unexercised tables retain honest intent markers,
with the empirical reasoning recorded — never a silent omission.

Both manifests follow `manifests/ninja-forms.json`/`manifests/woocommerce.json`'s
evidence-in-notes convention throughout: every classification decision
cites what was actually read (DESCRIBE output, source file + line-level
behavior, a live query result), not what was assumed.

## Round-trip, lint, and render acceptance

- **Round-trip**: pushed r3b1 → cloned into r3b2 → `wp duo plan` (17
  create, 1 update, 4 installer collisions) → `wp duo apply
  --adopt-by-slug=terms,posts` (canary **clean**) → `wp duo deploy` (0
  activated/deactivated — both sides already had matching code state).
  Recapturing r3b2 and diffing against r3b1's canonical state: **byte-identical**
  — posts, terms, options, *and* the new typed-snapshot table entities.
- **`wp duo lint` (hard gate)**: **zero findings** on the final captured
  tree.
- **The two original gaps' fixes, proven live:** after apply, TEC's
  `regen_dependency` has made all 3 events query-visible with zero pending
  regeneration markers. PMPro's composite-ref restriction row exists
  immediately, points at r3b2's own Studio Access level and Studio Members
  Only page, and requires no manual repair.
- **Render checks (complete buffered responses throughout)**: every HTTP
  response must be at least 4096 bytes and contain a closing `</html>`
  before its content is inspected. Content checks use here-strings rather
  than a producer pipeline under `pipefail`: r3b2's single event page
  renders the correct title *and* venue name; **each of the 3 events' own
  single-event pages** render correctly, and the first complete
  `/events/list/` response contains all three event titles; **zero**
  occurrences of r3b1's host string (`localhost:8852`)
  anywhere in r3b2's rendered pages, including inside the membership
  confirmation text, which correctly detokenized its internal link to
  r3b2's own host (`http://localhost:8853/event/community-meetup/`) —
  proving the typed-snapshot table grammar's URL tokenization round-trips
  correctly end to end, not just for post bodies. The restricted page
  correctly blocks an anonymous visitor using the captured restriction row,
  with no manual repair step.
- **DUO-3301 closes the aggregate-view ambiguity as a harness defect, not
  a TEC readiness defect.** An isolated pair running TEC 6.17.2 and PMPro
  3.8.3 reproduced the old checker reporting `Community Meetup` missing.
  An immediate request returned a complete 79,953-byte document containing
  all three titles (each three times), while the fixture already had three
  posts, three occurrences, and zero pending regeneration markers. The old
  `echo "$LIST_HTML" | grep -q "$t"` check let `grep -q` exit after its
  match and could leave `echo` to receive SIGPIPE; `pipefail` then made a
  present title look absent. All render assertions now require a complete
  response and inspect it via here-strings. The aggregate first-complete-
  response check is hard; no widened or unbounded retry masks failures.
- **Runtime isolation, both directions**: r3b2 has zero
  `pmpro_memberships_users` rows and no `dana.rivera` user after the whole
  round-trip — r3b1's real signup never propagated. r3b1's own signup (and
  its own `tec_occurrences`) remain untouched by any of r3b2's independent
  apply-time work.
- **Divergent-edit merge — proven on the new typed-snapshot table
  machinery specifically, not just posts**: conflicting Community-level
  price edits on both sides (`$12.99` vs `$8.99`) produced a real git
  conflict, surgically scoped to the single `billing_amount` line in the
  entity-per-file table JSON — the same clean, minimal-diff behavior
  posts/terms already get, now proven for `state/tables/`. Resolved
  editorially (split the difference: `$10.99`), applied to both
  environments, **full convergence** confirmed.

## Gap taxonomy

**(a) Engine gaps — each filed as its own board task, `SendMessage`'d to
team-lead:**

1. **Task #124 / DUO-3234 — closed:** TEC-style hard-dependency derived
   tables now use `regen_dependency` (gap 1 above).
2. **Task #125 / DUO-3235 — closed:** composite-primary-key join tables now
   use `identity.mode=composite_ref` (gap 2 above).
3. **Task #126 / DUO-3235 — closed:** `authored_snapshot_meta.id_column`
   overrides nonstandard sidecar PK names (gap 3 above).

**(b) Manifest gaps — closed in-round:** `manifests/the-events-calendar.json`
and `manifests/paid-memberships-pro.json` (both new, real, substantive —
see above).

**(c) Premise corrections to the task brief itself** — recorded above
(PMPro's wp.org removal; no `tribe_*` tables; `pmpro_pages` doesn't exist
as a single option; the real orders table name) — each verified
empirically before being treated as fact, per the brief's own explicit
instruction not to assume.

**(d) Harness/render-check finding — closed by DUO-3301:** the apparent
load-sensitive TEC list-view delay was a producer-side SIGPIPE race in an
`echo | grep -q` pipeline under `pipefail`. Complete-response validation,
here-string content checks, and a hard aggregate assertion now make the
driver report the rendered state truthfully.

## Files changed

- `manifests/the-events-calendar.json` (new), `manifests/paid-memberships-pro.json` (new).
- `sandbox/tests/grind/grind_r3b_events.sh` (new) — the full, re-runnable round.
- `Makefile` — `grind-r3b` target (additive).
- `docs/grind/r3b-events-memberships.md` (this file, new).
- Board tasks #124/#125/#126 created for the three engine gaps; no `agent/src/` edits, no
  edits to any manifest besides the two new ones, no `spec/` edits.

## Verdict

Both plugins are meaningfully branchable today with real, substantive,
evidence-grounded manifests. This round originally exposed three distinct
failure modes: a derived table with a hard availability dependency (TEC),
a join table with no surrogate key (PMPro's restriction join), and a
sidecar whose PK name differed from the grammar's assumption (PMPro's level
meta). DUO-3234 and DUO-3235 now close all three, and the same original
fixtures serve as end-to-end regression proofs rather than stale proofs of
the former gaps.
