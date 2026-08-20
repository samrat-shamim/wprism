# Duo Site-Repo Format

*Status: **normative** — the authoritative contract for site repositories; where narrative documents (README, DESIGN.md) and this spec disagree, this spec wins. The wire-format grammar version is the `spec_version` integer in `site.duo.json` — currently `2` — which must equal the engine's own `DUO_SPEC_VERSION` exactly (see "Adapter compatibility contract" below). The "spec v1"/"spec v0.x" markers throughout are this document's own draft-history labels — they record when a rule was introduced and are NOT the wire version.*

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

On the primary JSON command path, that known missing-ownership gate is the typed `unsupported_deletion` refusal. Its diagnostic `surface` is the exact generic selector (for example `table:nf3_forms`), while the raw operator exception remains private. Machine callers can therefore distinguish an unsupported deletion intent from an unrelated capture/compile/plan failure without weakening the catch-all redaction rule.

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
  "spec_version": 2
}
```

The `spec_version` field is the **wire-format grammar version**: an integer a repository declares that must equal the running engine's own `DUO_SPEC_VERSION` *exactly* (currently `2` — see "Adapter compatibility contract" below and the field table). It is deliberately NOT this document's own draft-history label — the "spec v1"/"spec v0.x" markers used throughout, and in this file's title, only record *when* a rule was introduced. An author copying this example must copy the integer the running engine requires, not the number in this document's title; an absent or mismatched value is the same failure and is refused at load, before any target contact.

`policy` holds site-local classification overrides (same shape as manifest rules); it wins over manifests. `manifests` pins which registry manifests apply (agent looks them up across its three installed adapter sources: its own manifest dir, this repository's `adapters/` source, and one `duo-adapter.json` at the root of each ACTIVE plugin that bundles one). The two sources the operator authors — shipped and site — still refuse outright if both could answer one name, so there is no precedence order to learn between them. A PLUGIN-bundled name that a shipped or site definition already answers to is a different case and is resolved rather than refused: sources rank `shipped > site > plugin`, the reviewed definition wins, and the bundled one is reported on every run as an installed-but-not-loaded row naming its winner. See "Out-of-tree adapter sources" and "Plugin-bundled adapters" below. A pin may remain the historical name string or use `{"name":"…","digest":"<sha256>","source":"shipped"|"site"|"plugin"}`. The object form is optional and content-addressed: load computes the same per-manifest digest recorded in compiled artifacts' `resolved_adapters` (including a declared interpreter's name and bytes) and refuses a mismatch before any policy consumer or target contact, naming the manifest plus expected and actual digests. `source` is likewise optional and likewise a refusal rather than a preference: a pin that names which adapter source must answer it refuses when a different source does, so removing a site-installed adapter can never silently hand its name to a later shipped one. An unknown pin key is refused outright rather than ignored. `{"name":"core"}` without `digest` or `source` is also equivalent to the legacy string form; adding these mechanisms does not force existing repositories to migrate.

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

Before that lease, the public host path runs descriptor-bound
`code-preflight`: standard `Requires PHP` / `Requires at least` headers from
the frozen source are compared with exact target-control-plane PHP/WordPress
evidence. Target values remain ephemeral and separate from the artifact;
missing/malformed evidence or an unmet requirement is non-forceable. The check
is repeated under the lease
immediately before the first stage rename. The subsequent lease-bound sequence
uses fresh WordPress processes:
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
- **Structured rebuild actions and providers** (DUO-3338; supersedes the retired free-form `rebuilders` channel, whose command-string entries — including `wp eval` payloads — the engine now refuses at manifest load): the hooks apply deliberately skips are also what maintain plugin derived state (indexables, lookup tables, blanket caches), so a manifest declares the repair as data in a top-level `"actions"` list. Each entry is `{"kind": "native"|"provider", ..., "triggers"?: [...], "effects"?: [...]}` — `triggers` is the same exact canonical-surface grammar apply projects from authored work (`(post|term|table|option|entity):<name>`; absent = unscoped, selected for any non-empty surface set; an empty surface set — a read-only apply — fires nothing), and `effects` feeds the same bounded-reversibility inventory `rebuilders` entries fed (omitted = explicit irreversible fallback row). A `native` entry names an action from the engine's **closed vocabulary** (v1: exactly `transient.delete`, args `{"name": <bounded string>}` — WordPress-core semantics, identical for every plugin, executed by reviewed engine code with a checked-readback receipt); unknown action names, unknown arg keys, and mistyped args are refused at load, so a manifest can neither mint operations nor smuggle executable text through arguments. A `provider` entry names a capability of a provider declared in the SAME manifest's top-level `"providers"` list: `{"id", "version" (exact x.y.z), "source": "manifest"|"plugin", "plugin": <basename>, "capabilities": [...]}`. Provider ids are globally unique across pinned manifests (conflict = refusal; pin order never picks which code runs), and a provider's `plugin` must equal the manifest's own `plugin` claim when one exists, so the executable half stays inside the version window the declarative half was certified for. `source: "manifest"` resolves to `manifests/providers/<id>.php` defining `\Duo\Providers\<CamelCase(id)>` — the interpreter/regenerator trust boundary: provider code ships, versions, digest-binds, and pins with its manifest, never with the engine: its bytes join the per-adapter digest beside the interpreter's (`RepositoryCompiler::manifest_rows()` and the `CapabilityRegistry::adapter_digest()` mirror of it), so a changed provider file is a changed adapter rather than invisible drift behind a stable manifest digest. A manifest-shipped regenerator (`post_types.<type>.regen_dependency.regenerator` → `manifests/regenerators/<name>.php`) is bound into the same row on the same terms since DUO-3360 — one entry per distinct declared name, **sorted by name**: regenerator names are discovered by walking `post_types{}`, a JSON object whose key order canonical encoding normalizes away, so discovery order would let a semantically-void key reshuffle move a certified digest (unlike `providers[]`, a JSON array whose order is canonical content). Two regenerator implementations can no longer hide under one manifest revision. `source: "plugin"` is discovered from the installed plugin itself through the `duo_providers` registry filter and is trusted as part of that plugin; its file is deliberately not digest-bound (the installed plugin is its identity anchor, checked against `version_range`). Before the first target mutation, apply **negotiates** every provider its selected actions reach — contract shape (`identity()`, `capabilities()`, `invoke()`), exact identity match against the declaration, owning plugin installed+active+in range, capability advertised with a well-formed declaration (`args` schema, `reads`/`writes` surface summary, `scope: "site"|"entity"`, `idempotent` — required `true`, since apply's retry machinery re-fires the rebuild pass — and a `timeout_seconds` budget), and manifest args valid against the capability schema — and refuses with per-problem remediation on any miss, so a missing or incompatible capability fails before destructive writes, never after commit. Invocation returns a receipt whose `verified` must be exactly `true` on the strength of a value-level readback (command-success-only verification is refused); `scope: "entity"` capabilities receive an engine-assembled batch of `{kind, id}` rows for the triggering surfaces. An adapter that needs no executable semantics simply declares neither key and remains purely declarative.
- **Structured capability arguments and engine batch context** (DUO-3369, extending the two grammars above): a capability `args` entry declares `{"type": "bool"|"int"|"string"|"list<string>"|"list<object>", "required": <bool>}`, and `list<object>` additionally declares `"fields": {"<name>": {"type": "bool"|"int"|"string", "required": <bool>}}` — a closed, per-capability row vocabulary with **exactly one level of nesting**: a field may not itself be a list or an object, so there is no depth a reviewer cannot state and no free-form payload channel. Field names use the argument-name charset; unknown keys in a `fields` declaration, an empty `fields` map, and a `fields` map on a scalar-typed argument are refused at negotiation, and unknown fields, missing required fields, mistyped fields, and non-object rows are refused in VALUES. The one-level bound is enforced twice, deliberately: `Policy` refuses a nested manifest argument at LOAD (where no provider code exists yet — a manifest argument is a scalar, a list of scalars, or a list of flat objects whose own values are scalars), and negotiation refuses anything the capability's own field vocabulary does not admit. Separately, a `scope: "entity"` capability may declare the optional key `"context": [...]` over the closed channel vocabulary `deletions`, `reparents`, `retry`, `always_on_write` (duplicates refused, empty list refused, unknown names refused, and the key itself refused on `scope: "site"`, naming the declared channels). Declaring channels changes what rides under the reserved `entities` argument: the value becomes `{"entities": [...], "always_on_write"?: <bool>, "deletions"?: [...], "reparents"?: [...], "retry"?: <bool>}` carrying only the declared channels in that fixed order, so an undeclared channel is ABSENT rather than empty and "nothing happened" stays distinguishable from "never asked for". `deletions` rows are `{kind, uuid, id, post_type, parent_id, child_ids}` (see the parity paragraph below) for the tombstones this run APPLIED (`--with-deletes`), that a previous incomplete apply had already made absent, or that an earlier incomplete apply left a durable receipt for — never one this run merely PLANNED: the pre-mutation selection deliberately projects surfaces from planned tombstones (a capability has to negotiate before the mutation), but an apply without `--with-deletes` gates every delete off and its entities are all still present, so handing them over as tombstones would be indistinguishable from real ones. `id` is the target-local id the ledger still holds, `0` once the mapping is gone (the expected shape when a previous run applied the delete) or when the entity kind has no single row id at all. `reparents` rows are `{kind, uuid, id, root_id, old_parent_id, new_parent_id}`, one row per derived root, which is how a chained move's accumulated roots survive a scalar-only field grammar; the engine captures a reparent receipt for post types with a batch `regen_dependency` OR whose canonical surface a `reparents`-declaring capability in this run's selection triggers on, and the channel unions this run's captures with the durable `regen_reparent_context:<uuid>` markers an earlier incomplete apply left outstanding (the retry case has no fresh capture at all). `retry` is the apply's own incomplete-retry marker; `always_on_write` is a boolean flag stating the capability fired on an always-on basis, mirroring `regen_dependency`'s flag of the same name exactly — there the flag suppresses a per-candidate existence check on a write candidate the engine already had, and never creates candidates, so here too it never manufactures work (see bound (2)). **PARITY WITH THE REGENERATOR CHANNEL, closed by DUO-3342**: a `deletions` row now carries `{kind, uuid, id, post_type, parent_id, child_ids}` — the pre-delete inventory `capture_regen_delete_context()` takes, which the batch channel's own consumers use to keep deleted children out of the live batch. Three changes made it deliverable, and each is worth stating because each was a real bound: the capture's consumer gate now recognizes a `deletions`-declaring negotiated capability as a consumer in its own right (so the inventory is TAKEN for a provider-only manifest at all, exactly as DUO-3369's review widened the reparent capture); the durable `regen_delete_context:<uuid>` markers are unioned into the channel the way `reparents` unions its own, so an apply that committed the delete and failed before the repair re-delivers on the retry, with the durable row winning the collision because it carries the inventory a tombstone projection never had; and `child_ids` being a list was never the obstacle it read like — `Providers::FIELD_TYPES` bounds what a MANIFEST may declare as a capability argument, while a batch channel is engine-assembled and validated only as a list of rows. Every row carries all six keys, so `post_type: ""` / `parent_id: 0` / `child_ids: []` means "the engine took no inventory here" (a tombstone on a non-post surface, or one no consumer declared) and is distinguishable from an absent key. **Marker lifetime is owned by the dispatcher that declares the channel**: a durable delete/reparent marker is deleted only after the declaring capability returns `verified: true`, addressed by its own key and narrowed to that action's own triggers — so a failed or unverified invocation retains it, one adapter's receipt never retires another's evidence, and the next apply re-delivers. Ownership is decided RUN-INDEPENDENTLY, which matters because a sweep is destructive: the marker survives when a PINNED provider action triggers on its surface and this run's selection reached that surface not at all, and is swept (with a warning naming the marker and the unconsumed channel) when the selection did reach it and no negotiated capability wanted the channel, or when nothing pins a claimant. A pinned claimant whose capability this run negotiated as `scope: site` owns nothing — it can never receive a channel — while a scope this run could not observe keeps the marker rather than guessing. Consequences worth stating: markers of a pinned-but-deactivated plugin persist rather than decaying, and both keyspaces are therefore surfaced in `plan.regen_context` / `duo status` (which reports not-ok while one stands) so a held receipt is legible instead of silent. Exactly ONE capability may consume a given channel on a given `post:` surface — the clear is per-marker, not per-consumer, so a second consumer would lose the evidence its own retry depends on; negotiation refuses it, naming both claimants. The entity batch shares the batch channel's `regen_pending:<uuid>` retry vocabulary on the same terms: armed for each delivered post-kind entity before the call, cleared on a verified receipt, unioned back into the batch on a later run, and never handed an id the deletions projection says is gone. One post type may be claimed by only ONE dispatcher — a channel-declaring capability triggering on a post type that also declares an enabled batch `regen_dependency` is refused at negotiation, before any mutation, naming both claimants. **Byte-compatibility is a contract, not a courtesy**: a capability that declares no `context` and no `list<object>` argument negotiates to byte-identical declaration bytes and receives a byte-identical injected batch (the bare row list), frozen as literal bytes in `sandbox/tests/offline/adapter/regress_provider_contract.php` rather than asserted in prose.

  Four bounds stated so nobody re-derives them: (1) **execution context** — the retired channel launched each command in a fresh WP-CLI process; native actions and providers run in apply's own process, compensated by an unconditional object-cache flush immediately before the action loop (in-process runtime caches can hold pre-commit plugin models — the reproduced Polylang 3.8.6 class), with the plugin-CLI-invoking providers (elementor, yoast) still launching subprocesses as their implementation detail. (2) **`scope: "entity"` semantics** — the declaring action must carry explicit `post:`/`term:`/`table:` triggers (negotiation refuses unscoped declarations and triggers with no ledger-resolvable per-entity id, before any mutation), and a selection whose surfaces came only from deletions/retry-tombstones invokes nothing: the engine records an explicit skip receipt rather than handing the provider an empty batch it could "verify" — unless (DUO-3369) the capability declared an EVIDENCE channel (`deletions`, `reparents`, `retry`) that came back non-empty/true, since a deletion-only selection is real work for a capability that asked to be told about tombstones. `always_on_write` is not such a channel and never suppresses the skip: it mirrors `regen_dependency`'s flag, which suppresses a per-candidate existence check but never creates a candidate, so a capability declaring it still fires only when its entity batch or one of its evidence channels carries something. The skip receipt survives verbatim for a capability declaring no channels, and survives with each declared channel's own state named when none of them carried work (a row channel is empty, a flag channel false/absent, `always_on_write` a flag that is never work of its own). DUO-3342 made this the shipped path for a real repair: `manifests/woocommerce.json`'s `rebuild_product_lookups` is `scope: "entity"` and declares all four channels, replacing the batch `regen_dependency` that dispatched the same adapter code before. (3) **`verified` is the provider's own value-level claim**, structurally required and receipt-recorded; the engine's independent backstop for authored state is the post-apply canonical recapture (a provider that corrupts authored state and returns `verified: true` is caught there — regression-covered), while derived state has no second checker beyond the provider's own readback. The RECORDED half of that receipt is a **bounded public projection**, not the provider's bytes (DUO-3383): `before`/`after` reach `wp duo apply --format=json` through apply's `actions` rows, so `Providers::invoke()` projects them at the trust boundary and nothing downstream ever holds a raw provider value. A string over `RECEIPT_MAX_STRING_BYTES` (512), one bearing control bytes or invalid UTF-8, one the shared public-output sensitivity screen flags (`CommandRefusalException::containsSensitivePublicDetail()`, i.e. `Secrets::hard_match()` plus the credentialed-URI/query-secret/email/home-path shapes), a map key breaking the same rules under `RECEIPT_MAX_KEY_BYTES` (128), a container past `RECEIPT_MAX_DEPTH` (4) or `RECEIPT_MAX_ENTRIES` (128), and a whole value whose bounded projection still exceeds `RECEIPT_MAX_VALUE_BYTES` (8192) each publish as `<duo:receipt-witness/v1:<reason>:sha256:<digest>>` over the closed reason vocabulary `ambiguous|binary|control|deep|oversized|secret|wide`. The digest is canonical and taken over the RAW value at every level, including levels that do not publish, so the projection is injective at the PHP-value level: equal raw values publish equal bytes and unequal ones do not (up to JSON number encoding — `1.0` and `1` are distinct raw values that JSON renders identically when published verbatim; witnessed values keep the distinction in the digest), and `before === after` stays decidable from the published receipt without the plaintext — value-level verification is preserved, not weakened. The sensitivity and control screens are per-leaf and pattern-based over C0/DEL, the same scope as the shared refusal screen: split or encoded credentials and C1/zero-width/bidi codepoints are not detected — providers must not put credentials in receipts. A receipt carrying an object, a resource, or a non-finite number fails closed, since the engine will not summarize bytes an array walk cannot read. The human apply render never carried receipt values (it renders the `verified` warning line and its duration only) and the host reads the apply summary for artifact identity alone, so JSON is the single public receipt surface and this is its complete contract. (4) **negotiation GATES at apply and REPORTS at plan** (DUO-3339 closed the reporting half of this bound). The refusal is still apply's alone, still immediately before the first mutation, and still scoped to the actions that run's own work selects. `plan` and `status` additionally carry a `provider_problems` list — the identical problem rows, produced by the identical code (`Providers::negotiate()` IS `Providers::diagnose()`), over every provider capability the PINNED manifests declare. That set is deliberately WIDER than any one apply negotiates, so a report cannot go quiet merely because this revision touched nothing. The NARROWED half gates: `Policy::provider_readiness_blockers($selectedActions)` negotiates exactly the actions a plan's own work reaches and merges its rows into `adapter_dispositions`, which `duo status`'s exit code counts — so a provider this revision genuinely needs and cannot get does make status non-zero. `provider_problems` carries only the remainder (rows already reported as gating are dropped, so one fact is never stated twice) and is rendered and counted without flipping the exit code, since a capability this revision never reaches will not refuse this promotion. Scoped rows gate; wide rows report. Each row names the provider, its declaring manifest, its owning plugin, the problem code, expected, found, and a remediation. Plan-time diagnosis constructs the same provider objects apply does — a manifest-sourced provider's file is required and its class constructed, and plugin-sourced providers come off the duo_providers filter — so plan/status now execute provider constructors and identity()/capabilities(). No capability is invoked. A packaging fault (a manifest-sourced provider whose file or class is missing) still THROWS at apply and is REPORTED as a row at plan, under the code `provider_code_unavailable` — a reporting surface that died on one broken adapter would hide every other adapter's verdict behind it.
- `"deletions": {"post:product": {"cascades": ["postmeta", "post_revisions", "term_relationships"], "guards": [{"table": "wc_order_product_lookup", "column": "product_id", "id_kind": "post", "reason": "orders reference this product"}]}}` — exact, adapter-owned deletion capability. `cascades` names every effect the engine will perform. Each guard declares the target id keyspace; optional `where` adds scalar predicates and optional `exclude_where` subtracts owned rows already covered by a declared cascade (for example revision child posts). A guard may also declare `source_id_kind` + `source_pk`, allowing rows whose source entities are themselves safe delete candidates in the same revision while still blocking runtime or conflicted references. Structured metadata guards add `meta_key` + `ref` (currently `postmeta` and a matching scalar/list id shape), `identity_column`, and the owner source pair; an explicitly authored owner update that removes the target token is the only repair witness, while child-only tombstones remain blocked. Option-name guards add `option_name_ref: true` on `options.option_name`; they resolve the target id through the manifest's `option_name_refs` rule and require the matching canonical option tombstone in the same revision. Missing guard tables fail closed. Matching rows mark deletion **BLOCKED**; `apply --with-deletes` refuses unless `--force-delete-referenced`, and forced execution remains loud.
- **Structured-value refs** (spec v0.7, widened by DUO-3316): a meta/option rule—or an authored key of an `authored_snapshot_meta` EAV sidecar—may declare refs *inside* a JSON-or-PHP-serialized value. `"json_refs": [{"path": "$.*.*.wpseo_opengraph-image-id", "kind": "post", "cast": "string"}]` rewrites id **values** at declared paths (minimal dialect: `$` root, `.` key steps, `*` wildcard); `"key_refs": {"path": "$.*", "kind": "term"}` rewrites entity-id **keys** of the map at the declared path. Attached sidecars use the identical decoder, tokenizer, linter, compiler, apply, and verification declarations as ordinary meta; `json_encoded:true` selects compact JSON text while the default is WordPress/PHP serialization. Undeclared sidecar keys keep their historical opaque-byte behavior. Everything undeclared inside a declared structure is byte-preserved except string leaves, which get ordinary URL tokenization; unmapped ids follow the drop-with-warning rule. The tokenizer also matches **JSON-escaped URL forms** (`https:\/\/…`, Elementor's convention): both forms collapse to one plain-spelled token, and structural re-encode restores the host convention on apply (RFC 8259 makes `/` vs `\/` equivalent inside JSON strings). `wp duo lint` treats declared paths as owned and still flags id-shaped values at *undeclared* paths inside the same structure — the linter catching manifest gaps is its purpose. Paths, casts, reference keyspaces, overlapping scalar paths, and scalar-path-versus-key-map ambiguity are validated when normal or frozen policy loads, before capture/apply. `option_patterns` has a meta twin: `"meta_patterns"` (versioned keys like `_elementor_migrations_*`).

- **Taxonomy descriptions and relationship keyspaces** (DUO-3316): `taxonomies.<taxonomy>.description_refs` retains the byte-compatible shorthand `{"kind":"post"}` / `{"kind":"term"}`, normalized to one flat-map `json_refs` path (`$.*`). Independent adapters may instead use the full shared shape `{"json_refs":[...],"key_refs":{...}}` for nested PHP-serialized descriptions; no description-specific traversal grammar or plugin payload walker exists. `taxonomies.<taxonomy>.object_keyspace` and `taxonomy_patterns[].object_keyspace` declare whether the taxonomy's relationship `object_id` values belong to the `post` or `term` identity space. Runtime `object_type` still selects concrete post types but never doubles as a term sentinel. Missing term/mixed ownership, declaration/runtime contradictions, or ambiguous pattern ownership refuse rather than dropping or cross-applying relationships.
- **Dynamic taxonomy scope** (spec v0.12, task #92): a manifest may declare `"taxonomy_patterns": [{"match": "<regex>", "object_type": ["<type>", ...]}]` for taxonomies whose NAME (not a meta/option key) is dynamic per site (WooCommerce's `pa_<attribute>`, minted at runtime from a custom-table row). Unlike `option_patterns`/`meta_patterns` (which classify a key some other enumeration already produced), `taxonomy_patterns` expands the taxonomy SCOPE LIST itself: `Policy::taxonomies()` unions the exact `policy.taxonomies` list with every name in live `wp_term_taxonomy` (a `SELECT DISTINCT`) that matches a declared pattern — **scope-gated**: only pattern-matched names are ever added, never a blanket widen to whatever the database holds (the posture task #73 established for ref-typed options). Matching against the live table, not `get_taxonomies()`'s in-memory registry, is deliberate and load-bearing: a taxonomy whose registration depends on a same-request custom-table write (a typed-snapshot table's phase-1 insert) is invisible to the registry for the rest of that request even though its own term rows are already live. The declared `object_type` is a fallback consulted only when `get_taxonomy()` fails — the registry stays authoritative whenever it succeeds. This is what lets direct-SQL term-relationship writes correctly scope a just-landed dynamic taxonomy in the SAME apply request that created its defining row, with zero manual pre-provisioning.
- **Whole-entity scope gate** (DUO-3229): capture enumerates WordPress-registered public post types/taxonomies plus whole-type contracts declared by pinned manifests, then counts their live capturable rows. A type with rows must either be in `policy.post_types` / `policy.taxonomies` (including a declared taxonomy-pattern match), or carry an explicit non-authored disposition. Pinned manifest `post_types.<name>.class` is already that disposition (`shop_order` and `nf_sub` are runtime); manifest taxonomy declarations default authored unless they declare otherwise. Site-local decisions live at `policy.scope.post_type.<name>.class` or `policy.scope.taxonomy.<name>.class`. `authored` adds the name to scope; `runtime`, `derived`, or `env` records a deliberate exclusion. Missing disposition blocks capture naming the surface, entity count, and exact policy fix; it never quietly shrinks the tree. `wp duo pending` reports keys such as `scope:post_type:book`, and `wp duo classify --set='scope:post_type:book=runtime'` persists an audited exclusion.
- **Sub-keyed options** (spec v0.14, DUO-3233): a manifest option rule may declare `"sub_keys": {"<name>": {...rule...}, ...}` — NAMED sub-keys of one option's array value classified and captured/applied **independently of the whole option and of each other**, using the same rule vocabulary as a top-level option/meta rule (`class`, `ref`, `json_refs`, `key_refs`, `cast`, `allow_secret`). `sub_keys` and `class: authored` are mutually exclusive on the same rule — a whole-option-authored value has no sub-key carve-out to speak of, and mixing the two leaves "authored the whole thing" vs. "authored named pieces of it" undefined. Whole-value behavior fields (`ref`, `json_refs`, `key_refs`, `json_encoded`, `cast`, `order_preserving`, `allow_secret`, or `lint_ok`) are also invalid on an exact or dynamic sub-keyed parent because consumers intentionally use the named sub-key rules; accepting them would silently ignore a declaration. The exact parent may still carry its actual container contract (`class`, the required flag for `env`, and `autoload`); a dynamic parent carries its resolver/prefix/autoload contract. A sub-key rule cannot itself declare another `sub_keys` map; the grammar is exactly one level because capture/apply merge only named children of the live option. Capture reads the live option, keeps only the entries whose sub-rule is `authored` (everything else — including any key genuinely absent from the manifest — is left out of state entirely, not merely excluded), and applies the ordinary ref/json_refs/key_refs/secret-guard machinery per sub-key exactly as it would to a same-shaped top-level rule. Apply reads the **target's own live value** of the option (defaulting to `[]` with a warning if the option is absent there), overlays only the captured sub-keys' resolved values on top of it, and writes the merged array back — every sibling key on the target, declared or not, whole-option class `env`/`runtime`/`derived` or otherwise, survives byte-for-byte untouched. Repository authorization mirrors the split: an option carrying `sub_keys` is authorized key-by-key against the declared sub-rules (any key present in a captured value with no `authored` sub-rule is refused by name — `option_sub_key` surface, not folded into the whole-option `class` check). `wp duo lint`'s bare-id scan is likewise sub-key aware: a `json_refs`/`key_refs`-declared sub-key gets the deep structural scan; a plain sub-key gets the ordinary shallow scan one level in. Motivating case: Polylang's `polylang` option and Yoast's `wpseo` option each mix authored configuration with per-environment bookkeeping that must never travel, inside the SAME option blob — `sub_keys` lets a manifest tell those apart without capturing the whole blob (silently clobbering another environment's own bookkeeping on apply) or excluding it whole (silently losing real authored configuration).
- **Dynamic option names** (spec v0.20, DUO-3264): `"dynamic_options": {"<key>": {"prefix": "<literal>", "resolver": "<resolver>", "sub_keys": {...}, "autoload"?: "..."}}` — for an option whose NAME is computed from environment state rather than declared literally (`theme_mods_<active stylesheet>`). The declaration is `sub_keys`-shaped and carries no top-level `class` of its own: the containing blob is **always** `env` (a hardcoded engine decision, not a manifest field — a dynamic-name blob is environment-local by construction except the keys the manifest names), and each declared sub-key classifies independently with the ordinary rule vocabulary. Exactly one live name is ever read: the one the resolver currently produces. Every other live name sharing the same `prefix` — a `theme_mods_*` row for a theme that is not currently active — is environment-local residue by the same declaration, never captured and never reported unclassified. `resolver` is a **closed, engine-owned vocabulary** (v1: exactly `active_stylesheet`), because a resolver is engine code, not data: admitting one costs three coordinated engine edits — the allowlist entry (`SubKeyGrammar::DYNAMIC_OPTION_RESOLVERS`), the capture-side match arm that computes the live value, and the apply-side map entry that supplies it — and every one of them is mandatory. A manifest declaring a resolver the engine cannot resolve is refused at load; a *declared* resolver that some engine call site fails to supply a value for is a loud engine-wiring error, never a silently unclassified option.
- **Widgets** (spec v0.19, DUO-3278): `"widgets": {"<id_base>": {"settings": {"<field>": {"class": "authored", "codec"?: "blocks", "ref"?: "term", "allow_secret"?: true}}}}` — a closed per-type registry of the settings fields Duo will carry for a widget instance. The type key is WordPress's own `id_base` (the `widget_<type>` option name), matching `^[a-z0-9_-]+$`; its derived ledger kind must fit `duo_map.id_kind`. `settings` is an allowlist and is mandatory: capture refuses any live setting the map does not name, so an absent map makes every instance of that type uncapturable rather than partially captured. Every named field declares `class: authored` — a settings map has no meaning for a field it is not carrying, so exclusion is expressed by leaving the field out. `codec` and `ref` are **closed, engine-owned vocabularies** (`blocks` and `term` respectively) and are mutually exclusive on one field: a value is either a structured document the engine decodes or a single entity reference it resolves. An undeclared widget type is reported, never guessed; its instances stay unmanaged.
- **Adapter compatibility contract** (spec v0.15, DUO-3222/DUO-3247): a manifest MUST declare `"spec_version"` (int, the wire-format grammar it was authored against) equal to the engine's own `DUO_SPEC_VERSION` exactly — absent and declared-wrong are the same failure, both refused at load time. (This was not always true: at v1/DUO-3222, while `DUO_SPEC_VERSION` had exactly one historical value, an absent declaration was lenient — it can't be "wrong" when nothing else it could have meant existed yet — with an explicit, written pre-commitment to flip the moment a second historical value existed to be silently wrong about; DUO-3210 performed that bump, DUO-3247 actioned the pre-committed flip.) A manifest may additionally declare `"plugin"`/`"version_range"` (unchanged from spec v0.9's original mechanic) and `"theme"`/`"theme_version_range"`, the exact same `{min,max}` (min inclusive, max exclusive) shape mirrored for themes: one theme per manifest, matching one plugin per manifest. `Policy::load()` rejects, at load time, before any target contact: a `plugin`/`theme` declared without a well-formed matching range (no latest/wildcard/unbounded support is certifiable); a malformed range (missing min/max, non-string, min not strictly less than max); and two pinned manifests naming the same plugin or theme with different ranges (conflicting ownership — manifest precedence may never depend on pin order, so this is refused outright, with no composition/override grammar in v1). The compiled artifact (`RepositoryCompiler`) records a `resolved_adapters` array — one row per pinned manifest, carrying its name, a per-manifest content digest (the same bytes `manifest_hash()` already folds into its one combined hash, now also exposed individually), and its declared identity/range facts. This is compilation staying honest about what it validated — declaration validity and non-ambiguity, reproducible and artifact-hashed — not a live-environment match, which stays `Deploy::code_mismatch()`'s job: it checks a declared `theme_version_range` against the environment's actual installed theme version exactly as it already does for plugins, producing the identical `outside_version_range`/`missing_in_code` finding shape with `kind: "theme"`.
- **External manifest disposition and capability contracts** (DUO-3224/DUO-3227): [manifests/dispositions.json](../manifests/dispositions.json) is separate from every manifest so declaration cannot imply certification. It has exact one-for-one coverage of the shipped manifest JSON files and classifies each `certified`, `experimental`, or `excluded`, naming supported versions, entity/field sections, operations, lifecycle phases, deletion semantics, explicit unsupported behavior, and every manifest table whose default keyspace is authored. Intent-only table declarations must appear as unsupported rather than implemented. **Retired:** the `duo-subject-certification-bundle/v1` evidence records every certified manifest and profile once owned, and the generated `manifests/capabilities/registry.json` projection of `manifests/capabilities/evidence.json`, are gone with no successor document — no content-addressed evidence stands behind a claim any more. The reviewed disposition is the whole claim source, and `AdapterRegistry::report()` computes the product-facing projection (`duo-capability-report/v1`) from it plus per-adapter provenance on each call. Disposition bytes are frozen in the policy snapshot (`duo-policy-snapshot/v6`, which rejects v5's frozen capability record rather than ignoring it — a snapshot carrying a record this agent no longer checks must not verify as if it had been checked); compiled adapters carry the same claim used by host promotion. `wp duo capabilities --repo=<p> [--operation=<op>] [--surface=<surface>] [--format=json]` evaluates the selected target; `--all` reports the shipped library; `--revision` is refused by name rather than accepted and ignored, because it selected an evidence-bound platform revision nothing binds any more. Missing/experimental/excluded claims, an unregistered operation or surface, target version mismatch, or multisite keeps readiness non-green. Plugin execution without source modification is a separate registry field from authored-state branchability. Custom test/operator manifest directories without a disposition registry retain legacy policy behavior but make no certified product claim. A new WordPress extension adds its manifest, disposition, convention-discovered conformance/custom test, and artifact-lock entries; nothing on the path contains an extension-name allowlist.
- **Out-of-tree adapter sources and certification** (DUO-3314): a site repository may install adapters of its own in `adapters/<name>.json`. This is a SECOND adapter source that **overlays** the shipped library — additional pinnable adapters, never replacements — so the shipped directory's one-for-one disposition coverage above is unchanged, and a repository with no `adapters/` directory behaves exactly as before. File names, pins, certificates, authority key ids, ratification maps, and frozen records share one canonical lowercase-ASCII slug identity (letter/digit endpoints; internal letters, digits, dots, underscores, and hyphens; **at least one lowercase letter**). Numeric-only identities are refused before lookup because PHP would coerce a numeric JSON object key into an integer map key. Path-like, hidden, uppercase, and Unicode variants also refuse rather than being normalized into a different identity. Discovery scans every source and refuses before any pin resolves: a site adapter whose file name collides with a shipped manifest (shadowing), whose declared `name` disagrees with its own file name (ambiguous identity), or whose declared name a shipped manifest already declares. The shipped library is held to the same identity rule (DUO-3371): a pinned shipped manifest whose declared `name` is not its file basename is refused at load, before any validator, with the same ambiguous-identity sentence. `adapters/dispositions.json`, `adapters/capabilities`, `adapters/{interpreters,providers,regenerators}`, symlinks, unreserved nested `*.json`, and extension near-misses such as `.JSON` are refused rather than ignored — an adapter cannot ratify itself, and the engine never loads code from this source. **A data-only manifest acquires no executable privileges**: an out-of-tree manifest declaring `interpreter`, a `regen_dependency.regenerator`, or a `providers[].source: "manifest"` is refused with remediation, because all three resolve inside the agent's own manifest directory. `providers[].source: "plugin"` remains available — its `plugin` must use the same safe WordPress basename grammar as a top-level plugin claim even when the adapter has no top-level plugin/version-range claim; that code's runtime trust anchor is the installed, active, version-bounded plugin plus the provider negotiation/receipt contract, and its live tree is deliberately not digest-bound by the manifest engine.

  The one reserved nested source is `adapters/certifications/<name>.json`, an exact one-to-one companion for the top-level adapter. It is a canonical `duo-adapter-certification/v1` Ed25519 envelope, never a public key or self-asserted trust root. Trusted keys live only in the agent-owned `manifests/capabilities/adapter-authorities.json`, format `duo-adapter-authorities/v1`: `keys` is keyed by the canonical key id, and each exact record declares `algorithm:"ed25519"`, `scope:"site_adapter_certification"`, `status:"trusted"|"revoked"`, a canonical base64 public key, non-empty `adapter_names`, and permitted trust tiers. The certificate binds the selected record's canonical digest and public-key fingerprint, so unrelated key additions do not move an adapter while selected-key rotation/revocation invalidates it. The signed, domain-separated payload binds the selected authority record, source/name/path, canonical and raw manifest hashes, engine-derived trust tier (`declarative_manifest`, `native_action`, or `plugin_provider`; a site adapter reaching `compatibility_shim` is refused), exact agent/spec/platform boundary, one valid externally reviewed certified disposition, and a passing `duo-site-adapter-certification-bundle/v1` scoped exactly to `site_adapter.<name>` whose named tests and raw subject input match. The verifier re-derives the capability claim; neither the site manifest nor the certificate may mint or widen it. A missing certificate retains the existing usable-but-conspicuous `uncertified` state and `adapter_source_uncertified` blocker. A present malformed, untrusted, revoked, stale, or mismatched certificate is a load-time refusal, never a silent downgrade. A valid signature is reported as `signed_unpinned` until `site.duo.json` explicitly pins both `source:"site"` and the final certificate-derived adapter digest emitted by `wp duo manifest-pin --repo=...`; only then is it promotion-ready third-party evidence.

  `duo-adapter-sources/v2` puts the verified external disposition/provenance in the same digest slot the compiler already hashes, including source/hash/tier, authority-record, envelope/payload, and evidence proof hashes; the signed payload never contains that final digest, avoiding circular identity. Every shipped digest and `duo capabilities --all` row therefore remains byte-for-byte unchanged. Capability reporting selects evidence per row: shipped entries use their own subject records, signed site entries use only their signed site-adapter evidence/platform, and unsigned site entries never inherit either; a mixed report has no shared evidence authority. `duo-policy-snapshot/v6` freezes the envelope but no public key; reconstruction reloads the current agent-owned authority, rechecks the signature, source manifest, tier, disposition, bundle, evidence, and derived record, and positively matches every name claimed as shipped to the current trusted agent library, so authority rotation/revocation invalidates affected frozen policies and deleting a v2 source record cannot launder site bytes. The v5 form, which froze the retired generated capability registry beside the envelope, is refused outright; so is the prior v4/v1 uncertified snapshot form, whose read path is retired rather than retained — every v4 document any engine version exported also carried the `capabilities` key, so v6's closed key set already refused all of them, leaving the path reachable only by a shape nothing ever wrote and verifying nothing, while the `duo-adapter-sources/v1` record it read granted shipped authority to any name absent from `out_of_tree` with no proof at all. A v4 envelope is now refused by format, ahead of the key check, so the refusal names the retired generation instead of reporting a malformed shape; the remedy is to re-export the policy. Every live export emits the fail-closed v2 form.

- **Plugin-bundled adapters** (DUO-3339/B2): a plugin may bundle exactly one adapter, as exactly one `duo-adapter.json` at the root of its own directory. This is the THIRD adapter source. Only ACTIVE plugins are scanned — activation is the operator consent that installs a bundled adapter, the same gate the `plugin_not_active` readiness blocker and provider negotiation already use for that plugin's code. A single-file plugin has no directory of its own and cannot bundle; a `duo-adapter.json` at the plugins directory root belongs to no plugin and is refused.

  Containment is proved before anything is read, on both the active and the inactive path: "not installed" describes what the engine will LOAD and is never a licence to open a file the plugin does not own. Every third-party value a refusal quotes — a declared `name`, a directory entry, a plugin basename, and the paths built from them — is rendered with control characters replaced and a length cap, because those rows are printed to a terminal and embedded in JSON, and an escape sequence inside one would be a refusal message forging the report it appears in. The renderers apply the same rule to the report's DATA fields (`name`, `path`, `paths[]`) at the point of print rather than at the point of record: the document keeps them exactly as the file is spelled, so a rendered line and a `--format=json` record never disagree about which file an operator has to go fix.

  Identity INVERTS the site rule. The file name is a constant, so it carries no identity; the declared `name` is the only identity, held to the same canonical slug grammar. The anchor is exact: `plugin` must equal one of the ACTIVE plugin basenames in that directory, not merely share its directory name — every version, activation, and compatibility verdict about the adapter is answered against the file it names, so a manifest naming a sibling plugin file nobody activated would get true-looking answers about the wrong code. In its place, a bundled manifest MUST declare `plugin`, equal to the basename of the plugin that bundles it (`plugin_anchor_mismatch` otherwise) — the exact analogue of the provider anchor. That claim does three things: it anchors the manifest to the code it ships with, it lets `providers[].plugin` agreement close the provider story for this source with no new rule, and it makes the frozen provenance path `plugins/<plugin-dir>/duo-adapter.json` re-derivable from the manifest alone, which is why this source needed no new wire key and no snapshot format bump. Because the anchor makes `plugin` mandatory, the pre-existing compatibility contract above applies transitively: a bundled adapter declaring no `version_range` is refused as unbounded support, so every bundled adapter is version-bounded and `plugin_version_mismatch` stays a reachable readiness verdict for this source.

  **Precedence is `shipped > site > plugin`, and a plugin-side name collision is reported, not refused.** A bundled adapter whose name a shipped or site definition already answers to is dropped and reported on every run as `not_installed`/`shadowed` naming its winner; nothing is deactivated and no other command is affected, while a pin that explicitly writes `source: "plugin"` for that name still refuses loudly. This differs from the site source deliberately: `adapters/` is operator-authored and a collision there is the operator's own broken installation, but WP_PLUGIN_DIR is not, and refusing the whole scan would mean that the day a popular plugin starts bundling a colliding name, every site running it loses every command through an automatic update its operator never performed. Two ACTIVE plugins declaring one name have no precedence available between them, so both are dropped and the pair draws one `source_collision` refusal.

  **Refusals in this source are per-adapter, never whole-scan**, for the same reason. Every condition — a malformed bundle, a non-slug or reserved declared name, an anchor mismatch, a `duo-adapter.json` that is a symlink or a directory rather than a real file inside the plugin (a symlinked plugin DIRECTORY is accepted; dev checkouts use them routinely), a plugin directory this process cannot enumerate at all (`source_unreadable` — mode 0711 is the ordinary way to reach it, and without the directory listing nothing can prove the plugin bundles exactly one `duo-adapter.json`), a bundle that cannot be read at all, a near-miss inside this engine's reserved `duo-adapter*` namespace, a certificate-shaped companion, or an out-of-tree manifest reaching for executable privilege — records a refusal ROW with `scope: "adapter"`, drops that one adapter, and lets the walk continue. It becomes fatal exactly when someone pinned that name: the pin fails with the refusal's own message and remediation instead of a generic not-found. Everything else about the data-only privilege boundary is identical to the site source, with only the noun in the message changed.

  **A plugin-bundled adapter cannot be certified in place.** Certification derives its path from the repository, hashes `adapters/<name>.json`, and binds `adapter.source: "site"` with `adapter.path: "adapters/<name>.json"` INSIDE the signed statement, so no certificate can name a bundled adapter at all; a frozen bundled record paired with a certificate is refused on the read side too. A bundled adapter is therefore `uncertified` by construction, with the `adapter_source_uncertified` blocker and the identical posture an unsigned site adapter has: plan and apply available, readiness and host promotion blocked. Its remediation is the promotion path, not a signature: an independently distributed adapter package is not a third source — it installs into the site source as `adapters/<name>.json` plus `adapters/certifications/<name>.json`, and its identity and version are the canonical file digest and the signed certificate's version-bound envelope (`supported_versions{plugin,range}` inside the signed ratification, `bundle.artifacts[].version` for what was exercised, `platform` for the engine window). Installing that package is safe with the bundling plugin still active: the site copy wins by precedence and the bundled copy reports as not installed.

- **The installed-adapter catalog** (DUO-3339): `duo adapter list|inspect|doctor [--repo=<site-repo>] [--format=json]` reports what is installed, offline, in the `duo-adapter-catalog/v2` envelope. Each adapter row carries `name`, `source`, `path`, the canonical manifest `sha256`, `trust_tier` and `tier_basis` (the exact declaration that produced the tier — a shim cannot be asserted without a coordinate), `certification`, `disposition_status`, `required_providers` (declared ids with the capabilities each must advertise), `executable_surfaces` (interpreter, regenerators, manifest-sourced providers), and an ISOLATED grammar verdict from the real loader. `certification` reports the same states the reviewed path reports and mints none of its own: `registry` for a shipped adapter, and for a site adapter `uncertified`, `signed_unpinned` (a valid signature the repository pin has not yet elevated), `third_party_signed` (a key in the agent-owned authorities file, exact pin), or `site_signed` (a key in the site's own `adapters/authorities.json`, exact pin — the customer organization vouching for its own adapter, explicitly not a Duo endorsement); a plugin-bundled adapter is always `uncertified`, by construction. Every row additionally carries `trust_root` (`platform` for a shipped row, `site` or `platform` for a signed out-of-tree one, `null` when nothing signed) and `principal` (the authority key id, or `null`). A shipped adapter displaced by an explicit `{name, source:"site", digest}` pin is not an adapter row at all: it moves to `not_installed` with reason code `shadowed_by_site` and its winner named. The v2 envelope additionally carries `sources` — which of the three adapter sources this process could reach, and where — and `not_installed`, one row per adapter that is on this machine and lost to a higher-precedence definition, with the winner named. `not_installed` rows print on every run and deliberately do NOT flip the exit code: a correctly resolved shadow is neither a blocker nor a break, and a permanently red doctor on every site running a colliding plugin would destroy the exit code's meaning. Every refusal row carries `source` (which adapter source the condition is about) and `scope` (`source` for a whole-directory refusal, `adapter` for a per-adapter one), which is what keeps one plugin author's typo from un-judging every operator-authored adapter. Because the host command is WordPress-free it cannot reach the plugin source at all; `sources` says so on every run, and `wp duo adapter-survey [--repo=<path>] [--format=json]` is the same survey run ON the target, emitting the same `duo-adapter-catalog/v2` document with `command: "survey"`. The catalog is built on `AdapterSources::survey()`, the REPORTING mode of the same scan `discover()` throws from: every condition above (shadowing, ambiguous identity, declared-name and case-fold collisions, a non-canonical identity slug, symlinks, nested `*.json`, extension near-misses, reserved names, an unreadable manifest, an `adapters/` directory that is itself a link, a malformed certification source, an out-of-tree manifest reaching for executable privilege, and a certificate that does not verify) becomes a refusal ROW carrying a stable code, the paths it is about, the engine's own message byte for byte except in two stated cases, both at the site-manifest read (see the delta comment at `AdapterSources::scan()`): an unreadable or unparseable manifest is wrapped so the row names the file as a site adapter, and one that is valid JSON but not an object now refuses as `malformed_manifest` naming its actual top-level type instead of as an `ambiguous_identity` about a declared name it never had, and a remediation; a repository whose own `site.duo.json` cannot be read draws a single `site_policy_unreadable` row instead of a per-manifest echo. Reporting rather than throwing is the point: those conditions make every other command refuse outright, so an operator whose repository is in one has nowhere else to look. `inspect` additionally merges the reviewed disposition entry, the capability claim that disposition projects, and the verification facts that already exist — `plugin_execution.status`, the bundle schema the disposition cites, and the test ids named in that citation — and mints no verification vocabulary of its own. `evidence.status` went with the generated evidence record that decided it: a citation is what a reviewer wrote down, not a verdict this process can resolve, and printing `current` beside it would be the agent vouching for itself. `doctor` adds the pinned set's readiness blockers. Exit 0 healthy, 1 anything surfaced, 2 usage/IO; every run emits a `deferred` list naming the live-target checks it did not perform, the manifest-shipped PHP it names but deliberately never loads, and the cross-manifest guards that belong to a pin set.
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

Shortcode rules may additionally declare a positional locator: `position` is a
zero-based positional argument, `kind` is the canonical entity kind, and
`lookup` contains the authoritative alternate domain (`post_meta` plus
`post_type`). Capture resolves the positional value through that exact
post-meta/post-type pair and emits the ordinary canonical entity token; apply
resolves the token back to the target row's alternate value. Positional rules
are closed to static kinds and cannot be combined with named `path`,
`kind_from`, `tokenize`, or `lint_ok`. A missing, duplicate, non-decimal, or
wrong-domain alternate value is a hard refusal; raw positional integers never
remain in canonical state.
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
| manifest disposition `status` (`certified`/`experimental`/`excluded`) and profile statuses | engine, in a file no manifest can reach (`manifests/dispositions.json`) | the external review process, never a manifest field — declaration must not be able to imply certification | load-time for the disposition registry; `wp duo capabilities` for the claim |
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

  A positional rule uses `{ "kind": "post", "position": <non-negative integer>, "lookup": { "post_meta": "…", "post_type": "…" } }` and is a closed declaration: no named path, cast, type, lint exemption, or extra key is permitted, and one tag cannot mix positional and named rules. The engine locates spans with WordPress's `get_shortcode_atts_regex()` including its required whitespace/end delimiter, then applies the declared callback's argument contract. Because legacy callbacks such as CF7's `[contact-form …]` consume the first value returned by `shortcode_parse_atts()`, any named attribute on a positional tag is refused rather than allowing a later bare span to change callback semantics. Lookup values are canonical positive decimal integers in the intersection of WordPress's bare `DECIMAL` meta-query domain and PHP's integer domain (at most ten digits on 64-bit builds); source and target uniqueness checks use the same numeric cast. A positional shortcode is therefore either fully canonicalized to a post token or fails closed; raw or ambiguous alternates never remain in canonical state.

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

## Scope resolution (DUO-3344)

`wp duo scope --repo=<p> --roots=<selectors>` resolves a bounded set of canonical entities from explicit roots and reports what it would carry. It is read-only: it compiles a revision and walks it, and it captures, promotes, and deletes nothing. Without `--contract`, its existing `duo-scope/v1` preview shape is unchanged. The immutable contract form is consumed by bounded capture/refresh/rebase, by the separately authorized scoped plan/apply/verification workflow, and by the narrow SSH-only checkpoint profile defined below. Ordinary promotion, user-invoked rollback, and code lifecycle remain whole-revision operations; the checkpoint profile does not make the contract itself mutation authority.

A root selector is one of `post:<uuid>`, `term:<uuid>`, `table:<table>:<uuid>`, `menu:<slug>`, `sidebar:<id>`, `user-meta:<login>`, `options`, `option:<name>`, `path:<state-relative-path>`, or `all`. Multiple roots are comma-separated. `all` is the whole revision, so a full-site operation is this same model with a wider root set rather than a second code path. A selector that resolves to nothing, resolves to an entity of a different type than it names, or cannot be parsed is **refused** — a silently empty scope is indistinguishable from a correctly small one. A menu item or widget uuid is refused by naming its owning menu/sidebar, because an item is not a file and cannot be scoped away from its owner.

`option:<name>` names one authored `wp_options` row rather than the whole `options/core` surface `options` names, refusing a name that is not currently authored the same way a uuid selector refuses one that does not resolve. Its closure is attributed to that option's own declared references only — a token in a *different* option's value never joins another option's scope — computed against `ReferenceGraph`'s existing per-entity edge enumeration rather than a second walker. The immutable contract carries this virtual record identity into the record-aware capture, refresh/rebase, scoped plan/apply, and SSH checkpoint-promotion protocols. Each consumer overlays or mutates only the named option record while preserving excluded siblings in the physical `options/core` carrier. The SSH profile's encrypted whole-database checkpoint is safe for rollback only inside its all-database-writer exclusion window; it does not widen the option's forward write set.

Closure follows declared edges in exactly one direction: **outbound**. If the scope holds X and X references Y, then Y is a dependency (X is incoherent without it) and joins the scope, recording the referring entity and the exact locator that pulled it in. Edges are the ones the compiler already validates — every `{{kind:uuid}}` token in any entity's data (including option *names*, which is where `option_name_refs` puts them) or post body, `terms` assignments, and term `parent` and `relationships` — plus declared parent → child post-type descent via `post_types.<t>.children` (DUO-3315). Menu-item hierarchy is enumerated too, but moves no scope and is deliberately dropped during resolution: a menu owns its items, so an item's parent is an intra-entry edge whose target is already whatever the menu carries. The engine learns every one of these from pinned declarations; an adapter that declares a new reference shape gets closure for free, and an engine branch naming a specific plugin is a defect.

The reverse direction is **not** closure and is not treated as one. An option pointing at a page does not join that page's scope by pointing. Such inbound referrers are reported separately and left out, because they are what a later scoped delete would strand.

Two properties follow from this being a read-only pass over an already-compiled revision. Compilation has already refused every dangling reference as a blocking diagnostic, so a closure over a tree that compiled cannot discover a missing dependency; the refusal scope resolution still owes is an unresolvable root. And because the walk consults only the typed IR and pinned policy, a scope is a property of a repository revision rather than of any environment — the same roots resolve identically everywhere, which is what will later let capture, plan, promote, verification, and rollback quote one scope instead of each recomputing it against a moving target.

The reference enumeration is shared: `Duo\ReferenceGraph` is the single walker, consumed both by the compiler's reference validation and by `Duo\ScopeClosure`. Two independent walkers would not fail loudly when only one learned a new declared shape — the validator would quietly stop guarding an edge, or a resolved scope would quietly ship without one of its dependencies.

### Immutable scope evidence (`duo-scope-contract/v1`)

`duo scope <env> --roots=<selectors> --contract` (the host-side public form)
emits a canonical `duo-scope-contract/v1` object. It is **read-only evidence,
never mutation authority**. The agent accepts contract mode only under the
isolated Duo control-plane bootstrap; the host supplies that bootstrap with
`--exec`, `--skip-plugins`, and `--skip-themes`, so ordinary plugins, themes,
and normal MU plugins cannot run before compilation. The legacy preview can
remain directly reachable for compatibility, but direct `wp duo scope
--contract` outside that isolation refuses.

The contract normalizes, sorts, and deduplicates request selectors. `all`
canonicalizes to exactly `['all']`, subsumes every narrower selector, resolves
the whole live tree through `ScopeClosure`, and includes **every** compiled
tombstone. Other live selectors use the normal scope-root grammar above. A
bounded tombstone is only `tombstone:<lowercase-uuid>` and must name a compiled
v1 tombstone exactly. It never enters live-root resolution; `delete:<uuid>` is
refused because this object does not grant deletion authority. v1 tombstones
do not carry prior graph data, so a bounded tombstone selection never infers
closure from `source_path`, a live UUID, or prior edges.

Its `source` binds `artifact_hash` (the outer code/state/effect/adapters
binding), `state_revision_hash` (the canonical state revision identity), and
`manifest_hash`. A code revision, when present, is a separate diagnostic only
and never becomes a selector or a state-revision input. The object includes
normalized request selectors plus explicit resolved live-root identities and
tombstone UUIDs; live roots and closure rows with semantic and source-byte
hashes plus provenance; inbound referrers and all excluded live rows with
their hashes/reasons; exact tombstone bytes/hashes and static policy deletion
capability, cascades, guards, and declarers; and uploads/media filtered to the
resolved closure.

`eligible_surfaces` is the shared, pure canonical-surface projection Apply
uses for trigger literals. `potential_actions`, `potential_providers`, and
`potential_effects` are deliberately named as possibilities: they are not
selected actions, provider negotiation results, authorization, execution, or
receipts. Effects are filtered to relevant core rebuild work, exact eligible
action declarations, and matching post-type regenerator declarations. Code
and lifecycle effects are excluded. Target guard witnesses, target IDs,
provider instances, plan/work buckets, and any effect execution are excluded.
Future scoped mutation must restrict guard repair/deletion inputs to these
contract rows and refuse reliance on out-of-scope rows or target evidence; it
must separately negotiate providers and recheck guards under its target
transaction.

`scope_hash` is exactly `sha256(Canon::encode(contract_without_scope_hash))`.
Consumers call `ScopeContract::from_array()` for strict format/schema/hash
validation and `ScopeContract::assert_associated($contract, $compiled,
$policy)` before use. The latter re-resolves the complete canonical contract
from the normalized selectors and exact compiled/policy pair, so changing a
row and merely recomputing `scope_hash` does not retain an artifact
association.

### Scoped capture and refresh overlays (v1)

`duo capture <env> --scope-contract=<local-path>` and `duo refresh`/`duo
rebase ... --scope-contract=<local-path>` are record-aware mutation/planning
consumers in v1. Scoped plan/apply and the SSH checkpoint-promotion profile
consume the same evidence through their separate target-bound protocols. The
host parses the local file with the engine's real
`ScopeContract::from_array()` implementation. It never sends that machine-local
path to a target: it sends only canonical selectors and the claimed
`scope_hash`. The target recompiles its own checked-out repository, resolves
the complete contract again, and requires the exact hash and source association
before observing or publishing target state. A stale, edited, or merely
rehashable contract therefore refuses before publication.

Scoped capture is an atomic full-tree publication assembled as an overlay. It
starts from the exact previously compiled repository tree; selected live rows
are replaced from one consistent target snapshot, while every excluded live
row, excluded tombstone, and unrelated media byte remains exact. It does not
mint identities, run the global stale-map pruners, or grant authority over an
unselected row. Before projection, the complete live target observation is
strict-compiled and closure-checked, so a target-only declared child, outbound
dependency, or inbound deletion referrer cannot disappear behind preserved
source bytes. `all` selects every identity in the associated source artifact;
it remains the same strict state-only transaction and refuses a target identity
minted after association rather than falling back to global capture. Media
authority comes only from a selected attachment record,
never from a filename appearing in arbitrary content. New selected blobs are
validated in an immutable compiler view; after writing them, the source is
re-associated while ignoring only those exact verified additions immediately
before publication. A selected live UUID may become a new tombstone only when the
normal deletion capability permits it, the UUID is in the resolved live
closure, and no excluded inbound referrer would be stranded. A selected
tombstone cannot be resurrected from this evidence. The complete candidate is
strict-compiled and its closure is re-resolved before the existing atomic
`duo-capture-publication/v1` swap; recovery continues to use the sealed full
candidate/previous hashes, so a crash cannot turn a bounded retry into a new
scope decision. Scoped capture is a repository publication and therefore
refuses `--out`; legacy unscoped output-only capture retains its existing
seed/determinism behavior and does not update or require repo media.

Scoped refresh associates the contract with the clean production target
repository at the exact `--production-ref`. Its exporter returns selected
state/media only and marks every other production row
`omitted_not_absent`; omission is never interpreted as deletion. The B/P/W
planner records the contract and scope hash, reports conflicts only inside the
scope, and materializes a complete candidate by starting from exact W bytes
and replacing selected whole records only. An `option:<name>` root is a virtual
record: materialization recombines its selected record with the exact W
carrier, so excluded option siblings remain byte-for-byte preserved rather
than becoming mutation authority. All unrelated W state, tombstones, and
media are verified byte-for-byte, and candidate closure may not escape the
contract. The second production export must reproduce the same scoped
snapshot before the new ref is created. Because the contract excludes code and
lifecycle effects, scoped rebase preserves W's code and ancestry and changes
state/media only.

Code dependency movement and user-invoked post-commit scoped rollback require
later contracts. Record-scoped capture/refresh and scoped plan/apply do not
infer mutation authority from the state-only overlay; they use their separate
target-bound protocols below, as does SSH scoped promotion and its
checkpoint-only pre-fresh-verification rollback.

### Scoped plan, apply, and verification

`duo plan <env> --scope-contract=<local-path>` and `duo apply <env>
--scope-contract=<local-path>` use the same host validation and compact
`duo-scope-request/v1` handoff as scoped capture. The target recompiles and
re-associates the complete contract. Planning is strict observation: it does
not repair or create ledger schema, invoke native/provider effects, or write
the target. It may load a selected provider and inspect its identity and
capabilities so the report uses the exact scoped reconciliation gate apply
will enforce. Its `duo-scoped-plan/v1` result projects ordinary three-way buckets to
selected identities while retaining global code and recovery preconditions,
and reports hash-only selected/protected target roots, canonical surfaces,
selected declarations, and provider problems.

The scope contract remains `mutation_authority=false`. After acquiring one
target promotion lease with a random `session_id`, apply performs a second
plan/guard/target observation and seals a separate
`duo-scoped-mutation-authority/v1`. The authority binds the exact scope and
source artifact/revision/manifest, lease owner/artifact/generation, selected
and protected authored and ledger-map roots, locked plan and guard witnesses,
hash-safe original work/deletion/action/effect identities, negotiated scoped
capability digests, and a separate code compatibility witness. Because menu
items and widgets are nested ledger identities while their menu/sidebar file
is the scope unit, authority also seals a sorted hash-only membership set for
the selected ledger-map partition. The set includes direct selected UUIDs,
source-new nested UUIDs, and target-old nested UUIDs observed under selected
owners; every recovery, verifier, and terminal read reuses that exact set
rather than reclassifying identities after a create, move, or removal. Raw
UUIDs never enter the durable authority or compact scope wire. Before sealing
authority, every existing selected map row must be backed by the same strict
target observation, including its direct entity or nested owner kind; a stale
selected map refuses through the identity-recovery route while unselected stale
rows remain untouched. Recovery, verification, and terminal replay repeat that
check, so a dead or reused local id can never become selected write authority.
A stale or tampered contract, changed source, replaced lease, changed
selected/protected target, changed guard, missing capability, triggerless
global action, legacy unreconciled regenerator, or attachment metadata rebuild
refuses before the first authored write.

An externally checkpointed scoped promotion uses the closed
`duo-scoped-mutation-authority/v2` extension. In addition to the v1 evidence,
it seals the signed receipt payload hash and generation plus hash-only receipt,
target, and signing-key identities and the exact `allow_deletes` capability.
The raw external identifiers never enter the ledger. A legacy/v1 authority is
valid only for ordinary scoped apply and is never a wildcard for checkpointed
promotion replay.

Execution is journaled in one append-only `duo-scoped-apply-session/v1` with
the phases `planned`, `authoring`, `authored_committed`, `effects_pending`,
`verifying`, `complete`, and `recovery_required`. Every mutation intent binds
the authority, lease generation, ordinal, action, operation, input, effect,
and before-witness hashes; every receipt repeats that binding and adds an
after-witness hash. At the authored COMMIT boundary, retry compares a fresh
target observation: the exact pre-root may execute once, exact desired state
advances without replay, and any mixed or protected change becomes
`recovery_required`.

Scoped native/provider effects additionally use
`duo-scoped-effect-operation/v1`. A provider explicitly advertises scoped
reconciliation; invocation durably records the operation before plugin code,
and recovery first reconciles the same `operation_id` and `input_hash`.
Only a missing operation is `not_started`; a verified receipt is read back,
while intent-only, mismatched, malformed, or changed postcondition evidence is
`recovery_required` and is never reinvoked. Provider `before`/`after` values
are bounded, secret-screened, and retained only as hashes. Empty entity
batches receive an explicit bounded-skip receipt. Ordinary unscoped provider
negotiation/invocation bytes remain unchanged. Recovery reconciles every
already-journaled native/provider effect again before trusting its outer
receipt, and re-reads the engine-owned schedule/count witness before trusting
that receipt too. The environment-local `deletions` and `reparents` provider
context channels remain outside scoped v1: their target-local IDs cannot be
reconstructed from the immutable authority after a crash, so selecting either
channel refuses before the scoped session or any target mutation instead of
writing or consuming the ordinary `regen_*` recovery keyspaces.

The verifier launches a fresh frozen artifact/policy process, opens the exact
active `verifying` session from the target ledger, compares the caller's
authority/effect roots to that live session, proves selected live rows and
tombstones, and requires exact equality of every protected out-of-scope
authored and ledger-map root. Its terminal transaction advances only selected ledger base rows and
the scoped terminal receipt; that receipt binds the post-finalization selected
identity-map root while the authority continues to bind the protected map.
It never clears global recovery debt and never writes `applied_revision`.
Full plan/apply refuse while a scoped session is nonterminal; a terminal retry
returns the same receipt bytes only after desired authored state and those
post/protected map roots are re-proved. Rotating a complete active slot writes
an immutable terminal archive plus a hash-only index derived from the stable
`scope_hash` and source `artifact_hash`; a later public retry can therefore
locate and re-prove the archived receipt without guessing the old random lease
session, while a missing, mismatched, or changed archive fails closed.
Externally checkpointed-promotion archives instead use a v2 index that additionally binds
the sealed external-generation digest. A new signed generation for the same
scope/artifact cannot discover or replay an older terminal. Exact committed
response-loss recovery may recreate the short target `ps-*` handoff, but only
the same signed external tuple and delete capability can reopen the archived
terminal. Ordinary promotion, code materialization, lifecycle, user-invoked
rollback, attachment derivative generation, legacy `regen_dependency`, and
triggerless actions remain explicitly outside this version. The externally
checkpointed SSH profile below is the scoped-promotion exception; it consumes
this same apply protocol without widening its selected record set.

### SSH scoped promotion checkpoint profile (v1)

`duo promote <env> --scope-contract=<local-path>` selects a separate SSH-only
protocol. It never enters ordinary promotion's code staging, lifecycle,
upload, release, or effect-provider paths. The host validates the local
contract, sends only its compact `duo-scope-request/v1`, compiles the frozen
artifact, and obtains a strict `duo-scoped-plan/v1` before claiming recovery
authority. Local/Docker drivers and direct agent contracts refuse this
profile; the receipt-bearing agent apply accepts only the compact host wire.

The first profile admits only DB-contained selected work: authored options,
declared snapshot tables, sidebars, and user meta, plus option/table
tombstones. Posts, terms, menus, attachments, code/lifecycle changes,
provider/native actions, and every force flag refuse before authored mutation.
Taxonomy recount callbacks are skipped when that bounded work cannot change
their inputs. WordPress object-cache flush remains an allowed derived eviction,
not persistent desired state; recovery restores the database and lets cache
misses repopulate it.

Before target apply, the SSH controller claims one signed
`duo-scoped-promotion-receipt/v1`. Its exclusion provider must speak v2 and
hold public traffic, background jobs, **every database writer**, filesystem
writers, and package updates. Under that whole-target exclusion, the
checkpoint provider prepares one encrypted full-database before-image and a
prior-world verifier. The full checkpoint is intentionally wider than the
selected authored scope: it is safe only because no unrelated database writer
can run inside the window. This profile prepares no code, upload, lifecycle,
or effect inverse.
The receipt also carries the exact boolean delete capability. A run claimed
without `--with-deletes` can never be widened by a direct target retry, while a
delete-authorized retry must present that same signed intent.

The target begins an exact random `ps-*` promotion session whose closed
`profile: scoped-checkpoint-v1` and signed receipt-payload hash are published
in its first durable write together with scope, generation, target, signing
key, and delete capability. Exact retries reuse that session and cannot swap
an ordinary session or another receipt into the profile. Scoped apply then
mints its normal target-bound mutation authority/session, performs the bounded
write, and returns its canonical terminal receipt. The host releases the
short target lease, appends that receipt to the external signed generation,
then seals `verifying_new` and `committed` while the database-writer exclusion
is still held. `scoped_fresh_verification` is forward-only: a response loss
after that first seal resumes commit before target begin, never checkpoint
restore. Only then may the host retire the exact target profile session and
release the exclusion.

Controller loss is idempotent at every boundary. A matching active or
terminal-but-still-held generation resumes by immutable artifact, scope,
owner, claimant, and delete capability; terminal apply replay must reproduce
the same external tuple and receipt.
Before durable `scoped_fresh_verification`, any failure advances only through
`database_restore` and `prior_verify`, proves the prior world, records
`rolled_back`, and releases the exclusion. After that forward-only seal,
rollback is never guessed: retry commits, completes the target handoff, and
releases the exclusion. Once the exclusion is released, unrelated writes may
resume, so this protocol deliberately offers no later public or automatic
rollback.

### Host-only redacted refresh field relation (DUO-3345)

`duo refresh <env> --field-diff` is an **unscoped, host-only** adjunct to the
ordinary private `duo-refresh-plan/v1`. It does not alter `plan_hash`, grant
apply authority, mutate the WordPress target, or turn a Git merge into a
field-aware merge. Its sole public artifact is a canonical,
immutable `duo-refresh-field-diff/v1` relation stored under the local Git
common-dir journal; the private plan may retain verified B/P/W source bytes,
but the public diff, machine JSON output, resolution file, field evidence in
`run.json`, receipts, and public errors are value-free. A detailed stopped-run
cause is private local operator evidence in `events/*-stopped`; it never enters
the public diff, JSON, resolution, receipt, or CLI error.

The exact top-level diff keys are `algorithm`, `authority`, `choices`,
`diff_hash`, `format`, `plan_hash`, `policy_projection_hashes`,
`production_snapshot_hash`, `records`, `redaction`, `roles`, and `summary`.
`format` and `algorithm` are both `"duo-refresh-field-diff/v1"`,
`authority` is exactly `false`, `redaction` is exactly `"values_omitted"`,
`choices` is exactly `{ "ours": "branch", "theirs": "production" }`, and
`roles` is exactly `{ "base": "merge_base", "ours": "branch", "theirs":
"production" }`. `diff_hash` is the SHA-256 of canonical JSON with only
`diff_hash` omitted. `plan_hash` and `production_snapshot_hash` are exact
SHA-256 bindings. `policy_projection_hashes` has exactly `base`, `branch`,
and `production`; branch and production must be equal. Each projection hash
binds the policy's `state_site_hash`, manifest hash, resolved-adapter digest,
and sorted derived-post-field map without publishing those inputs.

`records` is selector-sorted and contains only ordinary B/P/W plan entries
whose record category is `conflicting`; branch-only, production-only, and
compatible plan rows remain in the ordinary private plan/counts because they
need no field choice. A record has exactly `changes`, `entity`, `mode`,
`reason`, and `record_selector_sha256`. `entity` is one of `post`, `term`,
`attachment`, `menu`, `sidebar`, `options`, `user_meta`, `typed_table`, or
`record`; selectors are opaque SHA-256 values and never encode an id or path.
`mode` is `fields` or `record`. `summary` has only non-negative
`atomic_records`, `changes`, `conflicting_choices`, and `records` counts that
must equal the enclosed rows.

Every change is selector-sorted and contains only `category`, `field`,
`field_selector_sha256`, `hash_status`, `record_selector_sha256`, `relation`,
and `scope`; record-atomic changes additionally contain `reason`.
`category` is one of `unchanged`, `production-only`, `branch-only`,
`compatible`, or `conflicting`. `reason` is one of
`eligible_engine_fields`, `scoped_record`, `production_omitted`,
`absence_or_tombstone`, `option_or_state_witness`, `opaque_record_type`,
`routing_changed`, `unsupported_document_shape`, `attachment_media`,
`document_structure_changed`, `opaque_or_structural_field`,
`derived_field_policy`, `opaque_container`, or `body_changed`.
`hash_status` is exactly `"withheld"`; no raw value hash is public.
`field_selector_sha256` is exactly SHA-256 of
`"duo-refresh-field-selector/v1\0" || record_selector_sha256 || "\0" || field`.
Field labels are only
`post.author`, `post.comment_status`, `post.excerpt`, `post.menu_order`,
`post.parent`, `post.ping_status`, `post.publication`, `post.title`,
`post.modification`, `term.description`, `term.name`, `term.parent`, and
`record`. A `fields` record is exactly `post` or `term`, and every field label
must use that entity's `post.` or `term.` prefix. A field change has
`scope: "field"`; an atomic change has
`field: "record"`, `scope: "record"`, and `category: "conflicting"`.

`relation` is the closed, value-free role relation:

```json
{
  "base": "present|tombstone|absent",
  "branch": "present|tombstone|absent",
  "branch_vs_base": "same|different",
  "branch_vs_production": "same|different",
  "production": "present|tombstone|absent",
  "production_vs_base": "same|different"
}
```

`same` may not cross different presence states, and two `absent` roles are
necessarily `same`. Pairwise `same` is transitive: B/P/W may have zero, one,
or three `same` comparisons, never exactly two. Eligible scalar fields are
present in all roles and derive their relation from canonical scalar equality:
each scalar token is decoded and Canon-encoded only as private comparison
evidence, so equivalent spellings such as `"base"` and `"\\u0062ase"` or
`1` and `1.0` are `same`. Exact verified raw tokens and spans remain private
materialization input. Object/list values are never normalized for this
purpose; a changed group containing one is opaque and record-atomic.
`production-only` is B=W≠P,
`branch-only` is B=P≠W, `compatible` is P=W≠B, and `conflicting` has all three
different. Atomic rows derive relation equality from exact row type plus
semantic hash/absence only; those hashes never leave the private plan.

Field mode refuses every scope contract before target observation or candidate
worktree creation. It also refuses missing/invalid B/P/W policy projections,
branch/production policy skew, or a final candidate policy that differs after
code-only rebase. A post field is field-eligible only when B, P,
W, and the candidate policy all classify it authored. The v1 field surface is
limited to the post scalar groups listed above (publication is coupled
`status`/`date`/`date_gmt`; modification is coupled
`modified`/`modified_gmt`) and term `name`, `description`, and `parent`. A
legacy optional member of a coupled group may be absent only identically in
all B/P/W roles, and then only when every retained member is raw-identical;
otherwise the record is atomic. A changed post body, attachment/media record,
menu, sidebar, option/state witness, user-meta, typed-table row, tombstone,
container/list, or other opaque/structural field is one record-atomic change.
If B is a live record and P or W is absent, field mode refuses before it emits
a diff or accepts a resolution: absence never becomes a field-mode choice and
the legacy whole-record resolver remains the available path. Mixed eligible
field and record-atomic conflicts remain resolvable in one field-resolution
run.

`duo-refresh-field-resolution/v1` is a separate canonical immutable object
with exactly `algorithm`, `choices`, `diff_hash`, `format`, `plan_hash`,
`production_snapshot_hash`, and `resolution_hash`. Its `algorithm` is
`"duo-refresh-field-diff/v1"`; `format` is
`"duo-refresh-field-resolution/v1"`; every top-level binding must equal the
displayed diff; and `resolution_hash` is SHA-256 of canonical JSON with only
that hash omitted. `choices` is a complete, unique, selector-sorted list of
only `{ "choice": "ours|theirs", "field_selector_sha256": "…",
"record_selector_sha256": "…", "scope": "field|record" }`. It names every
and only conflicting change in that diff. A user-supplied local file must be a
non-empty, at-most-1 MiB regular non-symlink canonical JSON file and must
already carry its `resolution_hash`; interactive construction may compute that
hash internally. Interactive mode first renders every changed row as a closed
preview: automatic `production-only` decisions use production; automatic
`branch-only` and `compatible` decisions use branch, preserving the exact
branch-byte scaffold; and only `conflicting` rows are prompted. The public
diff contains only conflicting entries, but local interactive preview also
shows every changed non-conflicting private plan entry as a closed automatic
record decision; those rows add no resolution choice. CLI `--interactive` is
the one explicit host-local reveal surface: it requires TTY stdin and stdout
and may show a bounded C0/DEL-safe valid-UTF-8 authored post title, term/menu
name, or path fallback beside its opaque selector. That label is derived only
in memory from the exact prepared private plan, binds to the same plan/diff
hashes, is never returned or journaled, and never enters the public diff,
machine JSON, resolution, receipt, or `run.json`. Pipe/automation callers use
the value-free canonical `--field-resolution` file instead.
Field resolution cannot mix with any legacy `--strategy` spelling or
`--resolve` choice.

Materialization occurs only in the existing disposable worktree after the
second production snapshot observation and fresh candidate-policy check. It
never decodes and re-encodes a hybrid document: branch exact bytes are the
scaffold, selected allowlisted top-level scalar spans are copied verbatim from
verified B/P/W source bytes, and all container/list/body/opaque content stays
as the selected whole record. Field-spliced records use branch media authority;
attachments remain atomic. Strict compilation and the normal new-ref boundary
remain mandatory. A field receipt binds only the public `field_diff_hash` and
`field_resolution_hash`; private spans and literals never enter public run
evidence. Before any span is read, the host verifies that the process-local
bundle has exactly the public record selectors, maps each selector to its
conflicting plan entry, and carries exactly the public non-unchanged field
set/category/scope; it cannot introduce an unreviewed private splice.

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
and `{slug}` — a WordPress-style identifier, not an ASCII-only one:
`[\p{Ll}\p{Lo}\p{Nd}][\p{Ll}\p{Lo}\p{Nd}_-]{0,63}` (lower-case or
case-lacking Unicode letters — Latin, Cyrillic, CJK, Arabic, Hebrew, and
similar — plus decimal digits; upper/titlecase letters and non-decimal
numerics such as Roman numerals stay excluded, matching what
`sanitize_title()`-family sanitizers actually emit, DUO-3437). Control characters,
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

   Every `conflict` and `delete_conflict` row carries an additive
   `conflict_view` object with `format: "duo-plan-conflict/v1"`. Its three
   named roles are `base` (`last_synced`, sourced from `duo_state`, with
   `present`, `missing`, or `deleted` state), `repository`
   (`repository_intent`, sourced from the compiled repository, `update` or
   `delete`), and `target` (`target_observation`, sourced from the live target
   snapshot and preserving the current target change). The direct apply
   planner never calls those roles “branch” or “production,” because it has no
   Git/environment-role evidence; refresh/rebase owns that separate B/P/W
   vocabulary. Evidence is hash-only: full canonical content hashes, the
   deletion intent's expected-base hash, and its receipt hash where relevant;
   raw entity values never enter this diagnostic surface. A finite
   `reason_code` distinguishes both-sides-changed, option-delete conflict,
   missing base, recreation after delete, expected-base mismatch, and target
   change after the deletion base. `recommended_choice` is always
   `reconcile_in_repository`, whose non-destructive choice preserves both
   intents for capture/review/re-plan. The alternative `apply_repository` choice
   is explicitly destructive and lists the conflict-resolution flags
   (`--force-theirs`, plus `--with-deletes` for a tombstone or an option-record
   deletion). A tombstone row
   with a referential `blocked` finding omits that destructive choice until
   the declared references are repaired; `--force-delete-referenced` remains
   a report-not-hide escape hatch but is never described as a safe conflict
   choice. Human plan and
   status output render the same roles and choices with bounded hash prefixes;
   JSON retains the full evidence. `--force-theirs` is the flag that selects
   the destructive conflict choice; companion authorities such as
   `--with-deletes` or `--force-delete-referenced` do not select that choice
   by themselves and therefore retain the ordinary conflict refusal. Once
   `--force-theirs` requests the choice, omitting any other required flag
   refuses before mutation as `apply_conflict_override_incomplete`;
   its hashed `duo-forced-plan-override/v1` evidence separates
   `required_flags` from `supplied_flags` and uses `status=incomplete`, never
   a `FORCED` or authorization claim. Entity tombstone conflicts therefore
   cannot proceed on `--force-theirs` alone: `--with-deletes` is independently
   mandatory. Once every required flag is present, a forced ordinary conflict
   is reported in apply's human and machine warnings just like a forced
   deletion conflict; the escape hatch never hides what it overrode. If a later gate or
   convergence check prevents the normal apply summary, JSON returns the
   typed `apply_forced_override_failed` refusal with a `forced_overrides`
   list. Each entry is `duo-forced-plan-override/v1` evidence containing only
   the SHA-256 of the plan entity identity and engine-owned bucket, conflict,
   reason, choice, effect, and authorization enums—never the raw entity
   identity, value, title, later exception text, or guard/provider detail.
   An advertised destructive choice is recorded as `choice=apply_repository`
   with its exact required and supplied flags. A referentially blocked tombstone has no
   such advertised choice; if every explicit escape hatch is supplied and a
   later failure occurs, its evidence instead records
   `choice=explicit_force_flags`, deletion effect, the exact three required
   and supplied force flags, `status=authorized`, and
   `guard_override=force_delete_referenced`. Missing that guard flag instead
   returns `status=incomplete` and omits the guard-override claim. It never fabricates
   the conflict choice that the guarded plan intentionally suppressed.

   `wp duo explain <bucket>:<entity-key> --repo=<repo>` is an additive,
   entity-row projection of a freshly rebuilt plan. Human plan output prints
   a copyable hash-safe selector below each itemized row; the machine result is
   separately versioned as `format:"duo-explain/v1"`, so the plan/status JSON
   contract is unchanged. The accepted buckets are exactly `create`,
   `update`, `adopt`, `unchanged`, `drift`, `conflict`, `collision`, `delete`,
   `delete_conflict`, and `deleted`; code, lifecycle, capability, warning, and
   other aggregate sections remain plan/status concerns. A selector is
   current-plan-relative, resolves one opaque identity in memory, and is never
   interpreted as a repository or filesystem path.

   The explanation binds to the whole compiled artifact/revision/manifest/site
   digests and projects only value-free evidence: a structural source locator,
   effective policy/manifest coordinates and classification, declared outbound
   reference edges with the other identity hashed, the exact rebuild surfaces
   contributed by this row, structured native/provider declarations selected
   by those surfaces in manifest order, and the convergence/readback verifier.
   It never serializes canonical values, per-entity content hashes, raw entity
   keys, repository paths, target-local ids, titles/logins, action arguments,
   provider receipts, guard rows/reasons, or exception detail. Actions say
   `readiness:not_checked`: selection is not negotiation or invocation, and a
   deletion match remains conditional on apply's explicit authority gates.

   Explain has no maintenance or mutation authority. It asserts the existing
   ledger schema and embedded identities, captures one coherent strict target
   snapshot, and refuses with `explain_observation_precondition_failed` when
   repair is needed or attachment bytes are not already local. It does not
   create/prune/repair ledger state, diagnose or negotiate a provider, invoke
   a native/provider action or attachment-offload hook, or write target state.
   Invalid, unsupported, absent, ambiguous, and source-missing selectors
   use finite refusal codes and never echo the untrusted selector.
3. **Reference safety**: compilation blocks surviving canonical references. Adapter guards check runtime reverse references; a missing required guard table also blocks. `--force-delete-referenced` is an explicit report-not-hide escape hatch.
4. **Canary armed**: listeners on `save_post`, `transition_post_status`, `created_term`, `wp_insert_comment` + `pre_wp_mail` + `pre_http_request`; any fire during apply = hard failure.
5. **Phase 1** — upsert rows (posts, terms) with placeholder refs, direct `$wpdb`; mint local ids; write `_duo_uuid`.
6. **Phase 2** — resolve refs through the ledger: parents, metas, term relationships, menu structure, option values, body detokenization (block registry restores numeric types).
7. **Deletes** — only with `--with-deletes`, custom-table children before parents. The engine performs the declared cascades, then queries every exact target and attached sidecar before commit. Any survivor rolls back the transaction. Menus delete their owned menu-item posts; comments, Woo order lookups, Ninja Forms submissions, and other declared runtime references are preserved by guards rather than cascaded.
8. **Rebuild** — canary disarmed: required derived-dependency synthesis and verification, term recounts (direct SQL), attachment metadata regeneration, manifest-declared structured actions (closed native actions and pre-mutation-negotiated provider capabilities), and cache flush.
9. **Verify convergence** — recapture the live target through the canonical snapshot reader in a fresh WordPress process before any convergence metadata advances. The verifier is pinned to the exact compiled artifact used by apply, avoiding stale pre-apply plugin models and refusing a concurrently changed repository. Every entity in the compiled tree must have the same type and canonical hash. Target-only entities remain untouched because absence is not deletion authority; when `--with-deletes` is explicit, every compiled tombstone UUID must be absent. A mismatch names the failed invariant, retains `apply_in_progress`, and leaves all base hashes and `applied_revision` unadvanced. Naming it binds the operator-facing refusal, not only the verifier: the gate runs in a launched `--format=json` process whose value-free refusal envelope is the machine contract on stdout, so that process states its operator sentence on stderr too — the channel the launching apply reads and re-raises — and a launched process that halts without prose is read back from the envelope rather than reported as a bare exit code. The refusal also states that the target WAS mutated (this gate can only run after the authored transaction committed and the rebuild pass ran) and, when the run preserved ordinary environment `drift`, names those entities as the structural cause: this gate proves the whole tree while apply deliberately never writes `drift`, so a drifted target cannot converge until a capture folds it in.
10. **Receipts and retry** — only after verification passes, a successful or already-absent deletion stores the tombstone hash in `duo_state` with entity type `deletion`; re-planning returns `deleted`, so retries are idempotent. Live hashes and `applied_revision` update atomically with clearing `apply_in_progress`.

Every agent command that advertises `--format=json` refuses through this one
envelope — the set is closed, not a growing enumeration, and an argument gate
refuses through it exactly like a policy or target-state gate. A JSON-mode
refusal wraps the stable object above in `format:"duo-command-refusal/v1"` and
adds `command`, `reason_code`, reviewed public `message`, and reviewed public
`remediation`. The established top-level `error` and safe `diagnostics` fields
retain their exact shape. `policy-to-manifest` and `manifest-pin` are outside
that set by design: they advertise no `--format`, print one canonical JSON
document unconditionally, and refuse human-only, so a caller distinguishes
refusal by an empty stdout and a non-zero exit rather than by a negotiated
format.
Every serialized field is subject to one final sensitive-data guard: if any
typed diagnostic contains a secret, credential-bearing or signed URL, email,
private home path (Unix, drive-letter, or UNC), or control byte, the whole
diagnostic batch is omitted,
the stable `error` remains, and `details_redacted:true` is returned. Unknown
query- or fragment-bearing absolute URIs are conservatively treated as signed
or credential-bearing rather than serialized from refusal evidence. Only a
typed, reviewed refusal or an established typed compiler diagnostic may
contribute public machine evidence. The human-facing `duo: ` prefix is never
authority to publish an arbitrary caught Throwable: every unclassified
Throwable contributes none of its message, cause, path, or trace and sets
`details_redacted:true` (DUO-3404). Human output keeps the original operator
message, and the redacted sentence is recorded privately: the agent writes a
`duo-private-refusal-evidence/v1` JSON record (reason code, throwable class,
message, cause chain, origin file:line; no trace) under the site repository's
gitignored `.duo/refusals/`, mode 0600, whenever a `--repo` is known — the
envelope itself never names or carries it. Known scope
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

The marker's value is the canonical object
`{"format":"duo-apply-in-progress/v2","preserved_drift":[{path,type,uuid}…],"write_set":[uuid…]}`.
`preserved_drift` names exactly the entities that interrupted apply classified
as ordinary environment `drift` and therefore deliberately did not write;
`write_set` names exactly the identities of its authored work set — the
create/adopt/update/conflict rows it was authorized to mutate, locked in before
the first mutation. Reprocessing is about rows the failed run wrote, whose
`duo_state` base is now stale; it was never about rows it did not.

So the next plan widens `unchanged` and any *unrecorded* `drift` into `update`
with `retry:true` as before, and widens a `conflict` row only when `write_set`
contains its identity. A recorded identity that still classifies as `drift`
stays in `drift`, and a three-way `conflict` on an identity outside the write
set stays in `conflict` — where the ordinary gate keeps demanding an explicit
`--force-theirs` or capture-first choice, exactly as it would on a first apply.
Both matter because the repository side of an entity can move between the two
runs (the operator recompiles), which turns a preserved row into a genuine
three-way divergence that a retry must not resolve on its own. The retry plan
keeps reporting the true `drift` and `conflict` counts, `duo status` stays
non-zero on them, and the `incomplete_apply` reason states how many entities
the retry will not overwrite and which remedy each group needs.

The format string is the contract, and each version is read only for the claim
it makes. A `duo-apply-in-progress/v1` marker records `preserved_drift` and no
write set at all: its recorded identities are provably not-written and carve
out of both buckets, while every other `conflict` row keeps the original
widening. A marker carrying no record at all (an older agent's, or a
hand-planted one) keeps the original whole-bucket widening rather than
inventing a preservation claim.

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

Capture's sealed `state.capture-intent` and `state.capture-receipt` records use
fixed sibling transition slots (`.previous` and `.next`) for ordinary phase
replacement and removal. Recovery resolves those finite slots before it reads
either canonical record: it restores a proven prior phase, completes a proven
next phase, or retains all observed protocol artifacts and refuses an ambiguous
shape. The slots are protocol artifacts, not repository state, and the
canonical site-repository ignore template excludes them together with
intent/receipt temporary files. The capture lock serializes Duo writers only;
every capture and recovery therefore requires non-Duo tools to leave the
complete `state.capture*` protocol namespace untouched for its duration. The v0
PHP implementation detects stable type/hash anomalies but does not claim an
adversarial namespace-race sandbox for these siblings.
While `.duo-init-attempt` or its transition slot exists, ordinary capture
refuses before and after publication-lock acquisition; only confirmed init
recovery may reconcile that first-publication tuple. This prevents a later
capture from replacing the receipt that the sealed init journal must verify.

The target database lease serializes Duo promotions; it cannot exclude a
package manager, self-updater, shell user, or compromised process that writes
the code tree directly. Code stage/finalize therefore require operational
exclusion of every non-Duo writer from `WP_CONTENT_DIR` for their duration.
The same contract applies to first initialization across the complete site
repository namespace (`.git`, `code/`, `media/`, `state/`, and every
`state.capture*` sibling) until init or retained recovery completes.
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

### Local control-plane bootstrap (`duo-bootstrap-eligibility/v1`)

`duo driver-capabilities <env> --operation=adopt` remains target-free. A local
driver advertises `environment.bootstrap` and `code.transfer` only when its
loader-proven machine-local entry contains exactly
`"bootstrap":{"format":"duo-local-control-plane/v1"}` beside normalized
absolute, non-root `wp_path` and `repo_path`. The same declaration in
checked-in policy has no authority; a missing declaration is an actionable
unsupported row, while a present malformed declaration is a configuration
refusal. Docker never inherits bootstrap authority from generic shell access
or a mount.

The later local `duo adopt` call obtains a separate canonical
`duo-bootstrap-eligibility/v1` object with exact top-level fields `format`,
`driver`, `repo_path`, `ready`, `checks`, and `digest`. Checks are ordered
closed rows `{code,state,reason,remediation}`; `digest` is the SHA-256 of the
canonical object without that field. This report is advisory evidence, not a
write token: install repeats the bounded topology proof immediately before
staging. The proof uses a plugin/theme/MU-isolated WordPress bootstrap, requires
installed WordPress and its standard MU leaf, disjoint normalized source/WP/
repository roots, ordinary non-symlink ancestors and absent-or-ordinary
destinations, required tools/access, no stale adoption transaction, and either
an entirely absent local Duo agent/loader/manifest/`.duo` control plane. Local
bootstrap is initial-only: an installed target is refused with remediation to
use its existing update path, avoiding any race with recovery writers. It
performs no Duo target write.

Local adoption copies only the invoking checkout's fixed `agent`, `manifests`,
and recovery artifact. The new agent/loader/manifests and repository `.duo`
tree are staged under exclusively created, identity-recorded paths. Recovery initialization and configured probes run only against the
staged `.duo`; `site.duo.json` is hard-linked into place only when absent. The
swap, exact agent/policy/authority verification, and isolated public doctor are
one rollback unit. Only a green doctor crosses a mutation-free commit barrier;
rollback copies are deleted afterward, and any cleanup failure retains evidence
without attempting restoration from a possibly partial backup. Every earlier
failure restores the prior site policy and any newly created repository or MU
leaf byte-for-byte; cleanup refuses to delete a path whose filesystem identity
changed. The exclusively uploaded local archive is consumed and removed only
while its recorded identity still matches. This out-of-band control-plane/authority seed never installs
WordPress, materializes managed code, captures canonical state, creates entity
identity/ledger rows, or authorizes a later `duo init`.

Deploy and apply also share one target-authoritative lease in the target
database. The `duo_kv.promotion_lock` record names a random orchestrator owner,
the compiled artifact hash, current phase, and bounded expiry. In an
orchestrated multi-process promotion, only `promotion-begin` may create or
recover that sequence's row and it records the latest begun owner/artifact
session durably; explicit later phases are strict continuations of both that
session and its exact still-live row, so an absent row never authorizes—or
advertises recovery for—an obsolete checkpoint. Each live mutation process additionally
holds a connection-scoped database advisory fence. That fence covers unbounded
plugin/theme hooks and filesystem work: a second process cannot recover an
expired row while the original is still running, and the continuously fenced
owner renews when control returns. A crashed process drops the advisory fence
automatically and its row becomes recoverable by a different owner after
expiry. Direct `wp duo deploy` and `wp duo apply` calls acquire their own
single-phase row plus process fence too. Direct deploy begins its durable
session with that lease. Direct apply first acquires an `apply-preflight`
lease without replacing `promotion_session`, negotiates only the provider
actions selected by its exact plan, and completes the locked optimistic
recheck. A refusal releases that transient lease with the prior session bytes
unchanged. Only after those pre-mutation gates pass does direct apply publish
its own session immediately before `apply_in_progress` and authored mutation;
runtime provider negotiation remains under the lease and no earlier plan
report authorizes it.

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
- **Plan category summary** (DUO-3345): the plan entry point adds an additive top-level `category_summary` object with `format: "duo-plan-category-summary/v1"`. It is a value-free projection of the unchanged detailed buckets, emitted as a fixed ordered list of categories `code`, `lifecycle`, `authored_state`, `generated_effects`, `media`, `secrets`, `environment_state`, `capabilities`, and `deletions`. Each category has closed, zero-filled count maps named `metrics`, `entity_actions`, and `contained_entities`; those facets intentionally overlap. Deletion rows live in `deletions`, not `authored_state`; attachment classification uses compiled tree/tombstone context rather than a path guess. Code metrics split compatibility, lifecycle, revision-stale, drift, and future/other findings; lifecycle deliberately overlaps its code findings and adds `incomplete_lifecycle`. Generated metrics distinguish declared effects, Apply's exact selected native/provider actions, `regen_pending`, and `incomplete_apply` without claiming execution. Capability metrics distinguish certification/source blockers, selected provider blockers, and declared-but-unselected provider problems. Nested menu-item, widget, and option deletion candidates are reduced from the same coherent target snapshot and final plan; no post-plan query or guessed cascade is allowed. The secrets category emits only `visibility: "redacted"` and never scans or counts warning text or environment names. The public label `generated` is intentional product vocabulary; the shipped policy/wire class remains `derived`, and the summary's `vocabulary` records `public_label: "generated"` plus `wire_class: "derived"` without adding a manifest class. The projection never carries canonical values, secrets, PII, target-local ids, or plugin-specific logic. Host `duo status` strictly validates and renders the projection when present, omits it when absent or malformed, and never lets this optional display data alter plan completeness/readiness, promotion, or convergence.
- **Bounded plan view** (DUO-3345): no-flag `wp duo plan` JSON, detailed buckets, and direct human rendering remain byte/shape-compatible. Filtered direct-plan row labels and host status plan-row labels safely normalize C0/DEL controls. An explicit `--category=<csv>`, `--action=<csv>`, `--entity=<csv>`, or canonical `--limit=<1..200>` request adds only `plan_view` with `format: "duo-plan-view/v1"` beside the complete detailed plan; it never removes, reorders, or authorizes its buckets. The closed category vocabulary is the nine summary ids above; actions are `create`, `update`, `adopt`, `unchanged`, `drift`, `conflict`, `collision`, `delete`, `delete_conflict`, `deleted`; entities are `post`, `attachment`, `term`, `menu`, `sidebar`, `options`, `user_meta`, `typed_table`. CSV tokens are exact and comma-only, deduped/canonicalized in vocabulary order; OR applies within one dimension and AND across supplied dimensions. An explicit view defaults to and caps ordinary rows at 200; there is no cursor in v1, so an unfiltered full JSON plan is the complete escape hatch. `plan_view` states `authoritative: false`, normalized filters/order, exact ordinary `full`/`matching`/`shown`/`omitted` and `forced_safety` evidence, complete action/global/readiness counters, and selected value-free refs only: closed bucket/entity/category facets, safety bit, and a hashed explain selector. The selector resolves by a unique UUID scan within the full bucket, so no source position leaks into or destabilizes the view. Display rows sort by fixed action rank then bytewise UUID; the authoritative bucket arrays retain their original order. Facets are explicit and overlapping: normal live actions are `authored_state`, attachments also `media`, drift also `environment_state`, and tombstones are `deletions` (attachments also `media`). Every drift/conflict/collision/delete-conflict/blocked-delete row bypasses filters and the cap. Global diagnostics and readiness are always from the full plan, including `regen_context`; filtering cannot hide a blocker or affect apply, promotion, or convergence. A category request additionally requires a valid same-snapshot `category_summary`. `duo status` forwards one normalized request to its single full-plan fetch and typed-refuses `plan_view_unavailable` if that requested index is absent, malformed, or does not bind that full snapshot; unfiltered status remains compatible with legacy agents. V1 view flags and `--scope-contract` are mutually exclusive because `duo-scoped-plan/v1` is already a separate selected-contract projection rather than the complete detailed plan a view indexes; their combination typed-refuses `plan_view_unavailable`. Human filtered output dereferences refs only from that complete plan and strips C0/DEL control bytes from newly itemized path/title labels. Field/text/value searching, raw-value views, plugin-specific engine filters, interactive diffs, and cursors remain out of scope.
- **`wp duo doctor` DISALLOW_FILE_MODS check** (DUO-3231, `cli/src/Onboarding/Doctor.php`): advisory-only (never fails `doctor`'s own exit code) — reports when a target's `wp-config.php` does not `define('DISALLOW_FILE_MODS', true)`, the source-closing complement to `code_drift`'s after-the-fact detection (docs/proposals/code-half.md risk register #1).
- Operational note: with a plugin active in the DB but missing from disk, the `wp plugin deactivate` *command* refuses (it pre-resolves its argument against a disk scan) — but `wp duo deploy` handles this case fine: it calls core's `deactivate_plugins()` directly with basenames from the option, no disk resolution on the deactivation side (verified live). Manual `update_option('active_plugins', …)` surgery is the last resort only when duo itself is unavailable.

### Cross-branch plugin-version skew

When branches contain different versions of a plugin, integration is an ordered boundary rather than a single undifferentiated state merge:

1. Merge the code-only change first and materialize that code.
2. Run the upgraded plugin's migrations against the current database.
3. Capture and commit the migrated canonical state under the new code version.
4. Only then merge state authored by the older-version branch. Resolve any schema conflict explicitly in the new version's shape before apply.

This keeps old-schema state from being silently interpreted by new code and gives Git a reviewable conflict when both the migration and the older branch changed the same canonical entity. `make certify-merge` exercises this end to end: a v1 scalar option diverges on a state branch while a v2 code branch migrates it to an object; the integration branch must capture the v2 object before merging the v1 edit, resolve the one-file conflict without losing the edit, and re-capture byte-identically on both environments.
