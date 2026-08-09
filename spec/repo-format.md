# Duo Site-Repo Format — spec v1

*Status: draft, exercised by Spike A/B. Everything here is versioned; `spec_version` in `site.duo.json` pins it.*

A **site repo** is a git repository holding the branchable partition of one WordPress site: code, canonical state, media, and policy. Environments (any WP install with the Duo agent) materialize it; their runtime data never enters it.

## Layout

```
site.duo.json                # spec_version, manifest pins, site policy
code/
  wp-content/                # optional v0 code payload; exact vendored bytes
    plugins/
    themes/
    mu-plugins/              # user mu-plugins only; Duo's agent is out-of-band in v0
state/                       # canonical authored state (this spec's core)
  options/core.json
  posts/<post_type>/<uuid>--<slug>.md
  terms/<taxonomy>/<uuid>--<slug>.json
  user-meta/<sha256(exact-login)>.json
  menus/<slug>.json
  sidebars/<sidebar_id>.json
  deletions/<uuid>.json     # explicit, versioned deletion intent
media/<sha256>.<ext>         # content-addressed binaries (git LFS in real repos)
```

## Canonical serialization

**Canonical JSON**: UTF-8, LF, keys sorted lexicographically at every level, 2-space pretty-print, `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, trailing newline.

Rationale vs YAML: the agent is a drop-in mu-plugin and must not vendor dependencies (no YAML parser ships with WP/PHP); JSON gives a byte-deterministic emitter for free. Human-mergeability of the one thing that needed it — post bodies — is preserved by the front-matter+body format below (bodies are raw lines, never escaped into a string). YAML remains an open revisit if editor ergonomics demand it; it would be a mechanical format bump of `spec_version`.

Determinism is a hard requirement: **capturing the same site twice must produce byte-identical trees** (acceptance-tested). Nothing environment-specific may appear in `state/` — that's what tokens are for.

## Identity

- Every ordinary entity carries a lowercase RFC UUID (normally UUIDv7 minted at first capture; deterministic custom-table identities use UUIDv5), stored invisibly in the environment (`postmeta`/`termmeta` key `_duo_uuid`). Options are identified by option name.
- Entity filenames are `<uuid>--<slug>.<ext>`. The uuid is identity; the slug is a human affordance (renames change the filename's slug half; tooling treats uuid as the key).
- Each environment holds a **ledger** (`duo_map` table): `(uuid, entity_type, id_kind) → local_id`. `id_kind` is a distinct keyspace label: `post`, `term`, `term_taxonomy`, declared table kinds, and the closed per-type widget family (`widget_block`, `widget_text`, `widget_nav_menu`, …). Term entities map **two** kinds (`term`, `term_taxonomy`) because WordPress references both inconsistently.
- `_duo_uuid` is globally unique across posts and terms. Capture rejects invalid UUIDs, multiple identity-meta rows on one owner, or one UUID copied onto multiple owners before publishing state. The repository compiler independently rejects duplicate UUIDs across every entity kind and declared table. `duo_map` writes are contradiction-intolerant: no ordinary path deletes another mapping, changes a local id, or silently retypes an identity.
- Users are **not** entities: user references serialize as `user:<user_login>` tokens; apply resolves post authors by login and may fall back to a configured default author with a warning. User-meta sidecars are stricter: they resolve the owning login by exact bytes/case and never fall back. Users are never auto-created.
- `guid` never appears in canonical state. Apply generates it deterministically per environment (`<home>/?duo=<uuid>`) on first insert and pins it in the ledger.

## Tokens

Environment-bound values are tokenized at capture and re-bound at apply. Tokens may appear in front-matter scalars, structured values, and bodies.

| Token | Meaning | Apply resolution |
|---|---|---|
| `{{home}}` | site home URL, no trailing slash | `get_option('home')` |
| `{{uploads}}` | uploads baseurl | `wp_upload_dir()['baseurl']` |
| `{{post:<uuid>}}` | numeric post id | ledger `(uuid, post)` |
| `{{term:<uuid>}}` | numeric term_id | ledger `(uuid, term)` |
| `{{tt:<uuid>}}` | numeric term_taxonomy_id | ledger `(uuid, term_taxonomy)` |
| `user:<login>` | user reference (author fields) | user lookup by login, fallback + warn |

Numeric-typed positions (block attrs like `"id":123`, option values like `page_on_front`) are written as the quoted token string in canonical form; the applier restores the declared numeric type. Rewriting is **structure-aware only**: block attributes via the block parser and a per-block attribute-path registry; HTML-level forms limited to declared patterns (`wp-image-<id>` class, `src`/`href` URL prefixes). No blind regex over content.

Current heuristic for internal links: permalink hrefs tokenize as `{{home}}/<path>` (correct while slugs match across branches). uuid-precise link tokens (`{{link:<uuid>}}`) remain reserved for a future format revision. Query-string-style internal links (`?p=`/`?page_id=`/`?attachment_id=`, WordPress's own older URL scheme — verified against `redirect_canonical()` directly, not `?page=`, which is pagination) get the uuid-precise treatment already: `{{home}}/?p={{post:<uuid>}}` (spec v0.20, DUO-3260) — see "Unscoped references" below for the full triage.

## Entity files

### Posts — `state/posts/<post_type>/<uuid>--<slug>.md`

Front matter (canonical JSON between `---` fences) + raw body:

```
---
{
  "author": "user:admin",
  "comment_status": "open",
  "date": "2026-08-05 10:00:00",
  "date_gmt": "2026-08-05 10:00:00",
  "menu_order": 0,
  "meta": {
    "_thumbnail_id": "{{post:0198b0e2-...}}",
    "_wp_page_template": "default"
  },
  "modified": "2026-08-05 16:00:00",
  "modified_gmt": "2026-08-05 10:00:00",
  "parent": "{{post:0198b0d1-...}}",
  "ping_status": "closed",
  "slug": "about",
  "status": "publish",
  "terms": {
    "category": ["0198b0aa-..."]
  },
  "term_orders": {
    "category": {
      "0198b0aa-...": 0
    }
  },
  "title": "About",
  "type": "page",
  "uuid": "0198b0c3-..."
}
---
<!-- wp:paragraph -->
<p>Raw Gutenberg HTML, URLs tokenized, ids tokenized via the block registry.</p>
<!-- /wp:paragraph -->
```

- The body is `post_content` after capture rewriting — byte-faithful otherwise. It merges in git as plain lines.
- `meta` contains only keys classified **authored** by manifests/policy. Derived/runtime keys (`_edit_lock`, `_wp_attachment_metadata`, …) are excluded per the core manifest. **Unclassified keys abort capture loudly** (the loud-and-blocking gate); the error names the key and the policy file to amend.
- `date`/`modified` are site-local WordPress timestamps; `date_gmt`/`modified_gmt` are their UTC partners. Capture and apply preserve both columns explicitly rather than deriving one from the other.
- `terms` maps taxonomy → canonical term-uuid list. `term_orders` records the WordPress `term_relationships.term_order` integer for each relationship; omitted entries read as zero for compatibility with earlier spec-v1 trees.
- Excluded fields: `guid` (env-derived), `comment_count` (derived), revisions and auto-drafts (never captured). An empty `post_password` is default noise; a non-empty password is authored secret material and capture refuses the post before publishing a candidate tree because spec v1 has no portable secret representation for it.
- **Attachments** add: `"file": "<upload-relative/path.ext>"` (typically `Y/M/name.ext`, but normalized upload-root paths such as plugin placeholders are valid), `"media": "<sha256>.<ext>"` (binary in `media/`), `"mime": "image/jpeg"`, `"alt": "…"` (from `_wp_attachment_image_alt`). Body = attachment description; the uniform `excerpt` field carries the caption. `_wp_attachment_metadata` is derived: regenerated after every applied attachment create or update, including retry/adoption paths. Capture obtains the binary through the `duo_attachment_capture_source` filter: its default is `['path' => <local upload path>]` when that file exists, and an offload adapter may return exactly `['path' => <readable materialized path>]` or `['bytes' => <raw provider bytes>]`. If neither a local file nor a provider source exists, capture refuses loudly and names the attachment, relative upload path, and provider hook; it never treats absent bytes as a valid portable attachment.
- A post with `status: "future"` is materialized with a `publish_future_post` single event at its exact `date_gmt` UTC instant. Apply replaces any stale event for the post and verifies the new schedule.

### Terms — `state/terms/<taxonomy>/<uuid>--<slug>.json`

```json
{
  "description": "",
  "name": "News",
  "parent": null,
  "slug": "news",
  "taxonomy": "category",
  "uuid": "0198b0aa-..."
}
```

`parent` is a term uuid or null. `nav_menu` terms are not stored here — menus own them.

### User meta — `state/user-meta/<sha256(exact-login)>.json`

```json
{
  "login": "editor",
  "meta": {
    "agency_profile_id": "{{post:0198b0c3-...}}"
  }
}
```

This is a login-keyed sidecar, not a user entity. The filename is the lowercase SHA-256 of the ASCII domain separator `duo-user-meta`, one NUL byte, and the exact `user_login`; the compiler recomputes it from `login`, so case-only and punctuation-heavy logins remain safe on every filesystem without minting a UUID. No `duo_map` identity is created. Capture emits only keys explicitly classified `authored`; unknown and `runtime`/`env`/`derived` keys remain target-local. Authored values use the ordinary meta ref/token/structured-value machinery, reject multi-row values rather than choosing one, and are recursively scanned for hard secrets and conservative PII signals. `allow_secret: true` and user-meta-only `allow_pii: true` are explicit false-positive escape hatches.

Apply resolves the owning user with an exact, binary login comparison, never creates/renames/deletes a user, and reconciles only authored keys. Target-only non-authored keys survive byte-untouched. A missing owning login defaults to `missing_user: "block"` (refuse before mutation); `missing_user: "warn"` on every authored key in the sidecar instead warns and skips the whole sidecar. Mixed declarations fail closed. Capture retains an empty sidecar for a previously represented, still-existing login so removing the final authored key is explicit and deletes that owned key on apply. Removing the sidecar file itself is not deletion authority and leaves target metadata untouched; user lifecycle remains environment-local.

### Menus — `state/menus/<slug>.json`

A menu file owns the `nav_menu` term **and** its `nav_menu_item` posts (they never appear under `state/posts/`). Ordered `items` array (order = `menu_order`); this file is the known v2 candidate for a field-aware merge driver.

```json
{
  "items": [
    {
      "attr_title": "",
      "classes": [],
      "description": "Shown by themes that render menu descriptions.",
      "object": "page",
      "parent": null,
      "position": 1,
      "ref": "{{post:0198b0c3-...}}",
      "target": "",
      "title": "",
      "type": "post_type",
      "uuid": "0198b0f1-...",
      "xfn": ""
    },
    {
      "object": "custom",
      "parent": "0198b0f1-...",
      "position": 2,
      "ref": "{{home}}/contact/",
      "title": "Contact",
      "type": "custom",
      "uuid": "0198b0f2-..."
    }
  ],
  "locations": ["primary"],
  "name": "Main",
  "slug": "main",
  "uuid": "0198b0e9-..."
}
```

- `type` ∈ `post_type` | `taxonomy` | `custom` (WP's polymorphic `_menu_item_type`); `ref` is typed accordingly (`{{post:…}}`, `{{term:…}}`, or a tokenized URL).
- `parent` is a menu-item uuid (self-referential); apply is two-phase (create items, then resolve parents). `description` is the tokenized `nav_menu_item.post_content`; older files that omit it materialize an empty description.
- `locations` records this menu's slots in the active theme's `theme_mods` (`nav_menu_locations`) — the one theme-mod key the core manifest classifies authored in v0.
- **`locations` may be excluded under a plugin override** (spec v0.19, DUO-3272): the core manifest classifies `locations` `authored` by default (v0's existing behavior, unchanged for every ordinary site). A pinned plugin manifest may reclassify it `derived` when it can prove Polylang-style ownership — a plugin whose own runtime machinery unconditionally rewrites the raw `theme_mods_<stylesheet>['nav_menu_locations']` slot Duo would otherwise be racing against (Polylang: `Languages::update_default()`, which recomputes this slot from its own `nav_menus[theme][location][lang]` bookkeeping any time the default language changes or is re-resolved, entirely outside Duo's capture/apply cycle — proven, not assumed, via a 100%-reproducible repro, DUO-3272). Under the override, a menu file omits the `locations` key entirely (not an empty array) and apply never writes the raw slot; the portable per-language truth continues to propagate through the declaring plugin's own option `sub_keys` (Polylang: `nav_menus`/`default_lang`, already spec'd under Options above), and `wp duo plan` reports the active override as a loud warning, the same treatment a core-schema option reclassification already gets. Non-overridden sites see no change in `locations`' shape or behavior.
- Apply reconciles the menu fully: env items of this menu whose uuid is absent from the file are removed (menu-scoped ownership).
- **Menu-item meta** (spec v0.17, DUO-3266): a menu item's file entry may carry a `meta` map, identical in shape and semantics to a post's own `meta` field — keys classified `authored` (by a manifest or `site.duo.json`'s own `policy.post_meta`) capture/tokenize/apply/reconcile through the exact same generic machinery `post_meta` already uses. Everything else on a live menu-item post that isn't one of the eight WordPress-core structural keys (`_menu_item_type`/`_menu_item_object_id`/`_menu_item_url`/`_menu_item_menu_item_parent`/`_menu_item_classes`/`_menu_item_object`/`_menu_item_target`/`_menu_item_xfn`, all pre-classified `managed` in `manifests/core.json` and never routed through `meta`) makes capture refuse loudly, naming the menu, the item, and the key — the same unclassified-meta gate posture every other post-meta-owning surface already has.

### Sidebars — `state/sidebars/<sidebar_id>.json` (DUO-3278)

A sidebar file is the scoped-ownership boundary and contains one ordered `widgets` array. Each entry is exactly `{"uuid", "type", "settings"}`. Widget UUIDs are durable identity; source/target counter ids are not portable and never enter the file. Identity is ledger-only: the per-type `id_kind` `widget_<type>` maps the UUID to that type's integer instance number. No `_duo_uuid` key is injected into plugin/core settings arrays. Apply allocates a free counter per type, writes the mapping, preserves file order in `sidebars_widgets`, and removes target instances absent from that declared sidebar; unmapped theme defaults are reported as plan-visible `widget_deletes`, never overwritten or merged by counter.

The widget type set is closed and manifest-declared. Core v1 declares `block`, `text`, and `nav_menu`. An undeclared live type is a `duo pending` item and blocks capture; malformed `widget_<type>` options that are not WordPress's multi-instance array family block by option name. `widget_block.content` uses the ordinary `Blocks` capture/apply codec, and `widget_nav_menu.nav_menu` is a declared term reference. `sidebars_widgets.array_version` is internal bookkeeping and excluded. `wp_inactive_widgets` is excluded in v1 and produces a loud plan/capture note because parked content does not propagate.

Lost widget mappings fail closed when identity history exists; recovery is the unchanged `duo-identity-ledger/v1` export/import flow. The export includes and witnesses every owned widget mapping. The `duo_map.id_kind` width budget is 32 characters and is migrated idempotently; the regression floor is the 20-character family member `widget_media_gallery`.

### Deletion tombstones — `state/deletions/<uuid>.json` (spec v1)

Removing a live entity file is never deletion authority. During capture, if an entity present in the previously compiled revision is absent from the source environment, the agent replaces it with a durable tombstone:

```json
{
  "expected_hash": "<sha256 of the prior canonical entity>",
  "expected_revision": "<prior compiled revision sha256>",
  "format": "duo-deletion/v1",
  "kind": "post",
  "source_path": "posts/page/0198b0c3-...--about.md",
  "type": "page",
  "uuid": "0198b0c3-..."
}
```

`kind` is `post`, `term`, `menu`, or `table`; `type` is the exact post type, taxonomy, `nav_menu`, or custom-table name. Capture preserves a still-absent tombstone byte-for-byte. Reappearance removes it. Individual menu items are not independently tombstoned. Authored options use their own name-keyed record/tombstone grammar below because they have no UUID identity.

The offline compiler rejects malformed tombstones, live+tombstone identity collisions, unsupported entity types, and any surviving canonical reference to a deleted UUID. A tombstone is accepted only when a pinned adapter declares the exact selector in its top-level `deletions` map, including all cascade effects and runtime reverse-reference guards. Refusal is correct when no adapter owns the destructive semantics.

That refusal also applies when a plugin's destructive semantics exceed the current grammar. For example, WooCommerce 11.0.0 global product attributes are authored rows in `woocommerce_attribute_taxonomies`, but deleting one through WooCommerce derives `pa_<attribute_name>`, deletes that taxonomy's terms and relationships through WordPress APIs, fires plugin hooks, schedules a rewrite flush, and invalidates both transient and object caches. Its reverse references are derived taxonomy strings in `term_taxonomy`, serialized `_product_attributes`, variation meta *keys*, and `wc_product_attributes_lookup`, not scalar columns containing the row's numeric `attribute_id`. The v1 guard grammar cannot express those references and the table cascade grammar cannot reproduce the semantic delete, so the WooCommerce adapter deliberately does **not** declare `table:woocommerce_attribute_taxonomies`; capture, offline compilation, plan, and `apply --with-deletes` all fail closed for that selector.

### Options — `state/options/core.json`

Versioned option-record document. Every exact option classified authored (plus the three managed code-half options) has a record, so deleting a JSON key is invalid rather than ambiguous. Dynamic families have records for discovered canonical names only. A record has exactly one of three states:

- `absent`: no portable value and no deletion intent; apply leaves a target row untouched.
- `present`: carries the lossless canonical `value` and the row's exact `autoload` storage flag.
- `deleted`: durable destructive intent carrying the sha256 `expected_hash` of the prior `present` record. In v2 it may also carry a hash-bound `classification_witness` when the current interpreter rule explicitly declares `deletion_witness: true`.

```json
{
  "format": "duo-options/v2",
  "records": {
    "blogdescription": {"state": "present", "autoload": "yes", "value": ""},
    "blogname": {"state": "present", "autoload": "yes", "value": "Duo Demo"},
    "default_category": {"state": "present", "autoload": "auto", "value": "{{term:0198b0aa-...}}"},
    "page_for_posts": {"state": "absent"},
    "retired_authored_option": {
      "state": "deleted",
      "expected_hash": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
    },
    "_options_retired_acf_field": {
      "state": "deleted",
      "expected_hash": "d0c4953f210e6aacc1ef0f7445a1f9aea4da930126b4584433e8163ed1253eba",
      "classification_witness": {"autoload": "off", "value": "field_retired_acf_field"}
    }
  }
}
```

`null`, `false`, `""`, empty containers, and serialized structures are ordinary `present.value` values and never deletion signals. Capture converts a prior `present` record to `deleted` only after observing that exact authored row absent; it preserves a still-absent deletion record byte-for-byte, while reappearance replaces it with `present`. Apply requires `--with-deletes`, checks the tombstone's expected record hash during planning, and treats post-base edits or recreation as conflicts. Removing a required exact-name record fails compilation with its `records.<name>` location.

`duo-options/v2` adds the optional `classification_witness`. It is not desired option data and apply never writes it. It contains the prior record's exact `autoload` and `value`, and its reconstructed `present` record must hash to the tombstone's `expected_hash`; malformed or independently edited witnesses fail compilation. Repository authorization builds interpreter context from present values, deleted-name presence, and valid witnesses, then asks the currently pinned interpreter to classify the tombstone normally. The witness is emitted only when that prior rule explicitly returns `deletion_witness: true`, allowing a shadow-key interpreter to retain its minimum schema pointer without retaining the deleted authored payload. A bare provenance or `authored: true` assertion is never trusted. `duo-options/v1` remains the canonical encoding for documents that need no witness, preserving their existing hashes; capture selects v2 exactly when at least one record uses the v2 field. Both versions remain accepted for reads.

Every manifest surface that can author a whole option row (`options`, an authored `option_patterns`/`option_name_refs`, or a rule with authored `sub_keys`) must declare autoload storage semantics. A rule may give an exact supported value, or the manifest/site policy may declare `option_autoload: "preserve"` to authorize capture and replay of the source row's exact value. Missing declarations block policy load; create and update both write the canonical flag, never a WordPress/version-local default.

Ref-typed values inside `present.value` are tokenized per the core manifest. The core exact set includes `blogname`, `blogdescription`, `show_on_front`, `page_on_front`, `page_for_posts`, `sticky_posts`, `default_category`, `posts_per_page`, and `wp_page_for_privacy_policy`:

```json
{"state": "present", "autoload": "yes", "value": ["{{post:0198b0c7-...}}"]}
```

Exact option rules remain sufficient for fixed names. A plugin with dynamic or evolving names declares discovery ownership separately with top-level `"option_namespaces": [{"match": "^plugin_prefix_"}]`. Capture enumerates every live `wp_options.option_name` in that namespace on every run, independent of the provenance journal. Each match must resolve through the owning manifest's exact `options` rule, one of its `option_patterns`, or an explicit site override; otherwise it is pending and capture blocks. An authored `option_patterns` rule therefore captures a dynamic family, while runtime/derived/env families are enumerated and deliberately excluded. Overlapping namespace claims and cross-manifest classifications refuse rather than depending on pin order. Names outside all declared namespaces are not guessed to belong to a plugin.

Term-meta is enumerated for every in-scope term. The spec-v1 term file has no `meta` field, so both an unclassified key and a key classified `authored` block capture: the former needs a decision, while the latter names an unsupported representation rather than accepting an inert classification. Explicit `runtime`/`derived`/`env` (or managed) rules are the only non-blocking dispositions until a term-meta state format exists.

A manifest `taxonomy_patterns` entry may declare `object_keyspace` (`post` or `term`), `object_type`, and `update_count_callback`. `object_keyspace` says which WordPress identity space owns `term_relationships.object_id`; `object_type` only narrows post types inside the `post` keyspace. These are the version-pinned registration contract for a dynamic taxonomy that a typed-snapshot table creates after WordPress's `init` hook has already run. Apply uses the live registered taxonomy whenever it exists; only in that same-request timing gap may it construct the equivalent taxonomy contract from the manifest and invoke the declared callback. A missing, mixed, contradictory, or non-callable contract refuses instead of inferring ownership from a plugin sentinel or falling back to a generic SQL count.

An exact manifest `taxonomies.<name>` rule or a `taxonomy_patterns` rule may also declare `"object_keyspace": "post"|"term"`. It identifies the object-id namespace for that taxonomy's `wp_term_relationships` rows: post relationships belong in a post file's `terms` map, while term relationships belong in a term file's `relationships` map. Every exact or matching pattern declaration for one concrete taxonomy must agree; invalid and ambiguous values refuse at manifest load or resolution before a relationship query or mutation. Omission preserves the legacy `post` default only for a runtime post-only taxonomy. A runtime `object_type` of `term` requires an explicit `term` declaration; a mixed post/term registration refuses even when declared because one enum value cannot describe both owners. The engine never treats a plugin's literal `term` object-type string as an implicit wire-format contract. This declaration changes no canonical file grammar or existing canonical bytes.

### Custom tables — `state/tables/<table>/<uuid>--<slug>.json` (spec v0.10)

Typed snapshot: capture/apply for **authored custom tables** (DESIGN.md §3.3's middle tier — task #75; primary fixture `nf3_forms`/`nf3_fields`/`nf3_actions`, secondary `woocommerce_attribute_taxonomies`). A manifest's `"tables"` section declares each table as one of two classes; anything else stays the pre-existing honest-intent marker `authored_typed_snapshot_post_v1` (declared, loudly not yet captured):

- **`authored_snapshot`** — a row table with identity of its own. Declares `pk`, `id_kind` (a new typed keyspace in `duo_map`; ≤32 chars, unique across manifests), optional `slug_column`, `columns{}` (every non-pk, non-ref column individually classified `authored`/`runtime`/`derived`/`env` — the post-meta discipline generalized to columns), and `refs[]` (`{"column", "kind"}` FK columns resolved through the ledger). A declared `slug_column` must name a non-empty `authored` columns entry; its value supplies the human-readable filename suffix. Without one, capture uses the stable literal `record`, never the environment-local primary key (the UUID prefix already guarantees uniqueness). Readers continue accepting legacy `<uuid>--<local-id>.json` paths; the next capture atomically normalizes them to `--record`. **Every live column must be accounted for** by exactly one of pk / refs / columns — an undeclared column refuses capture loudly, whether or not it holds an id this round (finding #8's FK rule made absolute).
- **`authored_snapshot_meta`** — an EAV sidecar with no independent identity: `attached_to: {table, column}`, `key_column`/`value_column` (plus `legacy_key_column`/`legacy_value_column` where the plugin double-writes a back-compat pair), a `keys{}` map for exceptional runtime/ref keys, and a `default_class` for the remainder. An adapter can bound that default with `"keyspace": {"version_range": {"min": "1.2.0", "max": "2.0.0"}, "keys": ["known_key"], "patterns": [{"match": "^known_family_"}]}`. Once declared, `default_class` applies only inside that keyspace: a live key outside the exact/pattern set is reported by `duo pending` and refuses capture with the table, owner candidate, row count, representative value shapes, and pinned range. This is the plugin-upgrade tripwire; an open-ended default cannot silently absorb a newly introduced setting. Sidecar rows fold into the owning row's file as its `meta` map and reconcile as an owned key-set on apply, exactly like postmeta; they get no file, no uuid, and no `duo_map` entry of their own.

One file per row (the entity-per-file discipline, for the same merge reason as posts). A ref column's value is its token inline — no separate refs bucket in the file:

```json
{
  "columns": {
    "active": "1",
    "label": "Record Submission",
    "parent_id": "{{nf3_form:019fd535-...}}",
    "type": "save"
  },
  "meta": {
    "email_subject": "Ninja Forms Submission",
    "parent_id": "{{nf3_form:019fd535-...}}"
  },
  "table": "nf3_actions",
  "uuid": "019fd535-..."
}
```

- **Identity** lives in `duo_map` (`id_kind`, `local_id`) — a plugin's table never gets a `_duo_uuid`-style column added (plugins stay unmodified). Three declared modes: `"identity": {"mode": "mapped"}` (default; fresh source rows mint UUIDv7; populated target rows without mappings block, and a source with canonical UUIDs but missing mappings blocks); `"identity": {"mode": "natural_key", "column": "<col>"}` (for a human-chosen unique column: UUIDv5 of `"<table>:<value>"` is deterministic **bootstrap** identity for a never-seen row, while an existing `duo_map` entry is **continuity** identity thereafter); and `"identity": {"mode": "composite_ref", "columns": ["<ref-col-1>", "<ref-col-2>"]}` for a pure join table whose identity is derived from the two referenced entities' UUIDs. Contradictory mappings never rebind implicitly. Composite-ref ledger rows are recoverable bookkeeping because the referenced UUID tuple remains the identity truth. Changing a mapped `natural_key` value is an ordinary update/file rename and retains the UUID already assigned through the ledger; `UUIDv5(current key) != retained UUID` is therefore expected after a rename, and capture/plan report it as an informational note. Duo never forces re-derivation. A fresh environment that independently captures the already-renamed live row without the ledger or repository history derives UUIDv5 from the new key and therefore gets a different UUID; the existing table adopt flows are the reconciliation path for that documented bootstrap/continuity boundary.
- **Parent-scoped natural keys** (spec v1, DUO-3318): `"identity": {"mode": "natural_key", "columns": ["<col>", ...]}` is the same mode for a key that is unique only *within* a parent row — a slot code unique per room, an option key unique per form. `column` is exactly its one-component case, and for a **scalar** component its derivation string is frozen unchanged (`"<table>:<value>"`) — which is the whole installed base: every UUIDv5 ever minted by a shipped manifest came from a scalar single-column key, and none of them moves. Two or more components derive from `"<table>:<col>=<component>:<col>=<component>"` in **declared order**, so reordering `columns` is an identity change, not a formatting edit. A component that names a declared `refs[]` column contributes the **referenced row's own UUID**, never the local id in the column (the same portability argument `composite_ref` makes: an auto-increment parent id would mint a different UUID per environment for one authored fact); a scalar component contributes its raw value. That ref rule applies to a one-component key too, where it is strictly new behavior rather than a change: no shipped manifest has ever declared a `natural_key` over a ref column, so there is no derivation to keep frozen there and the portable spelling is the only one this engine has ever produced. Unlike `composite_ref`, `pk` stays required — the table keeps its surrogate primary key, `duo_map.local_id` stays that plain scalar, and delete/adopt/`invalidate` are unchanged. Every component must be a declared `refs[]` column or a declared `columns{}` entry, may not be the primary key, and may not repeat; a multi-component key must declare `slug_column` (a tuple has no portable one-line filename spelling). Adoption resolves the tuple against the target — scalars literally, ref components through the ledger, and on a ledger miss through the referenced row's *own* natural key in the same revision — so a pre-existing unmanaged child row is adoptable exactly as a single-column natural key already is, including on a target where the parent row is itself still unmanaged-but-adoptable (a whole plugin hand-provisioned before its first apply). A ref component naming a post/term parent is not resolved that way: slug adoption is the post/term collision path, and identity for a table row never reaches across into it.
- **Refs are structural at the row level** (an unmapped non-zero row ref throws, the `post_parent` category) but **optional at the sidecar level** (an unmapped meta-value ref drops with a warning, the ordinary dangling-reference category) — the two severities the dangling-reference rule below already implied but never had to distinguish.
- **`invalidate`** — declarative per-row cache invalidation run with apply, no plugin PHP in the engine: `[{"table": "nf3_upgrades", "column": "id"}, {"option_pattern": "nf_form_{id}"}]`, where `{id}` substitutes the row's resolved local id (raw deletes fire no hooks, so no canary carve-out). Blanket (non-row-keyed) caches use the top-level `actions` channel instead (a closed native action such as `transient.delete`, or a plugin-owned provider capability — see "Structured rebuild actions and providers" under the manifest registry format).
- `block_attrs` rules may name a declared table's `id_kind` as their ref kind (`ninja-forms/form`'s `formID` → `{{nf3_form:<uuid>}}`); `wp duo lint` scans `tables/*/*.json` like any other canonical state; `apply --adopt-by-slug=tables` adopts matching pre-existing env rows (one shared `tables` adopt key for all declared tables — a table entity's *type* is the table name).

- **Option-name-embedded refs** (spec v0.12, task #93): a manifest may declare `"option_name_refs": [{"match": "<regex with a required named group 'id'>", "malformed_match"?: "<regex for would-be names with an invalid id>", "id_kind": "<declared table id_kind>", "class": "authored", "json_refs"?: [...], "key_refs"?: {...}}]` for options whose NAME (not value) embeds another declared table's local id (WooCommerce's `woocommerce_<method_id>_<instance_id>_settings`). A sibling of ordinary `option_patterns`, not a variant: namespace-backed `option_patterns` classify/capture the real option name as-is, while `option_name_refs` drive their own discovery because the canonical key must replace the matched local `id` group with a portable token. `match` must admit only canonical positive decimal ids; a would-be namespace that matches `malformed_match` (for example a leading-zero or zero Woo instance id) is refused rather than disappearing because a stricter `match` did not select it. All consumers resolve the complete rule set together: a live/canonical name matching more than one rule, including rules for different id_kinds, is ambiguous and refused; pin/declaration order never selects a winner. The captured canonical KEY splices the resolved ref TOKEN into the exact byte position of the matched `id` group — `woocommerce_flat_rate_{{wc_zone_method:<uuid>}}_settings` — using the existing token grammar unchanged. Apply detects a token-bearing option KEY and detokenizes it BEFORE ordinary option-rule dispatch, unconditionally — this ordering is load-bearing: skipping it silently writes a real `wp_options` row whose NAME contains literal `{{...}}` bytes. The resolved real name is re-matched against the same patterns to recover the rule governing its VALUE, which routes through structural capture/apply unconditionally, so an array-shaped settings blob gets its string leaves URL-tokenized and deep secret-scanned with zero per-plugin special-casing.
- **Severity model for name-embedded ids** (mirrors task #73's dangling-vs-unscoped split, with one structural difference stated precisely): an id resolving to no row anywhere is ordinary dangling (warn + drop). An id naming a row that exists in its declared table but has no minted uuid is loud/blocking *only* when the capture is MINTING (`Capture::run()`, never `Capture::snapshot()`) — a declared table's rows are already minted unconditionally by the table-capture pass earlier in the same build, so a declared-but-unresolved row can only legitimately arise on a non-minting snapshot (plan's drift check), which is exactly the correctly-scoped-but-unminted false-positive class task #73's own fix documents (`default_category` on a never-captured fresh install); it falls through to warn-and-drop. Stated plainly: the loud branch is a defensive invariant guard, not a routinely-reachable scenario.

Not covered: a natural key whose components span more than one table, and `composite_ref` with more than two columns. `composite_ref` stays deliberately narrow — exactly two structural ref columns forming a pure join identity, where the row *is* the fact; a table that has its own primary key plus a parent-scoped authored key uses `natural_key` with `columns` instead.

### Identity disaster recovery

Mapped custom-table UUIDs are promotion metadata and must travel with the database backup. Immediately after taking a quiesced database backup, run:

```sh
wp duo identity-export --repo=/path/to/site-repo --out=/secure/backup/site.identity.json
```

The `duo-identity-ledger/v1` sidecar contains sorted identity mappings (including ledger-only widget instances), three-way sync hashes, the applied revision, and the exact compiled repository/site/manifest hashes. It contains SHA-256 row witnesses rather than plugin/widget-row contents. Keep it beside the matching database backup; it is environment-bound recovery material, not canonical state and should not be committed to the site repository.

After restoring that database and checking out the exact repository revision, restore identity before `plan`, `apply`, or `capture`:

```sh
wp duo identity-import --repo=/path/to/site-repo --in=/secure/backup/site.identity.json
```

Import verifies the artifact integrity hash, repository and manifest association, every embedded post/term UUID, every mapped-table row witness, tuple uniqueness, and any already-present ledger subset before one transaction restores `duo_map`, `duo_state`, and `applied_revision`. A stale database/sidecar pair, changed row, conflicting map, tampered artifact, or wrong repository blocks without partial rebinding. A populated mapped-identity table without this metadata also blocks; there is no force flag because no deterministic adoption key exists.

## `site.duo.json`

```json
{
  "code": {
    "format": 1,
    "layout": "wp-content",
    "source": "code/wp-content"
  },
  "manifests": [
    "core",
    {
      "digest": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
      "name": "woocommerce"
    }
  ],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"]
  },
  "spec_version": 1
}
```

`policy` holds site-local classification overrides (same shape as manifest rules); it wins over manifests. `manifests` pins which registry manifests apply (agent looks them up across its installed adapter sources: its own manifest dir and this repository's `adapters/` source — a name is refused if both could answer it, so there is no precedence order to learn; see "Out-of-tree adapter sources" below). A pin may remain the historical name string or use `{"name":"…","digest":"<sha256>","source":"shipped"|"site"}`. The object form is optional and content-addressed: load computes the same per-manifest digest recorded in compiled artifacts' `resolved_adapters` (including a declared interpreter's name and bytes) and refuses a mismatch before any policy consumer or target contact, naming the manifest plus expected and actual digests. `source` is likewise optional and likewise a refusal rather than a preference: a pin that names which adapter source must answer it refuses when a different source does, so removing a site-installed adapter can never silently hand its name to a later shipped one. An unknown pin key is refused outright rather than ignored. `{"name":"core"}` without `digest` or `source` is also equivalent to the legacy string form; adding these mechanisms does not force existing repositories to migrate.

`wp duo manifest-pin --name=<name> [--repo=<path>]` validates the installed manifest and prints the exact canonical `{name,digest,source}` object for copy/paste into this array. `--repo` is what makes a site-installed adapter pinnable; without it only the shipped library is searched. The requested name is always passed as an explicit pin, so the repository's own (possibly stale) `manifests` array is never resolved and a stale pin cannot prevent calculating a reviewed replacement after an intentional manifest update. Updating the pin is an explicit review act; it is never automatic.

### `code` (optional)

The first materialized code contract is deliberately narrow and explicit:

```json
{
  "code": {
    "format": 1,
    "layout": "wp-content",
    "source": "code/wp-content"
  }
}
```

When present, `code/wp-content/plugins/`, `themes/`, and user-owned
`mu-plugins/` are a versioned payload of exact vendored bytes. Public,
premium, private, and in-house extensions all have the same transport shape;
whether their database state is supported remains the separate manifest
contract. Symlinks and paths outside those three roots are refused. The Duo
agent's own `mu-plugins/duo/` directory and `duo-loader.php` are protected and
remain out-of-band for this first version, so a deployment cannot replace the
agent executing it.

The payload does not own WordPress core, `wp-config.php`, uploads, caches,
drop-ins, language packs, or unrelated `wp-content` directories. Finalization
may remove only paths recorded as Duo-owned by an earlier successful code
deployment (and obsolete files inside a component the new payload explicitly
owns); unrelated target files are never swept by a global `--delete`.

Format 1 maps those three roots to the standard `WP_CONTENT_DIR/plugins`,
`WP_CONTENT_DIR/themes`, and `WP_CONTENT_DIR/mu-plugins` locations. If a payload
actually manages a root that WordPress has redirected elsewhere (for example a
custom `WP_PLUGIN_DIR`), materialization refuses before writing; copying files
into an inert standard directory would be a false success. A payload managing
only one root is not coupled to the unused roots' layout.

Compilation inventories every payload file and SHA-256, plugin main-file
basename, theme slug and bounded child-theme `Template:` relation, and owned
component root. The descriptor's revision is stored separately from the
state/media `revision_hash`; the top-level `code` declaration is excluded from
that state revision while the outer artifact hash still binds the full site
policy and both halves. This makes the enforceable invariant:

```text
canonical active plugin/theme ⊆ compiled payload ⊆ verified target payload
```

The completed marker is not trusted by itself. Plan/apply revalidate its stored
descriptor and the managed target bytes (including unexpected regular files
inside an owned component); a same-version PHP edit is therefore stale code,
not a clean environment. Recovery is the host `duo deploy <env>` path. Generic
force flags cannot authorize state apply while this descriptor proof is stale.

Composer resolution, full-webroot/core ownership, controller-built SSH
artifacts, and atomic release-directory swaps are later build/deployment modes
that must emit this same descriptor contract; they are not implied by format 1.

The separation is structural, not merely naming. Code scanning and filesystem
mutation never parse or apply canonical entities. State planning and apply never
copy, delete, or claim executable files. One narrow lifecycle contract bridges
the halves: canonical `active_plugins`, `template`, and `stylesheet` express
database-held intent which must be satisfiable by the code descriptor and then
reconciled through WordPress's real activation/theme APIs. Promotion sequences
that bridge, but each half retains its own revision and completion marker.
For a code-enabled artifact, all three managed lifecycle records must be
explicitly `present`: `active_plugins` (an empty list is valid deactivation
intent), `template`, and `stylesheet`. Missing/`absent`/`deleted` records are
ambiguous and compilation/stage refuses before target writes.

The public host path is one lease-bound sequence with fresh WordPress processes:
`code-stage → lifecycle-retire → lifecycle-activate → code-finalize → apply`.
Retirement and activation each publish an ordered success receipt in the exact
owner/artifact session, including when the phase is a verified no-op. Activation
cannot start without retirement, and finalize cannot publish `code_revision`
until both receipts exist; a staged descriptor alone is never completion proof.
Plugin retirement follows the `Requires Plugins` graph in reverse topological
order, not incidental `active_plugins` list order. Stage never removes
completed plugin/theme code before lifecycle hooks. Its only pre-lifecycle
recovery deletion is narrower: an exact user-MU file absent before Duo first
staged it, never completed, unchanged since that staged receipt, and omitted
from the reviewed retry. That provenance is atomically recorded with the
staged descriptor; a staged hash alone grants no deletion authority.

Before any lifecycle API that can mutate `options/core`, deploy writes a
pre-hook attempt receipt into the exact owner/artifact promotion session. A
successful phase consumes that receipt in the same session write that publishes
its pending or completed state handoff. An exception/fatal deliberately leaves
the receipt: WordPress hooks are not transactional and may have committed an
authored option before failing. Apply, lifecycle, stage, finalize, and every
different promotion owner/artifact refuse while it exists, including on first
sync when no `duo_state` base exists. Plan/status renders the receipt as the
non-forceable `incomplete_lifecycle` bucket. Recovery requires external writer
exclusion, restoring code to the known pre-promotion revision, and the retained
checkpoint's exact abort → original begin → isolated import → final abort
sequence.

Control-plane commands load only the protected Duo agent after `wp-config.php`
has been read and before user MU/plugin/theme code. The v0 layout contract is
the standard `wp-content/mu-plugins` tree without explicit `WPMU_PLUGIN_DIR` or
`SUNRISE`; any configured variant refuses during compile before a checkpoint or
target write. A future custom-layout transport must provide an explicit trusted
agent/content-root mapping rather than weakening this proof with guessed paths.

### `envs` (optional)

`site.duo.json` may declare an `"envs"` object, keyed by environment name, describing the environments that materialize this site repo for the `duo` orchestrator CLI (see [cli/README.md](../cli/README.md)). Each entry names a `transport` (`local`, `docker`, or `ssh`) and a `repo_path` — this site repo's path *as seen from inside that environment*. Entries here are shared via git and must contain no secrets; anything machine-specific or sensitive belongs instead in a gitignored, machine-local `.duo-envs.json` overlay next to it, which replaces same-named entries whole. `envs` is orchestrator convenience, not part of the branchable state contract — the agent's `wp duo …` commands (this spec's actual subject) never read it.

Host provisioning remains outside the repository format. In particular,
`environment_provider` is a privileged, machine-local `.duo-envs.json` field
and is rejected when it originates in checked-in `site.duo.json` or an
auto-discovered, Git-tracked `.duo-envs.json`. An explicit `--envs-file` is an
operator-selected trust input, not repository policy. Its opaque
snapshot/resource/lease receipts live under the orchestrator checkout's Git
common directory, never under `state/`, `media/`, `code/`, manifests, or any
other canonical repository surface. Providers do not interpret plugin state;
their source-freeze, immutable snapshot-set, target mutation-fence, and TTL
readback receipts are opaque host evidence. TTL is observable expiry metadata,
never autonomous deletion authority: only an explicit identity-, ownership-,
and lease-fenced reap may destroy or detach a resource. The engine still
materializes the provider-restored production baseline through the separately
compiled code and state halves, and promotion consumes the exact compiled
artifact rather than a provider-specific or plugin-specific substitute.

## Manifests (registry format)

Everything in this section is refusable offline: `duo manifest-validate <manifests-dir> [--site=<site-repo>]` drives these same validators with no WordPress, database, or environment present, and `duo manifest-validate --emit-schema` prints the closed VALUE vocabularies and the named subset of bounded patterns below as a versioned JSON document read out of the engine itself, with a `coverage` field naming what it omits — see [docs/guides/adapter-authoring.md § Checking the grammar offline](../docs/guides/adapter-authoring.md#checking-the-grammar-offline). `--site` matters because two guards (the ref/token/ledger kind vocabularies, and conflicting option rules) read `site.duo.json`'s policy half as input, so without it a manifest valid on its real site can be refused. It is an authoring aid, not a gate, and it reports the checks that need a live target — plus that missing site half — as explicitly deferred.

`manifests/<name>.json` in the platform repo (shipped with the agent; version-range pinning lands with the plugin-manifest workstream):

```json
{
  "block_attrs": {
    "core/image": [{"kind": "post", "path": "id", "type": "int"}],
    "core/gallery": [{"kind": "post", "path": "ids", "type": "int[]"}]
  },
  "name": "core",
  "options": {
    "blogname": {"class": "authored"},
    "cron": {"class": "runtime"},
    "default_category": {"class": "authored", "ref": "term"},
    "home": {"class": "env"},
    "page_on_front": {"class": "authored", "ref": "post"},
    "siteurl": {"class": "env"},
    "sticky_posts": {"class": "authored", "ref": "post[]"}
  },
  "post_meta": {
    "_edit_lock": {"class": "runtime"},
    "_thumbnail_id": {"class": "authored", "ref": "post"},
    "_wp_attachment_metadata": {"class": "derived"},
    "_wp_page_template": {"class": "authored"}
  }
}
```

Classes: `authored` (captured), `runtime` / `derived` / `env` (excluded; `derived` additionally implies "regenerate on apply" where a rebuild action or regenerator exists). Anything unmatched by manifest+policy is **unclassified → loud abort**.

Extended manifest capabilities (spec v0.5):

- `"interpreter": "<name>"` — schema-driven classification: the named interpreter is consulted per (meta key, the entity's full meta map) *before* static rules — for plugins whose meta semantics live in data (field-group definitions), not in a static key list. **Interpreter code is part of the manifest artifact, never the engine**: the name resolves to `manifests/interpreters/<name>.php`, which must define `\Duo\Interpreters\<Name>` with `post_meta_rule(string $key, array $allMeta): ?array`; it may additionally define `term_meta_rule(...)` and `user_meta_rule(...)` with the same signature and nullable-defer semantics. The optional hooks do not widen older post-only interpreters: when absent, the corresponding static `term_meta`/`user_meta` rules retain control. Interpreter code ships, versions, and pins together with its manifest JSON. (Trust boundary: the manifests dir is operator-controlled and deploys with the agent itself, so loading it is the same trust decision as running the agent.)
- `"user_meta": {"<key>": {"class": "runtime|env|derived|authored", "missing_user": "block|warn", "allow_pii": false}}` mirrors the static post/term-meta classification vocabulary without making users repository entities. `runtime`/`env`/`derived` are target-local dispositions. `authored` uses the exact-login sidecar above; `missing_user` is user-meta-only and defaults to fail-closed `block`, while `allow_pii` is an explicit reviewed exception to the recursive PII gate. Interpreters follow the same contract through `user_meta_rule()`.
- `"post_types": {"acf-field": {"class": "authored", "body": "verbatim", "phase": "early"}}` — body mode `verbatim` byte-preserves `post_content` (serialized-data bodies, where URL substitution would corrupt serialized lengths); a verbatim body containing the environment's home URL warns loudly at capture (it will not re-bind). `"phase": "early"` makes the type finalize before all others in apply phase 2 — for definition CPTs whose content interpreters read to type other entities' meta (declared ordering, never glob luck).
- `"post_types": {"product": {"fields": {"modified": {"class": "derived"}, "modified_gmt": {"class": "derived"}}}, "product_variation": {"fields": {"title": {"class": "derived"}, "modified": {"class": "derived"}, "modified_gmt": {"class": "derived"}}}}` (spec v0.11, extended in v2) — per-post_type classification for **post fields**: the ~13 keys every post file carries unconditionally (title, slug, status, dates, parent, menu_order, …), distinct from post_meta/options, whose rules already carry `class`. v2 accepts exactly `"title"`, `"modified"`, and `"modified_gmt"` with `"class": "derived"`; Policy validates field name and class at manifest load and throws on anything else. `slug` participates in file names and Apply's collision/identity checks, while status/date/menu-order/comment/excerpt fields have no reviewed derived precedent, so they stay unsupported rather than half-supported. Manifest-only, no site-policy override (`policy.post_types` is already the flat scope list; a rule map under the same key would collide — the `body`/`phase` precedent). Semantics: capture always writes a derived field's current observed value into the file verbatim, never omitted — a human reading `state/` sees the truth even when it is not authoritative. Apply writes it once as a new row's bootstrap value on create and never overwrites it on update, preserving the plugin-owned target value. The field is excluded from the **hash basis** used by `duo_state`, plan's three-way compare, drift detection, and compiled semantic comparison (`Canon::post_hash_basis()`) — never from the file itself — so two environments may honestly have different raw captured bytes for a derived field while remaining the same branch state. Proven cases: WooCommerce recomputes `product_variation.title` hook-free via raw `$wpdb` on load (tasks #72/#88), and WooCommerce-mediated stock/order saves advance `modified`/`modified_gmt` on both a stock-managed variation and its variable parent while direct environment-local stock-meta provisioning advances neither (DUO-3302). The timestamp declaration is deliberately limited to `product` and `product_variation`; undeclared post types retain authored timestamp semantics.
- `"post_types": {"parent_cpt": {"children": ["child_cpt"]}, "child_cpt": {...}}` (DUO-3315) — a validated, direct **parent → child** CPT relation. Every listed child name is a distinct WordPress post-type identifier and must be another `post_types` key in the same manifest; missing endpoints fail policy load (including frozen-policy reconstruction). The declaration maps the child row's single `wp_posts.post_parent` value to a row of the named parent type. A parent can therefore have zero or many declared child rows. The relation is structural and composable: `Policy::child_post_types()` and `parent_post_types()` return lexical, duplicate-free direct neighbors, while `post_type_relation_closure()` traverses both directions transitively so scoped operations can expand only declared parent/child dependencies rather than discover a target-wide hierarchy. A declaration never grants delete/cascade authority: normal tombstone capability and guard checks remain required. During derived-state rebuild, a parent deletion receipt may carry only direct declared children that are **also explicit tombstones** in that revision; undeclared types and target-local surviving children are not enumerated, deleted, or handed to an adapter as cleanup work. Malformed lists, invalid identifiers, self-relations, and duplicates fail policy load.
- Meta ref rules may declare `"cast"`: `"string"` (ids stored as strings inside serialized arrays — the ACF shape) or `"csv"` (a `"1,2,3"` id list canonicalized to a token array, re-joined on apply). Ref kind `"user"` serializes as `user:<login>` tokens — users stay env-local; apply resolves by login and falls back to the default author with a warning.
- **Order-preserving values** (spec v0.15, task #123): a meta rule may declare `"order_preserving": true` for a value whose PHP array key order is semantically load-bearing — canonical JSON's own `ksort()`-at-every-level rule (the "Entity-per-file, deterministic serialization" line above) is only safe when no plugin reads a value's raw iteration order, and WooCommerce's variation-title generator (`WC_Product_Variation_Data_Store_CPT::read()`) reads the parent's `_product_attributes` array order directly — canonicalization was permanently reordering it on every applied target, a real (not timing-based) divergence. A declared value's key order — at every nesting level inside it, recursively — is captured and round-tripped exactly as WordPress held it, instead of being alphabetized; declaring it changes nothing else about the rule (it composes with `ref`/`json_refs`/`key_refs`/`cast` normally, applied to the fully-processed value). Scoped strictly to the declared value: every *other* key in the same document, including sibling meta keys, still sorts alphabetically as normal — this is not a document-wide behavior change. No apply-side changes were needed: `json_decode()` and `maybe_serialize()` never reorder keys on their own, so the ordinary decode → detokenize → re-serialize path was already order-preserving by construction — the capture-time `ksort()` was the only place order was ever lost. `manifests/woocommerce.json`'s `_product_attributes` is the first declared user.
- **Structured rebuild actions and providers** (DUO-3338; supersedes the retired free-form `rebuilders` channel, whose command-string entries — including `wp eval` payloads — the engine now refuses at manifest load): the hooks apply deliberately skips are also what maintain plugin derived state (indexables, lookup tables, blanket caches), so a manifest declares the repair as data in a top-level `"actions"` list. Each entry is `{"kind": "native"|"provider", ..., "triggers"?: [...], "effects"?: [...]}` — `triggers` is the same exact canonical-surface grammar apply projects from authored work (`(post|term|table|option|entity):<name>`; absent = unscoped, selected for any non-empty surface set; an empty surface set — a read-only apply — fires nothing), and `effects` feeds the same bounded-reversibility inventory `rebuilders` entries fed (omitted = explicit irreversible fallback row). A `native` entry names an action from the engine's **closed vocabulary** (v1: exactly `transient.delete`, args `{"name": <bounded string>}` — WordPress-core semantics, identical for every plugin, executed by reviewed engine code with a checked-readback receipt); unknown action names, unknown arg keys, and mistyped args are refused at load, so a manifest can neither mint operations nor smuggle executable text through arguments. A `provider` entry names a capability of a provider declared in the SAME manifest's top-level `"providers"` list: `{"id", "version" (exact x.y.z), "source": "manifest"|"plugin", "plugin": <basename>, "capabilities": [...]}`. Provider ids are globally unique across pinned manifests (conflict = refusal; pin order never picks which code runs), and a provider's `plugin` must equal the manifest's own `plugin` claim when one exists, so the executable half stays inside the version window the declarative half was certified for. `source: "manifest"` resolves to `manifests/providers/<id>.php` defining `\Duo\Providers\<CamelCase(id)>` — the interpreter/regenerator trust boundary: provider code ships, versions, digest-binds, and pins with its manifest, never with the engine: its bytes join the per-adapter digest beside the interpreter's (`RepositoryCompiler::manifest_rows()` and the `CapabilityRegistry::adapter_digest()` mirror of it), so a changed provider file is a changed adapter rather than invisible drift behind a stable manifest digest. Regenerator files remain unbound — a pre-existing gap DUO-3338 did not close. `source: "plugin"` is discovered from the installed plugin itself through the `duo_providers` registry filter and is trusted as part of that plugin; its file is deliberately not digest-bound (the installed plugin is its identity anchor, checked against `version_range`). Before the first target mutation, apply **negotiates** every provider its selected actions reach — contract shape (`identity()`, `capabilities()`, `invoke()`), exact identity match against the declaration, owning plugin installed+active+in range, capability advertised with a well-formed declaration (`args` schema, `reads`/`writes` surface summary, `scope: "site"|"entity"`, `idempotent` — required `true`, since apply's retry machinery re-fires the rebuild pass — and a `timeout_seconds` budget), and manifest args valid against the capability schema — and refuses with per-problem remediation on any miss, so a missing or incompatible capability fails before destructive writes, never after commit. Invocation returns a receipt whose `verified` must be exactly `true` on the strength of a value-level readback (command-success-only verification is refused); `scope: "entity"` capabilities receive an engine-assembled batch of `{kind, id}` rows for the triggering surfaces. An adapter that needs no executable semantics simply declares neither key and remains purely declarative.
- **Structured capability arguments and engine batch context** (DUO-3369, extending the two grammars above): a capability `args` entry declares `{"type": "bool"|"int"|"string"|"list<string>"|"list<object>", "required": <bool>}`, and `list<object>` additionally declares `"fields": {"<name>": {"type": "bool"|"int"|"string", "required": <bool>}}` — a closed, per-capability row vocabulary with **exactly one level of nesting**: a field may not itself be a list or an object, so there is no depth a reviewer cannot state and no free-form payload channel. Field names use the argument-name charset; unknown keys in a `fields` declaration, an empty `fields` map, and a `fields` map on a scalar-typed argument are refused at negotiation, and unknown fields, missing required fields, mistyped fields, and non-object rows are refused in VALUES. The one-level bound is enforced twice, deliberately: `Policy` refuses a nested manifest argument at LOAD (where no provider code exists yet — a manifest argument is a scalar, a list of scalars, or a list of flat objects whose own values are scalars), and negotiation refuses anything the capability's own field vocabulary does not admit. Separately, a `scope: "entity"` capability may declare the optional key `"context": [...]` over the closed channel vocabulary `deletions`, `reparents`, `retry`, `always_on_write` (duplicates refused, empty list refused, unknown names refused, and the key itself refused on `scope: "site"`, naming the declared channels). Declaring channels changes what rides under the reserved `entities` argument: the value becomes `{"entities": [...], "always_on_write"?: <bool>, "deletions"?: [...], "reparents"?: [...], "retry"?: <bool>}` carrying only the declared channels in that fixed order, so an undeclared channel is ABSENT rather than empty and "nothing happened" stays distinguishable from "never asked for". `deletions` rows are `{kind, uuid, id}` for the tombstones this run APPLIED (`--with-deletes`) or that a previous incomplete apply had already made absent — never one this run merely PLANNED: the pre-mutation selection deliberately projects surfaces from planned tombstones (a capability has to negotiate before the mutation), but an apply without `--with-deletes` gates every delete off and its entities are all still present, so handing them over as tombstones would be indistinguishable from real ones. `id` is the target-local id the ledger still holds, `0` once the mapping is gone (the expected shape when a previous run applied the delete) or when the entity kind has no single row id at all. `reparents` rows are `{kind, uuid, id, root_id, old_parent_id, new_parent_id}`, one row per derived root, which is how a chained move's accumulated roots survive a scalar-only field grammar; the engine captures a reparent receipt for post types with a batch `regen_dependency` OR whose canonical surface a `reparents`-declaring capability in this run's selection triggers on, and the channel unions this run's captures with the durable `regen_reparent_context:<uuid>` markers an earlier incomplete apply left outstanding (the retry case has no fresh capture at all). `retry` is the apply's own incomplete-retry marker; `always_on_write` is a boolean flag stating the capability fired on an always-on basis, mirroring `regen_dependency`'s flag of the same name exactly — there the flag suppresses a per-candidate existence check on a write candidate the engine already had, and never creates candidates, so here too it never manufactures work (see bound (2)). **PARITY GAP with the regenerator channel, stated rather than implied** (DUO-3342 closes it or records why not): a deletion row does NOT carry the regenerator deletion context's `parent_id`/`child_ids` — those come from a pre-delete inventory the engine writes only for post types with an enabled batch `regen_dependency`, while this channel is projected from the tombstone rows themselves, and `child_ids` is a list the scalar-only row grammar cannot carry without a per-row normalization. **Byte-compatibility is a contract, not a courtesy**: a capability that declares no `context` and no `list<object>` argument negotiates to byte-identical declaration bytes and receives a byte-identical injected batch (the bare row list), frozen as literal bytes in `sandbox/tests/regress_provider_contract.php` rather than asserted in prose.

  Four bounds stated so nobody re-derives them: (1) **execution context** — the retired channel launched each command in a fresh WP-CLI process; native actions and providers run in apply's own process, compensated by an unconditional object-cache flush immediately before the action loop (in-process runtime caches can hold pre-commit plugin models — the reproduced Polylang 3.8.6 class), with the plugin-CLI-invoking providers (elementor, yoast) still launching subprocesses as their implementation detail. (2) **`scope: "entity"` semantics** — the declaring action must carry explicit `post:`/`term:`/`table:` triggers (negotiation refuses unscoped declarations and triggers with no ledger-resolvable per-entity id, before any mutation), and a selection whose surfaces came only from deletions/retry-tombstones invokes nothing: the engine records an explicit skip receipt rather than handing the provider an empty batch it could "verify" — unless (DUO-3369) the capability declared an EVIDENCE channel (`deletions`, `reparents`, `retry`) that came back non-empty/true, since a deletion-only selection is real work for a capability that asked to be told about tombstones. `always_on_write` is not such a channel and never suppresses the skip: it mirrors `regen_dependency`'s flag, which suppresses a per-candidate existence check but never creates a candidate, so a capability declaring it still fires only when its entity batch or one of its evidence channels carries something. The skip receipt survives verbatim for a capability declaring no channels, and survives with each declared channel's own state named when none of them carried work (a row channel is empty, a flag channel false/absent, `always_on_write` a flag that is never work of its own). No shipped adapter uses entity scope yet; the contract is negotiation-checked and skip-explicit, not exercised coverage. (3) **`verified` is the provider's own value-level claim**, structurally required and receipt-recorded; the engine's independent backstop for authored state is the post-apply canonical recapture (a provider that corrupts authored state and returns `verified: true` is caught there — regression-covered), while derived state has no second checker beyond the provider's own readback. (4) **negotiation currently runs at apply only** — `plan`/`status` do not yet surface missing/incompatible providers; that diagnostics surface is DUO-3339's capability-catalog work, not silently absent.
- `"deletions": {"post:product": {"cascades": ["postmeta", "post_revisions", "term_relationships"], "guards": [{"table": "wc_order_product_lookup", "column": "product_id", "id_kind": "post", "reason": "orders reference this product"}]}}` — exact, adapter-owned deletion capability. `cascades` names every effect the engine will perform. Each guard declares the target id keyspace; optional `where` adds scalar predicates and optional `exclude_where` subtracts owned rows already covered by a declared cascade (for example revision child posts). A guard may also declare `source_id_kind` + `source_pk`, allowing rows whose source entities are themselves safe delete candidates in the same revision while still blocking runtime or conflicted references. Structured metadata guards add `meta_key` + `ref` (currently `postmeta` and a matching scalar/list id shape), `identity_column`, and the owner source pair; an explicitly authored owner update that removes the target token is the only repair witness, while child-only tombstones remain blocked. Option-name guards add `option_name_ref: true` on `options.option_name`; they resolve the target id through the manifest's `option_name_refs` rule and require the matching canonical option tombstone in the same revision. Missing guard tables fail closed. Matching rows mark deletion **BLOCKED**; `apply --with-deletes` refuses unless `--force-delete-referenced`, and forced execution remains loud.
- **Structured-value refs** (spec v0.7, widened by DUO-3316): a meta/option rule—or an authored key of an `authored_snapshot_meta` EAV sidecar—may declare refs *inside* a JSON-or-PHP-serialized value. `"json_refs": [{"path": "$.*.*.wpseo_opengraph-image-id", "kind": "post", "cast": "string"}]` rewrites id **values** at declared paths (minimal dialect: `$` root, `.` key steps, `*` wildcard); `"key_refs": {"path": "$.*", "kind": "term"}` rewrites entity-id **keys** of the map at the declared path. Attached sidecars use the identical decoder, tokenizer, linter, compiler, apply, and verification declarations as ordinary meta; `json_encoded:true` selects compact JSON text while the default is WordPress/PHP serialization. Undeclared sidecar keys keep their historical opaque-byte behavior. Everything undeclared inside a declared structure is byte-preserved except string leaves, which get ordinary URL tokenization; unmapped ids follow the drop-with-warning rule. The tokenizer also matches **JSON-escaped URL forms** (`https:\/\/…`, Elementor's convention): both forms collapse to one plain-spelled token, and structural re-encode restores the host convention on apply (RFC 8259 makes `/` vs `\/` equivalent inside JSON strings). `wp duo lint` treats declared paths as owned and still flags id-shaped values at *undeclared* paths inside the same structure — the linter catching manifest gaps is its purpose. Paths, casts, reference keyspaces, overlapping scalar paths, and scalar-path-versus-key-map ambiguity are validated when normal or frozen policy loads, before capture/apply. `option_patterns` has a meta twin: `"meta_patterns"` (versioned keys like `_elementor_migrations_*`).

- **Taxonomy descriptions and relationship keyspaces** (DUO-3316): `taxonomies.<taxonomy>.description_refs` retains the byte-compatible shorthand `{"kind":"post"}` / `{"kind":"term"}`, normalized to one flat-map `json_refs` path (`$.*`). Independent adapters may instead use the full shared shape `{"json_refs":[...],"key_refs":{...}}` for nested PHP-serialized descriptions; no description-specific traversal grammar or plugin payload walker exists. `taxonomies.<taxonomy>.object_keyspace` and `taxonomy_patterns[].object_keyspace` declare whether the taxonomy's relationship `object_id` values belong to the `post` or `term` identity space. Runtime `object_type` still selects concrete post types but never doubles as a term sentinel. Missing term/mixed ownership, declaration/runtime contradictions, or ambiguous pattern ownership refuse rather than dropping or cross-applying relationships.
- **Dynamic taxonomy scope** (spec v0.12, task #92): a manifest may declare `"taxonomy_patterns": [{"match": "<regex>", "object_type": ["<type>", ...]}]` for taxonomies whose NAME (not a meta/option key) is dynamic per site (WooCommerce's `pa_<attribute>`, minted at runtime from a custom-table row). Unlike `option_patterns`/`meta_patterns` (which classify a key some other enumeration already produced), `taxonomy_patterns` expands the taxonomy SCOPE LIST itself: `Policy::taxonomies()` unions the exact `policy.taxonomies` list with every name in live `wp_term_taxonomy` (a `SELECT DISTINCT`) that matches a declared pattern — **scope-gated**: only pattern-matched names are ever added, never a blanket widen to whatever the database holds (the posture task #73 established for ref-typed options). Matching against the live table, not `get_taxonomies()`'s in-memory registry, is deliberate and load-bearing: a taxonomy whose registration depends on a same-request custom-table write (a typed-snapshot table's phase-1 insert) is invisible to the registry for the rest of that request even though its own term rows are already live. The declared `object_type` is a fallback consulted only when `get_taxonomy()` fails — the registry stays authoritative whenever it succeeds. This is what lets direct-SQL term-relationship writes correctly scope a just-landed dynamic taxonomy in the SAME apply request that created its defining row, with zero manual pre-provisioning.
- **Whole-entity scope gate** (DUO-3229): capture enumerates WordPress-registered public post types/taxonomies plus whole-type contracts declared by pinned manifests, then counts their live capturable rows. A type with rows must either be in `policy.post_types` / `policy.taxonomies` (including a declared taxonomy-pattern match), or carry an explicit non-authored disposition. Pinned manifest `post_types.<name>.class` is already that disposition (`shop_order` and `nf_sub` are runtime); manifest taxonomy declarations default authored unless they declare otherwise. Site-local decisions live at `policy.scope.post_type.<name>.class` or `policy.scope.taxonomy.<name>.class`. `authored` adds the name to scope; `runtime`, `derived`, or `env` records a deliberate exclusion. Missing disposition blocks capture naming the surface, entity count, and exact policy fix; it never quietly shrinks the tree. `wp duo pending` reports keys such as `scope:post_type:book`, and `wp duo classify --set='scope:post_type:book=runtime'` persists an audited exclusion.
- **Sub-keyed options** (spec v0.14, DUO-3233): a manifest option rule may declare `"sub_keys": {"<name>": {...rule...}, ...}` — NAMED sub-keys of one option's array value classified and captured/applied **independently of the whole option and of each other**, using the same rule vocabulary as a top-level option/meta rule (`class`, `ref`, `json_refs`, `key_refs`, `cast`, `allow_secret`). `sub_keys` and `class: authored` are mutually exclusive on the same rule — a whole-option-authored value has no sub-key carve-out to speak of, and mixing the two leaves "authored the whole thing" vs. "authored named pieces of it" undefined. Whole-value behavior fields (`ref`, `json_refs`, `key_refs`, `json_encoded`, `cast`, `order_preserving`, `allow_secret`, or `lint_ok`) are also invalid on an exact or dynamic sub-keyed parent because consumers intentionally use the named sub-key rules; accepting them would silently ignore a declaration. The exact parent may still carry its actual container contract (`class`, the required flag for `env`, and `autoload`); a dynamic parent carries its resolver/prefix/autoload contract. A sub-key rule cannot itself declare another `sub_keys` map; the grammar is exactly one level because capture/apply merge only named children of the live option. Capture reads the live option, keeps only the entries whose sub-rule is `authored` (everything else — including any key genuinely absent from the manifest — is left out of state entirely, not merely excluded), and applies the ordinary ref/json_refs/key_refs/secret-guard machinery per sub-key exactly as it would to a same-shaped top-level rule. Apply reads the **target's own live value** of the option (defaulting to `[]` with a warning if the option is absent there), overlays only the captured sub-keys' resolved values on top of it, and writes the merged array back — every sibling key on the target, declared or not, whole-option class `env`/`runtime`/`derived` or otherwise, survives byte-for-byte untouched. Repository authorization mirrors the split: an option carrying `sub_keys` is authorized key-by-key against the declared sub-rules (any key present in a captured value with no `authored` sub-rule is refused by name — `option_sub_key` surface, not folded into the whole-option `class` check). `wp duo lint`'s bare-id scan is likewise sub-key aware: a `json_refs`/`key_refs`-declared sub-key gets the deep structural scan; a plain sub-key gets the ordinary shallow scan one level in. Motivating case: Polylang's `polylang` option and Yoast's `wpseo` option each mix authored configuration with per-environment bookkeeping that must never travel, inside the SAME option blob — `sub_keys` lets a manifest tell those apart without capturing the whole blob (silently clobbering another environment's own bookkeeping on apply) or excluding it whole (silently losing real authored configuration).
- **Dynamic option names** (spec v0.20, DUO-3264): `"dynamic_options": {"<key>": {"prefix": "<literal>", "resolver": "<resolver>", "sub_keys": {...}, "autoload"?: "..."}}` — for an option whose NAME is computed from environment state rather than declared literally (`theme_mods_<active stylesheet>`). The declaration is `sub_keys`-shaped and carries no top-level `class` of its own: the containing blob is **always** `env` (a hardcoded engine decision, not a manifest field — a dynamic-name blob is environment-local by construction except the keys the manifest names), and each declared sub-key classifies independently with the ordinary rule vocabulary. Exactly one live name is ever read: the one the resolver currently produces. Every other live name sharing the same `prefix` — a `theme_mods_*` row for a theme that is not currently active — is environment-local residue by the same declaration, never captured and never reported unclassified. `resolver` is a **closed, engine-owned vocabulary** (v1: exactly `active_stylesheet`), because a resolver is engine code, not data: admitting one costs three coordinated engine edits — the allowlist entry (`Policy::DYNAMIC_OPTION_RESOLVERS`), the capture-side match arm that computes the live value, and the apply-side map entry that supplies it — and every one of them is mandatory. A manifest declaring a resolver the engine cannot resolve is refused at load; a *declared* resolver that some engine call site fails to supply a value for is a loud engine-wiring error, never a silently unclassified option.
- **Widgets** (spec v0.19, DUO-3278): `"widgets": {"<id_base>": {"settings": {"<field>": {"class": "authored", "codec"?: "blocks", "ref"?: "term", "allow_secret"?: true}}}}` — a closed per-type registry of the settings fields Duo will carry for a widget instance. The type key is WordPress's own `id_base` (the `widget_<type>` option name), matching `^[a-z0-9_-]+$`; its derived ledger kind must fit `duo_map.id_kind`. `settings` is an allowlist and is mandatory: capture refuses any live setting the map does not name, so an absent map makes every instance of that type uncapturable rather than partially captured. Every named field declares `class: authored` — a settings map has no meaning for a field it is not carrying, so exclusion is expressed by leaving the field out. `codec` and `ref` are **closed, engine-owned vocabularies** (`blocks` and `term` respectively) and are mutually exclusive on one field: a value is either a structured document the engine decodes or a single entity reference it resolves. An undeclared widget type is reported, never guessed; its instances stay unmanaged.
- **Adapter compatibility contract** (spec v0.15, DUO-3222/DUO-3247): a manifest MUST declare `"spec_version"` (int, the wire-format grammar it was authored against) equal to the engine's own `DUO_SPEC_VERSION` exactly — absent and declared-wrong are the same failure, both refused at load time. (This was not always true: at v1/DUO-3222, while `DUO_SPEC_VERSION` had exactly one historical value, an absent declaration was lenient — it can't be "wrong" when nothing else it could have meant existed yet — with an explicit, written pre-commitment to flip the moment a second historical value existed to be silently wrong about; DUO-3210 performed that bump, DUO-3247 actioned the pre-committed flip.) A manifest may additionally declare `"plugin"`/`"version_range"` (unchanged from spec v0.9's original mechanic) and `"theme"`/`"theme_version_range"`, the exact same `{min,max}` (min inclusive, max exclusive) shape mirrored for themes: one theme per manifest, matching one plugin per manifest. `Policy::load()` rejects, at load time, before any target contact: a `plugin`/`theme` declared without a well-formed matching range (no latest/wildcard/unbounded support is certifiable); a malformed range (missing min/max, non-string, min not strictly less than max); and two pinned manifests naming the same plugin or theme with different ranges (conflicting ownership — manifest precedence may never depend on pin order, so this is refused outright, with no composition/override grammar in v1). The compiled artifact (`RepositoryCompiler`) records a `resolved_adapters` array — one row per pinned manifest, carrying its name, a per-manifest content digest (the same bytes `manifest_hash()` already folds into its one combined hash, now also exposed individually), and its declared identity/range facts. This is compilation staying honest about what it validated — declaration validity and non-ambiguity, reproducible and artifact-hashed — not a live-environment match, which stays `Deploy::code_mismatch()`'s job: it checks a declared `theme_version_range` against the environment's actual installed theme version exactly as it already does for plugins, producing the identical `outside_version_range`/`missing_in_code` finding shape with `kind: "theme"`.
- **External manifest disposition and capability contracts** (DUO-3224/DUO-3227): [manifests/dispositions.json](../manifests/dispositions.json) is separate from every manifest so declaration cannot imply certification. It has exact one-for-one coverage of the shipped manifest JSON files and classifies each `certified`, `experimental`, or `excluded`, naming supported versions, entity/field sections, operations, lifecycle phases, deletion semantics, explicit unsupported behavior, and every manifest table whose default keyspace is authored. Intent-only table declarations must appear as unsupported rather than implemented. Certified entries and profiles cite named tests in `duo-certification-bundle/v1`; the bundle embeds and hashes this matrix and refuses a certified evidence reference not present in that run. The generated [manifests/capabilities/registry.json](../manifests/capabilities/registry.json) is the product-facing projection: it binds those facts to the platform/spec versions, exact per-adapter digest, target runtime assumptions, content-addressed bundle digest, named passing tests, force-hatch record, operations, and flattened surfaces. Disposition and capability bytes are frozen in the policy snapshot; compiled adapters carry the same claim used by host promotion. `wp duo capabilities --repo=<p> [--operation=<op>] [--surface=<surface>] [--revision=<sha>] [--format=json]` evaluates the selected target; `--all` reports the shipped library. Missing/experimental/excluded claims, an unregistered operation or surface, target version mismatch, multisite, or expired evidence keeps readiness non-green. Plugin execution without source modification is a separate registry field from authored-state branchability. Custom test/operator manifest directories without the two registries retain legacy policy behavior but make no certified product claim.
- **Out-of-tree adapter sources** (DUO-3314): a site repository may install adapters of its own in `adapters/<name>.json`. This is a SECOND adapter source that **overlays** the shipped library — additional pinnable adapters, never replacements — so the shipped directory's one-for-one disposition coverage above is unchanged, and a repository with no `adapters/` directory behaves exactly as before. Discovery scans every source and refuses before any pin resolves: a site adapter whose file name collides with a shipped manifest (shadowing), whose declared `name` disagrees with its own file name (ambiguous identity), or whose declared name a shipped manifest already declares. `adapters/dispositions.json`, `adapters/capabilities`, `adapters/{interpreters,providers,regenerators}`, symlinks, nested `*.json`, and extension near-misses such as `.JSON` are refused rather than ignored — an adapter cannot ratify itself, and the engine never loads code from this source. **A data-only manifest acquires no executable privileges**: an out-of-tree manifest declaring `interpreter`, a `regen_dependency.regenerator`, or a `providers[].source: "manifest"` is refused with remediation, because all three resolve inside the agent's own manifest directory. `providers[].source: "plugin"` remains available — that code's trust anchor is the installed plugin, named explicitly and bounded by the manifest's `version_range`. Out-of-tree adapters are **uncertified by construction**: they receive no disposition entry and no registry claim, carrying instead a synthesized `duo-adapter-sources/v1` provenance record whose status is `uncertified` (deliberately outside the ratified `certified`/`experimental`/`excluded` vocabulary, so pasting one into `dispositions.json` is itself refused). That record occupies the same disposition slot the per-adapter digest already hashes, so an out-of-tree adapter's identity binds its origin while every shipped digest is byte-for-byte unchanged. Such an adapter stays usable — `plan` and `apply` run — but conspicuous: `resolved_adapters` rows carry `source` and `trust_tier` (`declarative_manifest`, `native_action`, `plugin_provider`, or `compatibility_shim`, derived from the privileges the manifest actually asks for, never self-declared); `wp duo capabilities` prints source, trust tier, certification state, and remediation per adapter; the plan's `adapter_dispositions` rows and `duo status` carry the same, keeping readiness and host promotion blocked with the `adapter_source_uncertified` reason code. The policy snapshot freezes the provenance (`duo-policy-snapshot/v4`), and the frozen path re-derives every field rather than trusting it: the expected `adapters/<name>.json` path is rebuilt from the record's own key, the recorded canonical content hash is recomputed from the frozen manifest, and the trust tier is re-derived from that manifest's declarations.

### Vocabulary ownership and extension (spec v1, DUO-3318)

Every closed vocabulary above has exactly one owner and exactly one extension path. A value outside a closed set is refused at manifest load — before any target contact — with a message that names the rejected token, prints the legal set, and states who owns extension. Silence is never the answer: an unrecognized value used to mean "fall back to the default" or "match nothing", which is indistinguishable from a deliberate declaration and is how a transposed letter silently dropped a plugin's authored rows out of canonical state.

| Vocabulary | Owner | Extension path | Refusal posture |
|---|---|---|---|
| `post_types.<t>.body`, `.phase` | engine | engine change + spec bump | load-time; names token, set, owner |
| `post_types.<t>.fields.<f>` and its `class` | engine (`Policy::DERIVABLE_FIELD_COLUMNS`) | engine change + spec bump, per field, with its own evidence | load-time |
| `tables.<t>.class` | engine | engine change + spec bump | load-time |
| `tables.<t>.identity.mode` | engine | engine change + spec bump | load-time |
| `tables.<t>.invalidate[]` shape | engine | a native action or provider capability, not a new invalidation verb | load-time |
| ref kinds (`ref`, `refs[].kind`, `json_refs`/`key_refs` `kind`, `block_attrs`/`shortcode_attrs` `kind`) | engine for `post`/`term`/`tt`/`user`; **adapter** for every other value | declare a `tables.<t>` you own and name its `id_kind` | load-time, across all pinned manifests |
| `block_attrs`/`shortcode_attrs` rule shape, `type`, `cast`, `tokenize` | engine | engine change + spec bump | load-time |
| `widgets.<t>.settings.*` `class`/`codec`/`ref` | engine | engine change + spec bump | load-time |
| `dynamic_options.<k>.resolver` | engine | engine change + spec bump (three coordinated edits — see above) | load-time |
| classification rule `class` (`authored`/`runtime`/`derived`/`env`/`managed`); whole-entity **scope** `class` is the same set minus `managed` | engine | engine change + spec bump | load-time |
| native action names and argument schemas | engine (`NativeActions`) | a plugin-owned provider capability | load-time |
| provider ids, capability names, argument schemas (including a `list<object>` argument's own `fields` row vocabulary, bounded at one level) | **adapter/plugin** | the plugin's own provider declaration | load-time for identity/shape/depth; negotiation before first mutation for the rest |
| provider argument TYPE names (`bool`/`int`/`string`/`list<string>`/`list<object>`) and engine batch channel names (`deletions`/`reparents`/`retry`/`always_on_write`) | engine | engine change + spec bump — each names evidence the engine itself assembles or validates, so a capability may opt in but never mint one | negotiation before first mutation |
| effect `kind`, `mode`, `selector.scope`, `selector.type`, member placeholders | engine | engine change + spec bump; `provider_resource` + a provider is the adapter-side path | load-time |
| interpreter and regenerator names | **adapter** (code ships with the manifest) | ship the file with the manifest artifact | load time for the name, first use for the class contract |
| `spec_version` | engine (`DUO_SPEC_VERSION`) | the engine's own bump; a manifest states which grammar it was authored against and may never widen it | load-time; absent and declared-wrong are the same failure |
| `providers[].source` (`manifest`/`plugin`) | engine | engine change + spec bump — the two values name the two code-loading paths the engine implements, not a location an adapter may invent | load-time |
| manifest disposition `status` (`certified`/`experimental`/`excluded`) and profile statuses | engine, in a file no manifest can reach (`manifests/dispositions.json`) | the external review process, never a manifest field — declaration must not be able to imply certification | load-time for the registry; `wp duo capabilities` for the claim |
| `actions[].triggers` values (`(post\|term\|table\|option\|entity):<name>`) | engine owns the SHAPE (`Policy::SURFACE_PATTERN`); the `<name>` half is deliberately **open** | ordinary manifest declaration — any adapter may name any surface, including another adapter's | load-time for the shape only |
| option/post-type/table NAMES, patterns, keyspaces | **adapter** | ordinary manifest declaration | n/a — this is the data surface, deliberately open |

`actions[].triggers` is the one row above that names another adapter's surfaces on purpose, so it is worth saying why that is not a hole in the rule. A trigger is a literal, pattern-bounded string matched against the surfaces apply *derived from authored work it already decided to write* (`Apply::rebuild_surfaces()`). Matching one grants exactly one thing: the declaring manifest's own action runs afterwards, inside its own declared effect budget. It grants no read of the other adapter's data, no say in whether that work happens, and no ability to change any rule the other adapter declared — which is the whole content of "authority" everywhere else on this page. That is what makes it safe to leave open, and it is also the honest shape of the problem: a caching adapter must be able to say "flush when products change" without WooCommerce having to know the cache exists. Observation is open; authority is one-owner.

Three precedence families exist, and they are not interchangeable. A new vocabulary must pick one **and say which**:

1. **Core yields to plugin** — single-name rule lookups (`options`, `post_meta`, `term_meta`, `user_meta`, `menu_fields`). Site policy outranks everything; among manifests, a non-`core` declaration of a name always outranks `core`'s regardless of pin order (DUO-3249), and two non-`core` manifests declaring the same name with different effective rules are refused outright rather than resolved by pin order (DUO-3255). Reclassifications are plan-visible. The bulk option enumerators (`authored_options`, `sub_keyed_options`, `env_options`) belong to this family too, not to family 3: they resolve every name through the same single-name lookup rather than re-deriving precedence with a merge (`Policy::resolved_exact_options()`), so a bulk read can never pick a different winner than capture/apply does for the same name.
2. **First declaration in pin order wins** — structural facts about someone else's data shape (`block_attrs`, `shortcode_attrs`, `taxonomies.<t>.description_refs`, `post_types.<t>.body`/`.phase`/`.fields`/`.regen_dependency`, `dynamic_options`, `deletions`, `version_range`). These have no site-policy override, because they assert a fact about a plugin's own behavior rather than a site-local choice.
3. **Last pin wins, plus site policy last** — the two bulk table/widget enumerations: `tables` (site `policy.tables` overrides a manifest declaration wholesale) and `widgets` (no site layer at all — a site repository has no `policy.widgets`, so the last pinned manifest is simply last). This disagrees with family 1 for the same underlying data and is a known, documented inconsistency rather than a design (`Policy::declared_tables()` says so in its own docblock); it is called out here so a new vocabulary does not inherit it by accident.

Adapter-owned extension may never grant one adapter authority over another's state. The rule for the four bulk **named-declaration** surfaces — `post_types.<t>`, `tables.<t>`, `taxonomies.<t>`, `widgets.<t>` — is **one owner per name**: two pinned manifests (including `core`) declaring the same name refuse at load unless their declarations are byte-identical, since a redundant restatement has no winner to pick. There is no composition grammar for these surfaces in v1 (`taxonomies.<t>`'s description_refs/object_type/class lookups are all first-pin-wins with no precedence layer, so it carries the identical hazard); reclassifying an individual FIELD of another adapter's surface is what the family-1 precedence layers exist for, never a whole-declaration takeover. The post-type surface additionally keeps a per-KEY contradiction guard, which fires first because it can name the exact contradicting key (`post_types.<t>.body` and so on) instead of only the name. `site.duo.json`'s own `policy.tables` is exempt from the rule, because it is the site's own last-word authority over its own state rather than a second adapter reaching into the first. The other load-time guards in the same family: one owner per option namespace, per plugin/theme version claim, per provider id, and per table `id_kind`; a provider-kind action may only name a provider its OWN manifest declares; an adapter widens the ref-kind vocabulary only by declaring a table it owns; and the two surfaces that name a `duo_map` keyspace directly (`deletions[].guards[].id_kind`/`source_id_kind`, `option_name_refs[].id_kind`) are closed against the ledger's own long spellings plus the declared table kinds.

## Ledger tables (per environment, never in the repo)

| Table | Purpose |
|---|---|
| `duo_map(uuid, entity_type, id_kind, local_id)` | typed identity map |
| `duo_state(uuid, entity_type, content_hash)` | canonical hash at last capture/apply — drift & 3-way base |
| `duo_kv(k, v)` | `applied_revision`, guid pins, config |
| `duo_journal(...)` | provenance journal (Spike C; runtime data, prunable) |

## The review queue (spec v0.6 — the core loop)

Unclassified state is never silently captured *or* silently skipped; it queues for human triage:

- **`wp duo pending --repo=<p> [--format=json]`** — the review queue. Items merge three sources: the classification **gate walk** (unclassified whole-entity scope plus post/term-meta keys on in-scope entities, with entity counts and post types — the same scope and rule lookups capture uses, so they can never disagree), **journal-observed unclassified option writes** (options are whitelist-only at capture, so the journal is what surfaces them — finding #5's answer), and annotations: a **ref hint** when the current value is a numeric id that exists in wp_posts/wp_terms (finding #9's linter seed; small ids can coincide — hints are hints), and a **secret flag** (`hard:<label>` on high-confidence patterns — Stripe/AWS/GitHub/Slack keys, PEM blocks, JWTs — or `suspicious` on key-name+shape heuristics). `proposal` is only ever the journal's capability×surface signal; with no journal evidence it is `null` — never guessed. Unclassified **term meta** is surfaced but does not abort capture: v0 term files carry no meta values at all, so there is nothing a classification could yet make capturable.
- **`wp duo classify --repo=<p> --set='<section>:<key>=<class>[,ref=<kind>][,cast=<c>]; …'`** — writes rules into `site.duo.json` policy. All decisions travel in ONE semicolon-joined `--set=` (wp-cli keeps only the last occurrence of a repeated assoc flag, and the space-separated form parses as a boolean — both documented traps). Classifying a key as `authored` while its current value hard-matches a secret pattern is **refused** unless `--allow-secret` (which records `allow_secret: true` on the rule).
- **Secret guard at capture**: any authored-classified option/post-meta string value that hard-matches a secret pattern **aborts capture** naming the key (the rule-level `allow_secret: true` is the escape hatch for false positives); post bodies warn loudly but never block. Values over 64KB are skipped.
- **`wp duo policy-to-manifest --repo=<p> --match=<regex> --name=<n>`** — exports matching policy rules as a canonical manifest JSON on stdout (policy is left untouched; moving rules upstream is a deliberate human act). A pinned exported manifest reproduces byte-identical captures to the policy it came from.
- **`wp duo manifest-pin --name=<n> [--repo=<p>]`** — emits the installed manifest's copy-pasteable `{name,digest,source}` pin using the same per-manifest digest recorded by offline compilation. `--repo` names the out-of-tree adapter source explicitly, so a site-installed adapter is pinnable; the repository's own `manifests` array is never resolved either way, allowing an operator to review a legitimate manifest change, generate its new pin, then update `site.duo.json`; until that explicit update, the old pin keeps every policy-loading command fail-closed.
- **Dangling references**: an unmapped id in a ref-typed meta value is **dropped with a warning** (array/csv: the element; scalar: the whole key), mirroring options' long-standing semantics — a raw env-local id in canonical state is indistinguishable elsewhere from a valid id and may silently point at an unrelated live entity after auto-increment reuse. Convergence comes through the repo: the corrected canonical value applies everywhere. A `block_attrs` ref (a `"kind"`/`"kind_from"` rule) that fails to map gets the identical uniform treatment: `int[]` drops just that element, a scalar drops the whole attribute key, both warning by block/attribute/id — this now includes the `wp-image-<id>` CSS class WordPress's own image/gallery/media-text/cover blocks carry alongside a `"kind":"post"` attribute ref (spec v0.16, DUO-3212): it used to fail *open*, leaving the raw digits unrewritten in canonical state on an unmapped id — the one place this uniform drop-with-warning treatment didn't already hold. A `shortcode_attrs` ref (spec v0.18, DUO-3259) gets the same treatment a third time: a scalar drops the whole attribute (its own leading whitespace dropped with it, so no double-space artifact); `cast: "csv"` drops just the unmapped element and rejoins the survivors on comma, dropping the whole attribute only when every element was unmapped. Rewriting splices the changed attribute's exact byte span back into the original shortcode text rather than reparsing and reserializing the whole attribute string — WordPress ships no canonical "serialize shortcode attributes back to text" function, so this is the only way to guarantee every undeclared attribute, and every declared-but-untouched byte (quote style, spacing, ordering) of a *touched* one, survives capture unchanged. A `?p=`/`?page_id=`/`?attachment_id=` url-query ref (spec v0.20, DUO-3260, always kind `post` — the only kind these three parameters ever resolve to) drops the whole `separator+param=value` span, piggybacking on `Tokens::tokenize_text()`/`detokenize_text()`'s own home/uploads pass rather than needing new declarative grammar: rewriting is scoped strictly to `{{home}}`-anchored URL spans (an external URL's own unrelated `?p=` is never touched — the one place this mechanism's own safety scope is narrower than `wp duo lint`'s matching detection, which is deliberately un-anchored; see `wp duo lint` below).
- **Unscoped references** (spec v0.10, task #73; extended to block refs at spec v0.16, DUO-3212; extended to shortcode refs at spec v0.18, DUO-3259; extended to url-query refs at spec v0.20, DUO-3260): an authored, ref-typed **option**, a `block_attrs` ref (including the `wp-image-<id>` class), a `shortcode_attrs` ref, or a `?p=`/`?page_id=`/`?attachment_id=` url-query ref whose id names a row that *genuinely exists* but whose post type/taxonomy is missing from policy scope is a different failure mode — a fixable scope gap, not deleted data — and **aborts capture loudly** by default (the unclassified-meta gate's posture), naming the option/post/block/shortcode/param, the raw id, the target's real type, and the exact policy key to amend. `--force-unresolved-refs` (accepted by `capture`, `plan`, and `apply` — the latter two hit the same gate through their internal env snapshot) opts back into dangling-style drop-with-warning, for any surface. A real, correctly scoped row that merely has no uuid minted yet (every fresh target environment before its first capture) is neither dangling nor unscoped and keeps the ordinary warn-and-drop path. Array-ref options, array-ref block attributes, csv-cast shortcode attributes, and url-query refs all get identical per-element treatment — scalar and array/csv never diverge in severity, on any surface. All four surfaces share the SAME entity-type-lookup implementation (`Capture::ref_target_type()`, and the shape-agnostic three-way decision built on it, `Capture::classify_unscoped_ref()`, extracted at DUO-3259 specifically so later callers would not need their own hand-copy of the same logic) — one source of truth for "does this id name a real row, and what type is it," not four. `post_meta` ref-typed values do not yet have this triage (still ordinary dangling-style drop only, unconditionally) — a real, separate gap, tracked apart from this one.
- **`wp duo lint`** — the suspicious-ref gate byte-diffing cannot provide (wrong bytes written once read back faithfully): flags `bare_id` (numeric values matching existing entity ids under rules with no declared ref), `escaped_home` (JSON-escaped env URLs the tokenizer's plain-form substitution misses), `unregistered_block_attr` (id-shaped attrs in blocks with no registry rule, and URL-shaped string attrs), `unrewritten_registered_ref` (a block attribute path *is* declared in the `block_attrs` registry but its captured value is still numeric — a declared ref whose rewrite silently didn't happen, e.g. an unmapped id or a `kind_from` dispatch that resolved to no kind — distinct from `unregistered_block_attr`, which fires when no rule exists for the path at all), `serialized_desc_ids` (id-bearing serialized term descriptions) — spec v0.18, DUO-3259 — their shortcode twins `unregistered_shortcode_attr`/`unrewritten_registered_shortcode_ref`, scoped to shortcode tags a `shortcode_attrs` rule actually declares (there is no registry-independent way to enumerate "every shortcode on the system" the way `parse_blocks()` enumerates every block for free — an unbounded, deliberately out-of-scope problem), and — spec v0.20, DUO-3260 — `unrewritten_url_query_ref` (a raw `?p=`/`?page_id=`/`?attachment_id=` digit anywhere in captured state). Unlike every other class here, this one is deliberately NOT scoped to a declared registry or a `{{home}}` anchor: `Tokens::tokenize_text()`'s own REWRITE is home-anchored for safety (never touch an external URL's own unrelated `?p=`), but this lint scan is a wide net with an honest caveat instead — this file's own established philosophy throughout (a genuine false positive here, a third-party URL sharing the same common parameter name, is exactly the same "small ids coincide" caveat `bare_id` already carries). Exit 1 on findings; the conformance harness runs it as a hard gate. A rule may declare `"lint_ok": true` — an explicit, auditable human review meaning "numeric but genuinely not a ref"; it works on option/meta rules (e.g. `posts_per_page`) and as a block_attrs entry (e.g. `queryId`, a query instance index — the rewriter skips such rules entirely, and lint treats it as owned rather than an unrewritten ref). The only sanctioned exemption.
- **Shortcode attributes not codec'd** (spec v0.18, DUO-3259): `shortcode_attrs` deliberately covers only attributes grounded as genuine, currently-reachable references by reading the relevant shortcode callback's WordPress core source directly — not every id-shaped-looking attribute on every shortcode. The legacy `[gallery]` shortcode's `id`/`ids`/`include`/`exclude` are declared (four genuine post refs, confirmed via `gallery_shortcode()`); `[caption]`'s own `id` attribute is deliberately NOT declared — confirmed via `img_caption_shortcode()` directly, it is `sanitize_html_class()`'d and emitted verbatim as a DOM id for CSS/JS targeting only, never parsed back into a numeric attachment reference anywhere in WordPress core, so there is nothing to codec (an explicit-unsupported ruling for a different reason than "hard to build" — see `manifests/core.json`'s own note).

The orchestrator surfaces this loop as `duo pending <env>` and `duo classify <env>` (interactive stdin triage; Enter accepts a proposal, explicit keys override, secrets require typing "allow"; `--accept-proposals` for CI, which never auto-authors a secret) — see cli/README.md.

## Offline repository compilation (spec v0.13)

`wp duo compile --repo=<p> [--out=<artifact.json>]` is the semantic merge gate. It reads one complete repository revision without constructing target-bound tokenizers or consulting the target database, parses every canonical entity into typed data (post metadata plus a distinct raw body), and emits `duo-compiled-repository/v1`. The artifact embeds referenced media bytes, active site-policy and pinned-manifest/interpreter hashes, an exact state/media `revision_hash`, the optional independent code-payload descriptor/`code_revision`, and its own SHA-256 content address over both halves. Loading an emitted artifact verifies those hashes; a current policy/manifest mismatch refuses it, as does descriptor absence/presence that disagrees with the active policy's code declaration. Code stage re-hashes the source payload before and during target writes, so changing `code/` after compilation fails rather than mixing revisions. Every host phase also supplies the outer hash it observed at compile time. Lifecycle deploy and finalization then consume only that frozen descriptor and verified staged target; they do not reopen mutable source.

Compilation batches stable blocking diagnostics for malformed or unknown entity kinds, invalid/duplicate UUIDs, duplicate natural identities (`post_type + slug + parent`, `taxonomy + slug`, or a declared table natural key), malformed/unsupported tombstones, live+tombstone collisions, conflict markers, graph references whose target is explicitly deleted or has the wrong kind, unsafe/duplicate attachment paths, missing or mis-hashed media, schema/content mismatches, and pinned adapter constraints. ACF's schema/value checks use the same manifest-shipped interpreter trust boundary as classification—never the installed plugin. The DUO-3203 policy-authorization pass is the final compiler layer and preserves its existing structured failure contract.

The compiled artifact also carries `uploads_inventory`: one deterministic row
per attachment with the exact original upload-relative path and content hash,
plus the only derivative directory/basename prefix metadata generation may
mutate. Two originals may not share a derivative root, even when their
extensions differ. `duo plan` exposes the same rows before target mutation.
This bounded compile declaration is not wildcard deletion authority; the SSH
upload provider resolves it into exact encrypted before-images and exact
absence receipts under the signed rollback generation (see
`docs/upload-bundle.md`).

Plan, apply, and deploy construct or load this artifact before target contact and accept only the `CompiledRepository` type internally—never a raw tree array. `--compiled=<artifact.json>` reuses a previously emitted artifact. All phases consume its decoded data/body/media payload and never reopen mutable `state/` or `media/` files after compilation; a failed compilation therefore creates no ledger and performs no target read, lifecycle call, rebuild, filesystem materialization, or database write.

Apply's mandatory fresh-process post-mutation verifier receives private
temporary snapshots of that exact in-memory artifact and its already-validated
`Policy`; it does not reload `site.duo.json`, manifests, `state/`, or `media/`
from the checkout. The child revalidates the policy shape and artifact
site/manifest hashes, requires the parent's exact outer artifact hash, and
removes both handoff files after the child exits.

## Bounded provider-resource selectors

An external `provider_resource` effect may declare a bounded aggregate by
adding a `members` object to its selector:

```json
{
  "scope": "external",
  "type": "provider_resource",
  "value": "vendor-cache:v1",
  "members": {
    "exact": ["fixed-resource"],
    "templates": ["item_{positive_uint}", "attribute_{slug}"]
  }
}
```

`members` is provider-neutral and has exactly the `exact` and `templates`
lists. Exact entries are non-empty literal values. Templates must contain at
least one typed placeholder; the only placeholders in spec v1 are
`{positive_uint}` (canonical decimal `1`–`19` digits, first digit non-zero)
and `{slug}` (lower-case `[a-z0-9][a-z0-9_-]{0,63}`). Control characters,
wildcards, angle brackets, unmatched/unknown placeholders, duplicate entries,
secret-shaped values, empty member lists, and extra selector/member keys are
refused during policy compilation and effect-inventory validation. An exact
member equal to the aggregate's selector `value` is also refused.

Runtime observation remains exact-shaped: an actual selector must contain only
`{"scope":"external","type":"provider_resource","value":"<concrete>"}`.
The concrete value must match one exact member or one template; an aggregate
identifier, wildcard, malformed value, or actual selector carrying `members`
never authorizes an effect. Selectors without `members` retain ordinary exact
matching, so this extension grants no implicit namespace authority to any
other effect type or provider resource.

## Apply semantics (v1)

0. **Offline compilation + repository authorization**: compile the complete immutable revision into the verified artifact above before any target contact. Its final layer checks every repository-carried post-meta key, option, typed-table column, and attached-meta key against current policy. The three managed code options and managed menu/attachment fields are accepted only through named dedicated routes. Unknown, runtime, derived, environment, stale-policy, and misplaced managed fields preserve the stable `{ok:false,error:"repository_authorization_failed",diagnostics:[...]}` contract; all earlier semantic failures return `{ok:false,error:"repository_compilation_failed",diagnostics:[...]}`.
1. **Plan live entities**: for each entity file: `create` (uuid not in map), `update` (canonical hash ≠ `duo_state` hash), `unchanged`; environment drift remains explicit. A ledger UUID absent from state and lacking a tombstone schedules nothing.
2. **Plan tombstones**: compare the tombstone `expected_hash`, target `duo_state` base, and current canonical environment hash. Exact base + unchanged target → `delete`; target already absent → `deleted`; missing/mismatched base, local edit, or recreation after a deletion receipt → `delete_conflict`. `--force-theirs` may override a deletion conflict but reports it loudly. Fresh and previously mapped targets therefore interpret the same repository deletion intent; absence alone never differs by ledger history.
3. **Reference safety**: compilation blocks surviving canonical references. Adapter guards check runtime reverse references; a missing required guard table also blocks. `--force-delete-referenced` is an explicit report-not-hide escape hatch.
4. **Canary armed**: listeners on `save_post`, `transition_post_status`, `created_term`, `wp_insert_comment` + `pre_wp_mail` + `pre_http_request`; any fire during apply = hard failure.
5. **Phase 1** — upsert rows (posts, terms) with placeholder refs, direct `$wpdb`; mint local ids; write `_duo_uuid`.
6. **Phase 2** — resolve refs through the ledger: parents, metas, term relationships, menu structure, option values, body detokenization (block registry restores numeric types).
7. **Deletes** — only with `--with-deletes`, custom-table children before parents. The engine performs the declared cascades, then queries every exact target and attached sidecar before commit. Any survivor rolls back the transaction. Menus delete their owned menu-item posts; comments, Woo order lookups, Ninja Forms submissions, and other declared runtime references are preserved by guards rather than cascaded.
8. **Rebuild** — canary disarmed: required derived-dependency synthesis and verification, term recounts (direct SQL), attachment metadata regeneration, manifest-declared structured actions (closed native actions and pre-mutation-negotiated provider capabilities), and cache flush.
9. **Verify convergence** — recapture the live target through the canonical snapshot reader in a fresh WordPress process before any convergence metadata advances. The verifier is pinned to the exact compiled artifact used by apply, avoiding stale pre-apply plugin models and refusing a concurrently changed repository. Every entity in the compiled tree must have the same type and canonical hash. Target-only entities remain untouched because absence is not deletion authority; when `--with-deletes` is explicit, every compiled tombstone UUID must be absent. A mismatch names the failed invariant, retains `apply_in_progress`, and leaves all base hashes and `applied_revision` unadvanced.
10. **Receipts and retry** — only after verification passes, a successful or already-absent deletion stores the tombstone hash in `duo_state` with entity type `deletion`; re-planning returns `deleted`, so retries are idempotent. Live hashes and `applied_revision` update atomically with clearing `apply_in_progress`.

For the primary `compile`, `capture`, `plan`, `apply`, `deploy`, `code-stage`,
and `code-finalize` command path, a JSON-mode refusal wraps the stable object
above in `format:"duo-command-refusal/v1"` and adds `command`, `reason_code`,
reviewed public `message`, and reviewed public `remediation`. The established
top-level `error` and safe `diagnostics` fields retain their exact shape.
Every serialized field is subject to one final sensitive-data guard: if any
typed diagnostic contains a secret, credential-bearing or signed URL, email,
private home path (Unix, drive-letter, or UNC), or control byte, the whole
diagnostic batch is omitted,
the stable `error` remains, and `details_redacted:true` is returned. Unknown
query- or fragment-bearing absolute URIs are conservatively treated as signed
or credential-bearing rather than serialized from refusal evidence. Unknown
throwables never contribute their message, cause, path, or trace. Known scope
and recovery gates use finite source-owned codes and remedies; an uncertain
commit or ambiguous publication boundary explicitly instructs callers not to
retry or discard retained evidence.

Every direct database mutation and transaction boundary is checked for
WordPress's `false` failure result; zero affected rows remains a valid
UPDATE/DELETE result, while an insert without a positive generated id fails
before identity can enter the ledger. Apply writes an environment-local
`apply_in_progress` marker before the first target mutation and clears it only
after all required rebuild actions and the canonical recapture verification gate
succeed. If a post-commit rebuild or verification fails, base hashes and
`applied_revision` do not advance; the marker makes the next apply reprocess
canonical entities (including attachment metadata) rather than mistaking
byte-equal authored rows for a completed promotion. Plan exposes the marker as
a structured `incomplete_apply` condition, so `duo status` remains non-zero
until that retry succeeds and clears it.

Snapshot/rollback is the orchestrator's job in v0. The normal host path,
`duo promote <env>`, compiles one immutable artifact, acquires a target lease
bound to its outer `artifact_hash`, then exports the database to the target
repo's gitignored `.duo/checkpoints/`. It then sequences code stage → lifecycle
retirement → fresh-process lifecycle activation → code finalize/verify → state
apply against that same artifact. Repositories without the optional `code`
contract run retirement → fresh-process activation → apply after the
checkpoint, without code materialization. A failed post-begin phase stops
all later phases and performs an exact idempotent lease abort while preserving
the original phase failure. Finalize publishes the completed code descriptor
and `code_revision` while removing all temporary stage markers in one database
transaction; statement/commit failure rolls the entire ledger transition back
to its retryable staged form.

The target database lease serializes Duo promotions; it cannot exclude a
package manager, self-updater, shell user, or compromised process that writes
the code tree directly. Code stage/finalize therefore require operational
exclusion of every non-Duo writer from `WP_CONTENT_DIR` for their duration.
The v0 PHP materializer refuses stable symlinks, unsafe paths, type changes,
and hash-changed removals and publishes each file by temporary rename, but does
not claim `openat(O_NOFOLLOW)`-grade safety against an adversarial concurrent
directory-to-symlink swap. Only a descriptor whose complete payload was
materialized is retained as component-root deletion authority; an interrupted
partial write can require manual cleanup but cannot make an unproven root
Duo-owned.

Lifecycle deploy and state apply remain separate writers even though
`active_plugins`, `template`, and `stylesheet` live in the same canonical
`options/core` entity as ordinary authored options. If deploy actually invokes
activation/deactivation/theme APIs, it binds the canonical entity hashes from
immediately before and after that hook window to the current promotion
owner/artifact session. Apply may use the pre-hook hash for its three-way base
only after deploy proves every changed canonical record is either one of those
three managed lifecycle records or already exactly equals the frozen artifact's
non-`absent` desired record, and only when a fresh live snapshot still equals
the recorded post-hook hash. Any unrelated hook mutation stops promotion before
state apply; any later target edit invalidates the handoff and retains the
normal conflict. This lets expected lifecycle-first progress and a
same-revision authored option change compose without `--force-theirs` without
granting entity-wide authority to arbitrary hook side effects.

The checkpoint necessarily contains the temporary `duo_kv.promotion_lock` row,
because it is taken under that lease. Since importing the database can replace
that same row, it cannot provide its own uninterrupted exclusion. A later
restore first requires external maintenance/exclusion for every Duo writer,
then uses this exact row-repair order: idempotently abort the old owner/hash,
begin that owner/hash again, import the checkpoint, then idempotently abort the
row restored by the import (the final abort is required even if import fails).
For a code-enabled failure, restore/reconcile code to its known
pre-promotion revision before that sequence; database import alone is never a
complete code-and-state rollback. A failed export is not a checkpoint and gets
no import instruction.

The automatic production-SSH rollback contract is specified separately in
`docs/proposals/verified-ssh-rollback.md`. SSH adoption installs a
database-independent runtime under
`.duo/control`, provisions public verification keys, preserves a never-reused
target generation, and verifies immutable signed receipts plus append-only
signed event chains. Status and new SSH mutations fail closed for corrupt or
nonterminal active generations. `duo promote` selects automatic rollback only
for an SSH environment whose explicit `verified_rollback` policy and complete
checkpoint/code-release/upload/effect provider set pass runtime preflight. It
binds the immutable compiled code/upload/effect inventories into provider
preparation and receipt v2 before the
database lease or semantic mutation, journals provider operations, and releases
traffic only after signed `committed` or proof-admitted `rolled_back`. Missing
capabilities are a loud operator-directed fallback; configured-but-invalid
capabilities refuse before mutation. No filesystem layout is capability
evidence.

Deploy and apply also share one target-authoritative lease in the target
database. The `duo_kv.promotion_lock` record names a random orchestrator owner,
the compiled artifact hash, current phase, and bounded expiry. Only
`promotion-begin` may create or recover it and records the latest begun
owner/artifact session durably; explicit later phases are strict continuations
of both that session and its exact still-live row, so an absent row never
authorizes—or advertises recovery for—an obsolete checkpoint. Each live mutation process additionally
holds a connection-scoped database advisory fence. That fence covers unbounded
plugin/theme hooks and filesystem work: a second process cannot recover an
expired row while the original is still running, and the continuously fenced
owner renews when control returns. A crashed process drops the advisory fence
automatically and its row becomes recoverable by a different owner after
expiry. Direct `wp duo deploy` and `wp duo apply` calls acquire their own
single-phase row plus process fence too.

Under that lease, apply computes the live plan a second time immediately before
the first write and hashes only mutation-authorizing facts: live entity state,
identity/adoption decisions, collisions/conflicts, deletion guards, active code
state, manifest association, incomplete-apply state, and pending rebuilds. Any
change refuses without a stale-plan force path. Runtime reverse-reference guards
are then queried once more immediately before each DELETE inside the mutation
transaction. This gate serializes Duo writers while leaving public reads and
unrelated runtime traffic available; a global maintenance page is not implied.

## Code-half facts & deploy (v0 payload skeleton)

- `active_plugins`, `template`, `stylesheet` are **managed-class** core-manifest options: captured bespoke into `state/options/core.json` (plain portable strings — plugin file paths and theme slugs need no tokenization; their cross-environment stability *is* the invariant), and **excluded from apply's generic direct-SQL path** — a raw options UPDATE would skip activation/switch hooks while leaving WordPress believing the code is active.
- **Host `duo deploy <env>`** compiles once, stages add/update files from the descriptor-bound payload, invokes agent `wp duo deploy` for real lifecycle hooks, then prunes only Duo-owned obsolete files/directories, proves the managed components contain exactly the descriptor's regular files and hashes, and records the completed code revision. Stage never claims completion; a failure before final verification leaves the prior completed revision truthful.
- **Removal ordering is lifecycle-safe**: stage retains outgoing files, the retirement process deactivates them while their hooks still exist, activation runs in a fresh process, and only finalization prunes them. A canonical active plugin/theme absent from the new source descriptor is refused before stage mutates the target.
- **Hook failure is recovery-safe**: a durable pre-hook receipt prevents state apply or a different promotion session from treating partially committed lifecycle effects as convergence. The exact retained checkpoint plus known pre-promotion code revision are the only recovery boundary; ordinary retry is intentionally refused.
- **Plan's `code_mismatch` bucket**: `missing_in_code` (canonical wants an activation whose plugin is absent from the environment's code), `outside_version_range` (a pinned manifest declares `{"plugin": "<file>", "version_range": {"min", "max"}}` and the installed version falls outside), `code_revision_stale` (the compiled payload is not the last successfully verified materialization), and lifecycle mismatches (`inactive_in_environment`, `unexpected_active_plugin`, `active_plugin_order_mismatch`). Apply refuses every row. Lifecycle deploy owns activation/deactivation/theme reconciliation; only the orchestrated staged path may pass its expected temporary staleness through to final verification. `--force-code-mismatch` remains the report-not-hide escape hatch for ordinary direct calls.
- During deploy's deliberately hook-firing window, a reporting-only observer records attempted `wp_mail` and outbound HTTP calls in `external_side_effects` and human warnings. It neither blocks those calls nor changes the apply canary's fixed meaning; apply still treats content hooks, mail, or HTTP as a hard failure.
- **Plan's `code_drift` bucket** (DUO-3231): a narrower, separate question from `code_mismatch` above — not "is the installed version compatible with the manifest's declared range" but "did this exact plugin/theme's version change since Duo last observed this environment," the direct code-half analogue of state's own drift concept, catching the case a wide `version_range` can't (a wp-admin one-click update landing comfortably inside a pinned range is invisible to `code_mismatch`, yet is exactly the out-of-band mutation risk this bucket exists for). The baseline it compares against — one JSON blob under `duo_kv['code_versions']` — is written by `Deploy::record_code_versions()` at the end of every successful `duo deploy` **and** `duo capture` (either is a moment Duo legitimately observed the environment's code); no baseline yet for a given plugin means nothing to compare, not a false positive. Scoped to exactly the plugins/theme slots `code_mismatch` already scopes to (the target state's own `active_plugins`/`template`/`stylesheet`). Same blocking posture and escape hatch as `code_mismatch`: `deploy`/`apply` refuse while non-empty, `--force-code-drift` proceeds while still reporting every overridden finding (Architecture Rulings §1) — in JSON output always, and in ordinary human output too, seeded as `WP_CLI::warning()` lines precisely because reaching that code path at all means the flag was set.
- **`wp duo doctor` DISALLOW_FILE_MODS check** (DUO-3231, `cli/src/Doctor.php`): advisory-only (never fails `doctor`'s own exit code) — reports when a target's `wp-config.php` does not `define('DISALLOW_FILE_MODS', true)`, the source-closing complement to `code_drift`'s after-the-fact detection (docs/proposals/code-half.md risk register #1).
- Operational note: with a plugin active in the DB but missing from disk, the `wp plugin deactivate` *command* refuses (it pre-resolves its argument against a disk scan) — but `wp duo deploy` handles this case fine: it calls core's `deactivate_plugins()` directly with basenames from the option, no disk resolution on the deactivation side (verified live). Manual `update_option('active_plugins', …)` surgery is the last resort only when duo itself is unavailable.

### Cross-branch plugin-version skew

When branches contain different versions of a plugin, integration is an ordered boundary rather than a single undifferentiated state merge:

1. Merge the code-only change first and materialize that code.
2. Run the upgraded plugin's migrations against the current database.
3. Capture and commit the migrated canonical state under the new code version.
4. Only then merge state authored by the older-version branch. Resolve any schema conflict explicitly in the new version's shape before apply.

This keeps old-schema state from being silently interpreted by new code and gives Git a reviewable conflict when both the migration and the older branch changed the same canonical entity. `make certify-merge` exercises this end to end: a v1 scalar option diverges on a state branch while a v2 code branch migrates it to an object; the integration branch must capture the v2 object before merging the v1 edit, resolve the one-file conflict without losing the edit, and re-capture byte-identically on both environments.
