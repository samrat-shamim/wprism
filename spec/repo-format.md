# WPrism Site-Repo Format

*Status: **normative** — the authoritative contract for site repositories; where narrative documents (README, DESIGN.md) and this spec disagree, this spec wins. The wire-format grammar version is the engine's `WPRISM_SPEC_VERSION`, **currently `3`** (`agent/wprism.php:13`, restated in `platform/adapter-library/capabilities/platform.json`). Both documents that carry that integer — a manifest's `spec_version` and `site.wprism.json`'s own — are judged against the **acceptance window {N-1, N}**, not against exact equality (§ v3.1). So a repository or manifest declaring `2` loads unchanged on this engine, which is the whole reason the v3 flip moved no adapter digest and required no action from a deployed site. The "spec v1"/"spec v0.x" markers throughout are this document's own draft-history labels — they record when a rule was introduced and are NOT the wire version. The "Spec v3" section near the end states this wire version's rules, each carrying the work package that implemented it and an `Enforced today:` line that is the authority on what the engine actually keeps; § v3.12 records what the flip deliberately did NOT change, and it is the section to read before assuming v3 means anything was re-stamped.*

A **site repo** is a git repository holding the branchable partition of one WordPress site: code, canonical state, media, and policy. Environments (any WP install with the WPrism agent) materialize it; their runtime data never enters it.

## Layout

```
site.wprism.json                # spec_version, manifest pins, site policy
code/
  wp-content/                # optional v0 code payload; exact vendored bytes
    plugins/
    themes/
    mu-plugins/              # user mu-plugins only; WPrism's agent is out-of-band in v0
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

- Every ordinary entity carries a lowercase RFC UUID (normally UUIDv7 minted at first capture; deterministic custom-table identities use UUIDv5), stored invisibly in the environment (`postmeta`/`termmeta` key `_wprism_uuid`). Options are identified by option name.
- Entity filenames are `<uuid>--<slug>.<ext>`. The uuid is identity; the slug is a human affordance (renames change the filename's slug half; tooling treats uuid as the key).
- Each environment holds a **ledger** (`wprism_map` table): `(uuid, entity_type, id_kind) → local_id`. `id_kind` is a distinct keyspace label: `post`, `term`, `term_taxonomy`, declared table kinds, and the closed per-type widget family (`widget_block`, `widget_text`, `widget_nav_menu`, …). Term entities map **two** kinds (`term`, `term_taxonomy`) because WordPress references both inconsistently.
- `_wprism_uuid` is globally unique across posts and terms. Capture rejects invalid UUIDs, multiple identity-meta rows on one owner, or one UUID copied onto multiple owners before publishing state. The repository compiler independently rejects duplicate UUIDs across every entity kind and declared table. `wprism_map` writes are contradiction-intolerant: no ordinary path deletes another mapping, changes a local id, or silently retypes an identity.
- Users are **not** entities: user references serialize as `user:<user_login>` tokens; apply resolves post authors by login and may fall back to a configured default author with a warning. User-meta sidecars are stricter: they resolve the owning login by exact bytes/case and never fall back. Users are never auto-created.
- `guid` never appears in canonical state. Apply generates it deterministically per environment (`<home>/?wprism=<uuid>`) on first insert and pins it in the ledger.

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

Numeric-typed positions (block attrs like `"id":123`, option values like `page_on_front`) are written as the quoted token string in canonical form; the applier restores the declared numeric type. A `block_attrs` rule may also declare `kind:user`: capture writes the existing `user:<login>` form and apply resolves it against the target user table, which is how `core/avatar.userId` stays portable even though users remain environment-local. Rewriting is **structure-aware only**: block attributes via the block parser and a per-block attribute-path registry; `tokenize:text` rewrites every string leaf of the named attribute (including nested arrays such as `core/video.tracks`); HTML-level forms are limited to declared patterns (`wp-image-<id>` class, `src`/`href` URL prefixes). No blind regex over content.

The reserved `wp-image-<id>` form is an exact ASCII-whitespace-delimited token in a `class` attribute, including plugin blocks, block widgets and classic HTML. It is not an arbitrary occurrence in prose, another attribute, a comment or raw script/style text. Capture and apply use one bounded whole-body HTML pass so Gutenberg child boundaries cannot reset text context. Capture preserves surrounding bytes and uses the existing dangling/unscoped reference rules; immutable compilation refuses numeric, entity-encoded or malformed canonical media classes before target access. The same pure reader supplies lint findings without WordPress. Character references are decoded for recognition while only the selected class token's byte span is replaced. SVG/MathML integration points and `noscript` fallback content share this grammar; fallback content remains portable with scripting disabled while its outer document remains portable with scripting enabled.

Current heuristic for internal links: permalink hrefs tokenize as `{{home}}/<path>` (correct while slugs match across branches). uuid-precise link tokens (`{{link:<uuid>}}`) remain reserved for a future format revision. Query-string-style internal links (`?p=`/`?page_id=`/`?attachment_id=`, WordPress's own older URL scheme — verified against `redirect_canonical()` directly, not `?page=`, which is pagination) get the uuid-precise treatment already: `{{home}}/?p={{post:<uuid>}}` (spec v0.20, issue #3260) — see "Unscoped references" below for the full triage.

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
- Excluded fields: `guid` (env-derived), `comment_count` (derived), revisions and auto-drafts (never captured). An empty `post_password` is default noise. A non-empty password emits only `password_binding: "post_password:<post-uuid>"` in front matter; the password bytes never enter canonical state, hashes, output, or a command argument. Each environment provisions that exact binding through masked `wprism env-set <env> --name=post_password:<uuid> --stdin`, whose intended-value authority is the existing gitignored mode-0600 `.wprism-env-values.json`. Plan reports the binding as required `env_missing` when absent or when an existing mapped post differs from its intended local value; apply resolves it for create/update and clears `post_password` when canonical state has no binding. Repository validation accepts only the exact UUID-derived name, so a hand-authored plaintext or cross-post binding refuses before mutation.
- **Attachments** add: `"file": "<upload-relative/path.ext>"` (typically `Y/M/name.ext`, but normalized upload-root paths such as plugin placeholders are valid), `"media": "<sha256>.<ext>"` (binary in `media/`), `"mime": "image/jpeg"`, `"alt": "…"` (from `_wp_attachment_image_alt`). Body = attachment description; the uniform `excerpt` field carries the caption. `_wp_attachment_metadata` is derived: regenerated after every applied attachment create or update, including retry/adoption paths. Capture obtains the binary through the `wprism_attachment_capture_source` filter: its default is `['path' => <local upload path>]` when that file exists, and an offload adapter may return exactly `['path' => <readable materialized path>]` or `['bytes' => <raw provider bytes>]`. If neither a local file nor a provider source exists, capture refuses loudly and names the attachment, relative upload path, and provider hook; it never treats absent bytes as a valid portable attachment.
- A post with `status: "future"` is materialized with a `publish_future_post` single event at its exact `date_gmt` UTC instant. Apply replaces any stale event for the post and verifies the new schedule.

### Terms — `state/terms/<taxonomy>/<uuid>--<slug>.json`

```json
{
  "description": "",
  "meta": {
    "thumbnail_id": "{{post:0198b0c3-...}}"
  },
  "name": "News",
  "parent": null,
  "slug": "news",
  "taxonomy": "category",
  "uuid": "0198b0aa-..."
}
```

`parent` is a term uuid or null. In spec v2, `meta` contains only keys
classified `authored`; reference-typed values use the same canonical tokens as
post meta. Runtime, derived, and environment-local keys remain target-local,
and an unclassified key blocks capture. `nav_menu` terms are not stored here —
menus own them.

### User meta — `state/user-meta/<sha256(exact-login)>.json`

```json
{
  "login": "editor",
  "meta": {
    "agency_profile_id": "{{post:0198b0c3-...}}"
  }
}
```

This is a login-keyed sidecar, not a user entity. The filename is the lowercase SHA-256 of the ASCII domain separator `wprism-user-meta`, one NUL byte, and the exact `user_login`; the compiler recomputes it from `login`, so case-only and punctuation-heavy logins remain safe on every filesystem without minting a UUID. No `wprism_map` identity is created. Capture emits only keys explicitly classified `authored`; unknown and `runtime`/`env`/`derived` keys remain target-local. Authored values use the ordinary meta ref/token/structured-value machinery, reject multi-row values rather than choosing one, and are recursively scanned for hard secrets and conservative PII signals. `allow_secret: true` and `allow_pii: true` are exact rule-level review exceptions; the same booleans are available on other declaration-aware authored option, post-meta, term-meta, table, and sub-key surfaces, never as a section-wide bypass.

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
- **`locations` may be excluded under a plugin override** (spec v0.19, issue #3272): the core manifest classifies `locations` `authored` by default (v0's existing behavior, unchanged for every ordinary site). A pinned plugin manifest may reclassify it `derived` when it can prove Polylang-style ownership — a plugin whose own runtime machinery unconditionally rewrites the raw `theme_mods_<stylesheet>['nav_menu_locations']` slot WPrism would otherwise be racing against (Polylang: `Languages::update_default()`, which recomputes this slot from its own `nav_menus[theme][location][lang]` bookkeeping any time the default language changes or is re-resolved, entirely outside WPrism's capture/apply cycle — proven, not assumed, via a 100%-reproducible repro, issue #3272). Under the override, a menu file omits the `locations` key entirely (not an empty array) and apply never writes the raw slot; the portable per-language truth continues to propagate through the declaring plugin's own option `sub_keys` (Polylang: `nav_menus`/`default_lang`, already spec'd under Options above), and `wp wprism plan` reports the active override as a loud warning, the same treatment a core-schema option reclassification already gets. Non-overridden sites see no change in `locations`' shape or behavior.
- Apply reconciles the menu fully: env items of this menu whose uuid is absent from the file are removed (menu-scoped ownership).
- **Menu-item meta** (spec v0.17, issue #3266): a menu item's file entry may carry a `meta` map, identical in shape and semantics to a post's own `meta` field — keys classified `authored` (by a manifest or `site.wprism.json`'s own `policy.post_meta`) capture/tokenize/apply/reconcile through the exact same generic machinery `post_meta` already uses. Everything else on a live menu-item post that isn't one of the eight WordPress-core structural keys (`_menu_item_type`/`_menu_item_object_id`/`_menu_item_url`/`_menu_item_menu_item_parent`/`_menu_item_classes`/`_menu_item_object`/`_menu_item_target`/`_menu_item_xfn`, all pre-classified `managed` in `platform/adapter-library/core/manifest.json` and never routed through `meta`) makes capture refuse loudly, naming the menu, the item, and the key — the same unclassified-meta gate posture every other post-meta-owning surface already has.

### Sidebars — `state/sidebars/<sidebar_id>.json` (issue #3278)

A sidebar file is the scoped-ownership boundary and contains one ordered `widgets` array. Each entry is exactly `{"uuid", "type", "settings"}`. Widget UUIDs are durable identity; source/target counter ids are not portable and never enter the file. Identity is ledger-only: the per-type `id_kind` `widget_<type>` maps the UUID to that type's integer instance number. No `_wprism_uuid` key is injected into plugin/core settings arrays. Apply allocates a free counter per type, writes the mapping, preserves file order in `sidebars_widgets`, and removes target instances absent from that declared sidebar; unmapped theme defaults are reported as plan-visible `widget_deletes`, never overwritten or merged by counter.

The widget type set is closed and manifest-declared. Core v1 declares `block`, `text`, and `nav_menu`. An undeclared live type is a `wprism pending` item and blocks capture; malformed `widget_<type>` options that are not WordPress's multi-instance array family block by option name. `widget_block.content` uses the ordinary `Blocks` capture/apply codec, and `widget_nav_menu.nav_menu` is a declared term reference. `sidebars_widgets.array_version` is internal bookkeeping and excluded. `wp_inactive_widgets` is target-owned except for an exact reference-selected overlay: the engine pre-scans only selected mapped block posts whose effective `core/legacy-widget` rule is one closed whole-block codec declaring `id`, accepts only a stored id whose effective widget-type declaration has that same manifest source, and emits/mints only the intersection with exact inactive assignments. A merged widget type owned by another manifest cannot inherit the block codec's authority. An empty reference set or empty intersection emits no pseudo-sidebar entity. Applying a nonempty overlay is merge-only; removing the source reference de-authorizes the parked instance without deleting its target assignment, settings, or ledger identity. Every unrelated inactive assignment and option byte remains target-owned and produces a loud capture note rather than propagating.

Lost widget mappings fail closed when identity history exists; recovery is the unchanged `wprism-identity-ledger/v1` export/import flow. The export includes and witnesses every owned widget mapping. The `wprism_map.id_kind` width budget is 32 characters and is migrated idempotently; the regression floor is the 20-character family member `widget_media_gallery`.

### Deletion tombstones — `state/deletions/<uuid>.json` (spec v1)

Removing a live entity file is never deletion authority. During capture, if an entity present in the previously compiled revision is absent from the source environment, the agent replaces it with a durable tombstone:

```json
{
  "expected_hash": "<sha256 of the prior canonical entity>",
  "expected_revision": "<prior compiled revision sha256>",
  "format": "wprism-deletion/v1",
  "kind": "post",
  "source_path": "posts/page/0198b0c3-...--about.md",
  "type": "page",
  "uuid": "0198b0c3-..."
}
```

`kind` is `post`, `term`, `menu`, or `table`; `type` is the exact post type, taxonomy, `nav_menu`, or custom-table name. Capture preserves a still-absent tombstone byte-for-byte. Reappearance removes it. Individual menu items are not independently tombstoned. Authored options use their own name-keyed record/tombstone grammar below because they have no UUID identity.

The offline compiler rejects malformed tombstones, live+tombstone identity collisions, unsupported entity types, and any surviving canonical reference to a deleted UUID. A tombstone is accepted only when a pinned adapter declares the exact selector in its top-level `deletions` map, including all cascade effects and runtime reverse-reference guards. A declaration may require `executable_owner_boundary: "all_active_owners"`; the retired `active_plugin_boundary` key is always malformed. Such a declaration MUST also carry `executable_owner_identities`, keyed by every exact `plugin:<basename>` and `theme:<slug>` owner derived from that manifest. Each owner value is a non-empty, duplicate-free list of at most 64 canonical `wprism-executable-tree/v1` tuples with the owner-derived root and a lowercase SHA-256. Co-declarations for the same selector and owner MUST agree byte-for-byte on the sorted identity set. This is adapter authority over independently shipped executable bytes; a site policy cannot mint it.

The live boundary inventories every active and network-active plugin, active child and parent theme, MU plugin, and recognized drop-in. Each exact executable tree must have a `wprism-deletion-owner-agreements/v2` site agreement for the selector. A manifest-declared plugin or theme owner must satisfy both independent checks: the live identity equals the site agreement, and it equals one of that adapter declaration's reviewed identities. Copying a modified live tree into site policy therefore cannot self-authorize it. Site-owned themes, MU plugins, and drop-ins still require an exact site-reviewed agreement because no adapter declaration can infer their reverse-reference behavior. Scoped deletion additionally requires the exact signed external writer-exclusion witness to remain held at plan, transaction admission, every destructive unit, and the final pre-COMMIT check. Missing, unreadable, code-mismatched, or unreviewed owners refuse before mutation. Refusal is correct when no adapter owns the destructive semantics.

The site agreement is a duplicate-resistant object of selector and owner lists:

```json
{
  "policy": {
    "deletion_owner_agreements": {
      "format": "wprism-deletion-owner-agreements/v2",
      "selectors": [
        {
          "selector": "post:product",
          "owners": [
            {
              "owner": "plugin:woocommerce/woocommerce.php",
              "code_identity": {
                "format": "wprism-executable-tree/v1",
                "root": "plugins/woocommerce",
                "sha256": "<64 lowercase hex>"
              },
              "rationale": "Reviewed exact installed tree; no undeclared product reverse-reference writer."
            }
          ]
        }
      ]
    }
  }
}
```

An adapter declaration is still mandatory for a `plugin:*` owner. A site row
binds the exact installed tree but cannot grant a foreign plugin deletion
authority or extend the adapter's reviewed identity set. A manifest-declared
theme is subject to the same adapter identity check. Other theme, MU-plugin,
and drop-in rows use owners
`theme:<slug>`, `mu-plugin:<top-level.php>`, and `dropin:<recognized.php>` with
canonical roots `themes/<slug>`, `mu-plugins`, and `<recognized.php>`.
Duplicate selectors or owners, noncanonical roots, stale hashes, symlinks,
unreadable/oversized trees, and any tree change after the transaction binding
all refuse. Diagnostics expose only the canonical `{format,root,sha256}` tuple
needed for explicit review; they never expose absolute paths or file rosters.

On the primary JSON command path, that known missing-ownership gate is the typed `unsupported_deletion` refusal. Its diagnostic `surface` is the exact generic selector (for example `table:nf3_forms`), while the raw operator exception remains private. Machine callers can therefore distinguish an unsupported deletion intent from an unrelated capture/compile/plan failure without weakening the catch-all redaction rule.

That refusal also applies when a plugin's destructive semantics exceed the current grammar. For example, WooCommerce 11.0.0 global product attributes are authored rows in `woocommerce_attribute_taxonomies`, but deleting one through WooCommerce derives `pa_<attribute_name>`, deletes that taxonomy's terms and relationships through WordPress APIs, fires plugin hooks, schedules a rewrite flush, and invalidates both transient and object caches. Its reverse references are derived taxonomy strings in `term_taxonomy`, serialized `_product_attributes`, variation meta *keys*, and `wc_product_attributes_lookup`, not scalar columns containing the row's numeric `attribute_id`. The v1 guard grammar cannot express those references and the table cascade grammar cannot reproduce the semantic delete, so the WooCommerce adapter deliberately does **not** declare `table:woocommerce_attribute_taxonomies`; capture, offline compilation, plan, and `apply --with-deletes` all fail closed for that selector.

### Options — `state/options/core.json`

Versioned option-record document. Every exact option classified authored (plus the three managed code-half options) has a record, so deleting a JSON key is invalid rather than ambiguous. Dynamic families have records for discovered canonical names only. A record has exactly one of three states:

- `absent`: no portable value and no deletion intent; apply leaves a target row untouched.
- `present`: carries the lossless canonical `value` and the row's exact `autoload` storage flag.
- `deleted`: durable destructive intent carrying the sha256 `expected_hash` of the prior `present` record. In v2 it may also carry a hash-bound `classification_witness` when the current interpreter rule explicitly declares `deletion_witness: true`.

```json
{
  "format": "wprism-options/v2",
  "records": {
    "blogdescription": {"state": "present", "autoload": "yes", "value": ""},
    "blogname": {"state": "present", "autoload": "yes", "value": "WPrism Demo"},
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

`wprism-options/v2` adds the optional `classification_witness`. It is not desired option data and apply never writes it. It contains the prior record's exact `autoload` and `value`, and its reconstructed `present` record must hash to the tombstone's `expected_hash`; malformed or independently edited witnesses fail compilation. Repository authorization builds interpreter context from present values, deleted-name presence, and valid witnesses, then asks the currently pinned interpreter to classify the tombstone normally. The witness is emitted only when that prior rule explicitly returns `deletion_witness: true`, allowing a shadow-key interpreter to retain its minimum schema pointer without retaining the deleted authored payload. A bare provenance or `authored: true` assertion is never trusted. `wprism-options/v1` remains the canonical encoding for documents that need no witness, preserving their existing hashes; capture selects v2 exactly when at least one record uses the v2 field. Both versions remain accepted for reads.

Every manifest surface that can author a whole option row (`options`, an authored `option_patterns`/`option_name_refs`, or a rule with authored `sub_keys`) must declare autoload storage semantics. A rule may give an exact supported value, or the manifest/site policy may declare `option_autoload: "preserve"` to authorize capture and replay of the source row's exact value. Missing declarations block policy load; create and update both write the canonical flag, never a WordPress/version-local default.

Ref-typed values inside `present.value` are tokenized per the core manifest. The core exact set includes `blogname`, `blogdescription`, `show_on_front`, `page_on_front`, `page_for_posts`, `sticky_posts`, `default_category`, `posts_per_page`, and `wp_page_for_privacy_policy`:

```json
{"state": "present", "autoload": "yes", "value": ["{{post:0198b0c7-...}}"]}
```

Exact option rules remain sufficient for fixed names. A plugin with dynamic or evolving names declares discovery ownership separately with top-level `"option_namespaces": [{"match": "^plugin_prefix_"}]`. Capture enumerates every live `wp_options.option_name` in that namespace on every run, independent of the provenance journal. Each match must resolve through the owning manifest's exact `options` rule, one of its `option_patterns`, or an explicit site override; otherwise it is pending and capture blocks. An authored `option_patterns` rule therefore captures a dynamic family, while runtime/derived/env families are enumerated and deliberately excluded. Overlapping namespace claims and cross-manifest classifications refuse rather than depending on pin order. Names outside all declared namespaces are not guessed to belong to a plugin.

Term-meta is enumerated for every in-scope term. In spec v2, an `authored` key
is captured into the term file's `meta` object and apply reconciles that owned
key set, including deletions; scalar and structured reference declarations use
the same token machinery as post meta. An unclassified key blocks capture,
while explicit `runtime`/`derived`/`env` (or managed) rules remain target-local.

A manifest `taxonomy_patterns` entry may declare `object_keyspace` (`post` or `term`), `object_type`, and `update_count_callback`. `object_keyspace` says which WordPress identity space owns `term_relationships.object_id`; `object_type` only narrows post types inside the `post` keyspace. These are the version-pinned registration contract for a dynamic taxonomy that a typed-snapshot table creates after WordPress's `init` hook has already run. Apply uses the live registered taxonomy whenever it exists; only in that same-request timing gap may it construct the equivalent taxonomy contract from the manifest and invoke the declared callback. A missing, mixed, contradictory, or non-callable contract refuses instead of inferring ownership from a plugin sentinel or falling back to a generic SQL count.

An exact manifest `taxonomies.<name>` rule or a `taxonomy_patterns` rule may also declare `"object_keyspace": "post"|"term"`. It identifies the object-id namespace for that taxonomy's `wp_term_relationships` rows: post relationships belong in a post file's `terms` map, while term relationships belong in a term file's `relationships` map. Every exact or matching pattern declaration for one concrete taxonomy must agree; invalid and ambiguous values refuse at manifest load or resolution before a relationship query or mutation. Omission preserves the legacy `post` default only for a runtime post-only taxonomy. A runtime `object_type` of `term` requires an explicit `term` declaration; a mixed post/term registration refuses even when declared because one enum value cannot describe both owners. The engine never treats a plugin's literal `term` object-type string as an implicit wire-format contract. This declaration changes no canonical file grammar or existing canonical bytes.

### Custom tables — `state/tables/<table>/<uuid>--<slug>.json` (spec v0.10)

Typed snapshot: capture/apply for **authored custom tables** (DESIGN.md §3.3's middle tier — task #75; primary fixture `nf3_forms`/`nf3_fields`/`nf3_actions`, secondary `woocommerce_attribute_taxonomies`). A manifest's `"tables"` section declares each table as one of two classes; anything else stays the pre-existing honest-intent marker `authored_typed_snapshot_post_v1` (declared, loudly not yet captured):

- **`authored_snapshot`** — a row table with identity of its own. Declares `pk`, `id_kind` (a new typed keyspace in `wprism_map`; ≤32 chars, unique across manifests), optional `slug_column`, `columns{}` (every non-pk, non-ref column individually classified `authored`/`runtime`/`derived`/`env` — the post-meta discipline generalized to columns), and `refs[]` (`{"column", "kind"}` FK columns resolved through the ledger). A declared `slug_column` must name a non-empty `authored` columns entry; its value supplies the human-readable filename suffix. Without one, capture uses the stable literal `record`, never the environment-local primary key (the UUID prefix already guarantees uniqueness). Readers continue accepting legacy `<uuid>--<local-id>.json` paths; the next capture atomically normalizes them to `--record`. **Every live column must be accounted for** by exactly one of pk / refs / columns — an undeclared column refuses capture loudly, whether or not it holds an id this round (finding #8's FK rule made absolute).
- **`authored_snapshot_meta`** — an EAV sidecar with no independent identity: `attached_to: {table, column}`, `key_column`/`value_column` (plus `legacy_key_column`/`legacy_value_column` where the plugin double-writes a back-compat pair), a `keys{}` map for exceptional runtime/ref keys, and a `default_class` for the remainder. An adapter can bound that default with `"keyspace": {"version_range": {"min": "1.2.0", "max": "2.0.0"}, "keys": ["known_key"], "patterns": [{"match": "^known_family_"}]}`. Once declared, `default_class` applies only inside that keyspace: a live key outside the exact/pattern set is reported by `wprism pending` and refuses capture with the table, owner candidate, row count, representative value shapes, and pinned range. This is the plugin-upgrade tripwire; an open-ended default cannot silently absorb a newly introduced setting. Sidecar rows fold into the owning row's file as its `meta` map and reconcile as an owned key-set on apply, exactly like postmeta; they get no file, no uuid, and no `wprism_map` entry of their own.

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

- **Identity** lives in `wprism_map` (`id_kind`, `local_id`) — a plugin's table never gets a `_wprism_uuid`-style column added (plugins stay unmodified). Three declared modes: `"identity": {"mode": "mapped"}` (default; fresh source rows mint UUIDv7; populated target rows without mappings block, and a source with canonical UUIDs but missing mappings blocks); `"identity": {"mode": "natural_key", "column": "<col>"}` (for a human-chosen unique column: UUIDv5 of `"<table>:<value>"` is deterministic **bootstrap** identity for a never-seen row, while an existing `wprism_map` entry is **continuity** identity thereafter); and `"identity": {"mode": "composite_ref", "columns": ["<ref-col-1>", "<ref-col-2>"]}` for a pure join table whose identity is derived from the two referenced entities' UUIDs. Contradictory mappings never rebind implicitly. Composite-ref ledger rows are recoverable bookkeeping because the referenced UUID tuple remains the identity truth. Changing a mapped `natural_key` value is an ordinary update/file rename and retains the UUID already assigned through the ledger; `UUIDv5(current key) != retained UUID` is therefore expected after a rename, and capture/plan report it as an informational note. WPrism never forces re-derivation. A fresh environment that independently captures the already-renamed live row without the ledger or repository history derives UUIDv5 from the new key and therefore gets a different UUID; the existing table adopt flows are the reconciliation path for that documented bootstrap/continuity boundary.
- **Parent-scoped natural keys** (spec v1, issue #3318): `"identity": {"mode": "natural_key", "columns": ["<col>", ...]}` is the same mode for a key that is unique only *within* a parent row — a slot code unique per room, an option key unique per form. `column` is exactly its one-component case, and for a **scalar** component its derivation string is frozen unchanged (`"<table>:<value>"`) — which is the whole installed base: every UUIDv5 ever minted by a shipped manifest came from a scalar single-column key, and none of them moves. Two or more components derive from `"<table>:<col>=<component>:<col>=<component>"` in **declared order**, so reordering `columns` is an identity change, not a formatting edit. A component that names a declared `refs[]` column contributes the **referenced row's own UUID**, never the local id in the column (the same portability argument `composite_ref` makes: an auto-increment parent id would mint a different UUID per environment for one authored fact); a scalar component contributes its raw value. That ref rule applies to a one-component key too, where it is strictly new behavior rather than a change: no shipped manifest has ever declared a `natural_key` over a ref column, so there is no derivation to keep frozen there and the portable spelling is the only one this engine has ever produced. Unlike `composite_ref`, `pk` stays required — the table keeps its surrogate primary key, `wprism_map.local_id` stays that plain scalar, and delete/adopt/`invalidate` are unchanged. Every component must be a declared `refs[]` column or a declared `columns{}` entry, may not be the primary key, and may not repeat; a multi-component key must declare `slug_column` (a tuple has no portable one-line filename spelling). Adoption resolves the tuple against the target — scalars literally, ref components through the ledger, and on a ledger miss through the referenced row's *own* natural key in the same revision — so a pre-existing unmanaged child row is adoptable exactly as a single-column natural key already is, including on a target where the parent row is itself still unmanaged-but-adoptable (a whole plugin hand-provisioned before its first apply). A ref component naming a post/term parent is not resolved that way: slug adoption is the post/term collision path, and identity for a table row never reaches across into it.
- **Refs are structural at the row level** (an unmapped non-zero row ref throws, the `post_parent` category) but **optional at the sidecar level** (an unmapped meta-value ref drops with a warning, the ordinary dangling-reference category) — the two severities the dangling-reference rule below already implied but never had to distinguish.
- **`invalidate`** — declarative per-row cache invalidation run with apply, no plugin PHP in the engine: `[{"table": "nf3_upgrades", "column": "id"}, {"option_pattern": "nf_form_{id}"}, {"cache_group": "pmpro_membership_level_meta", "cache_key": "{id}"}]`, where `{id}` substitutes the row's resolved local id (raw deletes fire no hooks, so no canary carve-out). The third verb is the object-cache one (WP-6.2) and is feature-gated: `{id}` may sit in either member, so `{"cache_group": "object_{id}", "cache_key": "lookup_table"}` is the same verb, and the engine proves the drop with a readback rather than trusting the delete's return. It is admitted only for a manifest declaring `invalidate-vocabulary/v1` in `engine_features` (§ v3.15). Blanket (non-row-keyed) caches — where neither member carries `{id}` — are still refused here and use the top-level `actions` channel instead (a closed native action such as `transient.delete`, or a plugin-owned provider capability — see "Structured rebuild actions and providers" under the manifest registry format).
- `block_attrs` rules may name a declared table's `id_kind` as their ref kind (`ninja-forms/form`'s `formID` → `{{nf3_form:<uuid>}}`); `wp wprism lint` scans `tables/*/*.json` like any other canonical state; `apply --adopt-by-slug=tables` adopts matching pre-existing env rows (one shared `tables` adopt key for all declared tables — a table entity's *type* is the table name).

- **Option-name-embedded refs** (spec v0.12, task #93): a manifest may declare `"option_name_refs": [{"match": "<regex with a required named group 'id'>", "malformed_match"?: "<regex for would-be names with an invalid id>", "id_kind": "<declared table id_kind>", "class": "authored", "json_refs"?: [...], "key_refs"?: {...}}]` for options whose NAME (not value) embeds another declared table's local id (WooCommerce's `woocommerce_<method_id>_<instance_id>_settings`). A sibling of ordinary `option_patterns`, not a variant: namespace-backed `option_patterns` classify/capture the real option name as-is, while `option_name_refs` drive their own discovery because the canonical key must replace the matched local `id` group with a portable token. `match` must admit only canonical positive decimal ids; a would-be namespace that matches `malformed_match` (for example a leading-zero or zero Woo instance id) is refused rather than disappearing because a stricter `match` did not select it. All consumers resolve the complete rule set together: a live/canonical name matching more than one rule, including rules for different id_kinds, is ambiguous and refused; pin/declaration order never selects a winner. The captured canonical KEY splices the resolved ref TOKEN into the exact byte position of the matched `id` group — `woocommerce_flat_rate_{{wc_zone_method:<uuid>}}_settings` — using the existing token grammar unchanged. Apply detects a token-bearing option KEY and detokenizes it BEFORE ordinary option-rule dispatch, unconditionally — this ordering is load-bearing: skipping it silently writes a real `wp_options` row whose NAME contains literal `{{...}}` bytes. The resolved real name is re-matched against the same patterns to recover the rule governing its VALUE, which routes through structural capture/apply unconditionally, so an array-shaped settings blob gets its string leaves URL-tokenized and deep secret-scanned with zero per-plugin special-casing.
- **Severity model for name-embedded ids** (mirrors task #73's dangling-vs-unscoped split, with one structural difference stated precisely): an id resolving to no row anywhere is ordinary dangling (warn + drop). An id naming a row that exists in its declared table but has no minted uuid is loud/blocking *only* when the capture is MINTING (`Capture::run()`, never `Capture::snapshot()`) — a declared table's rows are already minted unconditionally by the table-capture pass earlier in the same build, so a declared-but-unresolved row can only legitimately arise on a non-minting snapshot (plan's drift check), which is exactly the correctly-scoped-but-unminted false-positive class task #73's own fix documents (`default_category` on a never-captured fresh install); it falls through to warn-and-drop. Stated plainly: the loud branch is a defensive invariant guard, not a routinely-reachable scenario.

Not covered: a natural key whose components span more than one table, and `composite_ref` with more than two columns. `composite_ref` stays deliberately narrow — exactly two structural ref columns forming a pure join identity, where the row *is* the fact; a table that has its own primary key plus a parent-scoped authored key uses `natural_key` with `columns` instead.

### Identity disaster recovery

Mapped custom-table UUIDs are promotion metadata and must travel with the database backup. Immediately after taking a quiesced database backup, run:

```sh
wp wprism identity-export --repo=/path/to/site-repo --out=/secure/backup/site.identity.json
```

The `wprism-identity-ledger/v1` sidecar contains sorted identity mappings (including ledger-only widget instances), three-way sync hashes, the applied revision, and the exact compiled repository/site/manifest hashes. It contains SHA-256 row witnesses rather than plugin/widget-row contents. Keep it beside the matching database backup; it is environment-bound recovery material, not canonical state and should not be committed to the site repository.

After restoring that database and checking out the exact repository revision, restore identity before `plan`, `apply`, or `capture`:

```sh
wp wprism identity-import --repo=/path/to/site-repo --in=/secure/backup/site.identity.json
```

Import verifies the artifact integrity hash, repository and manifest association, every embedded post/term UUID, every mapped-table row witness, tuple uniqueness, and any already-present ledger subset before one transaction restores `wprism_map`, `wprism_state`, and `applied_revision`. A stale database/sidecar pair, changed row, conflicting map, tampered artifact, or wrong repository blocks without partial rebinding. A populated mapped-identity table without this metadata also blocks; there is no force flag because no deterministic adoption key exists.

## `site.wprism.json`

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

The `spec_version` field is the **wire-format grammar version**: an integer a repository declares that must lie inside the running engine's **acceptance window {N-1, N}**, where N is its `WPRISM_SPEC_VERSION` (currently `3`, so the window is `{2, 3}` — see § v3.1 and "Adapter compatibility contract" below). It is deliberately NOT this document's own draft-history label — the "spec v1"/"spec v0.x" markers used throughout, and in this file's title, only record *when* a rule was introduced.

This was exact equality until the v3 flip, and the change is the repository half of § v3.1's rule rather than a relaxation of it. Exact equality is invisible while the engine version never moves and becomes a fleet-wide event the moment it does: every repository in the field declares the version of the agent that adopted it, so a bump would refuse compilation on every deployed site at once, on this one field, with no remedy but a hand edit per repository. The window makes the flip survivable in the same way it makes a manifest's version survivable, and `RepositoryCompiler::compile()` reads it from the one definition the manifest grammar uses (`SpecVersionWindow`), so the floor is the same floor `make release-gate` holds to exactly N-1.

An absent or non-integer value keeps its own older refusal — it is not a version, so there is no window for it to be outside of — and a value outside the window is refused at load, before any target contact, naming the window. Re-stamping a repository UP to N is a one-way act: the N-1 agent's window is {N-2, N-1}, so a repository at N no longer compiles on the engine a rollback would restore. `docs/guides/flag-day.md` files that with the acts gate G3 forbids.

`policy` holds site-local classification overrides (same shape as manifest rules); it wins over manifests. `manifests` pins which registry manifests apply (agent looks them up across its three installed adapter sources: its embedded `agent/adapter-library/`, this repository's `adapters/` source, and one `wprism-adapter.json` at the root of each ACTIVE plugin that bundles one). The two sources the operator authors — shipped and site — still refuse outright if both could answer one name, so there is no precedence order to learn between them. A PLUGIN-bundled name that a shipped or site definition already answers to is a different case and is resolved rather than refused: sources rank `shipped > site > plugin`, the reviewed definition wins, and the bundled one is reported on every run as an installed-but-not-loaded row naming its winner. See "Out-of-tree adapter sources" and "Plugin-bundled adapters" below. A pin may remain the historical name string or use `{"name":"…","digest":"<sha256>","source":"shipped"|"site"|"plugin"}`. The object form is optional and content-addressed: load computes the same per-manifest digest recorded in compiled artifacts' `resolved_adapters` (including a declared interpreter's name and bytes) and refuses a mismatch before any policy consumer or target contact, naming the manifest plus expected and actual digests. `source` is likewise optional and likewise a refusal rather than a preference: a pin that names which adapter source must answer it refuses when a different source does, so removing a site-installed adapter can never silently hand its name to a later shipped one. An unknown pin key is refused outright rather than ignored. `{"name":"core"}` without `digest` or `source` is also equivalent to the legacy string form; adding these mechanisms does not force existing repositories to migrate.

`wp wprism manifest-pin --name=<name> [--repo=<path>]` validates the installed manifest and prints the exact canonical `{name,digest,source}` object for copy/paste into this array. `--repo` is what makes a site-installed adapter pinnable; without it only the shipped library is searched. The requested name is always passed as an explicit pin, so the repository's own (possibly stale) `manifests` array is never resolved and a stale pin cannot prevent calculating a reviewed replacement after an intentional manifest update. Updating the pin is an explicit review act; it is never automatic.

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
contract. Symlinks and paths outside those three roots are refused. The WPrism
agent's own `mu-plugins/wprism/` directory and `wprism-loader.php` are protected and
remain out-of-band for this first version, so a deployment cannot replace the
agent executing it.

The payload does not own WordPress core, `wp-config.php`, uploads, caches,
drop-ins, language packs, or unrelated `wp-content` directories. Finalization
may remove only paths recorded as WPrism-owned by an earlier successful code
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
canonical active plugin/theme ⊆ (vendored ∪ resolved locked) ⊆ verified target payload
```

For a format-1 repository the middle term is exactly the vendored payload, and
this reads as it always has. Format 2 (below) splits that term without weakening
it: a locked component satisfies the invariant only once its bytes are present
and hash-match the lock, which is what the compile gate refuses on.

The completed marker is not trusted by itself. Plan/apply revalidate its stored
descriptor and the managed target bytes (including unexpected regular files
inside an owned component); a same-version PHP edit is therefore stale code,
not a clean environment. Recovery is the host `wprism deploy <env>` path. Generic
force flags cannot authorize state apply while this descriptor proof is stale.

### `code` format 2 — the declared split

Format 2 adds exactly one key. Everything else about the code half is unchanged:

```json
{
  "code": {
    "format": 2,
    "layout": "wp-content",
    "lock": "code/wprism-code.lock.json",
    "source": "code/wp-content"
  }
}
```

`lock` may name only `code/wprism-code.lock.json` in this version. Format 1 remains
readable as the legacy, unenforced shape (every byte in Git, nothing declared);
`wprism init` produces format 2 for every site that has a plugin or theme
component, and `wprism code-classify` moves an existing repository to it. The
invariant format 2 exists to enforce is that **Git never carries third-party
code**: every plugin and theme component under `code/wp-content` is either a
locked component Git does not carry, or a first-party component Git carries by
the operator's explicit declaration. There is no third classification.

The lock is the sourcing declaration for both: `components` names what Git does
NOT carry and where it comes from; `first_party` names what Git carries by
declaration. It lives outside `code/wp-content`, so the descriptor never
inventories it and no lock byte ever reaches a target.

```json
{
  "format": "wprism-code-lock/v2",
  "components": [
    {
      "root": "plugins",
      "component": "acme-premium",
      "version": "1.4.2",
      "origin": {
        "kind": "imported-archive",
        "archive_sha256": "<64 hex>"
      },
      "tree_sha256": "<64 hex>"
    },
    {
      "root": "plugins",
      "component": "woocommerce",
      "version": "11.0.0",
      "origin": {
        "kind": "wp-org-release",
        "url": "https://downloads.wordpress.org/plugin/woocommerce.11.0.0.zip",
        "archive_sha256": "<64 hex>"
      },
      "tree_sha256": "<64 hex>"
    }
  ],
  "first_party": ["plugins/acme-site", "themes/acme-child"]
}
```

`root` is `plugins` or `themes`; `component` is one safe path segment; entries
are deterministically sorted by `(root, component)` and canonically encoded.
`origin.kind` is `wp-org-release` (with an `https://` `url`, the canonical
downloads.wordpress.org archive) or `imported-archive` (an archive the operator
imported into the host's content-addressed code-artifact cache with
`wprism code-import`, identified by `archive_sha256` alone — no URL, because a
vendor download link is usually license-keyed, and no path, because the
repository carries no copy), each carrying `archive_sha256` and optionally
`archive_root` — the directory inside the archive that holds the component.
Both digests exist because a published version can be re-packaged and a ZIP
digest is not a tree digest: `archive_sha256` says what to fetch or read,
`tree_sha256` says what the unpacked component must hash to. `first_party` is a
sorted list of unique `<root>/<component>` identities, none of which is also a
locked component. `wprism-code-lock/v1` (components only, no `first_party`) is
still read; its `vendored-archive` origin — a ZIP committed inside the
repository, third-party bytes in Git by another name — is refused by name with
the remedy (`wprism code-import` it on the host and re-lock with
`wprism code-classify`).

`tree_sha256` is the sha256 of the canonical, path-sorted `{path, sha256}` rows
of that component's subtree — exactly the rows compilation already inventories,
with each path made relative to the component root instead of to
`code/wp-content`.

Resolution — turning an origin back into bytes — happens strictly BEFORE
compilation and never on the target. The lock is a precondition gate, never an
indirection the descriptor follows, so `code_revision`, `artifact_hash` and the
staged-payload proof keep meaning exactly what they mean for format 1. A
repository that migrates from format 1 to format 2 without moving a byte
compiles to the identical `code_revision`.

Compilation raises four blocking, non-forceable diagnostics:

| diagnostic | condition | remedy |
| --- | --- | --- |
| `code_component_unresolved` | the lock declares a component and the repository carries none of its bytes | `wprism code-resolve <env>`, which `wprism deploy` and `wprism promote` also run themselves before compiling |
| `code_component_digest_mismatch` | the component is present but hashes to something other than `tree_sha256` | re-materialize the locked release, or re-lock the bytes if they are the intended ones |
| `code_component_unlocked` | the repository-root `.gitignore` excludes a component under `code/wp-content` that the lock does not declare | declare it in the lock, or remove the ignore line |
| `code_component_undeclared` | the repository carries a plugin or theme component that is neither a locked component nor in `first_party` | declare it first-party with `wprism code-classify --first-party=<root>/<slug>` if it is the site's own code; otherwise `wprism code-import` its archive on the host and re-lock it with `wprism code-classify` |

The third is answered without invoking `git`: only a literal, root-anchored
`/code/wp-content/<root>/<component>/` line in the repository-root `.gitignore`
counts. That file is the only supported placement, and the code half enforces
the asymmetry itself — a `.gitignore` at `code/wp-content/` is refused as an
unsafe payload path, and one at `code/wp-content/plugins/` is inventoried as an
owned component file and shipped to the target.

Composer resolution, full-webroot/core ownership, controller-built SSH
artifacts, and atomic release-directory swaps are later build/deployment modes
that must emit this same descriptor contract. Format 2 delivers the DECLARATION
and the compile-time gate, first-run classification (`wprism init`) and
re-declaration (`wprism code-classify`) on the orchestrator host, the import of
archives with no registry (`wprism code-import`, host-side, into the same
content-addressed cache wp.org releases are fetched into), and the resolver
(`wprism code-resolve`, which `wprism deploy` and `wprism promote` run as a phase before
compiling). Resolution is host work: the target never fetches, and an
imported archive resolves only on a host where it was imported — WPrism never
downloads from a vendor.

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
recovery deletion is narrower: an exact user-MU file absent before WPrism first
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
sync when no `wprism_state` base exists. Plan/status renders the receipt as the
non-forceable `incomplete_lifecycle` bucket. Recovery requires external writer
exclusion, restoring code to the known pre-promotion revision, and the retained
checkpoint's exact abort → original begin → isolated import → final abort
sequence.

Control-plane commands load only the protected WPrism agent after `wp-config.php`
has been read and before user MU/plugin/theme code. The v0 layout contract is
the standard `wp-content/mu-plugins` tree without explicit `WPMU_PLUGIN_DIR` or
`SUNRISE`; any configured variant refuses during compile before a checkpoint or
target write. A future custom-layout transport must provide an explicit trusted
agent/content-root mapping rather than weakening this proof with guessed paths.

### `envs` (optional)

`site.wprism.json` may declare an `"envs"` object, keyed by environment name, describing the environments that materialize this site repo for the `wprism` orchestrator CLI (see [cli/README.md](../cli/README.md)). Each entry names a `transport` (`local`, `docker`, or `ssh`) and a `repo_path` — this site repo's path *as seen from inside that environment*. Entries here are shared via git and must contain no secrets; anything machine-specific or sensitive belongs instead in a gitignored, machine-local `.wprism-envs.json` overlay next to it, which replaces same-named entries whole. `envs` is orchestrator convenience, not part of the branchable state contract — the agent's `wp wprism …` commands (this spec's actual subject) never read it.

Host provisioning remains outside the repository format. In particular,
`environment_provider` is a privileged, machine-local `.wprism-envs.json` field
and is rejected when it originates in checked-in `site.wprism.json` or an
auto-discovered, Git-tracked `.wprism-envs.json`. An explicit `--envs-file` is an
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

## Adapter manifests (package format)

**`rewrite.flush` fresh-process correction (issue #3509):** the later structured-actions paragraph's sentence saying this action reinitializes the loaded apply runtime records the first offline-green design and is superseded for this action. WordPress assembles post-type, taxonomy, endpoint, and plugin rewrite registrations during bootstrap; live dirty-target evidence proved a late `WP_Rewrite::init()` can retain permastructs built under the old front while clearing unrelated registration buckets. `rewrite.flush` therefore launches a fixed engine-owned `wp eval` child with no manifest-controlled command or argument. That child boots after the authored option commit, executes `flush_rules(false)`, compares the generated native representation with a checked database read, and emits only the closed `wprism-rewrite-flush-fresh/v1` hash/count/type envelope. Pretty permalinks produce an ordered rules array; plain or absent permalink structures produce WordPress 7.0.3's exact empty-string `rewrite_rules` sentinel. The parent validates the exact type plus hash/count and independently checks the durable row; a nonzero exit, malformed evidence, or any child warning refuses with `recovery_required`. It never requests a hard flush, so `.htaccess`/web.config remain target-owned. Ordinary native actions and providers remain in-process; a manifest-owned provider may instead opt a site-scoped capability into the fixed engine protocol specified under **Fresh-process manifest-provider capability** below. Process creation is never an adapter implementation detail.

Everything in this section is refusable offline: `wprism manifest-validate <adapter-library> [--site=<site-repo>]` drives these same validators with no WordPress, database, or environment present, and `wprism manifest-validate --emit-schema` prints the closed VALUE vocabularies and the named subset of bounded patterns below as a versioned JSON document read out of the engine itself (`wprism-manifest-grammar/v2`, which additionally publishes `spec_window` — the `spec_version` integers this engine accepts, MEASURED by probing the shipped refusal — and `top_level_keys`, the signer's own closed partition with the two places it does and does not refuse), with a `coverage` field naming what it omits — see [docs/guides/adapter-authoring.md § Checking the grammar offline](../docs/guides/adapter-authoring.md#checking-the-grammar-offline). The argument is normally the source tree containing `adapter-packages/` and `platform/adapter-library/`; an explicitly selected legacy flat library is a historical import/validation surface, never the installed runtime fallback. `--site` matters because two guards (the ref/token/ledger kind vocabularies, and conflicting option rules) read `site.wprism.json`'s policy half as input, so without it a manifest valid on its real site can be refused. It is an authoring aid, not a gate, and it reports the checks that need a live target — plus that missing site half — as explicitly deferred.

`adapter-packages/<name>/package/manifest.json` in the source tree (core instead lives at `platform/adapter-library/core/manifest.json`; adoption embeds both under `agent/adapter-library/`):

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

- **Exact plugin incompatibility** (§ v3.24): a plugin adapter declaring
  `plugin-incompatibility/v1` may add a sorted, non-empty, duplicate-free
  `incompatible_plugins` list of exact plugin basenames. If a pinned manifest
  claims one of those basenames, policy load refuses deterministically in both
  pin orders before compilation, capture publication, promotion, lifecycle,
  or provider work. One side's declaration is sufficient; this is a
  non-surface contract boundary, not load-order precedence.
- **Host-checkpointed schema settlement** (§ v3.23): a provider action may
  declare `phase: "schema_settle"` when the manifest declares
  `schema-settlement/v1`. Its sorted, non-empty `prepares` list names tables
  owned by that manifest; `readiness` names a distinct read-only site
  capability; and its restorable database-checkpoint effects exactly cover
  those tables. The strict observer still refuses absent schema. Only host
  `wprism deploy` may run the phase, after an authenticated checkpoint and
  under a durable ordered provider intent that ordinary policy load fences
  until completion or exact recovery.
- `"interpreter": "<name>"` — schema-driven classification: the named interpreter is consulted per (meta key, the entity's full meta map) *before* static rules — for plugins whose meta semantics live in data (field-group definitions), not in a static key list. **Interpreter code is part of the adapter artifact, never the engine**: the name resolves within the same capsule to `package/runtime/interpreters/<name>.php`, which must define `\WPrism\Interpreters\<Name>` with `post_meta_rule(string $key, array $allMeta): ?array`; it may additionally define `term_meta_rule(...)` and `user_meta_rule(...)` with the same signature and nullable-defer semantics. The optional hooks do not widen older post-only interpreters: when absent, the corresponding static `term_meta`/`user_meta` rules retain control. Interpreter code ships, versions, and pins together with its manifest JSON. (Trust boundary: the installed `agent/adapter-library/` deploys with the agent itself, so loading its code is the same trust decision as running the agent.)
- `"user_meta": {"<key>": {"class": "runtime|env|derived|authored", "missing_user": "block|warn", "allow_pii": false}}` mirrors the static post/term-meta classification vocabulary without making users repository entities. `runtime`/`env`/`derived` are target-local dispositions. `authored` uses the exact-login sidecar above; `missing_user` is user-meta-only and defaults to fail-closed `block`, while `allow_pii` is an explicit reviewed exception to the recursive PII gate. Interpreters follow the same contract through `user_meta_rule()`.
- `"post_types": {"acf-field": {"class": "authored", "body": "serialized", "phase": "early"}}` — body mode `serialized` requires canonical, bounded, class-free PHP plain data; capture tokenizes every string leaf and re-serializes it, so home/uploads rebinding cannot corrupt serialized byte lengths. Malformed, trailing, object/reference-shaped, over-depth, and secret-shaped bodies refuse at capture and compilation. `verbatim` remains the opaque byte-preserving mode and never re-binds URLs. `"phase": "early"` makes the type finalize before all others in apply phase 2 — for definition CPTs whose content interpreters read to type other entities' meta (declared ordering, never glob luck).
- `"post_types": {"product": {"fields": {"modified": {"class": "derived"}, "modified_gmt": {"class": "derived"}}}, "product_variation": {"fields": {"title": {"class": "derived"}, "modified": {"class": "derived"}, "modified_gmt": {"class": "derived"}}}}` (spec v0.11, extended in v2) — per-post_type classification for **post fields**: the ~13 keys every post file carries unconditionally (title, slug, status, dates, parent, menu_order, …), distinct from post_meta/options, whose rules already carry `class`. v2 accepts exactly `"title"`, `"modified"`, and `"modified_gmt"` with `"class": "derived"`; Policy validates field name and class at manifest load and throws on anything else. `slug` participates in file names and Apply's collision/identity checks, while status/date/menu-order/comment/excerpt fields have no reviewed derived precedent, so they stay unsupported rather than half-supported. Manifest-only, no site-policy override (`policy.post_types` is already the flat scope list; a rule map under the same key would collide — the `body`/`phase` precedent). Semantics: capture always writes a derived field's current observed value into the file verbatim, never omitted — a human reading `state/` sees the truth even when it is not authoritative. Apply writes it once as a new row's bootstrap value on create and never overwrites it on update, preserving the plugin-owned target value. The field is excluded from the **hash basis** used by `wprism_state`, plan's three-way compare, drift detection, and compiled semantic comparison (`Canon::post_hash_basis()`) — never from the file itself — so two environments may honestly have different raw captured bytes for a derived field while remaining the same branch state. Proven cases: WooCommerce recomputes `product_variation.title` hook-free via raw `$wpdb` on load (tasks #72/#88), and WooCommerce-mediated stock/order saves advance `modified`/`modified_gmt` on both a stock-managed variation and its variable parent while direct environment-local stock-meta provisioning advances neither (issue #3302). The timestamp declaration is deliberately limited to `product` and `product_variation`; undeclared post types retain authored timestamp semantics.
- `"post_types": {"parent_cpt": {"children": ["child_cpt"]}, "child_cpt": {...}}` (issue #3315) — a validated, direct **parent → child** CPT relation. Every listed child name is a distinct WordPress post-type identifier and must be another `post_types` key in the same manifest; missing endpoints fail policy load (including frozen-policy reconstruction). The declaration maps the child row's single `wp_posts.post_parent` value to a row of the named parent type. A parent can therefore have zero or many declared child rows. The relation is structural and composable: `Policy::child_post_types()` and `parent_post_types()` return lexical, duplicate-free direct neighbors, while `post_type_relation_closure()` traverses both directions transitively so scoped operations can expand only declared parent/child dependencies rather than discover a target-wide hierarchy. A declaration never grants delete/cascade authority: normal tombstone capability and guard checks remain required. During derived-state rebuild, a parent deletion receipt may carry only direct declared children that are **also explicit tombstones** in that revision; undeclared types and target-local surviving children are not enumerated, deleted, or handed to an adapter as cleanup work. Malformed lists, invalid identifiers, self-relations, and duplicates fail policy load.
- Meta ref rules may declare `"cast"`: `"string"` (ids stored as strings inside serialized arrays — the ACF shape) or `"csv"` (a `"1,2,3"` id list canonicalized to a token array, re-joined on apply). Ref kind `"user"` serializes as `user:<login>` tokens — users stay env-local; apply resolves by login and falls back to the default author with a warning. The same login codec is legal for `block_attrs`; table refs, structured refs, and shortcode attrs still require a ledger-backed token kind.
- A meta/option/user-meta rule may declare `"plain_data": true` when its value is native PHP scalar/array data with no id-bearing positions but with portable strings nested below the root. Capture recursively tokenizes those strings and apply recursively re-binds them before WordPress serialization. It is mutually exclusive with `ref`, `json_refs`, `key_refs`, `cast`, and `json_encoded`; `order_preserving` and the ordinary secret gate still compose. This is not an opaque escape hatch: `PlainData` has already rejected objects, references, recursion, excessive depth, and malformed serialization before the codec runs.
- **Order-preserving values** (spec v0.15, task #123): a meta rule may declare `"order_preserving": true` for a value whose PHP array key order is semantically load-bearing — canonical JSON's own `ksort()`-at-every-level rule (the "Entity-per-file, deterministic serialization" line above) is only safe when no plugin reads a value's raw iteration order, and WooCommerce's variation-title generator (`WC_Product_Variation_Data_Store_CPT::read()`) reads the parent's `_product_attributes` array order directly — canonicalization was permanently reordering it on every applied target, a real (not timing-based) divergence. A declared value's key order — at every nesting level inside it, recursively — is captured and round-tripped exactly as WordPress held it, instead of being alphabetized; declaring it changes nothing else about the rule (it composes with `ref`/`json_refs`/`key_refs`/`cast` normally, applied to the fully-processed value). Scoped strictly to the declared value: every *other* key in the same document, including sibling meta keys, still sorts alphabetically as normal — this is not a document-wide behavior change. No apply-side changes were needed: `json_decode()` and `maybe_serialize()` never reorder keys on their own, so the ordinary decode → detokenize → re-serialize path was already order-preserving by construction — the capture-time `ksort()` was the only place order was ever lost. `adapter-packages/woocommerce/package/manifest.json`'s `_product_attributes` is the first declared user.
- **Structured rebuild actions and providers** (issue #3338; supersedes the retired free-form `rebuilders` channel, whose command-string entries — including `wp eval` payloads — the engine now refuses at manifest load): the hooks apply deliberately skips are also what maintain plugin derived state (indexables, lookup tables, blanket caches), so a manifest declares the repair as data in a top-level `"actions"` list. Each entry is `{"kind": "native"|"provider", ..., "triggers"?: [...], "effects"?: [...]}` — `triggers` normally uses the same exact canonical-surface grammar apply projects from authored work (`(post|term|table|option|entity):<name>`; absent = unscoped, selected for any non-empty surface set; an empty surface set — a read-only apply — fires nothing). A manifest declaring `post-kind-action-trigger/v1` may also use the bounded `post:*` selector: it matches concrete `post:<type>` surfaces only, passes concrete kinds and ids rather than a wildcard to entity-scoped providers, and grants no term/table/option/`entity:` authority. It exists for plugin behavior that genuinely applies to every registered CPT, including site-defined types an adapter cannot enumerate. A context-bearing provider must still use exact post-type triggers because durable marker ownership is per concrete type. `effects` feeds the same bounded-reversibility inventory `rebuilders` entries fed (omitted = explicit irreversible fallback row). A `native` entry names an action from the engine's **closed vocabulary** (v1: `transient.delete`, args `{"name": <bounded string>}`, and argument-free `rewrite.flush`; both are WordPress-core semantics, identical for every adapter, executed by reviewed engine code with a checked-readback receipt). `rewrite.flush` reinitializes the loaded core rewrite runtime from the applied `permalink_structure` row and performs a soft database-only flush after `wp_loaded`; it never writes `.htaccess`/web.config and verifies the stored ordered rules against WordPress's own fresh read. Unknown action names, unknown arg keys, and mistyped args are refused at load, so a manifest can neither mint operations nor smuggle executable text through arguments. A `provider` entry names a capability of a provider declared in the SAME manifest's top-level `"providers"` list: `{"id", "version" (exact x.y.z), "source": "manifest"|"plugin", "plugin": <basename>, "capabilities": [...]}`. Provider ids are globally unique across pinned manifests (conflict = refusal; pin order never picks which code runs), and a provider's `plugin` must equal the manifest's own `plugin` claim when one exists, so the executable half stays inside the version window the declarative half was certified for. `source: "manifest"` resolves within the declaring capsule to `package/runtime/providers/<id>.php` defining `\WPrism\Providers\<CamelCase(id)>` — the interpreter/regenerator trust boundary: provider code ships, versions, digest-binds, and pins with its manifest, never with the engine: its bytes join the per-adapter digest beside the interpreter's (`RepositoryCompiler::manifest_rows()` and the `CapabilityRegistry::adapter_digest()` mirror of it), so a changed provider file is a changed adapter rather than invisible drift behind a stable manifest digest. A manifest-shipped regenerator (`post_types.<type>.regen_dependency.regenerator` → `package/runtime/regenerators/<name>.php`) is bound into the same row on the same terms since issue #3360 — one entry per distinct declared name, **sorted by name**: regenerator names are discovered by walking `post_types{}`, a JSON object whose key order canonical encoding normalizes away, so discovery order would let a semantically-void key reshuffle move a certified digest (unlike `providers[]`, a JSON array whose order is canonical content). Two regenerator implementations can no longer hide under one manifest revision. `source: "plugin"` is discovered from the installed plugin itself through the `wprism_providers` registry filter and is trusted as part of that plugin; its file is deliberately not digest-bound (the installed plugin is its identity anchor, checked against `version_range`). Before the first target mutation, apply **negotiates** every provider its selected actions reach — contract shape (`identity()`, `capabilities()`, `invoke()`), exact identity match against the declaration, owning plugin installed+active+in range, capability advertised with a well-formed declaration (`args` schema, `reads`/`writes` surface summary, `scope: "site"|"entity"`, `idempotent` — required `true`, since apply's retry machinery re-fires the rebuild pass — and a `timeout_seconds` budget), and manifest args valid against the capability schema — and refuses with per-problem remediation on any miss, so a missing or incompatible capability fails before destructive writes, never after commit. Invocation returns a receipt whose `verified` must be exactly `true` on the strength of a value-level readback (command-success-only verification is refused); `scope: "entity"` capabilities receive an engine-assembled batch of `{kind, id}` rows for the triggering surfaces. An adapter that needs no executable semantics simply declares neither key and remains purely declarative.
- **Manifest-owned provider runtime** (§ v3.22): a `source: "manifest"` provider may add `"contracts": {"<capability>": <capability declaration>}` when the manifest declares `manifest-provider-runtime/v1`. The map must be non-empty and its keys must exactly follow `capabilities[]`; plugin-sourced providers may not use it because independently shipped plugin code must still advertise its own executable contract. The provider class extends `WPrism\ManifestProviderRuntime` and implements only protected `invoke_<capability>(array): array`, `reconcile_<capability>(array): array` when scoped reconciliation is declared, and `project_<capability>(array): array` when handler projection is declared. Core owns identity, advertising, public dispatch, scoped receipt construction, recovery routing, and the `before`/`after`/`verified: true` receipt floor. The manifest-owned file still owns plugin calls and value-level verification, and its bytes remain in adapter identity. Providers, interpreters, and regenerators all cross one engine-owned loader whose descriptor comes from the exact adapter-identity row: it rechecks canonical source path, component/adapter digest, stable file identity, configured opcode-cache invalidation, expected defining file, and case-insensitive symbol occupancy before accepting the class. Core binds the validated capability map in a private weak registry keyed by that exact provider object; direct construction, cloning, and serialization do not transfer the binding and therefore cannot activate provider-SDK database read/write profiles. Direct runtime includes are not an adapter extension surface.
- **Fresh-process manifest-provider capability:** a manifest declaring `manifest-provider-fresh-process/v1` may add a sorted, unique, non-empty `fresh_process_capabilities` list to a `source: "manifest"` provider that already carries `contracts`. Every named capability must exist in both `capabilities` and `contracts`, be `scope: "site"`, be `idempotent: true`, and keep `timeout_seconds` within the engine's fixed WP-CLI ceiling. The provider additionally implements `observe_fresh_postimage_<capability>(array): array` and `project_fresh_postimage_<capability>(array): array`; it does not construct a command, parse stdin/stdout, manage a process, issue transaction-control SQL, or choose its own retry boundary.

  The engine freezes the validated policy into a bounded `0600` file and sends only one canonical, bounded request to each of two independent WordPress boots under one absolute deadline. The request binds artifact/site/manifest identity, the resolved adapter digest, current shipped disposition bytes, exact provider source bytes, owning plugin lifecycle/version, capability, and authored arguments. Before each boot the parent checks and flushes the persistent object cache; each child revalidates those identities before behavior. The first child mutates and projects its claimed complete postimage; the second invokes no provider mutation and runs the observer callback under canonical-`$wpdb` read-only isolation, independently projects the durable postimage, and the parent requires exact equality. Output, stderr, exit, timeout, parent death, malformed/noncanonical transport, identity drift, and unequal projections all refuse as `recovery_required`; the parent-death watchdog terminates the whole child session, including descendants.

  The observer's enforced isolation is specifically **database read-only through the canonical `$wpdb` transport**. The engine maps declared `option:*` surfaces to `wp_options` and declared `table:<logical>` surfaces to registered/prefixed physical tables, starts one consistent read-only snapshot, and refuses DML, transaction control, undeclared tables, query-hook replacement, and SQL outside the closed profile. This is not a filesystem, object-cache, network, or alternate-database sandbox. Those effects remain declaration/recovery obligations, and an observer must not use them as proof. Cache-backed helpers such as `get_option()` are not durable evidence: use the SDK's exact option reader, which performs a bounded size/hash preflight, rejects duplicate or collation-alias rows, and decodes serialized data with classes disabled.
- **Structured capability arguments and engine batch context** (issue #3369, extending the two grammars above): a capability `args` entry declares `{"type": "bool"|"int"|"string"|"list<string>"|"list<object>", "required": <bool>}`, and `list<object>` additionally declares `"fields": {"<name>": {"type": "bool"|"int"|"string", "required": <bool>}}` — a closed, per-capability row vocabulary with **exactly one level of nesting**: a field may not itself be a list or an object, so there is no depth a reviewer cannot state and no free-form payload channel. Field names use the argument-name charset; unknown keys in a `fields` declaration, an empty `fields` map, and a `fields` map on a scalar-typed argument are refused at negotiation, and unknown fields, missing required fields, mistyped fields, and non-object rows are refused in VALUES. The one-level bound is enforced twice, deliberately: `Policy` refuses a nested manifest argument at LOAD (where no provider code exists yet — a manifest argument is a scalar, a list of scalars, or a list of flat objects whose own values are scalars), and negotiation refuses anything the capability's own field vocabulary does not admit. Separately, a `scope: "entity"` capability may declare the optional key `"context": [...]` over the closed channel vocabulary `deletions`, `reparents`, `retry`, `always_on_write` (duplicates refused, empty list refused, unknown names refused, and the key itself refused on `scope: "site"`, naming the declared channels). Declaring channels changes what rides under the reserved `entities` argument: the value becomes `{"entities": [...], "always_on_write"?: <bool>, "deletions"?: [...], "reparents"?: [...], "retry"?: <bool>}` carrying only the declared channels in that fixed order, so an undeclared channel is ABSENT rather than empty and "nothing happened" stays distinguishable from "never asked for". `deletions` rows are `{kind, uuid, id, post_type, parent_id, child_ids}` (see the parity paragraph below) for the tombstones this run APPLIED (`--with-deletes`), that a previous incomplete apply had already made absent, or that an earlier incomplete apply left a durable receipt for — never one this run merely PLANNED: the pre-mutation selection deliberately projects surfaces from planned tombstones (a capability has to negotiate before the mutation), but an apply without `--with-deletes` gates every delete off and its entities are all still present, so handing them over as tombstones would be indistinguishable from real ones. `id` is the target-local id the ledger still holds, `0` once the mapping is gone (the expected shape when a previous run applied the delete) or when the entity kind has no single row id at all. `reparents` rows are `{kind, uuid, id, root_id, old_parent_id, new_parent_id}`, one row per derived root, which is how a chained move's accumulated roots survive a scalar-only field grammar; the engine captures a reparent receipt for post types with a batch `regen_dependency` OR whose canonical surface a `reparents`-declaring capability in this run's selection triggers on, and the channel unions this run's captures with the durable `regen_reparent_context:<uuid>` markers an earlier incomplete apply left outstanding (the retry case has no fresh capture at all). `retry` is the apply's own incomplete-retry marker; `always_on_write` is a boolean flag stating the capability fired on an always-on basis, mirroring `regen_dependency`'s flag of the same name exactly — there the flag suppresses a per-candidate existence check on a write candidate the engine already had, and never creates candidates, so here too it never manufactures work (see bound (2)). **PARITY WITH THE REGENERATOR CHANNEL, closed by issue #3342**: a `deletions` row now carries `{kind, uuid, id, post_type, parent_id, child_ids}` — the pre-delete inventory `capture_regen_delete_context()` takes, which the batch channel's own consumers use to keep deleted children out of the live batch. Three changes made it deliverable, and each is worth stating because each was a real bound: the capture's consumer gate now recognizes a `deletions`-declaring negotiated capability as a consumer in its own right (so the inventory is TAKEN for a provider-only manifest at all, exactly as issue #3369's review widened the reparent capture); the durable `regen_delete_context:<uuid>` markers are unioned into the channel the way `reparents` unions its own, so an apply that committed the delete and failed before the repair re-delivers on the retry, with the durable row winning the collision because it carries the inventory a tombstone projection never had; and `child_ids` being a list was never the obstacle it read like — `Providers::FIELD_TYPES` bounds what a MANIFEST may declare as a capability argument, while a batch channel is engine-assembled and validated only as a list of rows. Every row carries all six keys, so `post_type: ""` / `parent_id: 0` / `child_ids: []` means "the engine took no inventory here" (a tombstone on a non-post surface, or one no consumer declared) and is distinguishable from an absent key. **Marker lifetime is owned by the dispatcher that declares the channel**: a durable delete/reparent marker is deleted only after the declaring capability returns `verified: true`, addressed by its own key and narrowed to that action's own triggers — so a failed or unverified invocation retains it, one adapter's receipt never retires another's evidence, and the next apply re-delivers. Ownership is decided RUN-INDEPENDENTLY, which matters because a sweep is destructive: the marker survives when a PINNED provider action triggers on its surface and this run's selection reached that surface not at all, and is swept (with a warning naming the marker and the unconsumed channel) when the selection did reach it and no negotiated capability wanted the channel, or when nothing pins a claimant. A pinned claimant whose capability this run negotiated as `scope: site` owns nothing — it can never receive a channel — while a scope this run could not observe keeps the marker rather than guessing. Consequences worth stating: markers of a pinned-but-deactivated plugin persist rather than decaying, and both keyspaces are therefore surfaced in `plan.regen_context` / `wprism status` (which reports not-ok while one stands) so a held receipt is legible instead of silent. Exactly ONE capability may consume a given channel on a given `post:` surface — the clear is per-marker, not per-consumer, so a second consumer would lose the evidence its own retry depends on; negotiation refuses it, naming both claimants. The entity batch shares the batch channel's `regen_pending:<uuid>` retry vocabulary on the same terms: armed for each delivered post-kind entity before the call, cleared on a verified receipt, unioned back into the batch on a later run, and never handed an id the deletions projection says is gone. One post type may be claimed by only ONE dispatcher — a channel-declaring capability triggering on a post type that also declares an enabled batch `regen_dependency` is refused at negotiation, before any mutation, naming both claimants. **Byte-compatibility is a contract, not a courtesy**: a capability that declares no `context` and no `list<object>` argument negotiates to byte-identical declaration bytes and receives a byte-identical injected batch (the bare row list), frozen as literal bytes in `sandbox/tests/offline/adapter/regress_provider_contract.php` rather than asserted in prose.

  Four bounds stated so nobody re-derives them: (1) **execution context** — ordinary native actions and providers run in apply's own process, compensated by an unconditional object-cache flush immediately before the action loop (in-process runtime caches can hold pre-commit plugin models — the reproduced Polylang 3.8.6 class). The two explicit engine-owned exceptions are `rewrite.flush` and a manifest-owned site capability declaring `fresh_process_capabilities`, each governed by its fixed protocol above; adapters never launch their own plugin CLI subprocess as an implementation detail. (2) **`scope: "entity"` semantics** — the declaring action must carry explicit `post:`/`term:`/`table:` triggers (negotiation refuses unscoped declarations and triggers with no ledger-resolvable per-entity id, before any mutation), and a selection whose surfaces came only from deletions/retry-tombstones invokes nothing: the engine records an explicit skip receipt rather than handing the provider an empty batch it could "verify" — unless (issue #3369) the capability declared an EVIDENCE channel (`deletions`, `reparents`, `retry`) that came back non-empty/true, since a deletion-only selection is real work for a capability that asked to be told about tombstones. `always_on_write` is not such a channel and never suppresses the skip: it mirrors `regen_dependency`'s flag, which suppresses a per-candidate existence check but never creates a candidate, so a capability declaring it still fires only when its entity batch or one of its evidence channels carries something. The skip receipt survives verbatim for a capability declaring no channels, and survives with each declared channel's own state named when none of them carried work (a row channel is empty, a flag channel false/absent, `always_on_write` a flag that is never work of its own). issue #3342 made this the shipped path for a real repair: `adapter-packages/woocommerce/package/manifest.json`'s `rebuild_product_lookups` is `scope: "entity"` and declares all four channels, replacing the batch `regen_dependency` that dispatched the same adapter code before. (3) **`verified` is the provider's own value-level claim**, structurally required and receipt-recorded; the engine's independent backstop for authored state is the post-apply canonical recapture (a provider that corrupts authored state and returns `verified: true` is caught there — regression-covered). Ordinary providers have no second checker for derived state beyond their own readback; a fresh-process capability is the explicit exception, requiring a distinct observer child plus the parent's exact postimage equality check. The RECORDED half of that receipt is a **bounded public projection**, not the provider's bytes (issue #3383): `before`/`after` reach `wp wprism apply --format=json` through apply's `actions` rows, so `Providers::invoke()` projects them at the trust boundary and nothing downstream ever holds a raw provider value. A string over `RECEIPT_MAX_STRING_BYTES` (512), one bearing control bytes or invalid UTF-8, one the shared public-output sensitivity screen flags (`CommandRefusalException::containsSensitivePublicDetail()`, i.e. `Secrets::hard_match()` plus the credentialed-URI/query-secret/email/home-path shapes), a map key breaking the same rules under `RECEIPT_MAX_KEY_BYTES` (128), a container past `RECEIPT_MAX_DEPTH` (4) or `RECEIPT_MAX_ENTRIES` (128), and a whole value whose bounded projection still exceeds `RECEIPT_MAX_VALUE_BYTES` (8192) each publish as `<wprism:receipt-witness/v1:<reason>:sha256:<digest>>` over the closed reason vocabulary `ambiguous|binary|control|deep|oversized|secret|wide`. The digest is canonical and taken over the RAW value at every level, including levels that do not publish, so the projection is injective at the PHP-value level: equal raw values publish equal bytes and unequal ones do not (up to JSON number encoding — `1.0` and `1` are distinct raw values that JSON renders identically when published verbatim; witnessed values keep the distinction in the digest), and `before === after` stays decidable from the published receipt without the plaintext — value-level verification is preserved, not weakened. The sensitivity and control screens are per-leaf and pattern-based over C0/DEL, the same scope as the shared refusal screen: split or encoded credentials and C1/zero-width/bidi codepoints are not detected — providers must not put credentials in receipts. A receipt carrying an object, a resource, or a non-finite number fails closed, since the engine will not summarize bytes an array walk cannot read. The human apply render never carried receipt values (it renders the `verified` warning line and its duration only) and the host reads the apply summary for artifact identity alone, so JSON is the single public receipt surface and this is its complete contract. (4) **negotiation GATES at apply and REPORTS at plan** (issue #3339 closed the reporting half of this bound). The refusal is still apply's alone, still immediately before the first mutation, and still scoped to the actions that run's own work selects. `plan` and `status` additionally carry a `provider_problems` list — the identical problem rows, produced by the identical code (`Providers::negotiate()` IS `Providers::diagnose()`), over every provider capability the PINNED manifests declare. That set is deliberately WIDER than any one apply negotiates, so a report cannot go quiet merely because this revision touched nothing. The NARROWED half gates: `Policy::provider_readiness_blockers($selectedActions)` negotiates exactly the actions a plan's own work reaches and merges its rows into `adapter_dispositions`, which `wprism status`'s exit code counts — so a provider this revision genuinely needs and cannot get does make status non-zero. `provider_problems` carries only the remainder (rows already reported as gating are dropped, so one fact is never stated twice) and is rendered and counted without flipping the exit code, since a capability this revision never reaches will not refuse this promotion. Scoped rows gate; wide rows report. Each row names the provider, its declaring manifest, its owning plugin, the problem code, expected, found, and a remediation. Plan-time diagnosis constructs the same provider objects apply does — a manifest-sourced provider's file is required and its class constructed, and plugin-sourced providers come off the wprism_providers filter — so plan/status now execute provider constructors and identity()/capabilities(). No capability is invoked. A packaging fault (a manifest-sourced provider whose file or class is missing) still THROWS at apply and is REPORTED as a row at plan, under the code `provider_code_unavailable` — a reporting surface that died on one broken adapter would hide every other adapter's verdict behind it.
- `"deletions": {"post:product": {"executable_owner_boundary": "all_active_owners", "cascades": ["postmeta", "post_revisions", "term_relationships"], "guards": [{"table": "wc_order_product_lookup", "column": "product_id", "id_kind": "post", "reason": "orders reference this product"}]}}` — exact, adapter-owned deletion capability. `cascades` names every effect the engine will perform. `executable_owner_boundary: "all_active_owners"` composes only when every co-declaration agrees and delegates live owner consent to the exact v2 site agreements described above; a manifest cannot whitelist code it does not own. Each guard declares the target id keyspace; optional `where` adds scalar predicates and optional `exclude_where` subtracts owned rows already covered by a declared cascade (for example revision child posts). By default the guarded reference column must lead a usable next-key lock index. `lock_column` may instead name an exact `where` predicate whose index establishes that lock range (for example Woo legacy order-item meta locks `_product_id` through indexed `meta_key` while testing unindexed `meta_value`); it cannot name an unrelated column. A guard may also declare `source_id_kind` + `source_pk`, allowing rows whose source entities are themselves safe delete candidates in the same revision while still blocking runtime or conflicted references. Structured metadata guards add `meta_key` + `ref` (currently `postmeta` and a matching scalar/list id shape), `identity_column`, and the owner source pair; an explicitly authored owner update that removes the target token is the only repair witness, while child-only tombstones remain blocked. Option-name guards add `option_name_ref: true` on `options.option_name`; they resolve the target id through the manifest's `option_name_refs` rule and require the matching canonical option tombstone in the same revision. Missing or unreadable required guard tables fail closed. A same-manifest table reviewed as absent on some supported versions may declare `table_absence: "empty"`: an exact information-schema census binds absence as a distinct zero-reference witness and repeats immediately before commit, while presence retains the ordinary InnoDB/index/locked-row proof. The enum is exact; a foreign table, mixed required/empty declarations, a census error, or an ambiguous near match refuses. Matching rows mark deletion **BLOCKED**; `apply --with-deletes` refuses unless `--force-delete-referenced`, and forced execution remains loud. A guard with `"forceable": false` is never crossed by that flag, and an unreadable guard is never treated as forceable.
- **Structured-value refs** (spec v0.7, widened by issue #3316): a meta/option rule—or an authored key of an `authored_snapshot_meta` EAV sidecar—may declare refs *inside* a JSON-or-PHP-serialized value. `"json_refs": [{"path": "$.*.*.wpseo_opengraph-image-id", "kind": "post", "cast": "string"}]` rewrites id **values** at declared paths (minimal dialect: `$` root, `.` key steps, `*` wildcard); `"key_refs": {"path": "$.*", "kind": "term"}` rewrites entity-id **keys** of the map at the declared path. Attached sidecars use the identical decoder, tokenizer, linter, compiler, apply, and verification declarations as ordinary meta; `json_encoded:true` selects compact JSON text while the default is WordPress/PHP serialization. Undeclared sidecar keys keep their historical opaque-byte behavior. Everything undeclared inside a declared structure is byte-preserved except string leaves, which get ordinary URL tokenization; unmapped ids follow the drop-with-warning rule. The tokenizer also matches **JSON-escaped URL forms** (`https:\/\/…`, Elementor's convention): both forms collapse to one plain-spelled token, and structural re-encode restores the host convention on apply (RFC 8259 makes `/` vs `\/` equivalent inside a JSON string). `wp wprism lint` treats declared paths as owned and still flags id-shaped values at *undeclared* paths inside the same structure — the linter catching manifest gaps is its purpose. Paths, casts, reference keyspaces, overlapping scalar paths, and scalar-path-versus-key-map ambiguity are validated when normal or frozen policy loads, before capture/apply. `option_patterns` has two metadata fallbacks: legacy `"meta_patterns"` classifies both post and term metadata, while `"post_meta_patterns"` classifies post metadata only. Use the narrower form whenever evidence is specific to `wp_postmeta`; the core `_oembed_<md5>` cache must not silently classify an identically named term-meta value.

- **Repeated authored meta rows**: an authored `post_meta` or `term_meta` rule may opt into the closed storage declaration `"repeated_rows":{"cardinality":"one_or_more","duplicates":"forbid","order":"preserve"}`. This means one canonical list element per physical metadata row, in `meta_id` order; a missing key means zero rows, while a present key must be a non-empty list of unique decoded scalar values (a serialized collection string cannot masquerade as one scalar row). The declaration can carry one scalar `ref` so each row is independently tokenized and rebound. It cannot combine with `ref: "…[]"`, `cast: "csv"`, `plain_data`, structured refs, or `order_preserving`: each of those describes one row containing a collection and would make the same canonical list ambiguous. Capture still refuses undeclared multi-row authored keys. Apply compares the complete ordered row set, replaces every byte-exact owned key row when it differs, deletes all byte-exact rows when the key is absent, and leaves runtime rows plus collation-equal non-byte-exact key aliases untouched; the surrounding authored transaction supplies rollback and retry. List order is ordinary canonical content, so reorder-only changes participate in the same post hash and three-way conflict rules as every other authored byte.

- **Taxonomy descriptions and relationship keyspaces** (issue #3316): `taxonomies.<taxonomy>.description_refs` retains the byte-compatible shorthand `{"kind":"post"}` / `{"kind":"term"}`, normalized to one flat-map `json_refs` path (`$.*`). Independent adapters may instead use the full shared shape `{"json_refs":[...],"key_refs":{...}}` for nested PHP-serialized descriptions; no description-specific traversal grammar or plugin payload walker exists. `taxonomies.<taxonomy>.object_keyspace` and `taxonomy_patterns[].object_keyspace` declare whether the taxonomy's relationship `object_id` values belong to the `post` or `term` identity space. Runtime `object_type` still selects concrete post types but never doubles as a term sentinel. Missing term/mixed ownership, declaration/runtime contradictions, or ambiguous pattern ownership refuse rather than dropping or cross-applying relationships.
- **Dynamic taxonomy scope** (spec v0.12, task #92): a manifest may declare `"taxonomy_patterns": [{"match": "<regex>", "object_type": ["<type>", ...]}]` for taxonomies whose NAME (not a meta/option key) is dynamic per site (WooCommerce's `pa_<attribute>`, minted at runtime from a custom-table row). Unlike `option_patterns`/`meta_patterns` (which classify a key some other enumeration already produced), `taxonomy_patterns` expands the taxonomy SCOPE LIST itself: `Policy::taxonomies()` unions the exact `policy.taxonomies` list with every name in live `wp_term_taxonomy` (a `SELECT DISTINCT`) that matches a declared pattern — **scope-gated**: only pattern-matched names are ever added, never a blanket widen to whatever the database holds (the posture task #73 established for ref-typed options). Matching against the live table, not `get_taxonomies()`'s in-memory registry, is deliberate and load-bearing: a taxonomy whose registration depends on a same-request custom-table write (a typed-snapshot table's phase-1 insert) is invisible to the registry for the rest of that request even though its own term rows are already live. The declared `object_type` is a fallback consulted only when `get_taxonomy()` fails — the registry stays authoritative whenever it succeeds. This is what lets direct-SQL term-relationship writes correctly scope a just-landed dynamic taxonomy in the SAME apply request that created its defining row, with zero manual pre-provisioning.
- **Whole-entity scope gate** (issue #3229): capture enumerates WordPress-registered public post types/taxonomies plus whole-type contracts declared by pinned manifests, then counts their live capturable rows. A type with rows must either be in `policy.post_types` / `policy.taxonomies` (including a declared taxonomy-pattern match), or carry an explicit non-authored disposition. Pinned manifest `post_types.<name>.class` is already that disposition (`shop_order` and `nf_sub` are runtime); manifest taxonomy declarations default authored unless they declare otherwise. Site-local decisions live at `policy.scope.post_type.<name>.class` or `policy.scope.taxonomy.<name>.class`. `authored` adds the name to scope; `runtime`, `derived`, or `env` records a deliberate exclusion. Missing disposition blocks capture naming the surface, entity count, and exact policy fix; it never quietly shrinks the tree. `wp wprism pending` reports keys such as `scope:post_type:book`, and `wp wprism classify --set='scope:post_type:book=runtime'` persists an audited exclusion.
- **Sub-keyed options** (spec v0.14, issue #3233): a manifest option rule may declare `"sub_keys": {"<name>": {...rule...}, ...}` — NAMED sub-keys of one option's array value classified and captured/applied **independently of the whole option and of each other**, using the same rule vocabulary as a top-level option/meta rule (`class`, `ref`, `json_refs`, `key_refs`, `plain_data`, `cast`, `allow_secret`, `allow_pii`). `sub_keys` and `class: authored` are mutually exclusive on the same rule — a whole-option-authored value has no sub-key carve-out to speak of, and mixing the two leaves "authored the whole thing" vs. "authored named pieces of it" undefined. Whole-value behavior fields (`ref`, `json_refs`, `key_refs`, `json_encoded`, `cast`, `plain_data`, `order_preserving`, `allow_secret`, `allow_pii`, or `lint_ok`) are also invalid on an exact or dynamic sub-keyed parent because consumers intentionally use the named sub-key rules; accepting them would silently ignore a declaration. The exact parent may still carry its actual container contract (`class`, the required flag for `env`, and `autoload`); a dynamic parent carries its resolver/prefix/autoload contract. A sub-key rule cannot itself declare another `sub_keys` map; the grammar is exactly one level because capture/apply merge only named children of the live option. Capture reads the live option, keeps only the entries whose sub-rule is `authored` (everything else — including any key genuinely absent from the manifest — is left out of state entirely, not merely excluded), and applies the ordinary ref/json_refs/key_refs/plain-data/secret-and-PII clearance machinery per sub-key exactly as it would to a same-shaped top-level rule. Apply reads the **target's own live value** of the option (defaulting to `[]` with a warning if the option is absent there), overlays only the captured sub-keys' resolved values on top of it, and writes the merged array back — every sibling key on the target, declared or not, whole-option class `env`/`runtime`/`derived` or otherwise, survives byte-for-byte untouched. Repository authorization mirrors the split: an option carrying `sub_keys` is authorized key-by-key against the declared sub-rules (any key present in a captured value with no `authored` sub-rule is refused by name — `option_sub_key` surface, not folded into the whole-option `class` check). `wp wprism lint`'s bare-id scan is likewise sub-key aware: a `json_refs`/`key_refs`-declared sub-key gets the deep structural scan; a plain sub-key gets the ordinary shallow scan one level in. Motivating case: Polylang's `polylang` option and Yoast's `wpseo` option each mix authored configuration with per-environment bookkeeping that must never travel, inside the SAME option blob — `sub_keys` lets a manifest tell those apart without capturing the whole blob (silently clobbering another environment's own bookkeeping on apply) or excluding it whole (silently losing real authored configuration).
- **Dynamic option names** (spec v0.20, issue #3264): `"dynamic_options": {"<key>": {"prefix": "<literal>", "resolver": "<resolver>", "sub_keys": {...}, "autoload"?: "..."}}` — for an option whose NAME is computed from environment state rather than declared literally (`theme_mods_<active stylesheet>`). The declaration is `sub_keys`-shaped and carries no top-level `class` of its own: the containing blob is **always** `env` (a hardcoded engine decision, not a manifest field — a dynamic-name blob is environment-local by construction except the keys the manifest names), and each declared sub-key classifies independently with the ordinary rule vocabulary. Exactly one live name is ever read: the one the resolver currently produces. Every other live name sharing the same `prefix` — a `theme_mods_*` row for a theme that is not currently active — is environment-local residue by the same declaration, never captured and never reported unclassified. `resolver` is a **closed, engine-owned vocabulary** (v1: exactly `active_stylesheet`), because a resolver is engine code, not data: admitting one costs three coordinated engine edits — the allowlist entry (`SubKeyGrammar::DYNAMIC_OPTION_RESOLVERS`), the capture-side match arm that computes the live value, and the apply-side map entry that supplies it — and every one of them is mandatory. A manifest declaring a resolver the engine cannot resolve is refused at load; a *declared* resolver that some engine call site fails to supply a value for is a loud engine-wiring error, never a silently unclassified option.
- **Widgets** (spec v0.19, issue #3278): `"widgets": {"<id_base>": {"settings": {"<field>": {"class": "authored", "codec"?: "blocks", "ref"?: "term", "allow_secret"?: true}}}}` — a closed per-type registry of the settings fields WPrism will carry for a widget instance. The type key is WordPress's own `id_base` (the `widget_<type>` option name), matching `^[a-z0-9_-]+$`; its derived ledger kind must fit `wprism_map.id_kind`. `settings` is an allowlist and is mandatory: capture refuses any live setting the map does not name, so an absent map makes every instance of that type uncapturable rather than partially captured. Every named field declares `class: authored` — a settings map has no meaning for a field it is not carrying, so exclusion is expressed by leaving the field out. `codec` and `ref` are **closed, engine-owned vocabularies** (`blocks` and `term` respectively) and are mutually exclusive on one field: a value is either a structured document the engine decodes or a single entity reference it resolves. An undeclared widget type is reported, never guessed; its instances stay unmanaged.
- **Adapter compatibility contract** (spec v0.15, issue #3222/issue #3247; the next version's window is specified in "Spec v3" § v3.1 and is not in force): a manifest MUST declare `"spec_version"` (int, the wire-format grammar it was authored against) equal to the engine's own `WPRISM_SPEC_VERSION` exactly — absent and declared-wrong are the same failure, both refused at load time. (This was not always true: at v1/issue #3222, while `WPRISM_SPEC_VERSION` had exactly one historical value, an absent declaration was lenient — it can't be "wrong" when nothing else it could have meant existed yet — with an explicit, written pre-commitment to flip the moment a second historical value existed to be silently wrong about; issue #3210 performed that bump, issue #3247 actioned the pre-committed flip.) A manifest may additionally declare `"plugin"`/`"version_range"` (unchanged from spec v0.9's original mechanic) and `"theme"`/`"theme_version_range"`, the exact same `{min,max}` (min inclusive, max exclusive) shape mirrored for themes: one theme per manifest, matching one plugin per manifest. `Policy::load()` rejects, at load time, before any target contact: a `plugin`/`theme` declared without a well-formed matching range (no latest/wildcard/unbounded support is certifiable); a malformed range (missing min/max, non-string, min not strictly less than max); and two pinned manifests naming the same plugin or theme with different ranges (conflicting ownership — manifest precedence may never depend on pin order, so this is refused outright, with no composition/override grammar in v1; "Spec v3" § v3.13 adds the one way out, and it is the operator's rather than an adapter's: an explicit `site.wprism.json` `policy.adapter_claims` row naming which claim is IN FORCE, with the displaced claimant reported — resolution, never composition, and an undeclared conflict still refuses with this exact message). The compiled artifact (`RepositoryCompiler`) records a `resolved_adapters` array — one row per pinned manifest, carrying its name, a per-manifest content digest (the same bytes `manifest_hash()` already folds into its one combined hash, now also exposed individually), and its declared identity/range facts. This is compilation staying honest about what it validated — declaration validity and non-ambiguity, reproducible and artifact-hashed — not a live-environment match, which stays `Deploy::code_mismatch()`'s job: it checks a declared `theme_version_range` against the environment's actual installed theme version exactly as it already does for plugins, producing the identical `outside_version_range`/`missing_in_code` finding shape with `kind: "theme"`.
- **External manifest disposition and capability contracts** (issue #3224/issue #3227): each capsule's `package/disposition.json` is separate from its `package/manifest.json` so declaration cannot imply certification. It is one document per subject — `adapter-packages/<name>/package/disposition.json` holding that adapter's entry verbatim, with `platform/adapter-library/profiles.json` holding the profiles map (§ v3.4, WP-4.4); the pre-split `dispositions.json` is refused at load rather than ignored, and no adapter digest moved across the relocation. It has exact one-for-one coverage of the shipped manifest JSON files and classifies each `certified`, `experimental`, or `excluded`, naming supported versions, entity/field sections, operations, lifecycle phases, deletion semantics, explicit unsupported behavior, and every manifest table whose default keyspace is authored. Intent-only table declarations must appear as unsupported rather than implemented. **Retired:** the `wprism-subject-certification-bundle/v1` evidence records every certified manifest and profile once owned, and the generated `manifests/capabilities/registry.json` projection of `manifests/capabilities/evidence.json`, are gone with no successor document — no content-addressed evidence stands behind a claim any more. The reviewed disposition is the whole claim source, and `AdapterRegistry::report()` computes the product-facing projection (`wprism-capability-report/v1`) from it plus per-adapter provenance on each call. Disposition bytes are frozen in the policy snapshot (`wprism-policy-snapshot/v6`, which rejects v5's frozen capability record rather than ignoring it — a snapshot carrying a record this agent no longer checks must not verify as if it had been checked); compiled adapters carry the same claim used by host promotion. `wp wprism capabilities --repo=<p> [--operation=<op>] [--surface=<surface>] [--format=json]` evaluates the selected target; `--all` reports the shipped library; `--revision` is refused by name rather than accepted and ignored, because it selected an evidence-bound platform revision nothing binds any more. Missing/experimental/excluded claims, an unregistered operation or surface, target version mismatch, or multisite keeps readiness non-green. Plugin execution without source modification is a separate registry field from authored-state branchability. Explicitly selected historical flat libraries without a disposition registry retain legacy validation behavior but make no certified product claim. A new WordPress extension adds one `adapter-packages/<name>/` capsule containing its package, convention-discovered tests/evidence, and fixtures; the dynamic aggregate gate discovers it without an extension-name allowlist or Makefile row.
- **Out-of-tree adapter sources and certification** (issue #3314): a site repository may install adapters of its own in `adapters/<name>.json`. This is a SECOND adapter source that **overlays** the shipped library — additional pinnable adapters, never replacements — so the embedded library's one-for-one disposition coverage above is unchanged, and a repository with no `adapters/` directory behaves exactly as before. File names, pins, certificates, authority key ids, ratification maps, and frozen records share one canonical lowercase-ASCII slug identity (letter/digit endpoints; internal letters, digits, dots, underscores, and hyphens; **at least one lowercase letter**). Numeric-only identities are refused before lookup because PHP would coerce a numeric JSON object key into an integer map key. Path-like, hidden, uppercase, and Unicode variants also refuse rather than being normalized into a different identity. Discovery scans every source and refuses before any pin resolves: a site adapter whose file name collides with a shipped manifest (shadowing), whose declared `name` disagrees with its own file name (ambiguous identity), or whose declared name a shipped manifest already declares. The shipped library is held to the same identity rule (issue #3371): a pinned shipped manifest whose declared `name` is not its file basename is refused at load, before any validator, with the same ambiguous-identity sentence. `adapters/dispositions.json`, `adapters/capabilities`, `adapters/{interpreters,providers,regenerators}`, symlinks, unreserved nested `*.json`, and extension near-misses such as `.JSON` are refused rather than ignored — an adapter cannot ratify itself, and the engine never loads code from this source. **A data-only manifest acquires no executable privileges**: an out-of-tree manifest declaring `interpreter`, a `regen_dependency.regenerator`, or a `providers[].source: "manifest"` is refused with remediation, because all three executable names resolve only through a package in the agent's embedded `AdapterLibrary`. `providers[].source: "plugin"` remains available — its `plugin` must use the same safe WordPress basename grammar as a top-level plugin claim even when the adapter has no top-level plugin/version-range claim; that code's runtime trust anchor is the installed, active, version-bounded plugin plus the provider negotiation/receipt contract, and its live tree is deliberately not digest-bound by the manifest engine.

  The one reserved nested source is `adapters/certifications/<name>.json`, an exact one-to-one companion for the top-level adapter. It is a canonical `wprism-adapter-certification/v1` Ed25519 envelope, never a public key or self-asserted trust root. Trusted keys live only in the platform-owned `platform/adapter-library/capabilities/adapter-authorities.json` (embedded at `agent/adapter-library/platform/capabilities/adapter-authorities.json`), format `wprism-adapter-authorities/v1`: `keys` is keyed by the canonical key id, and each exact record declares `algorithm:"ed25519"`, `scope:"site_adapter_certification"`, `status:"trusted"|"revoked"`, a canonical base64 public key, non-empty `adapter_names`, and permitted trust tiers. The certificate binds the selected record's canonical digest and public-key fingerprint, so unrelated key additions do not move an adapter while selected-key rotation/revocation invalidates it. The signed, domain-separated payload binds the selected authority record, source/name/path, canonical and raw manifest hashes, engine-derived trust tier (`declarative_manifest`, `native_action`, or `plugin_provider`; a site adapter reaching `compatibility_shim` is refused), exact agent/spec/platform boundary, one valid externally reviewed certified disposition, and a passing `wprism-site-adapter-certification-bundle/v1` scoped exactly to `site_adapter.<name>` whose named tests and raw subject input match. The verifier re-derives the capability claim; neither the site manifest nor the certificate may mint or widen it. A missing certificate retains the existing usable-but-conspicuous `uncertified` state and `adapter_source_uncertified` blocker. A present malformed, untrusted, revoked, stale, or mismatched certificate is a load-time refusal, never a silent downgrade. A valid signature is reported as `signed_unpinned` until `site.wprism.json` explicitly pins both `source:"site"` and the final certificate-derived adapter digest emitted by `wp wprism manifest-pin --repo=...`; only then is it promotion-ready third-party evidence.

  `wprism-adapter-sources/v2` puts the verified external disposition/provenance in the same digest slot the compiler already hashes, including source/hash/tier, authority-record, envelope/payload, and evidence proof hashes; the signed payload never contains that final digest, avoiding circular identity. Every shipped digest and `wprism capabilities --all` row therefore remains byte-for-byte unchanged. Capability reporting selects evidence per row: shipped entries use their own subject records, signed site entries use only their signed site-adapter evidence/platform, and unsigned site entries never inherit either; a mixed report has no shared evidence authority. `wprism-policy-snapshot/v6` freezes the envelope but no public key; reconstruction reloads the current agent-owned authority, rechecks the signature, source manifest, tier, disposition, bundle, evidence, and derived record, and positively matches every name claimed as shipped to the current trusted agent library, so authority rotation/revocation invalidates affected frozen policies and deleting a v2 source record cannot launder site bytes. The v5 form, which froze the retired generated capability registry beside the envelope, is refused outright; so is the prior v4/v1 uncertified snapshot form, whose read path is retired rather than retained — every v4 document any engine version exported also carried the `capabilities` key, so v6's closed key set already refused all of them, leaving the path reachable only by a shape nothing ever wrote and verifying nothing, while the `wprism-adapter-sources/v1` record it read granted shipped authority to any name absent from `out_of_tree` with no proof at all. A v4 envelope is now refused by format, ahead of the key check, so the refusal names the retired generation instead of reporting a malformed shape; the remedy is to re-export the policy. Every live export emits the fail-closed v2 form.

- **Plugin-bundled adapters** (issue #3339/B2): a plugin may bundle exactly one adapter, as exactly one `wprism-adapter.json` at the root of its own directory. This is the THIRD adapter source. Only ACTIVE plugins are scanned — activation is the operator consent that installs a bundled adapter, the same gate the `plugin_not_active` readiness blocker and provider negotiation already use for that plugin's code. A single-file plugin has no directory of its own and cannot bundle; a `wprism-adapter.json` at the plugins directory root belongs to no plugin and is refused.

  Containment is proved before anything is read, on both the active and the inactive path: "not installed" describes what the engine will LOAD and is never a licence to open a file the plugin does not own. Every third-party value a refusal quotes — a declared `name`, a directory entry, a plugin basename, and the paths built from them — is rendered with control characters replaced and a length cap, because those rows are printed to a terminal and embedded in JSON, and an escape sequence inside one would be a refusal message forging the report it appears in. The renderers apply the same rule to the report's DATA fields (`name`, `path`, `paths[]`) at the point of print rather than at the point of record: the document keeps them exactly as the file is spelled, so a rendered line and a `--format=json` record never disagree about which file an operator has to go fix.

  Identity INVERTS the site rule. The file name is a constant, so it carries no identity; the declared `name` is the only identity, held to the same canonical slug grammar. The anchor is exact: `plugin` must equal one of the ACTIVE plugin basenames in that directory, not merely share its directory name — every version, activation, and compatibility verdict about the adapter is answered against the file it names, so a manifest naming a sibling plugin file nobody activated would get true-looking answers about the wrong code. In its place, a bundled manifest MUST declare `plugin`, equal to the basename of the plugin that bundles it (`plugin_anchor_mismatch` otherwise) — the exact analogue of the provider anchor. That claim does three things: it anchors the manifest to the code it ships with, it lets `providers[].plugin` agreement close the provider story for this source with no new rule, and it makes the frozen provenance path `plugins/<plugin-dir>/wprism-adapter.json` re-derivable from the manifest alone, which is why this source needed no new wire key and no snapshot format bump. Because the anchor makes `plugin` mandatory, the pre-existing compatibility contract above applies transitively: a bundled adapter declaring no `version_range` is refused as unbounded support, so every bundled adapter is version-bounded and `plugin_version_mismatch` stays a reachable readiness verdict for this source.

  **Precedence is `shipped > site > plugin`, and a plugin-side name collision is reported, not refused.** A bundled adapter whose name a shipped or site definition already answers to is dropped and reported on every run as `not_installed`/`shadowed` naming its winner; nothing is deactivated and no other command is affected, while a pin that explicitly writes `source: "plugin"` for that name still refuses loudly. This differs from the site source deliberately: `adapters/` is operator-authored and a collision there is the operator's own broken installation, but WP_PLUGIN_DIR is not, and refusing the whole scan would mean that the day a popular plugin starts bundling a colliding name, every site running it loses every command through an automatic update its operator never performed. Two ACTIVE plugins declaring one name have no precedence available between them, so both are dropped and the pair draws one `source_collision` refusal.

  **Refusals in this source are per-adapter, never whole-scan**, for the same reason. Every condition — a malformed bundle, a non-slug or reserved declared name, an anchor mismatch, a `wprism-adapter.json` that is a symlink or a directory rather than a real file inside the plugin (a symlinked plugin DIRECTORY is accepted; dev checkouts use them routinely), a plugin directory this process cannot enumerate at all (`source_unreadable` — mode 0711 is the ordinary way to reach it, and without the directory listing nothing can prove the plugin bundles exactly one `wprism-adapter.json`), a bundle that cannot be read at all, a near-miss inside this engine's reserved `wprism-adapter*` namespace, a certificate-shaped companion, or an out-of-tree manifest reaching for executable privilege — records a refusal ROW with `scope: "adapter"`, drops that one adapter, and lets the walk continue. It becomes fatal exactly when someone pinned that name: the pin fails with the refusal's own message and remediation instead of a generic not-found. Everything else about the data-only privilege boundary is identical to the site source, with only the noun in the message changed.

  **A plugin-bundled adapter cannot be certified in place.** Certification derives its path from the repository, hashes `adapters/<name>.json`, and binds `adapter.source: "site"` with `adapter.path: "adapters/<name>.json"` INSIDE the signed statement, so no certificate can name a bundled adapter at all; a frozen bundled record paired with a certificate is refused on the read side too. A bundled adapter is therefore `uncertified` by construction, with the `adapter_source_uncertified` blocker and the identical posture an unsigned site adapter has: capture and plan available, readiness and host promotion blocked — and apply blocked with them wherever a compiled code revision is pinned, which is every repository `wprism init` created, because `wp wprism apply` refuses the non-forceable `code_revision_stale` until `wprism deploy <env>` has run and that deploy is the host promotion the blocker withholds. Its remediation is the promotion path, not a signature: an independently distributed adapter package is not a third source — it installs into the site source as `adapters/<name>.json` plus `adapters/certifications/<name>.json`, and its identity and version are the canonical file digest and the signed certificate's version-bound envelope (`supported_versions{plugin,range}` inside the signed ratification, `bundle.artifacts[].version` for what was exercised, `platform` for the engine window). Installing that package is safe with the bundling plugin still active: the site copy wins by precedence and the bundled copy reports as not installed.

- **The installed-adapter catalog** (issue #3339): `wprism adapter list|inspect|doctor [--repo=<site-repo>] [--format=json]` reports what is installed, offline, in the `wprism-adapter-catalog/v2` envelope. Each adapter row carries `name`, `source`, `path`, the canonical manifest `sha256`, `trust_tier` and `tier_basis` (the exact declaration that produced the tier — a shim cannot be asserted without a coordinate), `certification`, `disposition_status`, `required_providers` (declared ids with the capabilities each must advertise), `executable_surfaces` (interpreter, regenerators, manifest-sourced providers), and an ISOLATED grammar verdict from the real loader. `certification` reports the same states the reviewed path reports and mints none of its own: `registry` for a shipped adapter, and for a site adapter `uncertified`, `signed_unpinned` (a valid signature the repository pin has not yet elevated), `third_party_signed` (a key in the agent-owned authorities file, exact pin), or `site_signed` (a key in the site's own `adapters/authorities.json`, exact pin — the customer organization vouching for its own adapter, explicitly not a WPrism endorsement); a plugin-bundled adapter is always `uncertified`, by construction. Every row additionally carries `trust_root` (`platform` for a shipped row, `site` or `platform` for a signed out-of-tree one, `null` when nothing signed) and `principal` (the authority key id, or `null`). A shipped adapter displaced by an explicit `{name, source:"site", digest}` pin is not an adapter row at all: it moves to `not_installed` with reason code `shadowed_by_site` and its winner named. The v2 envelope additionally carries `sources` — which of the three adapter sources this process could reach, and where — and `not_installed`, one row per adapter that is on this machine and lost to a higher-precedence definition, with the winner named. `not_installed` rows print on every run and deliberately do NOT flip the exit code: a correctly resolved shadow is neither a blocker nor a break, and a permanently red doctor on every site running a colliding plugin would destroy the exit code's meaning. Every refusal row carries `source` (which adapter source the condition is about) and `scope` (`source` for a whole-directory refusal, `adapter` for a per-adapter one), which is what keeps one plugin author's typo from un-judging every operator-authored adapter. Because the host command is WordPress-free it cannot reach the plugin source at all; `sources` says so on every run, and `wp wprism adapter-survey [--repo=<path>] [--format=json]` is the same survey run ON the target, emitting the same `wprism-adapter-catalog/v2` document with `command: "survey"`. The catalog is built on `AdapterSources::survey()`, the REPORTING mode of the same scan `discover()` throws from: every condition above (shadowing, ambiguous identity, declared-name and case-fold collisions, a non-canonical identity slug, symlinks, nested `*.json`, extension near-misses, reserved names, an unreadable manifest, an `adapters/` directory that is itself a link, a malformed certification source, an out-of-tree manifest reaching for executable privilege, and a certificate that does not verify) becomes a refusal ROW carrying a stable code, the paths it is about, the engine's own message byte for byte except in two stated cases, both at the site-manifest read (see the delta comment at `AdapterSources::scan()`): an unreadable or unparseable manifest is wrapped so the row names the file as a site adapter, and one that is valid JSON but not an object now refuses as `malformed_manifest` naming its actual top-level type instead of as an `ambiguous_identity` about a declared name it never had, and a remediation; a repository whose own `site.wprism.json` cannot be read draws a single `site_policy_unreadable` row instead of a per-manifest echo. Reporting rather than throwing is the point: those conditions make every other command refuse outright, so an operator whose repository is in one has nowhere else to look. `inspect` additionally merges the reviewed disposition entry, the capability claim that disposition projects, and the verification facts that already exist — `plugin_execution.status`, the bundle schema the disposition cites, and the test ids named in that citation — and mints no verification vocabulary of its own. `evidence.status` went with the generated evidence record that decided it: a citation is what a reviewer wrote down, not a verdict this process can resolve, and printing `current` beside it would be the agent vouching for itself. `doctor` adds the pinned set's readiness blockers. Exit 0 healthy, 1 anything surfaced, 2 usage/IO; every run emits a `deferred` list naming the live-target checks it did not perform, the manifest-shipped PHP it names but deliberately never loads, and the cross-manifest guards that belong to a pin set.
### Vocabulary ownership and extension (spec v1, issue #3318)

Every closed vocabulary above has exactly one owner and exactly one extension path. A value outside a closed set is refused at manifest load — before any target contact — with a message that names the rejected token, prints the legal set, and states who owns extension. Silence is never the answer: an unrecognized value used to mean "fall back to the default" or "match nothing", which is indistinguishable from a deliberate declaration and is how a transposed letter silently dropped a plugin's authored rows out of canonical state.

| Vocabulary | Owner | Extension path | Refusal posture |
|---|---|---|---|
| `post_types.<t>.body`, `.phase` | engine | engine change + spec bump | load-time; names token, set, owner |
| `post_types.<t>.fields.<f>` and its `class` | engine (`Policy::DERIVABLE_FIELD_COLUMNS`) | engine change + spec bump, per field, with its own evidence | load-time |
| `tables.<t>.class` | engine | engine change + spec bump | load-time |
| `tables.<t>.identity.mode` | engine | engine change + spec bump | load-time |
| `tables.<t>.invalidate[]` shape | engine | a new verb on TWO OR MORE independent demands, staged through `engine_features` (§ v3.15); otherwise a native action or provider capability | load-time |
| ref kinds (`ref`, `refs[].kind`, `json_refs`/`key_refs` `kind`, `block_attrs`/`shortcode_attrs` `kind`) | engine for `post`/`term`/`tt`; `user` additionally for scalar `ref` rules and `block_attrs`; **adapter** for every other value | declare a `tables.<t>` you own and name its `id_kind` | load-time, across all pinned manifests |
| `block_attrs`/`shortcode_attrs` rule shape, `type`, `cast`, `tokenize`, `unsupported` | engine | engine change + spec bump | load-time |

Every block/shortcode attribute rule declares exactly one disposition from its
implemented rewrite vocabulary. Block rules may use `kind`, `kind_from`,
`tokenize`, `lint_ok`, or `unsupported`; `unsupported` is block-only and carries
a non-empty reviewer-facing reason of at most 512 bytes. A present value on an
`unsupported` block path refuses capture and apply before publication rather
than passing environment-bound bytes through; lint reports
`unsupported_block_attr` if hand-edited canonical state already contains it.
`tokenize: "text"` recursively rewrites every string leaf, including structured
values such as `core/video.tracks`; lint reports
`unrewritten_registered_text` when a declared path still contains the current
environment's home URL.

Shortcode rules may additionally bind a plugin-public alternate identity to a
post. A positional locator declares `position`, static `kind: "post"`, and an
authoritative `lookup` domain (`post_meta` plus `post_type`). A named
hash-prefix locator is exactly `{path, kind: "post", required: true, lookup}`;
its lookup is exactly `{codec: "hex-prefix", post_meta, post_type,
prefix_length, stored_length}` or the plural
`{codec: "hex-prefix", post_meta, post_type, prefix_length, stored_lengths}`.
The scalar form requires `1 <= prefix_length <= stored_length <= 128`; the
plural form is a strictly increasing list of at least two unique widths, each
inside that same bound. The two forms are mutually exclusive. Source capture resolves the
public alternate through that exact live post-meta/post-type domain and emits
the ordinary canonical post token; apply restores the repository's authored
alternate witness because that post-meta value belongs to the same canonical
entity. Target observation receives the sealed repository witness so a native
destructive uninstall that deletes the owner but leaves embedding pages can be
recovered: only a genuinely missing live owner may resolve through that reverse
witness. A live owner mapped to another canonical entity still refuses, as do
duplicate or ambiguous live owners. The named codec accepts only an exact
lowercase-hex prefix, requires exactly one attribute occurrence, validates the
full stored value, and preflights every canonical owner—including forms no page
currently embeds—against target collisions before mutation. Lookup rules are
closed to static post kinds and cannot acquire casts, tokenization, lint
exemptions, title fallbacks, or extra keys. A missing source, duplicate,
malformed, unmanaged, or wrong-domain alternate is a hard refusal; raw
alternates never remain in canonical state.
| `widgets.<t>.settings.*` `class`/`codec`/`ref` | engine | engine change + spec bump | load-time |
| `dynamic_options.<k>.resolver` | engine | engine change + spec bump (three coordinated edits — see above) | load-time |
| classification rule `class` (`authored`/`runtime`/`derived`/`env`/`managed`); whole-entity **scope** `class` is the same set minus `managed` | engine | engine change + spec bump | load-time |
| native action names and argument schemas | engine (`NativeActions`) | a plugin-owned provider capability | load-time |
| provider ids, capability names, argument schemas (including a `list<object>` argument's own `fields` row vocabulary, bounded at one level) | **adapter/plugin** | the plugin's own provider declaration | load-time for identity/shape/depth; negotiation before first mutation for the rest |
| provider argument TYPE names (`bool`/`int`/`string`/`list<string>`/`list<object>`) and engine batch channel names (`deletions`/`reparents`/`retry`/`always_on_write`) | engine | engine change + spec bump — each names evidence the engine itself assembles or validates, so a capability may opt in but never mint one | negotiation before first mutation |
| effect `kind`, `mode`, `selector.scope`, `selector.type`, member placeholders | engine | engine change + spec bump; `provider_resource` + a provider is the adapter-side path | load-time |
| interpreter and regenerator names | **adapter** (code ships with the manifest) | ship the file with the manifest artifact | load time for the name, first use for the class contract |
| `spec_version` | engine (`WPRISM_SPEC_VERSION`) | the engine's own bump; a manifest states which grammar it was authored against and may never widen it | load-time; the accepted window is {N-1, N} (§ v3.1), an integer outside it refuses wholesale naming the window, and absent/non-integer keeps its own older refusal |
| `providers[].source` (`manifest`/`plugin`) | engine | engine change + spec bump — the two values name the two code-loading paths the engine implements, not a location an adapter may invent | load-time |
| manifest disposition `status` (`certified`/`experimental`/`excluded`) and profile statuses | engine, in each capsule's sibling `package/disposition.json`, which its manifest cannot author | the external review process, never a manifest field — declaration must not be able to imply certification | load-time for the disposition registry; `wp wprism capabilities` for the claim |
| `actions[].triggers` values (`(post\|term\|table\|option\|entity):<name>`, plus feature-gated `post:*`) | engine owns the SHAPE (`ActionTriggerMatcher`); the exact `<name>` half is deliberately **open** | ordinary manifest declaration for exact surfaces; `post-kind-action-trigger/v1` for the bounded all-post-kind selector | load-time for shape and feature; runtime matching only against concrete surfaces |
| option/post-type/table NAMES, patterns, keyspaces | **adapter** | ordinary manifest declaration | n/a — this is the data surface, deliberately open |

`actions[].triggers` is the one row above that names another adapter's surfaces on purpose, so it is worth saying why that is not a hole in the rule. An exact trigger is a literal, pattern-bounded string; `post:*` is the only non-literal selector and expands only against concrete `post:<type>` surfaces. Both match surfaces apply *derived from authored work it already decided to write* (`Apply::rebuild_surfaces()`). Matching one grants exactly one thing: the declaring manifest's own action runs afterwards, inside its own declared effect budget. It grants no read of the other adapter's data, no say in whether that work happens, and no ability to change any rule the other adapter declared — which is the whole content of "authority" everywhere else on this page. That is what makes observation safe to leave open. The bounded selector is intentionally narrower than the general wildcard it resembles: term/table/option/entity wildcards remain malformed, and context-bearing providers must enumerate exact post kinds because their durable marker ownership cannot be wildcard-owned. Observation is open; authority is one-owner.

Three precedence families exist, and they are not interchangeable. A new vocabulary must pick one **and say which**:

1. **Core yields to plugin** — single-name rule lookups (`options`, `post_meta`, `term_meta`, `user_meta`, `menu_fields`). Site policy outranks everything; among manifests, a non-`core` declaration of a name always outranks `core`'s regardless of pin order (issue #3249), and two non-`core` manifests declaring the same name with different effective rules are refused outright rather than resolved by pin order (issue #3255). Reclassifications are plan-visible. The bulk option enumerators (`authored_options`, `sub_keyed_options`, `env_options`) belong to this family too, not to family 3: they resolve every name through the same single-name lookup rather than re-deriving precedence with a merge (`Policy::resolved_exact_options()`), so a bulk read can never pick a different winner than capture/apply does for the same name.
2. **First declaration in pin order wins** — structural facts about someone else's data shape (`block_attrs`, `shortcode_attrs`, `taxonomies.<t>.description_refs`, `post_types.<t>.body`/`.phase`/`.fields`/`.regen_dependency`, `dynamic_options`, `deletions`, `version_range`). These have no site-policy override, because they assert a fact about a plugin's own behavior rather than a site-local choice.
3. **Last pin wins, plus site policy last** — the two bulk table/widget enumerations: `tables` (site `policy.tables` overrides a manifest declaration wholesale) and `widgets` (no site layer at all — a site repository has no `policy.widgets`, so the last pinned manifest is simply last). This disagrees with family 1 for the same underlying data and is a known, documented inconsistency rather than a design (`Policy::declared_tables()` says so in its own docblock); it is called out here so a new vocabulary does not inherit it by accident.

Adapter-owned extension may never grant one adapter authority over another's state. The rule for the five bulk **named-declaration** surfaces — `post_types.<t>`, `tables.<t>`, `taxonomies.<t>`, `widgets.<t>`, `body_refs.<t>` — is **one owner per name**: two pinned manifests (including `core`) declaring the same name refuse at load unless their declarations are byte-identical, since a redundant restatement has no winner to pick. There is no composition grammar for these surfaces in v1 (`taxonomies.<t>`'s description_refs/object_type/class lookups are all first-pin-wins with no precedence layer, so it carries the identical hazard); reclassifying an individual FIELD of another adapter's surface is what the family-1 precedence layers exist for, never a whole-declaration takeover. The post-type surface additionally keeps a per-KEY contradiction guard, which fires first because it can name the exact contradicting key (`post_types.<t>.body` and so on) instead of only the name. Identical post-type declarations do not exempt their sibling `body_refs` records: the complete reference, sentinel, URL and privacy grammar must also agree in both live and immutable policy loaders. `site.wprism.json`'s own `policy.tables` is exempt from the rule, because it is the site's own last-word authority over its own state rather than a second adapter reaching into the first. The other load-time guards in the same family: one owner per option namespace, per plugin/theme version claim, per provider id, and per table `id_kind`; a provider-kind action may only name a provider its OWN manifest declares; an adapter widens the ref-kind vocabulary only by declaring a table it owns; and the two surfaces that name a `wprism_map` keyspace directly (`deletions[].guards[].id_kind`/`source_id_kind`, `option_name_refs[].id_kind`) are closed against the ledger's own long spellings plus the declared table kinds.

## Spec v3 — the windowed format (IN FORCE)

*Status of this whole section: **normative, and each subsection's own `Enforced today:` line is the
authority on its rule***. `WPRISM_SPEC_VERSION` is `3` (`agent/wprism.php:13`) and
`platform/adapter-library/capabilities/platform.json` restates that `3`. The flip is WP-4.12 and § v3.12 records exactly
what it did and did not move.

**What "v3 is in force" means, stated precisely, because it is easy to over-read.** It means the WIRE
VERSION IS 3 and the acceptance window is therefore `{2, 3}`. It did NOT restamp anything on the flag day:
all 16 shipped manifests then remained at `spec_version: 2`, every deployed repository retained whatever
its adopting agent wrote, and not one adapter digest, `manifest_hash`, content pin or compiled artifact
moved because the defines changed. Per-adapter migration is the intended later path: Paid Memberships Pro
was the first shipped manifest deliberately stamped to 3, paying its own identity change to consume
`invalidate-vocabulary/v1`; ten provider-bearing manifests later opt into
`manifest-provider-runtime/v1`. Redirection was authored after the flip with v3 feature declarations in
its first digest, and six pre-flag manifests remain at 2. A v3-gated rule therefore reaches only a document
that deliberately opts into it, never the retained library or repository population as a side effect.

Four rules were already in force before the bump — the acceptance window and the `engine_features`
channel (§ v3.1, § v3.2), environment narrowing (§ v3.5) and authority record v2 (§ v3.7) — and that was
the point: an engine that installs the window only on the day it needs it has already had the flag day.
What the bump changed for the rest is reachability, not text.

**Why a v3 at all, and why it is meant to be the last one.** The wire version WAS checked by exact
equality — `if (!is_int($spec) || $spec !== $supported)` — so an absent declaration and a declaration one
version behind produced the identical refusal, and every format change was therefore a flag day for every
adapter anyone had authored. v3's first rule (§ v3.1) installs an acceptance window (defined once in
`agent/src/Kernel/SpecVersionWindow.php` and read by both `AdapterContractGrammar::
validate_adapter_contract()` for a manifest and `RepositoryCompiler::compile()` for `site.wprism.json`);
every later format change stages through that window or through the per-adapter feature channel (§ v3.2),
one adapter at a time, and needs no further bump. The flip itself is the first evidence for that claim:
`engine_features` was implemented since `spec_version: 3` and could not be declared by any manifest the
v2 engine accepted; the bump brought it inside the window with no further change, which is what "the next
primitive rides the channel instead of a bump" looks like in practice. The measured cost of getting this
wrong is in
`sandbox/tests/offline/policy/regress_spec_v3_dry_run.php`, which evaluates each candidate rule against the
whole shipped library before it is enabled, and the window's own behaviour is pinned at both spec eras in
`sandbox/tests/offline/policy/regress_spec_window.php`.

**How to read a v3 subsection.** Each one carries a `Rider:` line naming the work package that implements
it and an `Enforced today:` line naming what the engine actually does now. That is the deferred-rows
discipline applied to a specification: a reader can tell, per rule, whether they are reading a contract
the engine keeps or a contract it has only promised. A rule with no shipped enforcement may not be relied
on by an adapter author, and an engine claiming to implement one must satisfy its rider's own acceptance
evidence before this line changes.

| § | rule | rider | enforced today |
|---|---|---|---|
| v3.1 | N/N-1 acceptance window, per-section refusal by name | WP-4.2 / WP-4.12 | YES — {2, 3}, for a manifest AND for `site.wprism.json`; floor gated at release |
| v3.2 | `engine_features` declaration channel | WP-4.2 / WP-6.1 | YES, DECLARABLE, and USED — eleven implemented features; eleven shipped declarers opt in per adapter with no engine version bump |
| v3.3 | closed top-level key set and its growth rule | WP-4.3 | YES from `spec_version: 2`; one set, gated at release; `_draft` is the recognised v2 authoring-only exception |
| v3.4 | per-adapter disposition addressing; per-subject registry pins | WP-4.4 / WP-4.5 | LAYOUT yes — one document per subject; ADDRESSING no — still one whole-document hash |
| v3.5 | per-adapter environment narrowing | WP-4.6 | YES at `spec_version: 3`; inert at v2 |
| v3.6 | certificates bind exercised axes; in-statement version; domain `/v2` | WP-4.7 | YES — v2 statements bind exercised cells; v1 generation withdraws per adapter |
| v3.7 | authority record v2, and the platform root's identity-only binding | WP-4.8 | YES — v2 records enforce; v1 unchanged; both roots bind identity |
| v3.8 | depth-1 delegation and typed revocation | WP-4.9 | no — no chain, one `status` word per key |
| v3.9 | namespace grammar and the closed grandfather list | WP-4.10 | names and provider ids: YES at `spec_version: 3`, live since the flip, inert at v2; `id_kind`: convention only (R-17) |
| v3.10 | reserved-but-refusing slots | WP-4.11 | YES — four slots refuse by name; the graduated verdict shipped as a word (WP-2.8) |
| v3.11 | the executable lane's evidence contract (gate G5) | WP-7.1 (shut) | n/a — the lane is shut and the reservations refuse |
| v3.13 | the `invalidate[]` vocabulary and its two-demand admission rule | WP-6.2 | YES — three verbs; the third gated on `invalidate-vocabulary/v1`, the first section shipped POST-v3 with no bump |
| v3.17 | a signing profile that accepts an author-written disposition | WP-5.3 | YES — `certify --ratification-file`, judged by the shipped disposition validator; the derivation stays the floor |
| v3.18 | the evidence grade: computed beside the reviewed word | WP-5.4 | YES — three axes derived on every call into a byte-compared document; no wire member, no stored verdict, no shipped byte |
| v3.19 | `wprism-adapter-index/v1` — discovery and distribution over an unsigned pointer document | WP-5.6 | YES — three host verbs, digest-pinned resolution that never falls through, one transport (`file://`); nothing under `agent/` reads the format |
| v3.22 | manifest-owned provider protocol in engine core | adapter absorption | YES — nine provider files declare contracts as data and retain only plugin semantics plus value-level verification |
| v3.23 | host-checkpointed schema settlement | Rank Math | YES — absent declared schema is created only after a bound checkpoint, with create-only row witnesses and durable provider-phase recovery |
| v3.24 | exact plugin incompatibility | Rank Math + Yoast | YES — a declared exact-basename conflict refuses one shared policy in either pin order before mutation authority |

The flip itself — the two defines, the migration verbs, the cohorted rollout and the rollback rehearsal —
is WP-4.12, is DONE, and § v3.12 is the record of what it deliberately left alone. The runbook that
executes it against a fleet is [docs/guides/flag-day.md](../docs/guides/flag-day.md).

### v3.1 The acceptance window: N and N-1

**Riders: WP-4.2 (manifests), WP-4.12 (`site.wprism.json`). Enforced today: yes, for both.**
`validate_adapter_contract()` accepts a manifest's `spec_version` ∈ {N-1, N} and
`RepositoryCompiler::compile()` accepts a repository's own on the same terms, both from the single
definition in `agent/src/Kernel/SpecVersionWindow.php`. `php tools/wire-surface.php --check` — a
`make release-gate` step — refuses the release unless the measured floor is exactly
`WPRISM_SPEC_VERSION - 1` (register row R-18).

An engine accepts a document declaring `spec_version` ∈ {N, N-1}, where N is the engine's own
`WPRISM_SPEC_VERSION`. The floor is exactly N-1 and never deeper: the release gate asserts the equality, so
N-2 can never accumulate by inattention, and closing the window is its own dated decision (§ v3.12), not
a side effect of the next release. At `WPRISM_SPEC_VERSION` 3 the window is {2, 3}, and that is what made
the flip a non-event for the field: no manifest and no repository was re-stamped, because the engine that
arrived already accepts the version each of them already declares.

**Both carriers, one window, and why the repository half is not an afterthought.** The manifest half was
the visible one — a format change is felt first by adapter authors — but the repository's own
`spec_version` is the same integer with a larger blast radius, because every site has one and no site
author chose it: `wprism adopt` wrote whatever the adopting agent's define said. Under exact equality the
first bump refuses `RepositoryCompiler::compile()` on the entire fleet simultaneously, on
`site.wprism.json:spec_version`, with a remedy that must be applied per repository by hand. The window makes
that a non-event on exactly the same terms it does for a manifest, and sharing one definition is what
keeps the two from drifting: a second `[$n - 1, $n]` written out in the compiler would be a floor the
release gate does not probe.

Three refusals, and the difference between them is the whole point of the window:

- an absent or non-integer `spec_version` keeps the older refusal, byte for byte. It is not a version, so
  there is no window to be inside;
- a version outside {N, N-1} refuses **wholesale**, naming the window it is outside;
- a manifest inside the window that declares a section the engine only implements at a HIGHER version
  refuses **per section, by name**. `engine_features` (§ v3.2) is the first such section and is what makes
  the rule live rather than hypothetical.

**What "scoped to that adapter" delivers, stated exactly.** The refusal names the ADAPTER and the SECTION,
never only "this engine requires spec_version N", because those are the two facts that decide whether an
operator edits a manifest or moves an engine. Its blast radius is the blast radius the SOURCE already
grants and no more: `wprism manifest-validate` loads every manifest on its own and prints a verdict per
manifest, so the rest of a pin set is judged and reported in the same run;
`AdapterSources::grammar_verdict()` judges one adapter at a time for the survey; and a plugin-bundled
adapter is refused per adapter, the walk continuing, which is the `SCOPE_ADAPTER` posture that exists so
one third party's defect cannot take an installation down (see "Plugin-bundled adapters" above). A PINNED
adapter still refuses the load, exactly as every other manifest grammar refusal does and exactly as a
pinned plugin-bundled refusal already does — dropping a pinned adapter silently would BE the state loss
this rule exists to prevent.

A window is not tolerance. It is a staging channel with an expiry, and a v3-only section inside a v2
manifest is refused by name — never ignored, never silently defaulted. The failure this rule exists to
prevent is the one "Vocabulary ownership and extension" already states for values: an unrecognised
declaration that means nothing is indistinguishable from a deliberate one, which is how a transposed
letter drops a plugin's authored rows out of canonical state.

### v3.2 `engine_features` — the declaration channel

**Rider: WP-4.2. Enforced today: yes.** `AdapterContractGrammar::IMPLEMENTED_FEATURES` is the engine-owned
vocabulary, `validate_adapter_contract()` refuses a declared name it does not carry, and register row R-19
records what a name costs once one is declared. Paid Memberships Pro is the first shipped manifest to
declare the key, using it to negotiate the generic invalidation verb that replaced its provider. The key
is a v3-only section (§ v3.1), so every use is an explicit per-adapter migration rather than a silent read
by an older manifest grammar. Redirection was authored later with the channel and therefore moved no
pre-existing adapter identity.

A manifest may declare `"engine_features": ["<feature>", …]`, a sorted, duplicate-free, non-empty list of
the engine features its declarations depend on. An engine that implements every listed feature loads the
adapter; an engine that lacks one refuses **that adapter, naming the feature and the adapter**, with the
same blast radius § v3.1 states exactly.

This is what keeps the closed key set (§ v3.3) from becoming the next flag day. A post-v3 primitive ships
as: an engine feature name, whatever manifest key the feature claims, and a refusal for the engine that
does not have it. An older v3 engine meeting a manifest that uses the primitive says so by name instead of
mis-reading the declaration, and no version integer moves anywhere. The three facts a feature decides —
its name, the first `spec_version` its sections exist at, and the top-level keys it claims — are ONE
constant in the engine, because a feature that is implemented while its section is unknown (or the
reverse) is precisely the silent mis-read the channel exists to remove.

The implemented feature roster is emitted by `wprism manifest-validate --emit-schema`;
the following features use its `spec-window/v1` channel:

- **`provider-filesystem-file-snapshot/v1`** adds no manifest section or mutation
  authority. It negotiates `ProviderSdk::filesystem_file_snapshot(root, canonicalPath)`:
  a bounded observation of one present regular file or verified absence under
  existing, confined parents. Its result and refusal boundaries are described in
  the [adapter authoring guide](../docs/guides/adapter-authoring.md#fresh-process-capabilities).

- **`spec-window/v1`** — the acceptance window of § v3.1 and this channel itself, claiming the
  `engine_features` key from `spec_version` 3. It is a real entry, not a placeholder — the channel's own
  requirement is that one feature the engine IMPLEMENTS exists on the day it ships, so that "declared and
  implemented admits the claimed key" is a path something walks rather than an argument about
  admissibility. That path is walked in `sandbox/tests/offline/policy/regress_spec_window.php`, against a
  synthetic `spec_version` 3 engine before the flip and against the shipped engine after it.
- **`typed-column-codecs/v1`** (WP-6.1) — claims `column_codecs` from `spec_version` 3: per typed table,
  per authored column, `{container, leaves}`, both members required and both vocabularies closed. It
  decodes the column's container, rewrites its string leaves and re-encodes with correct length prefixes.
- **`mixed-column-codecs/v1`** — claims no additional top-level key. It admits the
  `php_serialized_or_text` container inside `column_codecs` for a measured column that stores canonical
  PHP-serialized containers, plain text, and SQL `NULL`. Serialized-looking malformed bytes and values of
  any other type refuse; the strict `php_serialized` container keeps its original semantics.
- **`attr-id-codecs/v1`** (WP-6.1) — claims `attr_id_codecs` from `spec_version` 3: per block, per
  attribute path, `{id_type}`, a closed vocabulary of one member (`string`). It decides the JSON type a
  resolved entity id is written back as, instead of normalising every id to an integer.

**`typed-column-codecs/v1` and `attr-id-codecs/v1` are the evidence for the claim this section makes.** They are the first grammar this engine
grew after v3, they shipped through this channel and NOTHING ELSE, and `WPRISM_SPEC_VERSION` is still 3 —
asserted by `sandbox/tests/offline/grammar/regress_column_codec_grammar.php` and
`sandbox/tests/offline/grammar/regress_attr_id_codec_grammar.php`, which also walk each section's three
distinct verdicts: refused BY SECTION in a `spec_version: 2` manifest (§ v3.1), refused BY KEY in a v3
manifest that does not declare the feature (§ v3.3), refused BY FEATURE NAME on an engine that lacks it.
Neither is a new addressing surface: one refines how a declared `tables.<t>.columns.<c>` decodes and the
other how a declared `block_attrs` rule re-encodes. Both are nonetheless TOP-LEVEL keys, because a field
nested inside an existing section cannot be staged — an engine that predates it would ignore the field and
carry the plugin's bytes into canonical state unchanged, which is exactly the silent mis-read this channel
converts into a named refusal. Redirection is the first shipped manifest to declare the typed and mixed
codec features; pre-existing manifests remain byte-identical. `tools/engine-gaps.json` records the demand
each one closed and the coordinates that stayed open beside it.
- **`structured-evidence/v1`** (WP-6.4, § v3.14) — claims `declaration_evidence` from `spec_version` 3:
  the typed sibling of `notes` carrying per-declaration `{source, locator, observation}` evidence rows and
  answered questions, keyed by the declaration they justify. The first section added AFTER v3 shipped —
  its suite asserts `WPRISM_SPEC_VERSION` is still 3 in the same run that walks all three verdicts, which is
  the no-bump proof § v3.12's window-close condition demands.
- **`invalidate-vocabulary/v1`** (WP-6.2, § v3.15) — claims NO top-level key: it widens the
  `tables.<t>.invalidate[]` verb vocabulary with `{cache_group, cache_key}` inside a section that already
  exists, the row that shows a feature can stage a VALUE-vocabulary change without inventing a section.
- **`manifest-provider-runtime/v1`** (§ v3.22) — claims NO top-level key: it widens a manifest-sourced
  `providers[]` row with a closed `contracts` map and moves identity, capability advertising, dispatch,
  scoped receipt construction, recovery routing, and receipt-shape enforcement into engine core.
- **`plugin-incompatibility/v1`** (§ v3.24) — claims the non-surface
  `incompatible_plugins` top-level key: a sorted exact-basename list that
  narrows which other plugin adapters may share the declaring adapter's policy.
  The shared policy finalizer refuses a claimed conflict before any consumer
  receives the aggregate policy.
- **`post-kind-action-trigger/v1`** — claims NO top-level key: it widens the
  `actions[].triggers` value vocabulary with the single bounded selector `post:*`.
  That selector matches concrete post-kind surfaces only and never enters a provider
  batch as a wildcard. All other wildcard-shaped triggers remain malformed, and a
  provider declaring durable engine context channels must use exact post kinds.
- **`schema-settlement/v1`** (§ v3.23) — claims NO top-level key: it widens a
  provider action with the `schema_settle` phase, its exact `prepares` table set,
  and a distinct read-only readiness capability. The phase is host-only,
  checkpoint-bound, create-only, and durably recoverable.
- **`structured-body-refs/v1`** (WP-6.5, § v3.20) — claims `body_refs` and admits the `json` post-body
  mode under one feature, so a document cannot declare either inert half without the other.


A feature need not claim a key at all. WP-6.2 is the worked example: `invalidate-vocabulary/v1` widens a
VALUE vocabulary inside `tables.<t>.invalidate[]`, a section that already exists, so its `keys` list is
empty and § v3.3's partition does not move. The channel gates the value the same way it would gate a
section — declared-and-implemented admits it, declared-and-unimplemented refuses by feature name — which
is what let a grammar change ship after the flip with nothing re-stamped (§ v3.15).

PMPro was the first shipped adapter to walk that path: it declares both `spec-window/v1` and
`invalidate-vocabulary/v1`, because the first claims the channel key and the second widens the value
vocabulary. Ten provider-bearing manifests now declare `spec-window/v1` and
`manifest-provider-runtime/v1` for the same reason. Those paired declarations are the growth rule
working, not redundant metadata. Redirection was authored with those runtime declarations plus
`typed-column-codecs/v1` and `mixed-column-codecs/v1`, whose pairing similarly admits a measured mixed
container without inventing a second top-level section.

Feature names are engine-owned: an adapter may declare one, never mint one. A name nothing implements is
refused as unimplemented rather than admitted as forward-looking — the honest-refusal posture, which is
what distinguishes this channel from `authored_typed_snapshot_post_v1`, the existing declared-but-not-
implemented marker (see "Custom tables" above), whose one honest property is that it captures nothing and
says so.

### v3.3 The closed top-level key set, and how it grows

**Rider: WP-4.3. Enforced today: yes, for every accepted manifest version.**
`AdapterContractGrammar::validate_adapter_contract()` refuses an unrecognised top-level key by name
(`assert_top_level_keys()`, `agent/src/Adapter/AdapterContractGrammar.php:459`), reading the set from
`AdapterCertification::topLevelKeyPartition()` rather than from a list of its own; `php
tools/wire-surface.php --check`, a `make release-gate` step, asserts that the validator's admitted set
and the signer's partition are the SAME SET in both directions (register row R-21).

Before this rider the set refused in exactly one place — `AdapterCertification::siteRatification()`
(`agent/src/Adapter/AdapterCertification.php:943`), which most authors reach long after the typo — so a
manifest carrying `totally_made_up_section` and a transposed `optoins` validated `[ok]` and was then
unsignable. Both halves were measured in `regress_spec_v3_dry_run.php` under rule V3-KEYS before the rule
was turned on, and the same fixtures now refuse.

From `spec_version: 2` the top-level key set is CLOSED: a key in no arm of the partition, and claimed by no
implemented engine feature, refuses at load, by name. This changes no shipped manifest bytes or adapter
digests because every shipped key is already admitted; it changes only the reader's verdict on invented or
transposed sections. The recognised `_draft` authoring sidecar remains loadable at v2 so `wprism adapter-draft`
can validate facts while keeping proposals inert, but it remains unsignable and refuses from v3 with its own
strip-before-install remedy. The product-path and synthetic next-engine verdicts live in
`sandbox/tests/offline/policy/regress_closed_top_level_keys.php`.

The set has one definition, not two. It is the signer's own three-arm partition
(`AdapterCertification.php:265-300`), published by `AdapterCertification::topLevelKeyPartition()`
(`:347`) and emitted by `wprism manifest-validate --emit-schema` as `wprism-manifest-grammar/v2`'s
`top_level_keys` block. The arms are kept apart because a key's arm decides what a derived ratification
says about it — an entity section becomes a covered surface, a non-surface key covers nothing — so
flattening them would publish less than the engine knows:

- **entity sections** (5): `post_types`, `tables`, `taxonomies`, `taxonomy_patterns`, `widgets`;
- **field sections** (14): `block_attrs`, `dynamic_options`, `interpreter`, `menu_fields`,
  `meta_patterns`, `option_name_refs`, `option_namespaces`, `option_patterns`, `options`, `post_meta`,
  `post_meta_patterns`, `shortcode_attrs`, `term_meta`, `user_meta`;
- **non-surface keys** (14, declaring no branchable state surface of their own): `actions`, `deletions`,
  `environment`, `lifecycle_effects`, `name`, `note`, `notes`, `option_autoload`, `plugin`, `providers`,
  `spec_version`, `theme`, `theme_version_range`, `version_range`.

`environment` and `theme_version_range` are the two newest members, and each is this rule's own discipline
exercised once: a key joins the partition in the change that reads it, never ahead of one. WP-4.6 added
the first with the narrowing rule (§ v3.5) and WP-4.3 added the second with resolution 1 below, both
because a key in no arm makes its whole adapter unsignable and a channel nobody can certify is not a
channel. No shipped adapter declares either, or `theme`, so admitting them moved no digest and no
certificate.

**The growth rule.** A closed set that cannot grow is the next flag day. It grows in exactly one way: a
key claimed by a declared `engine_features` value the engine IMPLEMENTS is admitted; a key claimed by a
declared feature the engine does NOT implement refuses by feature name (§ v3.2); a key nothing claims
refuses as an unrecognised section. Those three verdicts are distinct and each is pinned by suite. There
is no fourth answer, and in particular there is no "unknown keys are ignored" answer — that is the
behaviour v3 removes. `engine_features` itself is the first worked example: it is in no arm of the
partition and is admitted only because `spec-window/v1` — a feature this engine implements — claims it.
`declaration_evidence` (§ v3.13) is the second, and the one that exercises the rule for a section v3 did
not have: it was added after the flip, admitted only by a feature declared in the manifest, and cost no
version bump. The set GREW, which is the property this paragraph asserts and the one an unexercised growth
rule cannot evidence.

**What the growth rule admits, and what it does not.** It admits at LOAD and does not classify. A feature
record carries `{since, keys}` and no arm (`AdapterContractGrammar::IMPLEMENTED_FEATURES`), so a
feature-claimed key has no reviewed answer to the only question the signer asks — whether a certificate
covers it as a surface — and `siteRatification()` still refuses it by name with "teach the signer this
section". That is the honest refusal and not an oversight: a certificate that silently omitted a declared
section would cover less than the adapter does. A feature whose key must also be SIGNABLE therefore gives
that key an arm in the partition in the same change, exactly as WP-4.6 did for `environment`. Until one
does, an adapter using the channel loads everywhere and is not certifiable, which is a state an operator
can see rather than one they discover from a missing surface.

**Two reviewed decisions, resolved here rather than left as findings.** WP-1.6's dry run measured the
partition against every key in use and found the difference in both directions. Both are settled as
follows, and WP-4.3 implemented them with the rule:

1. **`theme_version_range` JOINS the partition, as a non-surface key.** The partition knew `theme` but
   not its mandatory companion, while `validate_adapter_contract()` (`AdapterContractGrammar.php:507`)
   refuses a `theme` declared without a `theme_version_range` and `ArtifactPolicyIdentity` folds the range
   into the adapter identity row (`agent/src/Policy/ArtifactPolicyIdentity.php:167-168`). A theme adapter
   therefore validated and was then unsignable — the partition incomplete against the shipped grammar by
   exactly one key. It is a field-adjacent contract key with precisely the standing `version_range`
   already has (a bound on the subject, not a state surface), so it takes `version_range`'s arm. No
   shipped adapter declares `theme`, so admitting it moved no digest and no certificate.
2. **`_draft` is REFUSED at v3, as an authoring artifact.** `wprism adapter-draft` writes a top-level
   `_draft` into the manifest it hands the author (`cli/src/Adapter/AdapterDraft.php:379`); the key is in
   no arm, so draft output is unsignable and is refused by name at v3. That refusal is correct and is
   kept: a `_draft` sidecar is a proposal record for a human, its contents are inert by construction, and
   admitting it into the closed set would put unreviewed proposals inside the identity row every
   certificate covers. The remedy is that a draft is STRIPPED before install —
   `wprism manifest-validate` already reports the sidecar's facts/proposals/unsupported counts on every run —
   so the v3 refusal names `_draft`, says it is the drafting sidecar, and says to strip it, rather than
   telling the author to declare an engine feature they may not mint. A drafting tool may keep the sidecar
   in its own working copy; a manifest with a `_draft` key is not installable at v3.

### v3.4 Per-adapter disposition addressing, and per-subject registry pins

**Riders: WP-4.4 (layout) and WP-4.5 (addressing). Enforced today: the flat-library
split was enforced then and is superseded by the capsule layout in “Adapter
manifests”; the ADDRESSING proposal remains unimplemented.**
`manifests/dispositions.json` was one document — 302 lines, 37,707 bytes, 16 entries plus one `profiles`
row (`fse`) — where one missing entry refused `Policy::load()` for every site and every unrelated
adapter. WP-4.4 removed that file. At that stage the reviewed claim source
became addressed per subject, ahead of the version bump for the reason § v3.1
gives about the window: a layout change that moves no wire byte has
nothing to gain by waiting, and everything to gain from being proved on a tree nobody has flipped yet.

```
adapter-packages/<name>/package/disposition.json  # one document per plugin adapter
platform/adapter-library/core/disposition.json    # the platform-owned core adapter
platform/adapter-library/profiles.json            # profiles, keyed independently of package discovery
```

The reviewed source has one disposition per adapter, plus the profiles document.
Package discovery determines the subject set. Adding a capsule adds its own root;
source line and byte counts are not a second adapter inventory.

Each document carries the entry's DECODED array unchanged, so `Canon::encode` of the disposition member
is byte-identical before and after and no adapter digest moves. That is the invariant the whole flag day
rests on: `ArtifactPolicyIdentity::manifest_rows()` folds each manifest's own disposition into that
adapter's row (`:82`) and the row hashed is its `digest` (`:162`), so a canonical-encoding difference of
one byte in one document would move that adapter's digest and every `site.wprism.json` pin naming it. It is
proved rather than argued: `regress_disposition_split.php` retains a historical 21-subject fixture through three
compatible worlds, their `manifest_hash` and snapshot, and `registry_sha256` as
explicit greenfield literals. New capsules do not expand that historical fixture;
their package-local evidence validates their identities. Separate frozen constants preserve the pre-move capture, and the
suite carries one case per enumerated Canon-encoding hazard, in three verdicts rather than one. A nested LIST re-ordered and a UTF-8 prose
`reason` re-composed each MOVE a digest, so the equality above is a measurement and not a tautology. Map
KEY order at every nesting level moves nothing — that is precisely what makes lifting an entry out of a
document admissible. And the int/float round trip is the hazard a digest CANNOT catch: `Canon::encode()`
does not pass `JSON_PRESERVE_ZERO_FRACTION`, so `3` and `3.0` are the same bytes, and two integers past
PHP's precision collide on one float. Its guard is the census beside it — no shipped reviewed member is a
number at all — which is why the split's own decode/encode rewrite was lossless in fact rather than by
argument.
Every v3 disposition key (§ v3.5's axis narrowing, § v3.10's reviewer evidence, an expiry) is OPTIONAL
with a default that reproduces today's value exactly.

Refusals are preserved, not relaxed: a document missing for a PINNED adapter refuses by name, in the
monolith's coverage-mismatch sentence to the byte — `assert_covers()` distinguishes an absent document
from one that decodes to `null`, so a present-but-malformed entry still meets the per-entry validator's
own wording rather than being reported as missing. Two rules the directory adds, because a namespace can
carry mistakes a key set could not: a file not named for a canonical adapter slug refuses instead of
being skipped, and `profiles` is reserved, so no adapter may be called that. A stale
`dispositions.json` beside the directory refuses the load by name — the bytes an operator believes
ratify their library must never be inert. The existing external-entry validator
(`ManifestDispositions::validate_external_entry()`) is the seam — it already validated one entry living
outside the monolith, on the site-certificate path — so the split was a layout change against proven
semantics rather than a second long-lived validator.

**Per-subject addressing is also what makes the reviewed source stop being a per-entry load cost.**
`load()` decodes one entry document per PIN and scans the subject directory only when a profile has to
resolve against it, where the monolith was decoded whole on every `Policy::load()`. Measured in
`regress_policy_load_scale.php` over an identical 10,000-manifest library: a 10,001-subject reviewed
directory now costs what a 2-subject one costs (7,495,944 bytes both ways), where the 10,001-entry
monolith cost 10,346,448 against the 2-entry document's 7,495,944. A whole-library survey pays the
mirror of that trade — one document opened per subject rather than one file for all of them, the same
bytes counted differently (`regress_adapter_survey_scale.php`, 2n reads for n adapters).

**Registry pins address subjects, not the document.** A host contract's `evidence_pins.registry_sha256`
is `hash('sha256', Canon::encode($whole_document))` (`agent/src/Policy/ManifestDispositions.php:478-480`,
over the document `data()` reassembles from the per-subject files),
so a one-space edit to one adapter's `reason` invalidates a contract that pins no adapter at all — the
projection says so in its own words: "It proves that something moved and nothing about what"
(`cli/src/Contract/ContractProjection.php:186-189`). At v3 the pin is per subject (per-subject digests, or
a Merkle root over the per-subject rows). The narrowing may only ever remove refusals that are PROVABLY
unrelated: an edit attributable to no pinned subject still invalidates fail-closed, which is the branch
`ContractProjection::invalidation()` already implements as `whole-contract` and which stays.

### v3.5 Per-adapter environment narrowing, never widening

**Rider: WP-4.6. Enforced today: yes, for a `spec_version: 3` manifest; inert for v2.**
`claim_from_disposition()` used to copy `environment_assumptions` verbatim out of the single global
`platform.json` into every claim, so all 16 shipped claims carried byte-identical environment assumptions
and no adapter could state anything about the cells it actually ran on. It now projects them through
`ManifestDispositions::narrowed_environment()`, which honours the adapter's own declaration.

An adapter may declare an environment that is a SUBSET of the reviewed platform boundary — the same
subset shape `unsupported[]` already uses for surfaces — and that declaration narrows the CLAIM only:

- a narrower declaration is honoured and reported;
- a WIDER one refuses by name. An adapter may never claim a cell the reviewed boundary does not carry;
- an adapter declaring nothing binds the whole current boundary, which is today's behaviour exactly.

**The declaration.** A top-level `environment` key, an object of axis to the list of boundary cells that
axis was exercised on:

```json
"environment": {"database": ["MariaDB"], "php": ["8.3"], "site_mode": ["single-site"], "wordpress": ["6.9", "7.0"]}
```

The four narrowable axes are exactly the four members a capability claim states — `site_mode` plus the
`php`, `database` and `wordpress` compatibility axes. `filesystem` and `process` are load-time profiles
that no claim states, so naming one would narrow a sentence the claim never makes and refuses by name
along with any other unrecognised axis. A cell is the boundary's own vocabulary for that axis: an
exercised series of `php.verified`/`wordpress.verified`, a key of `database.engines`, or the one reviewed
`site_mode` value — so `"site_mode": ["multisite"]` is a widening and is refused, which is the sharpest
demonstration that this channel subtracts and never adds. An axis the declaration omits keeps the whole
boundary; narrowing is opt-in per axis. A narrowed `wordpress` axis recomputes its own `last_verified`,
because that scalar is the GREATEST exercised core and must be a member of the map it heads
(`PlatformCompatibility::valid_wordpress_axis()`).

`environment` therefore JOINS § v3.3's partition as a non-surface key — it covers no branchable state —
and it joins in the same change that reads it, because a top-level key in no arm makes the whole adapter
unsignable (the `theme_version_range` case measured under rule V3-KEYS): a narrowing channel only
uncertified adapters could use would be no channel at all.

**What v2 does with it.** Nothing. Under a `spec_version: 2` manifest the key is INERT — the claim is the
whole boundary, byte for byte, exactly as if the key were absent — which is the same silence
`engine_features` sits in (§ v3.2). Refusing a v3-only section inside a v2 manifest BY NAME rather than
ignoring it is § v3.1's acceptance window, and it is the only mechanism that can do so without refusing
the manifest wholesale. `WPRISM_SPEC_VERSION` is `3`; the 16 pre-flag manifests remain at v2 and newly
authored Redirection is v3 but declares no `environment` narrowing, so no shipped claim is narrowed today.

Narrowing relaxes no load-time assertion. `PlatformCompatibility::assert_supported()` still gates
`site_mode`, PHP, database engine and version, WordPress version, the filesystem profile and the process
profile exactly as it does now — it takes no manifest and reads no claim, so a declaration cannot reach
it, and `regress_adapter_environment_narrowing.php` drives every one of those refusals with a narrowing
adapter projected. A claim that says which cells were exercised is strictly more information than one
that inherits the whole boundary; it is not permission to run outside it.

### v3.6 Certificates bind exercised axes, carry an in-statement version, and move to domain `/v2`

**Rider: WP-4.7. Enforced today: yes, and gated on the CERTIFICATE WIRE GENERATION, never on
`spec_version`.** Until WP-4.7 verification was
`hash_equals(Canon::encode($platform), Canon::encode($statementTyped->platform))` — the WHOLE platform
record, byte for byte — so every agent release invalidated every certificate in existence, including
patch releases that moved no axis anyone exercised. `AdapterCertification::assertPlatformBinding()` is
that comparison's replacement.

A v2-generation certificate binds:

- `spec_version` — the grammar gate, unchanged. A certificate never verifies across a grammar bump;
- `site_mode`. It is not a compatibility axis, but it is one of the four cells a capability claim states
  and one of the four § v3.5 lets an adapter narrow, so a certificate that did not bind it could project
  a claim naming a site mode nobody exercised;
- a per-axis digest of the **compatibility axes** the certificate was exercised against. The boundary
  declares five as of #560 — `database`, `filesystem`, `php`, `process`, `wordpress` — and `process` is
  the newest (the bounded WP-CLI child transport's POSIX process-group profile). A new axis is a reviewed
  sentence in this section, never a silent widening of what a certificate signs over;
- a `version` field INSIDE the signed statement, so a future wire change is refused BY VERSION rather
  than read as corruption. This is the member the v1 statement could not grow (see below), which is why
  it had to be added in the same change that changed the binding.

**What one axis contributes, and the single rule that decides it.** A cell's bound value is everything
the boundary uses to ACCEPT a runtime on that cell, and nothing it uses only to WITNESS one. The three
shapes the boundary actually declares each answer that rule differently, and the engine refuses an axis
carrying two of them rather than choosing:

- an axis with a `verified` series map (`php`, `wordpress`) binds the series NAMES and not the patches
  beside them, because acceptance is series membership — "a runtime is accepted only when it is inside
  [min, max) AND its MAJOR.MINOR is one of those exercised series";
- an axis with an `engines` map (`database`) binds each engine's own min/max line, because there "the
  range is now a function of the engine": the value IS the acceptance term, so widening `MySQL` to admit
  a 9.x nobody ran is not new evidence for the same cell but a different cell wearing the same name;
- an axis with neither (`filesystem`, `process`) is one reviewed PROFILE — a versioned identity plus the
  functions, families and separators the gate requires. That whole object minus its prose `note` is one
  cell, named by the profile string.

`min`/`max`, every `note`, and `wordpress`'s derived `last_verified` are outside every binding. A release
that exercises a new series moves `max` in the same edit that adds the cell, so binding the range would
make every additive release invalidate every certificate — the pathology this subsection exists to end.
The residual that leaves is stated rather than argued away: a boundary that NARROWED `[min, max)` around
an already-bound series moves no cell and raises nothing here. That is not a hole in the honesty
property, because a certificate is not what admits a runtime — `PlatformCompatibility::assert_supported()`
gates every load on the range AND the series against the boundary installed now, so such a site refuses
at load time on the axis itself.

`SIGNATURE_DOMAIN` moves to `wprism-site-adapter-certification-signature/v2\0` because the binding semantics
changed. Per the irreversibility register's R-01, a v2 domain is a NEW statement type verified BESIDE the
v1 one, never an edit of it: an agent may verify both, and a certificate says which it is by the bytes it
was signed over. WHICH GENERATION a certificate is must therefore be decided without a signature — the
domain is what the generation names — so it is decided by the statement's MEMBER SET: a v2 statement
carries `version`, a v1 statement is exactly the five members R-06 closed. A v1-generation statement met
by this agent degrades to `uncertified` by name (the typed withdrawal
`SupersededWireSiteAdapterCertificate`), per adapter, on the live scan and inside a frozen snapshot alike
— never a whole-source refusal that would take the site's unrelated adapters down with it. An
in-statement `version` this engine does not implement takes the same route, refused by VERSION rather
than read as corruption. Both tests sit BEHIND the closed root key set, the canonical base64
Ed25519-length signature check and the statement's own member-shape proofs, so a hand-authored file
cannot reach the degrade path by being cheap.

The honesty property is carried by the axes, not by `agent_version`: a claim may not describe a runtime
nobody ran, and after this change it states WHICH runtime cells it covers. `agent_version` stays inside
the signature — an operator still has to be told which agent state a certificate was minted beside, and
`wprism adapter doctor --migration` reads it — but it is no longer the thing validity turns on.
`branchable_state` and `plugin_execution` are outside the binding entirely: they are prose about the
agent's posture rather than runtime cells anything was exercised against, and a claim restates them from
the boundary installed now. Recording a newly exercised PHP patch adds coverage and invalidates nothing;
so does an axis or a cell the boundary GAINS; changing a bound axis invalidates.

### v3.7 Authority record v2, and the platform root's identity-only binding

**Rider: WP-4.8. Enforced today: YES — this is the one v3 subsection with enforcement behind it, and it
is gated on the AUTHORITIES DOCUMENT, never on `spec_version`.** A `wprism-adapter-authorities/v1` document
keeps today's behaviour byte for byte: six-member records (`adapter_names`, `algorithm`, `public_key`,
`scope`, `status`, `trust_tiers`), exact adapter names, no window, no envelope signature, and a key id held
to nothing but the shared identity slug grammar. A document declaring `wprism-adapter-authorities/v2` accepts
all five rules below at once. `platform/adapter-library/capabilities/adapter-authorities.json` is `{"keys":{}}` and stays
empty through the flag day: issuing one key freezes this wire format in a stranger's hands.

WP-4.8 rides the flag day rather than the first enrollment because of change (5): a trust root chooses its
record binding at the moment its first certificate is signed and never after (R-08), so the platform root's
binding is fixable exactly while that file is empty and not one hour later.

**Why the DOCUMENT format is the gate, not `spec_version`.** An authority record has no manifest around it
to carry a wire version, and on the frozen path it has no document around it either — `verifyFrozen()`
re-binds a site certificate to the record its own SIGNATURE covers, which arrives with no `format` line
above it. So v2 states its version twice: `wprism-adapter-authorities/v2` on the envelope and
`record_version: 2` inside every record, with a disagreement between the two REFUSED in either direction.
The in-record member is what makes a grammar this engine does not implement refuse BY VERSION rather than
read as corruption — the same member § v3.6 adds inside the certification statement, for the same reason —
and it is what stops a tamperer downgrading a record by DELETING bytes.

Five changes, one grammar:

1. **Key ids are fingerprint-derived by grammar, not by keygen default.** `keyId()` is nothing but the
   shared identity slug check, while the host side already DERIVES
   `site-<first 12 hex of sha256(public key)>` as a default an operator may override
   (`cli/src/Adapter/AdapterCertify.php:1241`). At v2 the derivation IS the grammar: a key id must END in
   `-<first 12 hex of sha256(its own public_key)>`, so a squatted or misleading id is unrepresentable
   rather than merely discouraged, and swapping the key under a legitimate id is the same refusal read
   from the other end. The label half stays free — `<anything the slug grammar allows>-<fingerprint>` —
   because the fingerprint is what has to be honest, not the noun in front of it; 12 hex is the length
   `wprism adapter keygen` already emits, so v2 mints no second convention. 48 bits is a selector, not a
   signature, and is not asked to be one: the `key_id` inside the statement is what a signature binds.
   Existing certificates keep verifying under whatever id they were signed with, because `key_id` is
   already inside the signature and this rule reads only a record that declared `record_version: 2`.
2. **`not_after`, with a NAMED clock source and a stated implausible-clock posture.** The clock is the
   verifying host's own wall clock — literally `$now ?? time()`, the same expression the contract root is
   judged against (R-14) — and the refusal names it and prints what it read. There is no skew allowance in
   either direction, and the comparison is `>=`, so a record refuses AT its `not_after` and not one second
   later. An implausible clock — one reading before the record's own issuance instant — REFUSES, and that
   test runs FIRST, because a backwards clock would otherwise find every already-retired record inside its
   window: the expiry test alone would RESURRECT it.
   *Resolved here:* the issuance instant is a member, `not_before`, and both ends are MANDATORY at v2. The
   spec text named an issuance instant without naming a member; without one the implausible-clock refusal
   is inexpressible, and an optional member has no honest home in a key set that refuses missing and
   unknown alike (R-05's `assertExactKeys()` posture). Both parse strictly at `Y-m-d\TH:i:s\Z`, checked
   at both ends so "expired" is never a parse accident, and `not_before` must be strictly less than
   `not_after`. A holder that wants no expiry keeps its record at v1, where there is none. The window is
   enforced in the same seat as revocation (`assertAuthorityScope()`), so an expired key refuses wherever a
   revoked one does — signing, live verification, and the frozen path — and revocation still answers first.
   *Deferred, and named rather than assumed:* approaching expiry as a fleet-health census row is NOT in
   this rider. No v2 record exists anywhere yet, so the row would report on an empty set; it rides with the
   first enrollment (WP-5.1), which is also the first moment it has a subject.
3. **`adapter_names` admits a namespace PATTERN beside exact names**, evaluated at the same live scope
   check the shipped code performs (`assertAuthorityScope()`). `acme-*` covers `acme-forms` and anything
   deeper; it does not cover `acme` itself, and it does not cover `acmex-forms`.
   *Resolved here:* "scope can never widen through a pattern" is enforced as a GRAMMAR restriction rather
   than a review rule, because at the record level a pattern IS the grant and there is no delegator to
   narrow against (that is § v3.8's job). The wildcard must therefore bind a non-empty vendor namespace:
   `<vendor>-*` and nothing else, with the vendor half held to the one shared identity grammar every
   adapter name is held to. A bare `*`, a bare suffix `*-forms`, an interior `acme-*-pro` and a doubled
   `acme-**` are each refused by name. A wildcard binding no prefix is exactly the widening this rule
   forbids, so the grammar cannot express it at all.
4. **The authorities document gains a signed envelope**, checked by `make release-gate` the way the
   generated capability document and the classmap already are. The signature is its own domain-separated
   statement type — `wprism-adapter-authorities-signature/v1\0` prepended to `Canon::encode({format, keys})`,
   the document minus its own signature — never an arm inside the certification verifier (R-01/R-04), and
   it is made by a key the document ITSELF carries. What that proves is exact: a signed registry cannot be
   PARTIALLY edited, so nobody without the signing key can append a key, widen a scope list, move a window
   or flip a status in it — which is the property enrollment needs, because enrollment is the moment this
   file starts growing under a hand other than a reviewer's. What it does not prove, stated so nobody reads
   more into it: it is not a chain to an off-document root. Delegation is § v3.8's own signed statement
   type. The signer must be `trusted`; its own window is deliberately NOT applied, because bricking every
   other vendor's key in the file when one signer's window lapses is a blast radius this section exists to
   remove rather than add.
   *Resolved here:* an EMPTY registry needs no signature — because an empty v2 registry is
   **unrepresentable**. The envelope signature names a key inside the document, so a registry with no keys
   has nothing that could sign it, and a signature over an empty key set would prove nothing about any key.
   An empty registry stays `wprism-adapter-authorities/v1`; v2 is the ENROLLED format. That is precisely what
   lets `platform/adapter-library/capabilities/adapter-authorities.json` stay `{"keys":{}}`, byte-identical, through the
   flag day. The release gate accordingly admits exactly two states for that file — the empty v1 registry
   byte for byte, or a v2 document that verifies through the SHIPPED reader — and refuses everything else.
5. **The platform root adopts the site root's identity-only record binding.** The site branch binds
   `authorityIdentity()` — everything but the scope lists — because the site trust root is a living
   registry: binding the whole record would invalidate every earlier certificate under that key the moment
   a second adapter is certified. The platform branch bound the whole record, justified by a premise
   enrollment falsifies — that the shipped file never grows under an operator's hand. It will grow, once
   per enrolled vendor, and each growth would invalidate every certificate already issued under that key,
   silently and all at once. The platform root therefore binds identity too, and the second-enrollment case
   is a named regression rather than an inference: certify adapter A under a platform key, enroll adapter B
   on the same key, and A's certificate still verifies
   (`sandbox/tests/offline/adapter/regress_site_adapter_certification.php`).
   Nothing is laundered by the narrowing, and the suite asserts both halves: the two scope lists are
   enforced LIVE against the current record (a key narrowed out of an adapter still refuses, by name), and
   `authorityIdentity()` drops the scope lists and NOTHING ELSE — so a moved `status`, `public_key`,
   `record_version` or window is still an identity move that refuses. The proof digest every repository pin
   binds now follows the record the certificate was SIGNED over under both roots, which is the identical
   value for every certificate that verified before this change: the old platform branch REQUIRED
   `record_sha256` to equal the installed record's digest, so the two were equal by construction.

Per R-08, a future root chooses one of the two bindings at the moment its first certificate is signed and
never after. This change is possible only because the platform root has never signed one.

### v3.8 Depth-1 delegated authorities, and typed revocation

**Rider: WP-4.9. Enforced today: YES — gated on TWO DOCUMENTS THAT DO NOT EXIST, never on `spec_version`.**
An agent that meets neither behaves exactly as it does today: `adapters/delegations.json` is absent from
every repository in the field and `capabilities/adapter-revocations.json` is absent from the shipped
manifest library and stays absent. There is still no chain beyond one level, no cross-signing and no path
validation, and that remains deliberate design rather than an unfinished edge.

A **delegation document** is a new domain-separated signed statement type in which a platform key
delegates a namespace pattern, a tier set and a validity window to a vendor key, installable site-side. It
gets its own signature domain constant —
`AdapterCertification::SIGNATURE_DOMAIN_DELEGATION`, `wprism-adapter-authority-delegation-signature/v1\0` —
never a new arm inside the certification verifier, for the same reason `SIGNATURE_DOMAIN` exists at all: a
statement of one kind must not be able to verify as a statement of another (R-01, R-04).
`ContractAttestation` is the proof this pattern replicates: a second operator-provisioned Ed25519 root
with its own format, scope and domain.

*Resolved here, because the spec text named a document without naming where it lives:* the delegation
document is a flat `adapters/delegations.json` beside the site trust root, holding a MAP of delegate key
id to a `{signature, statement}` object, and it is a RESERVED NAME in `adapters/` exactly as
`authorities.json` is — without that it would be globbed as a site adapter called `delegations` and
refused for declaring no name. A flat file rather than a `delegations/` directory because
`AdapterSources::assert_flat_json_source()` refuses a nested `.json` under `adapters/` by name and admits
exactly one directory, `certifications/`; admitting a second is a site-source grammar change and belongs
to § v3.9. Like the site trust root beside it, the document is validated WHOLE the moment it exists —
inert authority bytes an operator believes in are the failure mode this source refuses everywhere else.

*Resolved here, and it is the decision with the largest blast radius:* a delegated key resolves under
trust root **`site`**, not a third word. R-13 reserves a third `trust_root` VALUE for a genuinely new
custody model, and spending it here would make every deployed verifier refuse these certificates by name
for no gain — a delegated key lives in one site repository and certifies that repository's adapters, which
is what `site` already means inside the signed statement. What the delegation adds is not a new root but a
documented provenance for a key inside the existing one. That is also exactly why the revocation half
below is not optional: putting a vendor key into the site root is what breaks the frozen path's old
premise.

The rules are refusals, and the refusal matrix is the acceptance criterion:

- verification chains **exactly one level**. A two-level chain refuses BY NAME
  (`… is delegated by '<id>', which is itself a delegate — verification chains exactly 1 level and a
  delegate may not delegate`) rather than merely failing. *Resolved here:* the depth test runs BEFORE the
  delegator is looked up in the platform root, because resolving first would report the honest but useless
  "that key is not installed" and hide the chain. The bound lives in the verifier as
  `DELEGATION_DEPTH`, not in a policy anyone can raise;
- a delegation may only NARROW its delegator's namespace, tier set **and window**. *Resolved here:* time
  is a scope like any other, so the grant must lie inside the delegator's window when the delegator has
  one, judged at read time with no clock involved. Namespace narrowing reuses § v3.7's `<vendor>-*`
  grammar rather than inventing a second reading of it, and an EXACT delegator grant covers no pattern at
  all — `acme-forms` cannot delegate `acme-forms-*`, because a pattern reaches names that do not exist yet
  and an exact name never does;
- an expired delegation refuses; a delegation signed under a site key rather than a platform key refuses.
  *Resolved here:* the site-key rule is enforced twice and the order is deliberate — the `trust_root` word
  inside the signature is checked first so the refusal names the CLAIM, and the delegator is then resolved
  only in the shipped, reviewed root so the refusal names the MISS. Expiry is judged where every window in
  the engine is judged, `assertAuthorityScope()`, and not at document-read time: a record whose window has
  closed must still parse, still report, and still be distinguishable from a malformed one (§ v3.7);
- a revoked delegator invalidates its delegates, on both revocation channels, read LIVE against the
  current shipped root on every resolution rather than copied into the grant at signing time. *Resolved
  here:* the delegator's WINDOW is deliberately NOT applied at read time — § v3.7 already refused to apply
  the authorities envelope signer's window for the blast-radius reason, and a document a whole site source
  is judged against has more of that radius, not less. Nothing is lost by the omission, because the
  containment rule above forces every grant inside its delegator's window;
- and two collision rules the matrix needs to be complete: a delegation may not claim a key id the shipped
  root reviews, nor one the site trust root already carries. One identity has one record, never a written
  one and a granted one that could disagree.

**Revocation becomes typed and reachable.** The platform authority root is assembled into the agent
archive — adoption embeds the selected library and tars exactly `agent recovery` — so a status change in
that root still has agent-release latency, which is the wrong cadence for the one direction that matters under
compromise. v3 adds a typed revocation record carrying its own timestamp and reason, and a distribution
channel that is NOT the agent release.

*Resolved here, because "not the agent release" has to mean something checkable:* the document's logical
AdapterLibrary coordinate is `capabilities/adapter-revocations.json`; an installed agent reads it from
the durable `wprism-control/adapter-revocations.json` path. Its envelope is
`{format, signature, statement}`, statement `{format, issued_at, revocations, version}`, each entry
`{effective_at, fingerprint, key_id, reason}`, signed under its own domain
`wprism-adapter-authority-revocation-signature/v1\0` by a key the SHIPPED platform root carries. Three
properties are what distinguish it from the `status` word, and each one is asserted rather than argued:
it ships ABSENT and its absence means "nothing is revoked"; it is not byte-compared by `make release-gate`
the way the trust root beside it is (R-08's gate 6), so updating it moves no gated byte; and it is
SELF-AUTHENTICATING, so the identical bytes produce the identical verdict from any path a courier put them
on — an operator's cron over plain HTTP, a configuration run, an incident responder's paste. The trust
comes from the signature and not from the channel. An entry binds `fingerprint` = `sha256(public_key)` and
never the key id, because an id can be re-minted over new key material and material cannot be re-minted
under an old id. The signer's own window is deliberately NOT applied: letting a lapsed window silently
un-revoke a key would make expiry a way to RESURRECT the exact identities the document exists to burn.

*Current resolution:* operator revocations live at the durable
`wprism-control/adapter-revocations.json` path outside the assembled
`agent/adapter-library/`. Adoption migrates an old flat-library revocation
document only after byte equality is proven, and the embedded-library reader
refuses an operator revocation file inside the shipped platform projection.
There is no runtime directory override or fallback; frozen verification reads
the one `AdapterLibrary` selected by the installed agent plus that explicit
durable control document.

*Three states, not two (G2-FIXES C2).* Absence means nothing is revoked. A document signed by a key the
SHIPPED platform root carries applies. A document whose signer that root does NOT carry is **inert**: its
entries do not apply, it is reported as a library-scoped row by `AdapterSources::survey()` (so
`wprism adapter doctor` and `wp wprism adapter-survey` print the sentence and exit 1), and the site is not
refused. That third state is what makes the channel installable before enrollment at all — the shipped
root is `{"keys":{}}` and stays that way through the flag day (§ v3.12), so a hard refusal was the only
outcome a correctly-signed revocation document could produce, and installing one took the site down
instead of revoking anything. Tampering is unchanged and still fatal: an unreadable file, a malformed
envelope or statement, a version this agent does not implement, a revoked signer, and a signature that
does not verify under a key this root DOES carry all stay hard refusals. Only "this agent holds no key by
that id" is inert, because that is the one condition under which no verdict about the bytes is available.

*What a revocation takes away is the CLAIM, not the site (G2-FIXES C3).* A revoked authority — and an
authority outside its own validity window — withdraws the adapters it certified to uncertified support,
through a typed signal both the live scan and the frozen path catch
(`WithdrawnAuthoritySiteAdapterCertificate`). Untyped, it was a whole-source refusal: revoking a key to
protect the fleet bricked every promoted site holding a certificate under it, and a v2 authority record's
mandatory `not_after` made the same brick DATED. Forgery, tamper and a key that is not installed at all
stay whole-source refusals.

*What does not reach a frozen snapshot, and it is not the one people expect.* Revoking a DELEGATOR reaches
every live scan at once, because the delegator is resolved in the shipped root on every resolution — but a
frozen snapshot holds no repository, so it reads no `adapters/delegations.json` and the delegate's record
is the one inside the signature. An incident response that must reach PROMOTED sites therefore names the
DELEGATE's own fingerprint, never only its delegator's.

It also closes the frozen-path gap. `verifyCertificate()`'s site branch
(`agent/src/Adapter/AdapterCertification.php:1338-1379` — the comment headed "THE ONE ASYMMETRY BETWEEN THE
TWO ROOTS", whose line numbers this rider re-verified against the merged WP-4.8 tree) re-binds a
site-rooted certificate to the authority record the SIGNATURE covers, because the frozen path reopens no
mutable file — reasoned as correct while that root is the operator's own, a premise that fails the moment
federation-by-copy puts a VENDOR key in a site root. A revoked vendor key held in a site root now stops
verifying on the frozen path, because the revocation document is agent-owned and therefore readable
exactly where the site's own document is not.

The operator-own-key asymmetry is preserved, and preserving it is a decision rather than a leftover:
flipping `status` in the operator's own `adapters/authorities.json` still stops every live scan and still
does not reach an already-frozen snapshot, because closing that too would claim a custody property this
profile explicitly defers (T6 §2). Every refusal the new channel raises states the distinction in its own
sentence — "*This channel reaches the frozen path, which a status flip in the operator's own
adapters/authorities.json deliberately does not*" — so an operator looking at a refused snapshot can tell
which of the two mechanisms answered without reading the source.

Register rows R-25 and R-26 record what these two statement kinds make permanent.

### v3.9 The namespace grammar, and the closed grandfather list

**Rider: WP-4.10. Enforced today: for names and provider ids, yes at `spec_version: 3`; inert at v2. For
`id_kind`, never — see the last paragraph.** One shared identity grammar
(`AdapterSources::assert_name()`, `agent/src/Adapter/AdapterSources.php`) governs adapter names, authority
key ids, pins, ratification maps and frozen records, and it decides SHAPE, never ownership. `id_kind`
uniqueness across pinned manifests is load-bearing for correctness because `wprism_map` is keyed by
`(id_kind, local_id)` (`agent/src/Policy/CrossManifestGuards.php:429-453`) — with prose advice as its
entire remediation.

At v3, `<vendor>-<name>` is a RESERVED form in the three flat identity spaces — adapter names,
`tables.<t>.id_kind` and `providers[].id` — and a prefixed identity is bound to the certifying authority's
namespace scope. An authority scoped to `acme-*` cannot certify `zeta-foo`. Squatting therefore requires
holding a key rather than being first, which is the same property fingerprint-derived key ids give
(§ v3.7); the two decisions reinforce each other. That scope half is the one WP-4.8 already shipped
(`AdapterCertification::assertScopeEntry()` / `scopeCoversName()`); what this rider adds is the other end
of the same binding — the requirement that an out-of-tree identity be inside a vendor namespace at all, so
there is something for a scope to bind to.

**What is enforced, exactly.** `IdentityNamespaces::assert_out_of_tree_identity()` runs inside
`AdapterSources::assert_out_of_tree_contract()` — the one boundary the site scan, the plugin scan,
Policy's post-load re-check and frozen reconstruction all pass through — and refuses two things on a
manifest declaring `spec_version: 3`:

- an adapter NAME that is neither `<vendor>-<name>` nor on the closed grandfather list, naming the list and
  the authority scope that would grant a namespace;
- a `providers[].id` outside the DECLARING ADAPTER's own vendor namespace, naming the index. Without this
  second half the binding would be decorative: an adapter certified under an authority scoped `acme-*`
  could still mint provider id `zeta-thing` and squat a space no key of its holder's covers. Binding
  provider ids to the declaring adapter's vendor binds the whole identity set one adapter contributes to
  the VENDOR half of the name its certificate was checked against.

**One hyphen deep, and no deeper (G2-FIXES M3).** `IdentityNamespaces::vendor()` splits on the FIRST
hyphen, so a sub-vendor delegated `acme-forms-*` may name its adapter `acme-forms-widget` — vendor half
`acme` — and mint provider ids across the PARENT's whole `acme-` space rather than inside the scope its own
certificate was checked against. The rule binds a provider id to the first segment of the declaring
adapter's name, which is the top of the namespace its scope lies within; it does not bind it to the
narrowest scope entry that certified the adapter. Stated rather than closed: binding to the matched scope
entry means carrying a certificate into a loader that runs with no certificate in hand
(`assert_out_of_tree_contract()` judges identity for every out-of-tree manifest, certified or not), which
is a new permanent decision rather than a correction. Register row R-27 records the same limit.

**The grandfather exemption is the NAME's alone (G2-FIXES M2).** A grandfathered name that HAS a vendor
half — `ninja-forms`, `yoast-duplicate-post`, `code-snippets`, 11 of the 18 — is still held to the
provider-id rule; only the name is exempt, because only the name was argued for (the reviewed override).
A grandfathered name with NO vendor half — `core`, `acf`, `woocommerce`, `elementor`, `polylang`, `yoast` —
has no `<vendor>-` for a provider id to be bound to, so the rule has nothing to say about its providers.
Every shipped provider id already satisfies this, measured on every run by
`regress_identity_namespaces.php` rather than assumed, so the reviewed override of any of the 18 still
loads at `spec_version: 3`.

**A namespace grant may not reach a reserved name (G2-FIXES M4, register row R-22).** A non-platform
authority record's `adapter_names` entry may not be a `<vendor>-*` pattern COVERING one of the 18 — refused
at authority-record validation time, so it fires on the site trust root, on a delegated grant, and on the
record a certificate embeds. Without it, enrolling a vendor with its own products' namespace handed that
vendor the SHIPPED adapter of the same name, whose out-of-tree override inherits that adapter's
interpreter, regenerator and provider declarations. An EXACT reserved name stays legal: that is the
reviewed override, and refusing it would delete a shipped capability to close a hole the pattern form is
the whole of.

The rule returns before reading a member on any manifest below `spec_version: 3`; this spec-3 engine
therefore enforces it for v3 manifests while accepted v2 manifests remain unchanged. That is the same gate
§ v3.5's environment narrowing uses, and for the same reason: a v3-only rule that fired at v2 would be
refusing a manifest the acceptance window (§ v3.1) has not judged yet. A refused row carries its own code,
`reserved_namespace`, rather than
`out_of_tree_privilege`, because the two remediations are opposites — "install this adapter into the
agent's own manifest library" is exactly the wrong instruction for an adapter whose only fault is the name
it answers to.

**Unprefixed names stay legal, and the reserved set is a CLOSED ENUMERATED LIST living in `agent/src`**
(`agent/src/Adapter/ShippedIdentityInventory.php`) — never under an adapter package, where it would become a rule-2
identity input folded into every adapter row, so that admitting a nineteenth adapter would invalidate
the other eighteen's pins and certificates. `php tools/shipped-identity-inventory.php --check` and
`php tools/wire-surface.php --check`, both `make release-gate`
step, pins the list's LOCATION (by reflection over the class, not by a path literal) and its MEMBERSHIP
(equality with the shipped library, in both directions), and records the decision as register row R-27; a
nineteenth unprefixed adapter name cannot be added without regenerating the reviewed inventory. Out of tree, a
grandfathered name is reachable only as the reviewed `{name, source: "site"}` override of a shipped
adapter, which is the case the list exists to keep loading.

The list ENUMERATES rather than tests shape. The current identity census is
printed by `regress_spec_v3_dry_run.php`; the generated wire register records the
shipped reservation inventory and R-17's permanent `id_kind` floor.

- A bare `<vendor>-<name>` refusal would reject unprefixed adapter names such as
  `acf`, `core`, `elementor`, `polylang`, `redirection`, `woocommerce` and `yoast`,
  as well as the underscore-separated `id_kind`s.
- Hyphen-shaped adapter names can also lack a vendor prefix: `the-events-calendar`
  is not vendor `the`. Shape cannot determine membership in the shipped list.
- Provider ids use a plugin-slug first segment, the space where this convention
  is enforced. Package discovery and the generated register carry the evolving
  inventory; this specification does not maintain another adapter count.

**`id_kind` prefixing can never become a RULE, and v3 does not make it one.** The irreversibility register
rules on this at R-17: captured state and `wprism_map` rows embed the BARE kind, so a prefix rule introduced
later would have to rewrite every token in every branch of every site — the one migration this product
cannot perform, because the branches are the customer's data. The register reserves the CONVENTION and
refuses the RULE. So the 21 shipped kinds are a permanent floor, not a break list, and v3's contribution
in that space is a reserved form bound to an authority scope plus the unchanged uniqueness refusal.
Nothing in the shipped engine refuses an unprefixed `id_kind`, at any `spec_version`, and a later rider
adding one would be overruling the register rather than implementing this section.

### v3.10 Reserved-but-refusing slots

**Riders: WP-4.11 reserved; WP-5.2 opened the reviewer tier. Enforced today: YES for the three slots that
remain reserved — they refuse with the pinned messages below. The two reviewer-tier slots are OPEN
(§ v3.16).** The manifest slot refuses at `spec_version: 3`, where the key set is closed (§ v3.3), and is
active for v3 manifests on this spec-3 engine while remaining inert for accepted v2 manifests; the two
statement members refuse at every version, on every certificate the engine reads today. The fifth line this section
used to reserve — the graduated verdict — is not a reservation at all and is recorded as delivered below.

**The opening is the point, not an exception to it.** WP-4.11 reserved four slots so that opening one
later would be a policy flip proven by suite rather than a second flag day, and WP-5.2 is the first
redemption of that: two slots moved from refusing to admitted, `AdapterCertification::STATEMENT_KEYS` did
not move, no signature domain moved, and no certificate already in the field changed a byte. What the
reservation actually bought is visible in the second table below — a host at the previous version still
answers with the published sentence, so an operator meeting a flipped target reads a version skew instead
of a corruption verdict.

v3 ships attachment points that REFUSE, each with a pinned message naming the gate that would open it.
Reserving an attachment point — a member slot or a vocabulary value, never a schema — is what makes each
later opening a policy flip proven by suite instead of a second flag day; the detail of what eventually
rides on it arrives through `engine_features` (§ v3.2), so the reservation need only be right about WHERE
an extension attaches.

**A reservation here is a REFUSAL, never an admitted-and-ignored key**, and that distinction is the whole
of the rider. Every one of these slots lands inside a closed set that already refuses it, so admitting the
member instead would move a wire: the statement's member set is closed in both directions AND is the
generation discriminator (R-06, R-24), the bundle evidence object is inside the digest a certificate
binds, and the certification vocabulary refuses a whole document on a word it does not know. What each
reservation changes is WHICH refusal an author gets — the gate that decides, rather than "correct the
spelling" or "must contain exactly …", which are false about a document that is asking for a lane. No
verdict moves in either direction: every input below was refused before this rider and is refused after
it, with the same exception and the same failure.

**STILL RESERVED — these three refuse today, with these exact sentences:**

| slot | where it attaches | shipped refusal site | pinned refusal |
|---|---|---|---|
| manifest `package` | manifest top level, inside the closed key set | `AdapterContractGrammar::assert_top_level_keys()` | `wprism: manifest '<name>' declares 'package' — the executable adapter lane is reserved and shut. It opens only at gate G5 (spec/repo-format.md § v3.11), never by declaring the key` |
| statement `code_digest` | the signed certification statement | `AdapterCertification::assertStatementShape()` | `wprism: site adapter certification statement declares 'code_digest' — a signed binding over adapter code is reserved and shut; it opens with the executable lane at gate G5` |
| statement `delegated_authority` | the signed certification statement | `AdapterCertification::assertStatementShape()` | `wprism: site adapter certification statement declares 'delegated_authority' — delegated authority is verified through its own signed delegation document, not through a member of this statement (spec/repo-format.md § v3.8)` |

**OPENED at gate G4 by WP-5.2 (§ v3.16).** The fourth column is what a reader at the PREVIOUS version
still answers, and it is recorded rather than deleted because it is live in the field: those bytes ship on
every host that has not taken this release, and an operator who meets one needs to recognise the sentence.

| slot | where it attaches | state | what a v3-era reader still says | opened by |
|---|---|---|---|---|
| certification word `reviewer_signed` | the certification vocabulary | MINTED by `AdapterSources::certification_word()` / `site_certification()`; admitted by both observer vocabularies | `wprism: certification 'reviewer_signed' is reserved — the reviewer tier opens at gate G4 with an 'evidence.reviewer' bundle, and no engine mints it today` | WP-5.2 |
| `evidence.reviewer` | the bundle evidence object | ADMITTED by `AdapterCertification::bundleEvidence()` as an OPTIONAL member; the three-member object is unchanged | `wprism: <label>.evidence declares 'reviewer' — the reviewer evidence member is reserved; it is admitted when the reviewer tier opens at gate G4` | WP-5.2 |

Three consequences worth stating. First, the signed statement is still exactly six members — `adapter`,
`authority`, `bundle`, `platform`, `ratification`, `version` (`AdapterCertification::STATEMENT_KEYS`) —
checked with a MISSING-and-UNKNOWN refusal, which is register row R-06: a seventh member would change the
signed bytes AND be refused by every deployed verifier. The two statement slots above are reserved
WITHOUT touching that set, which is why this rider moves no certificate: the canonical bytes of every
statement already signed are byte-identical before and after it, and opening a member later is still a
wire generation (§ v3.6, R-24) rather than something a reservation quietly pre-paid for. Second, the
reserved statement members are shut for different reasons and say so: `code_digest` waits on gate G5,
while `delegated_authority` is shut permanently as a statement member — a delegation is verified through
its own signed, domain-separated document (§ v3.8), and a member here would be an unsigned second copy of
a fact a signature already carries. Third, the reviewer word's refusal was enforced at the HOST boundary,
where a foreign word can actually arrive, and that placement is exactly what made the flip cheap: the
sentence shipped one release ahead of the first engine able to mint the word, so a host reading a
target whose agent has already opened gate G4 answers with a version fact instead of a corruption verdict.
WP-5.2 supplied the other half — both observer vocabularies now ADMIT the word — and the two halves
landing in different releases is the whole mechanism, not an accident of scheduling.

`sandbox/tests/offline/adapter/regress_v3_reservations.php` drives all five slots — the three that still
refuse against their pinned messages, and the two the flip opened against the behaviour that replaced them
— proves that no verdict which existed before the reservation moved, and pins the six-member statement's
canonical bytes and signature. It is also § v3.11 condition 7's evidence, now with one worked example
behind it: WP-5.2 opened two slots by editing the pins in that file, visibly and in one diff, which is
what "a policy flip proven by test" was supposed to mean. Register row R-28 records the decision beside
the other irreversible ones.

**Already delivered, and recorded here so the reservation is not re-taken:** the graduated
`outside_version_range` verdict shipped as `version_range_graduated` (WP-2.8), a third evidence-bound
state between "inside the certified window" and "deploy blocked", minted only when every recorded release
between the declared window and the installed bytes probed green. It is a shipped word, not a reserved
one — `VersionEvidenceGrammar::VERDICT` and `PlanContract::GRADUATED_VERSION_RANGE` are the same string,
`LifecyclePlanner::code_mismatch()` mints it, and `wp wprism plan` renders it as its own block. WP-4.11
therefore reserved four slots and not five: re-reserving a word the engine already mints would have
described the shipped library falsely, and un-shipping it to make the count match the plan that predates
WP-2.8 would have been the same error in the other direction.

### v3.11 The executable lane: what gate G5 requires

**Rider: WP-7.1, which is gated shut. Enforced today: the lane does not exist and its channels refuse.**

**The default answer is NO, and a gate that never opens is a legitimate outcome.** This section records
the evidence contract so that opening the lane is a reviewed change against written conditions rather than
a judgement call inside a pull request. v3 reserves the attachment points (§ v3.10) and ships no lane.

Today an out-of-tree manifest acquires no executable privileges at all: declaring `interpreter`, a
`regen_dependency.regenerator`, or `providers[].source: "manifest"` is refused with remediation
(`AdapterSources::assert_out_of_tree_contract()`, `agent/src/Adapter/AdapterSources.php:3479`), because
all three resolve only through the declaring package in the installed
`AdapterLibrary`. `providers[].source: "plugin"` remains
available and is the only decentralized code path — its trust anchor is an installed, active,
version-bounded plugin plus the provider negotiation and receipt contract, which is a working channel for
any party that owns the plugin.

The lane opens only when ALL SEVEN of the following hold. They are independently verifiable and none is
substitutable for another:

1. **Declarative sufficiency.** The engine-gap ledger shows a residual demand that still requires
   executable repair AFTER the declarative primitives land, and the `compatibility_shim` share of NEWLY
   authored adapters has fallen below a threshold stated in advance of the measurement. The historical
   pre-absorption measurement was 11 of 16 adapters and 20 hook files totalling 27,643 lines under the
   former flat library. The current inventory is derived directly from each capsule's manifest declarations
   and `package/runtime/` tree; no checked-in project-level file list or physical-line total is an adapter
   authoring input.
   The baseline more than doubled with #561 alone — one adapter reaching production-readiness added a TEC
   interpreter and a Category Colors provider and rewrote its regenerator. Polylang then added the sixteenth
   hook file and 1,828 lines through its reviewed production-readiness port — which is the condition arguing
   against itself, and is recorded here rather than smoothed away. The later reviewed empty-catalog
   lifecycle correction added seven lines to that measured hook surface. Woo's final four-plugin
   co-install correction then added 15 lines to admit only the source-bound inert generic filters that
   execute during Polylang's native save; this baseline is re-measured rather than preserving a stale
   threshold. The same four-plugin witness then reached TEC's checked option writer: closing its exact
   Woo/Yoast pre-update, update, add and sitemap-cache union without constructing missing services added
   216 lines to the shipped TEC interpreter, so the measured baseline moves with that reviewed boundary.
   Closing Woo's reciprocal union, including its inert-only settings tracker, TEC's five exact
   `updated_option` observers, and the hash-bound partial-load settings authority, added 561 lines to the
   Woo interpreter; source-bound callback identity and observer-state inspection keep that admission finite.
   The inactive-Woo lifecycle correction added a net 194 lines for exact installed-root and native-file
   provenance plus a bounded stdin child that validates permalink bytes without preloading Woo's bare-required
   formatter into the activation process; the baseline moves because those executable bytes ship with the adapter.
   WP-6.2 then supplied the concrete reversal: PMPro moved its exact cache postcondition onto the generic
   invalidation primitive, retiring one adapter and 233 provider lines. The manifest-provider runtime removed
   another 697 duplicated protocol/read-guard lines across nine providers while retaining their plugin
   semantics and verifiers. `regress_spec_v3_document.php` derives every currently declared runtime member
   and refuses an orphan, missing declaration, wrong owner, or non-capsule path; ordinary line-count changes
   need no edit outside the owning package.
2. **Falsifiable effects.** Declared-effect verification is live and REFUSING, with a measured
   false-refusal rate on the shipped 16 below a stated threshold — because the compiled inventory is
   recovery's entire authority, and under-declaring `effects[]` is the cheapest way for an adapter to pass.
3. **Checked receipts.** The engine independently re-reads a capability's own declared `writes` surfaces
   around `invoke()`, so `verified: true` is a check rather than an assertion third-party code makes about
   itself.
4. **Exercised revocation at speed.** Revocation-to-effect latency measured on the rehearsal fleet
   (§ v3.8), because revoking executable code matters more than revoking a data claim.
5. **A named isolation posture.** A real answer for memory, time and process bounds on third-party
   provider and interpreter execution — either a bound with a suite behind it, or a written statement that
   none is constructible and why the lane should open anyway.
6. **Demonstrated parity.** A third party has produced a platform-admissible `exercised: true` bundle
   using the shipped adapter test kit, verified at distance from an evidence repository that is not this
   one.
7. **Clean reservations.** § v3.10's suite still refuses with its pinned messages for the three slots this
   lane owns — the manifest `package` key and the two statement members — and the full refusal suite for
   the opened lane is authored and green on a branch, so the flip is a policy change proven by test, not a
   format break taken on faith. WP-5.2 has now DONE this once, for the two reviewer-tier slots at gate G4
   (§ v3.16): the mechanism is no longer a promise, and the three slots above are what remains of it.

If any condition is unmet the lane stays shut and the answer is more declarative primitives, never a
relaxed gate.

### v3.12 What v3 does NOT change

**Rider: WP-4.12. Enforced today: THE FLIP IS DONE.** `WPRISM_SPEC_VERSION` is `3` and `WPRISM_AGENT_VERSION`
is `0.7.0` (`agent/wprism.php:12-13`), `platform/adapter-library/capabilities/platform.json` restates both in the same
commit (AGENTS.md rule 8), and the acceptance window is `{2, 3}`. The migration verbs are
`wprism adapter recertify <repo>` and `wprism release --spec-v3 <repo>`; the runbook is
[docs/guides/flag-day.md](../docs/guides/flag-day.md).

The bump was engineered to move no adapter digest, and the exclusions below are the reason that was
achievable. The invariant is not argued, it is computed:
`sandbox/tests/offline/policy/regress_spec_v3_digest_neutrality.php` recomputes all 18 adapter digests
from two maximal compatible worlds and `manifest_hash` for eight representative pin sets, including
those two worlds, then compares them against the explicit current
WPrism greenfield baseline in `sandbox/tests/fixtures/spec-v3/wprism-greenfield-identity.json`. The
fixture pins the manifest, registry and snapshot maps as independent literals, so those comparisons
cannot pass as self-derived equalities; the suite separately mutates both platform-version inputs and
proves their existing refusal boundary.

- **No shipped manifest was re-stamped by the flag-day bump.** This was the central exclusion that made the
  bump survivable and reversible. Stamping all manifests would have moved every adapter digest, every
  `manifest_hash`, every deployed site's compiled artifact, and every `site.wprism.json` content pin
  simultaneously for zero capability gained on that day. The promised later path is now exercised per
  adapter: Paid Memberships Pro moved to `spec_version: 3` to consume `invalidate-vocabulary/v1`, retiring
  its 233-line provider; ten provider-bearing manifests later moved to consume
  `manifest-provider-runtime/v1`. Redirection was authored after the flip at v3 rather than migrated.
  Each migration moves only its own digest and the sites that pin it; the other six pre-flag manifests remain
  at 2 inside the window (§ v3.1). Operators recompile and re-pin
  affected adapters; there is no fallback to old manifest/provider bytes.
- **No DEPLOYED REPOSITORY is re-stamped either, and it does not need to be.** `site.wprism.json`'s own
  `spec_version` is the same wire integer with a larger population — every site has one, and no site
  author chose it. It is judged against the window (§ v3.1), so a repository declaring `2` compiles
  unchanged on this engine: THIS door moves no `site_hash`, no `state_site_hash` and therefore no
  `revision_hash`, and costs no compiled artifact its verification. (The certificate door below is a
  different one and does move `revision_hash`, for the sites it touches. Neither claim covers the other,
  and conflating them is the one misreading of this section that would surprise an operator mid-rollout.)
  `wprism release --spec-v3 <repo>` reports the surfaces a site must
  re-project and journals its PRIOR pin objects; it deliberately does NOT re-stamp the repository,
  because a repository at 3 no longer compiles on the N-1 agent a rollback restores.
- **The platform trust root stays empty.** `platform/adapter-library/capabilities/adapter-authorities.json` remains
  `{"keys":{}}` through the flag day (§ v3.7).
- **But every CERTIFIED SITE ADAPTER withdraws to uncertified at the flip, and `wprism adapter recertify`
  re-establishes it.** This is the bump's most fleet-visible effect and it is NOT digest-neutral for the
  sites it touches — the one place the neutrality claim above stops. The withdrawal moves the
  certificate-derived row `ArtifactPolicyIdentity::manifest_rows()` folds, so such a site moves its own
  `manifest_hash` AND `revision_hash`, and its held compiled artifact refuses with
  `compiled_artifact_manifest_mismatch` until it is recompiled and re-pinned. Its `site_hash` and every
  SHIPPED digest it pins still hold — the no-restamp rule covers it exactly as it covers any other site.
  `sandbox/tests/offline/guards/regress_spec_migration_rehearsal.php` measures the split per site across
  a nine-site estate, and `wprism adapter doctor --migration` predicts it per site before the bump.
  Mechanically: `spec_version` is inside every signed `statement.platform`, and
  `assertPlatformBinding()` raises `StalePlatformSiteAdapterCertificate` — *"site adapter '<name>'
  certification was signed under spec version 2, which is not the spec version 3 this agent publishes"* —
  the moment the two disagree. Every certificate in the field was signed under 2. **§ v3.6's axis binding
  does not spare this, deliberately:** a certificate binds the exercised compatibility CELLS, which is why
  an ordinary `agent_version` release withdraws nothing, but `assertPlatformBinding()` compares the
  in-statement `spec_version` BEFORE any cell, so the spec half PRE-EMPTS the cell half. That ordering is
  R7's whole reason for putting a version inside the signed statement — a wire change must read as a named
  refusal rather than as corruption — and both branches are measured on one fixture by the rehearsal's
  state-A and state-B passes. On the live scan and
  inside a frozen snapshot alike the adapter degrades to uncertified support rather than refusing the
  source (WP-1.1's routing), so nothing bricks: `plan` and `apply` stay available, readiness and host
  promotion stay blocked for that adapter until it is re-signed against the post-flip boundary. Rolling
  back restores the claim untouched, because the withdrawal writes nothing. The rehearsal of both paths is
  `regress_site_adapter_certification.php` case (k), and the migration verb is `wprism adapter recertify
  <repo>`: it re-signs every certified site adapter in one idempotent invocation under the key each
  certificate already names, reusing `created_at` when a re-sign is byte-identical so an unchanged input
  mints nothing, and restoring `adapters/authorities.json` to its prior bytes if any signature fails.
- **The executable lane does not open** (§ v3.11). Only its reservations ride.
- **No new declarative primitive rode the bump itself.** Each stages later through the window or feature
  channel, one adapter at a time. PMPro's later invalidation migration is the worked product example: the
  engine version did not move, while one adapter opted into a feature and paid one identity change.
- **Nothing widens the platform boundary.** v3 makes a NARROWER environment declarable (§ v3.5); it never
  widens what the boundary CLAIMS. `site_mode: single-site` is unchanged.
- **v2 acceptance is not retired.** Removing the old version on the day the window is installed would make
  v3 a flag day of exactly the kind this section exists to end. The window closes by a dated decision,
  gated on fleet telemetry showing no v2-declaring pinned manifests plus at least one grammar section
  shipped post-v3 through `engine_features` with no bump — the replacement mechanism proven before the
  thing it replaces is retired. **The second half of that condition is MET**, and PMPro is now a shipped
  consumer of `invalidate-vocabulary/v1` with `WPRISM_SPEC_VERSION` unmoved (§ v3.15, WP-6.2). The first half
  is fleet telemetry and is not met, so the window stays open.

Rollback, until that dated decision, is the shipped atomic bundle swap run
backwards: redeploying the prior-version `agent recovery` archive restores the
v2 agent AND its embedded v2 manifest library as one archive through the three
journaled surfaces (`Adopt.php:600-603`), so there is no partial
runtime/library state. The original flag-day rehearsal remains clean because its captured A/B estate
predates later per-adapter restamps. A site that has adopted the current PMPro digest must instead restore
or recompile against the old PMPro manifest as part of that bundle rollback; its current pin cannot match
both identities, which is the explicit cost of the first act below.

The one thing an operator must redo in each direction is certificates, and the two things that follow
them: `wprism adapter recertify` signs against whatever boundary is installed, so it is bidirectional by
construction, and a certificate-holding site's `manifest_hash` returns home with the re-mint — which means
an artifact recompiled AFTER the flip refuses until it is recompiled again. That is the cost of having
crossed, not a defect in the rollback; an operator who declines the backward re-mint lands on
`uncertified`, which is honest and non-blocking.

**Exactly three acts make it lossy, and each is one-way for its own reason.** They are named here. The
first has now been taken deliberately by PMPro's engine-absorption migration; the second remains absent,
and the third follows the certificate rollout described above:

1. **The first shipped manifest stamped `spec_version: 3` — now Paid Memberships Pro.** The N-1 agent's
   window is {1, 2}, so it refuses that current manifest wholesale. A rollback must restore the prior
   manifest and recompile/re-pin sites that adopted the new digest; copying only the old agent is invalid.
2. **The first REPOSITORY re-stamped to `spec_version: 3`.** Same window, other carrier: the restored
   agent refuses to compile that repository at all. This act is the one a routine "tidy the version
   field" commit could perform by accident, which is why no verb performs it.
3. **The first certificate RE-SIGNED after the bump** — that is, running `wprism adapter recertify` (or
   `wprism adapter certify`) on a site whose certificate was minted before it. Note what this act is NOT:
   the `/v2` statement wire is already shipped and already in the field (§ v3.6, WP-4.7), so the wire
   generation is not what strands anything. What strands the rollback is the PLATFORM BINDING — a
   re-signed certificate binds `spec_version: 3`, and the restored N-1 agent raises
   `StalePlatformSiteAdapterCertificate` against it exactly as the v3 agent did against the old one.
   The re-sign also OVERWRITES `adapters/certifications/<name>.json`, so the certificate the rollback
   target could have verified is gone. The remedy is symmetric rather than absent: run `recertify` again
   after the rollback and the claim is re-established against the restored boundary — `recertify` signs
   against whatever boundary is installed, in either direction. An operator who declines lands on
   `uncertified`, which is honest and non-blocking (`plan` and `apply` stay available; readiness and host
   promotion do not).

A mixed state is already unreachable through the supported path — `ManifestDispositions::
platform_boundary()` throws "platform version disagrees with the loaded agent" the moment the defines and
`platform.json` disagree, which is the same equality `make release-gate` checks. WP-4.12 proves that
refusal fires for a HAND-MIXED bundle (a v3 agent over a copied v2 `platform.json`) rather than adding a
mechanism to survive one; the case is in `regress_spec_v3_digest_neutrality.php`.

### v3.13 The plugin/theme claim resolution: an operator decision, not a load refusal

**Rider: WP-5.5. Enforced today: yes.** `site.wprism.json` may declare `policy.adapter_claims`, and
`AdapterClaimResolutions` (`agent/src/Policy/AdapterClaimResolutions.php`) is the whole of it. It is a
REPOSITORY section, not a manifest one, so no manifest byte moves, no adapter digest moves, and it costs
`engine_features` nothing: § v3.3's closed key set is the MANIFEST top-level set, and this key is not in
it. An older agent meeting a repository that declares it ignores the section and refuses the collision
exactly as it always did — which is the correct failure, because the safe answer to "I cannot read the
decision" is to keep refusing rather than to pick a winner.

**The collision.** Two pinned manifests declaring the same `plugin` (or the same `theme`) with different
ranges are refused at load: `Policy::version_ranges()` resolves by pin order, so admitting both would let
the position of a name in `site.wprism.json`'s list decide which range bounds an installed plugin —
"manifest precedence may never depend on pin order". Identical ranges are redundant rather than ambiguous
and have always been allowed. Nothing about either verdict changes here, absent a resolution:

```
wprism: manifests '<a>' and '<b>' both declare plugin '<basename>' with different version_range values
(<a's range> vs <b's range>) — conflicting ownership with no v2 composition rule; pin only one, or
narrow one range to a disjoint window
```

`sandbox/tests/offline/guards/regress_plugin_claim_resolution.php` asserts that sentence, and its theme
twin, against a LITERAL in both arms, so the message an existing repository sees cannot move as a side
effect of this section existing (AGENTS.md rule 8).

**The resolution.** `policy.adapter_claims` is a two-armed map, keyed by claim kind and then by the
claimed identity, whose value names the manifest whose claim is IN FORCE:

```json
{ "policy": { "adapter_claims": {
  "plugin": { "acme/acme.php": { "in_force": "acme-pro", "note": "this site runs acme 2.x" } },
  "theme":  { "acme-theme":    { "in_force": "acme-theme-adapter" } }
} } }
```

`in_force` is mandatory and `note` is the sole non-semantic annotation, exactly as it is on an option
rule; the row admits no third key, and in particular **no range of its own** — a resolution that could
restate a bound would be a second, uncertified place a version window is authored. The two arms are keyed
apart because a plugin basename and a theme directory are different namespaces that can collide on one
string; a resolution filed under the wrong arm resolves nothing and says so.

WHY THE SITE FILE AND NOT A MANIFEST. The collision is a property of a pin SET — each manifest is
individually legal, and neither may be granted authority to displace the other by declaring something
about it, which is the "extension must not grant one adapter authority over another adapter's state"
ruling five other cross-manifest guards already keep. The only party who can decide is the one who pinned
both. That is the same answer, in the same file, that two manifests contradicting each other about one
option name already get: "a site policy rule for the colliding name is the explicit resolution path"
(`CrossManifestGuards::validate_no_conflicting_option_rules()`, whose own refusal ends "Add an explicit
site.wprism.json policy.options.<name> override to resolve this option").

**RESOLUTION, NEVER COMPOSITION — and this is the boundary the section is written to hold.** Exactly one
manifest's claim bounds one plugin or theme. Nothing is merged, intersected or unioned; joint ownership
of one plugin's surface is deliberately not built and is not a gap this section left for later. The
displaced manifest is not unloaded, not unpinned and not diminished anywhere else: every other
declaration it makes still governs, and only its claim about THIS subject is displaced. Correspondingly,
a resolution resolves the claim it names and nothing else — an unrelated cross-manifest conflict in the
same pin set still refuses, and an undeclared conflict still refuses.

**The displaced claim is REPORTED.** `Policy::displaced_adapter_claims()` emits one row per displaced
claimant carrying `reason_code: "displaced_by_resolution"`, both manifest names, both ranges and the
operator's note; `ApplyPlanBuilder` renders each as a plain plan warning. The word is one step over from
the catalog's `shadowed_by_site`, which reports a shipped adapter an explicit `{name, source:"site"}` pin
displaced "so the operator can see WHICH definition is in force rather than inferring it from a silence"
— distinct rather than reused, because a displaced CLAIMANT is still installed, still pinned and still
loaded, and a `not_installed` row would be false about all three. Like that one it does not flip an exit
code or an `ok`: a resolved collision is a decision, not a defect.

**A resolution that decides nothing refuses.** A row naming a `<kind>`/`<id>` that fewer than two pinned
manifests claim, or whose `in_force` names a manifest making no such claim, is refused at load — before
the collision guard, so the operator is told about their own file rather than about a conflict it already
tried to answer. The case that matters is DRIFT, not typos: unpin one of two colliding manifests and the
resolution left behind is a decision about a collision that no longer exists, which must not go on
looking like the reason the survivor is in force. The posture is the pin record's own — "a pin whose
author believed it constrained something is the failure this whole record exists to prevent".

**What this does NOT open.** Nothing about gate G4 or G5 (§ v3.11): no author is admitted, no trust root
moves, no certificate binds anything new, and no executable lane is reserved or opened. The section is
inert on every repository that does not declare it, and on one that does it can only choose among claims
that were already made by manifests the operator already pinned.

### v3.14 `declaration_evidence` — the first POST-v3 section, shipped with no bump

**Rider: WP-6.4. Enforced today: yes, for a manifest that declares `structured-evidence/v1`.**

This section is numbered v3.13 and is not part of v3. It was added AFTER the flip, `WPRISM_SPEC_VERSION` did
not move to admit it and is still `3` — asserted in the same run as its three verdicts, in
`sandbox/tests/offline/policy/regress_structured_evidence.php`, because a channel that worked while the
integer quietly moved would have demonstrated nothing. It is here because § v3.2's channel is what a
post-v3 section rides, and this is the first one to ride it. § v3.12's window-closing condition asks for
"at least one grammar section shipped post-v3 through `engine_features` with no bump"; this is that
section.

**What it carries.** `wprism adapter-draft` proposes every candidate with `evidence[]` rows of
`{source, locator, observation}` and the `questions[]` a live target must answer
(`cli/src/Adapter/AdapterDraft.php:1529-1530`), nested inert under `_draft`. § v3.3 resolution 2 refuses
`_draft` at `spec_version` 3, so ratifying a proposal into a real section DELETES the evidence behind it;
what survives is at best a sentence in `notes`. `declaration_evidence` is where it survives instead:

```json
"declaration_evidence": {
  "options.acme_settings": {
    "evidence": [
      {"source": "state/options/core.json", "locator": "acme_settings",
       "observation": "scalar in 12 captured files, no id positions"}
    ],
    "answered": [
      {"question": "does any value carry a post id?", "answer": "no — probe showed 0 of 12 rows"}
    ]
  }
}
```

The grammar is closed in both directions at every level (`agent/src/Adapter/StructuredEvidence.php`): the
section is a non-empty object, a record is `{evidence}` or `{evidence, answered}`, an evidence row is
exactly `{source, locator, observation}`, an answered row exactly `{question, answer}`, and every member
is a non-empty string. Two rules carry the weight:

1. **A target ADDRESSES a declaration the manifest makes.** The key's head — `options` in
   `options.acme_settings` — must be a top-level key this manifest declares, so a record for a section
   that was deleted refuses at load and a record for a section never declared cannot be written. The tail
   is deliberately not resolved: fourteen field sections have fourteen sub-grammars, several address
   positions inside a value rather than a key, and a resolver here would be a second, drifting copy of all
   of them. The head is what makes the address falsifiable at the granularity that matters.
2. **A question arrives only with its answer.** A draft's `questions[]` are bare strings because a draft's
   question is OPEN — it names a deferral for a human. An open question has no business in an installed
   manifest; the shape is the rule.

**`notes` keeps everything.** Nothing is migrated, nothing is rewritten, and no rule here reads `notes`.
The 16 pre-existing adapters were not retrofitted: a manifest byte is adapter identity (AGENTS.md rule 2),
so mass-adopting the section would move every affected digest and invalidate every pin and certificate
naming one for a documentation change. Redirection was authored after the feature existed and is the first
shipped manifest to declare structured evidence; its first digest already includes those bytes. Rank Math,
authored later, likewise includes the section in its first identity. No pre-existing adapter was restamped.
So the declaration-to-rationale link is gated two ways at once, and honestly:
`regress_shipped_option_declarations.php` keeps its `str_contains($text, 'issue #3509')` grep over `notes`
prose for the shipped library, and the schema check applies to fixtures and out-of-tree adapters that
carry the section. The grep is retired per adapter, when that adapter is next touched for a product
reason — a deferral recorded here rather than left implicit, because a converted gate that never converted
is worse than one that says which half it covers.

**An adapter that adopts it is certifiable through § v3.21's feature roster.** Neither
`declaration_evidence` nor `engine_features` was copied into the older signer partition: each feature row
carries its own `non_surface` certificate arm, and that arm is admitted only when the manifest declares the
corresponding implemented feature. A declaration without its feature still refuses by name. This preserves
the three-way feature gate without putting evidence prose in a certificate's state surfaces.

### v3.15 The `invalidate[]` vocabulary, and the price of admitting a verb

**Rider: WP-6.2. Enforced today: yes — three verbs, the third gated on `invalidate-vocabulary/v1`.**
This is the first section to arrive POST-v3 through § v3.2's channel, and it is here to be read as
evidence for the claim § v3.12 makes rather than as a cache feature: a grammar change shipped with no
version integer moving anywhere, on an engine that had already had its last flag day. It is also the
condition § v3.12 names for ever closing the window — "at least one grammar section shipped post-v3
through `engine_features` with no version bump" — now met.

**What the verb is.** `tables.<t>.invalidate[]` admitted exactly two entry shapes: `{table, column}` for a
targeted row delete and `{option_pattern}` for a named option. Both reach a cache that is STORED as a
database row. Nothing reached the WordPress object cache, which no `DELETE` can touch, so a plugin that
caches per row in an object-cache group could only be repaired with hook code. The third shape is
`{cache_group, cache_key}`, with `{id}` substituting the row's resolved local id into EITHER member; the
engine drops the entry and then re-reads it, refusing when it survives.

**The admission rule, which is the actual subject.** The old refusal ended "belongs in a native action or
a provider capability", and that sentence is what converts a declarative adapter into a
`compatibility_shim` one. Widening the vocabulary is therefore the highest-leverage move available and the
easiest to abuse — chasing per-plugin behaviour into the engine one verb at a time is precisely what the
boundary text refuses. So a verb enters on TWO OR MORE INDEPENDENT DEMANDS in the engine-gap ledger and
the shipped provider corpus, and on nothing less:

- Paid Memberships Pro's former provider dropped
  `wp_cache_delete(<level id>, 'pmpro_membership_level_meta')` — the id on the KEY side. Its shipped
  declaration now lives at `tables.pmpro_membership_levels.invalidate` in
  `adapter-packages/paid-memberships-pro/package/manifest.json`;
- `adapter-packages/woocommerce/package/runtime/providers/woocommerce-product-lookups.php:1301` drops
  `wp_cache_delete('lookup_table', 'object_<product id>')` — the id on the GROUP side, in an unrelated
  plugin. It is the second demand that made the rule "`{id}` in either member" rather than a
  transcription of the first plugin's spelling, which is the difference between a generalisation and a
  branch.

**Single-demand shapes stay refused without becoming phantom platform gaps.** A cache entry shared by every
row of a table — neither member carrying `{id}` — is demanded by Code Snippets alone, so it does not enter
the engine grammar. The certified Code Snippets provider owns that literal table-wide cache boundary and
verifies its native postcondition. `tools/engine-gaps.json` therefore records the demand on the closed
`verified_code_snippets_state_provider` primitive, not as an open generic invalidation primitive; the
grammar refusal still names the `actions` channel where a blanket cache belongs.

**The staging, and why the gate is where it is.** The verb is admitted only for a manifest declaring
`invalidate-vocabulary/v1` in `engine_features` — which, by § v3.3's growth rule, also means declaring
`spec-window/v1`, since that is the feature claiming the `engine_features` key itself. An engine without
the feature refuses the adapter BY FEATURE NAME instead of mis-reading the declaration, which is the whole
property that makes a bump unnecessary. This feature is the first whose `keys` list is EMPTY: it widens a
value vocabulary inside a section that already exists, so § v3.3's partition does not move and register
row R-21 still counts 33 top-level keys.

The gate is asked once, in `ManifestGrammar::validate_tables()`, where the declaring document is in hand —
never inside the per-declaration shape check, which also runs on `Snapshot`'s live re-checks where no
manifest exists and which must reach the same verdict there as it did at load. `site.wprism.json`'s
`policy.tables` overrides pass through the same funnel with a SITE marker set, so a repository cannot mint
a feature by writing `engine_features` into its own policy object.

**What it bought, measured.** Paid Memberships Pro's entire executable surface was one `actions[]` entry,
one `providers[].source: "manifest"` row, and a 233-line provider whose whole product act was that
`wp_cache_delete()` in a loop plus its private verification projection. All three have been replaced by one
declarative line, dropping the shipped adapter from `compatibility_shim` to `declarative_manifest` — G5
condition 1's "the answer is more declarative primitives" performed once on a real adapter. The engine
proves the exact cache entry absent, and the product regression then observes the committed value through a
faithful PMPro API read (`adapter-packages/paid-memberships-pro/tests/offline/regress_paid_memberships_pro_production_readiness.php`).
The migration intentionally re-stamps the adapter identity, so deployed artifacts must be recompiled and
re-pinned; there is no fallback to the retired provider.

### v3.16 The reviewer tier: federating the `exercised` leg

**Rider: WP-5.2. Enforced today: yes — `evidence.reviewer` is admitted and `reviewer_signed` is minted.**
This is the flip of two of the five slots § v3.10 reserved, at gate G4, and it changes no wire: the signed
statement is the same six members, the signature domain is untouched, and every certificate already in the
field derives the identical disposition. What moved is a policy and a vocabulary.

**The fact the ladder could not say.** A certification word answers "who vouched for this adapter", and
until this rider that was the only question it could answer, because the party who VOUCHED and the party
who EXERCISED were always the same one. `site_signed` means the customer organization vouched for its own
adapter; `third_party_signed` means a root this project reviews vouched. Neither can say that an
independent conformance lab produced the run and a root vouched for the lab — two distinct named parties —
and collapsing that into either word prints one of them and deletes the other.

**What is admitted.** The bundle evidence object gains ONE optional member:

```
evidence: { exercised: true, grammar: "ok", reason: "…", reviewer: "<party>" }
```

`reviewer` names one reviewing party as a canonical identity — the same grammar
(`AdapterSources::assert_name()`) an adapter name and an authority key id are held to, so it is comparable
across certificates and safe as a JSON object-map key. The three-member object is unchanged and remains
the common case; the member is optional in the strict sense that a bundle omitting it produces a
byte-identical disposition, which is what keeps every `site.wprism.json` pin binding (AGENTS.md rule 2).

**Three rules make the word unreachable by relabelling**, and each closes one way a third tier could
launder an unreviewed claim rather than federate a reviewed one:

1. **It requires `exercised: true`.** A bundle declaring no exercise has no exercise to attribute, and
   "reviewed by X, exercised by nobody" is the one sentence this tier must never be able to print.
2. **The party is an identity, not prose.** Free text would make two certificates naming the same lab
   incomparable, which is most of what a tier is for.
3. **It may not BE the signing authority.** A bundle whose reviewer is its own signer is `site_signed` or
   `third_party_signed` wearing a third word. The tier means two parties; one party is already covered.

**Nothing about verification relaxed, and that is the whole safety argument.** The proof stays
content-addressed, signed, git-revision-bound and re-verified on every load. In particular
`verifyRatification()` still refuses a disposition that CITES a test the bundle does not carry as passing
— "cites absent or non-passing bundle test" — and `verifyBundleAssets()` still refuses a cited test whose
RESULT asset records a non-zero exit. What federates is not the checking; it is WHERE the evidence was
produced. `AdapterCertification::sign()` has always taken an `$evidenceRepo` distinct from the site
repository, and runtime verification reopens no evidence checkout at all, so a bundle minted from a
foreign repository verifies on a site that holds none of the cited tests and can never run them.

**Precedence, stated because a ladder word is only as good as its order.** `reviewer_signed` is reached
exactly where `site_signed`/`third_party_signed` would have been — after `certification_unjudged`,
`uncertified` and `signed_unpinned`, all of which are about whether a reviewed signature exists at all. A
named reviewer does not buy past the repository pin: an unpinned reviewer-tier certificate is
`signed_unpinned`, the same as any other.

**Both observer vocabularies admit the word in this same change**, because a closed enum a target can emit
and a host cannot read refuses the whole observation. The reading half shipped one release earlier as
§ v3.10's pinned refusal, which is why an operator whose host is behind gets a version fact rather than
`invalid enum` — the property R-28 says a reservation on a read surface exists to buy.

`sandbox/tests/offline/adapter/regress_reviewer_evidence_tier.php` is the evidence: a bundle from a
foreign evidence repository, over a test this repository holds no file and no Makefile target for, signed
through the shipped verb and then re-verified after that repository is deleted from disk; all three signed
words minted in one estate; the three refusals above and the three arms of the cited-test rule; and the
seven-member proof a reviewer-less bundle still produces.

**What this does NOT do:** it does not enroll anyone. `platform/adapter-library/capabilities/adapter-authorities.json` is
still the empty v1 registry, and gate G4's condition 7 — a third party ACTUALLY producing an exercised
bundle — is a fact about the world that no fixture can supply (docs/guides/trust-enrollment.md). This
rider makes the tier expressible and verifiable; it does not make it populated.

### v3.17 A signing profile that accepts an author-written disposition

**Rider: WP-5.3. Enforced today: yes — `wprism adapter certify --ratification-file`, judged by the shipped
disposition validator and by nothing else.**
The site profile could sign only a claim the engine wrote. `siteRatification()` derives every field from
the manifest and stamps `deletion_semantics.supported: []`, `lifecycle_phases: []` and one canned sentence
on each refusal, so `--reason` was the operator's ONLY input — one string, for a document whose every
`unsupported[]` row and every `default_authored_keyspaces[]` row the shipped validator requires a separate
non-empty prose reason on (`ManifestDispositions::validate_entry()`). A site organization that
had actually reviewed its adapter's deletion semantics had no way to say so, and a site organization that
had reviewed nothing signed the same sentences — which is the shape of a claim nobody can weigh.

**The entry is the author's; the envelope is the signer's.** `--ratification-file` takes ONE disposition
entry — the exact document shape `adapter-packages/<name>/package/disposition.json` carries now, not a new
dialect — and `sign_site()` wraps it in the `wprism-manifest-dispositions/v1` envelope it already owns.
`format`, `profiles: []` and the single `manifests.<name>` key are never authored, so an authored document
cannot ratify a second adapter, smuggle a profile, or name a subject other than the one being signed. That
is the same posture as the certificate PATH being derived rather than declared.

**What validates it is the shipped validator, and this is the whole property.** The authored entry goes
through the identical chain the derived one goes through — `verifyRatification()` →
`validateDisposition()` → `ManifestDispositions::validate_external_entry()` — with the same
`$evidenceSchema` and the same `$requireExerciseTests` the bundle's own `exercised` flag decides. The
docblock on that seam already named this purpose: it exists so an entry living outside the shipped
registry "keeps section, version, capability, and evidence grammar identical instead of growing a second
long-lived validator beside it". Nothing on the path has any notion of who wrote the bytes, which is what
makes the property federated rather than delegated. So a blank refusal reason, a section the manifest does
not declare, an intent-only table the entry does not mark unsupported, a version range the manifest does
not carry, a cited test the bundle does not hold, and a boilerplate entry that refuses nothing
(`unsupported: []`, "a malformed required field") are all refused by code that shipped before this rider.

**One rule the profile adds, and why it is not a second grammar.** An authored entry must name every
surface the manifest declares, in the arm this engine's own vocabulary gives it. `validate_entry()`
refuses a section the manifest does not declare and has nothing to say about one it OMITS — while
`claim_from_disposition()` builds the claim's `surfaces` list from exactly those two lists, so an omitted
section is a surface that is simply blocked later with nothing saying why. It is the signer's own existing
sentence pointed at the authored profile: **narrow a claim with an `unsupported[]` row and its reason,
which a reader can weigh, never by leaving a surface out, which no reader can see.** Strength is the
author's to argue; scope is not.

**Nothing about the wire moves.** The ratification document's format, the six-member statement, the
signature domain and every verifier are untouched: an authored certificate and a derived one are the same
bytes with different content, and a deployed verifier cannot tell which profile signed — because there is
nothing there for it to tell. The honesty is carried where it already was: the bundle still records
`exercised: false`, `evidence.tests` is still empty by construction, and the claim still reads
`Site-certified`. What changed is that the sentences inside it can now be the site's own.

**`recertify` will not re-derive over an authored claim.** The flag-day verb replays the inputs a
certificate carries and derives the ratification, which was total before this rider and is a choice after
it. Re-deriving over an authored entry would replace a site's own argument with the canned floor, under
the site's own key, with nothing in the report saying a claim had changed. So it compares the ratified
disposition against the one it WOULD derive (`AdapterCertification::site_disposition_is_derived()`) and
reports a mismatch as a `blocked` row naming the remedy verb — the same loud-and-scoped posture as its
existing blocked row for a certificate under another key.

**What this does not fix, stated so it is not mistaken for coverage.** An author can still write
self-serving prose; no code answers that, and pretending otherwise would be the false assurance this
document exists to refuse. What is enforced is the SHAPE of the argument — per-refusal reasons, complete
scope, a claim checked against the manifest in five directions — by shipped code. WP-5.2's reviewer tier
records someone other than the author having read it, and WP-5.4's graded axis is where a claim the prose
cannot support goes, so the pressure to relax this requirement has somewhere else to land.
`sandbox/tests/offline/adapter/regress_authored_ratification.php` drives all of it, including the derived
floor still standing for an author who writes no file.

### v3.18 The evidence grade: computed beside the reviewed word, never instead of it

**Rider: WP-5.4. Enforced today: yes — three axes derived on every call and projected into
`docs/adapter-grades.md`, byte-compared by `make release-gate`. No wire member, no stored verdict, no
shipped byte.**
A disposition `status` is a three-value enum and every read surface projects it BINARY:
`AdapterRegistry::report()` raises `authored_state_not_certified` for anything that is not the exact
string `certified`, and `AdapterSources::claim()` writes `uncertified` over a signed claim the repository
pin does not bind exactly — one word for every distance from the one accepted answer. Measured on
the shipped library, 14 of 16 reviewed subjects print the same word while their evidence differs by
half — `acf` carries 11 of its 11 applicable scenario families, `polylang` and `woocommerce` carry 5 of
12. An operator choosing among adapters for one plugin cannot see that, and the only vocabulary available
for saying "this one carries far more evidence" was to widen what `certified` means. That pressure is the
hazard this section removes.

**Three axes, each already machine-readable and previously projected into nothing.** *Coverage breadth* —
which of each `adapter-packages/<slug>/evidence/production-readiness.json`
document's reviewed `scenario_families` have a
`covered` bucket naming evidence files, against that ledger's own taxonomy minus the families reviewed
`not_applicable`. *Exercise depth* — the certification bundle's per-test pass map, reaching a claim as
`provenance.proof.bundle.exercised` + `.tests`, which `verifyBundleManifest()` has already refused unless
every entry is a named passing test exactly once. *Platform reach* — the `verified` cells of
`platform/adapter-library/capabilities/platform.json` that the claim states after § v3.5 narrowing, which is the same set
a certificate binds as exercised under § v3.6; the narrowing itself is CALLED
(`ManifestDispositions::narrowed_environment()`), never reimplemented, so the axis cannot grade a claim
the agent does not make.

**The grade is its weakest present axis, and that is a rule rather than a formula choice.** Averaging
would let a wide platform claim compensate for missing scenario evidence, which is exactly the arithmetic
that turns an evidence summary into a marketing number; the weakest axis is the sentence an operator needs
("this is as far as the evidence goes"). An axis with no evidence document for a subject is **silent** and
leaves the arithmetic; an axis whose document records nothing exercised is **none** and drags the grade
down — the distinction `AdapterSources::certification_evidence()` already draws between `[]` and `null`,
applied one level up.

**No evidence, no grade.** Platform reach alone cannot mint one. An adapter nobody exercised still states
the whole reviewed boundary through `narrowed_environment()`'s default, so grading that would hand a fresh
unreviewed adapter a number for having declared nothing. A subject with neither breadth nor depth reads
`no grade`, which is the honest answer where a low grade would not be.

**Computed on every call; a `grade` member may never be authored.** Nothing reads a stored verdict, and
every input carrying a `grade` member is refused BY NAME rather than ignored — a member somebody could
write down would be read by the next reader that wanted one, and from that moment the number is an
assertion wearing a derivation's clothes. The projection writes one prose document and no machine-readable
record, so there is nothing for a later reader to mistake for a source of truth.

**`certified` is untouched, and this section is not a second status.** The grade ships nothing: the model
lives in `tools/adapter-grade.php`, two of its three inputs are outside `Adopt.php`'s
assembled `agent recovery` tar, and no file under `agent/`, `cli/`, or `recovery/` names it —
so no adapter digest, no repository pin, no refusal message and no wire member moved. The reviewed word
means exactly what it meant, is printed verbatim in its own column beside the grade, and remains the only
thing any engine decision consults. What the grade absorbs is everything real-but-not-reviewed, which is
precisely what keeps the pressure to widen `certified` off the word itself.
`sandbox/tests/offline/adapter/regress_graded_claim.php` drives all of it, including the gate biting on a
hand-edited grade, on evidence that moved while the prose did not, and on an authored `grade` member.

**No register row.** `docs/wire-surface.md` records decisions frozen inside SIGNED bytes, every value read
out of the shipped engine by reflection or by running its refusals. A grade is in no signature, is stored
nowhere, and is re-derived from documents anyone may move; a row for it would be an authored entry in a
register whose whole discipline is that it contains none.

### v3.19 `wprism-adapter-index/v1` — discovery and distribution, over a document that carries no authority

**Rider: WP-5.6. Enforced today: yes — `cli/src/Adapter/AdapterDistribution.php` reads it,
`wprism adapter discover|install|update` are the three verbs, and
`sandbox/tests/offline/cli/regress_adapter_distribution.php` drives every refusal below. Nothing under
`agent/` reads this format, and no shipped byte moved.**

Three adapter sources ship — the agent's own library, `<site-repo>/adapters/`, and one
`wprism-adapter.json` at the root of each active plugin — and all three answer a question about bytes that
are already on the disk. `docs/guides/adapter-authoring.md` stated the gap as Planned in exactly those
terms: "what remains absent is a remote/registry mechanism that tells you an adapter you do not already
have EXISTS". This section is that mechanism, and it is deliberately the smaller half of what the word
"registry" usually means.

**The document.** `{adapters, format}`, closed. `adapters` maps an adapter NAME to a non-empty list of
entries, each exactly
`{adapter_sha256, agent_versions, authority_fingerprint, certificate_sha256, certificate_url, url,
version}`. Digests are lowercase 64-hex sha256 of the exact published bytes; `agent_versions` is the
`{min, max}` window `Policy::assert_min_max_range()` already authors, min inclusive and max exclusive,
naming the agent line the publisher offers the package for; `version` is an opaque publisher label in the
same slug `fetch_artifact` accepts for an artifact version; both URLs are absolute. The name is the map
key rather than a member for the reason `AdapterCertification::certificatePath()` derives its own path:
a name that can be stated twice can be stated inconsistently.

**It carries no signature, and that is the decision.** An index is a POINTER document. Every entry names
bytes and their digest; every trust decision is re-derived at install from the FETCHED bytes by
`AdapterCertification::verifyFile()`, the same call the live policy path makes, against the trust root
the installing repository already holds. So the complete blast radius of a tampered, replayed, truncated
or hostile index is DENIAL: move a digest and resolution refuses, move a URL and the digest refuses, move
the fingerprint and the enrolled-authority check refuses, delete an entry and the package is not offered.
It can never cause an unverified byte to land. Signing it would create a second trust root — with its own
custody, enrollment and revocation story — in front of a decision already taken by a root that has all
three. `docs/wire-surface.md` R-30 records that, and records what would have to be true for a later
generation to reverse it.

**Installation is an operator act, and stays outside `agent/`.** AGENTS.md rule 1 says the drop-in fetches
nothing at runtime; nothing here changed that, because nothing under `agent/` reads this format. An
installed package lands as `adapters/<name>.json` plus `adapters/certifications/<name>.json` — exactly the
two files `wprism adapter certify` writes — so the agent cannot tell a distributed package from an adapter an
operator hand-placed, and every rule already written about an installed adapter keeps applying unchanged.
Installing is also not PINNING: `wprism adapter pin` remains the separate decision that makes a site load it.

**Resolution never falls through.** `sandbox/bin/fetch-artifact.sh`'s discipline, generalised: an unpinned
version, a digest that does not match the fetched bytes, an unreachable URL, a package served through a
symbolic link, an out-of-window package, an unsigned or unverifiable one, or a signer this repository has
not enrolled is each REFUSED. There is no arm that installs a package uncertified — an uncertified adapter
still loads, so that arm is the whole reason a distribution channel would be worth attacking. Verification
happens in a staging root, so a refusal at any rung leaves the repository byte-for-byte as it was found.

**Enrollment is never inferred.** `authority_fingerprint` must already be carried by a record in the
installing repository's own `adapters/authorities.json`, and the authority the certificate actually
verifies under must be that same fingerprint — an enrolled key is not automatically the right key. There
is no trust-on-first-use arm: a channel that could enroll its own signer would be a channel that signs for
itself. Revocation and expiry need no clause here at all — `AdapterCertification::authority()` resolves
every key through `assertNotRevoked()` and the v2 record's mandatory window, so § v3.8's typed revocation
channel and a lapsed `not_after` reach an install through the identical door they reach every other
verifier.

**No order over `version`.** The format defines none, and neither verb invents one. With two entries
inside the agent's window and no `--version`, `install` refuses and lists both; `update` requires `--to`.
Ranking opaque vendor labels would be a resolver guessing at a grammar the publisher never agreed to, and
the wrong guess installs the wrong package silently. `update` also refuses when the installed bytes hash
to nothing that index published: those bytes are somebody's decision, and an unsigned document does not
get to overwrite one.

**One transport ships: `file://`.** An `https://` entry is DISCOVERABLE — learning that an adapter exists
is the capability this section adds, and it does not need a fetcher — and refuses at install naming the
mirror step. A network fetcher no offline suite can exercise is an unevidenced supply-chain surface inside
the one command whose entire job is to refuse unevidenced bytes, which is why `fetch-artifact.sh` keeps
its own network half in the harness rather than in shipped code. Adding an HTTPS transport is a separate
reviewed decision with its own evidence, not a fill-in.

### v3.20 `body_refs` and the `json` body mode — a reference path inside a post body

**Rider: WP-6.5. Enforced today: yes, for a manifest that declares `structured-body-refs/v1`.**
`WPRISM_SPEC_VERSION` did not move to admit it and is still `3`, asserted in the same run as the section's
own verdicts by `sandbox/tests/offline/grammar/regress_body_ref_grammar.php`. It is the THIRD grammar
change to ride § v3.2's channel after § v3.14 and § v3.15, and the first to ride it in both directions at
once: it claims a top-level key AND widens a value vocabulary inside a section that already exists.

**What was missing.** `post_types.<type>.body` was closed at `{blocks, verbatim, serialized}`, and
`tools/engine-gaps.json` carried `structured_post_body_reference_paths` as its highest-demand open
primitive: a `wpforms` post's `post_content` is a JSON document that carries a reference to another
entity inside it, and none of the three modes addresses that. `blocks` runs a block parser over a
document with no blocks; `serialized` decodes PHP serialization that is not there; `verbatim` preserves
the bytes, which means preserving a SOURCE-LOCAL id. Omitting `body` is not a fourth option — it defaults
to `blocks` — so a JSON body was mis-read rather than left alone.

**The declaration.** One post type in `json` mode, and the paths inside it:

```json
"post_types": {"wpforms": {"class": "authored", "body": "json"}},
"body_refs": {
  "wpforms": {
    "json_refs": [
      {"path": "$.settings.confirmations.*.page", "kind": "post", "cast": "string"}
    ],
    "sentinels": {"$.settings.confirmations.*.page": ["previous_page"]}
  }
}
```

`json_refs` is the SHIPPED dialect, not a new one: the same minimal JSONPath (`$`, `.`, `..`, `.*`), the
same `kind` keyspace names, the same `cast: "string"`, and the same overlapping-path refusal, because
`BodyRefGrammar::validate_one()` hands the list to `ReferenceRules::body_json_refs()` rather than
re-implementing any of it. `key_refs` is refused BY NAME: an id-keyed map inside a post body has no
measured demand, and this engine does not claim a shape it has never seen.

**What is genuinely new is `sentinels`, and it is the measurement that forced it.** On a live WPForms
Lite 2.0.0.5 pair, `settings.confirmations.<n>.page` holds a PAGE post id as the JSON string `"4"`, and
the SAME key legitimately holds the literal `previous_page` — `includes/class-process.php:1553-1562`
branches on exactly that before `get_permalink((int) $confirmation['page'])`. A rule that coerced the
slot would silently repoint a confirmation at post 0. So a declared path may carry a declared set of
non-reference literals, and a value that is neither a positive id nor a declared sentinel REFUSES: "the
adapter forgot a sentinel" and "this path is not a reference after all" have opposite remedies, and only
the author can tell them apart. A NUMERIC sentinel is refused in turn — it would be indistinguishable
from the id the path resolves.

**The identity round-trip precondition, which is the mode's whole safety.** Before any substitution the
document is decoded and re-encoded with `wp_json_encode()`'s default flags and compared to the input BYTE
FOR BYTE; a mismatch refuses, naming the document. A post body is not a meta row — it is folded into
`Canon::post_hash_basis()` and it is what an operator reads in a diff — so a mode that could not reproduce
an untouched body would show every adopted form as changed forever. Two hazards make this a real check:
a JSON object whose keys are `"0","1","2"…` decodes to a PHP list and re-encodes as a JSON ARRAY, and an
empty object `{}` re-encodes as `[]`. Both are refusals, not accommodations. The same discipline decides
the per-path TYPE in both directions: apply writes the DECLARED type, so a source whose stored type
disagrees with the declaration refuses at capture — `AttrIdCodecGrammar::assert_source_type()`'s rule
(§ v3.2's WP-6.1 pair) reached through a different door.

**Optional self-references still need rebinding.** The measured `$.id` is ABSENT on the template create
path, an INT on the `['builder' => false]` path and a STRING on the real builder save. Preserving it
undeclared reproduces bytes but leaves a source-local form identity that can select an unrelated target
form. A manifest declaring **`body-ref-preserve-type/v1`** as well as `spec-window/v1` and
`structured-body-refs/v1` may use `{"path":"$.id","kind":"post","cast":"preserve"}`. This value-vocabulary
feature claims no new top-level section and does not widen option/meta or block-attribute casts.

Capture accepts a positive native integer or an exactly representable canonical positive decimal string.
Negative/overflow/leading-zero IDs, booleans, floats and undeclared literals refuse. Missing paths remain
missing; `null`, `""`, `0`, `"0"` and declared sentinels retain the existing absence/literal semantics.
Present references become a closed, ordered object with exactly `format`, `type`, `ref`:

```json
{"format":"wprism-typed-reference/v1","type":"string","ref":"{{post:019200cc-0000-7000-8000-000000000012}}"}
```

`type` is exactly `int` or `string`; `ref` is the existing ordinary identity token in the declared
keyspace. Apply resolves that token through the existing ledger and emits a positive target-local ID in
the retained type. The pure `IdentityTokenCodec` owns strict envelope decoding; compiler, Apply and lint
reuse it. Extra/missing/reordered fields, wrong format/type/keyspace, bare tokens and malformed token
framing refuse. The JSON decode/re-encode precondition also rejects duplicate JSON keys. Compiler
portability validates every declared JSON-body reference, including fixed-cast paths, before any target
mutation. The `ref` name avoids the credential role denoted by `token`; neither the envelope nor its
surrounding configuration receives a secret/PII clearance exemption.

Typed references are atomic values during path traversal. The shared walker freezes native terminal
coordinates before rewriting and does not visit nested matches inside an already-owned value. Thus a
recursive native path such as `$..ref`, `$..type` or `$..ref.ref` cannot reinterpret its own generated
envelope. Capture, Apply and canonical compiler/lint projection share that atomic traversal; ordinary
fixed-cast declarations retain the original walk behavior.

**URL rebinding is separately negotiated.** A manifest declaring `body-url-rebinding/v1`,
`spec-window/v1` and `structured-body-refs/v1` may add `"url_rebinding": true` to one post type's
`body_refs` record. Only literal `true` is accepted; omit the member for the original reference-only
behavior. Merely declaring the feature does not enable it. The engine applies the existing
home/uploads/query-reference text codec to decoded string leaves outside declared reference positions.
It does not interpret block or shortcode syntax. JSON framing, key order and escaping still obey the
byte-exact decode/re-encode precondition.

Reference positions, including literal sentinels, take precedence over text rewriting. The shared
`JsonRefs` traversal protects their native key coordinates, not display locators: a key named `a.b`
and nested keys `a` then `b` can print the same dotted locator but must not protect each other's
values. Keys, non-string scalars and undeclared plugin-local field IDs are unchanged. Capture and Apply
must receive the product's text codec when opted in; missing machinery refuses before reference work.

**Reviewed scalar privacy exceptions are separately negotiated.** A manifest declaring
`body-pii-paths/v1`, `spec-window/v1` and `structured-body-refs/v1` may add `pii_paths` to a post type's
`body_refs` record. It is a non-empty list of distinct JSON paths, for example
`["$.settings.notifications.*.email", "$.settings.notifications.*.replyto"]`. Each path uses the existing
reference dialect, with a named first and terminal child and no recursive descent. Intermediate
wildcards select repeated record maps; lists retain the dialect's transparent mapping. Malformed,
duplicate, whitespace-padded, root-wildcard and terminal-wildcard declarations refuse at manifest load.
Omission grants no exception, including when the feature itself is declared. A replacement body rule
does not inherit earlier pins' privacy paths.

The permission covers only each matched scalar/null value and that field's semantic PII role. It does
not cover associative key bytes, containers, their descendants, unrelated siblings or secrets. A path
that currently selects a container grants no clearance to it; ordinary recursive checking still runs.
Selection uses native key coordinates, not ambiguous dotted locators, and changes no stored value.
Capture and immutable-repository authorization repeat the same selection over their respective decoded
documents, before publication or target mutation. The whole-body secret gate remains unconditional;
reference validation, exact JSON framing and URL rebinding retain their independent contracts.

This is reviewed authority to place an authored field in canonical state, not a claim that email is
non-personal, an environment-value substitution mechanism, or authority to capture form responses.
The motivating regression replaces the native fixture's notification smart tags with synthetic literal
recipient/sender/reply-to settings: without this feature both PostCapture and repository authorization
refuse. The shared compiler/materializer/recapture regression proves the scoped exception composes with
typed references and URLs. It does not establish native WPForms support or notification delivery.

**What the mode does NOT claim.** Without URL opt-in, the measured
`settings.confirmations.<n>.redirect` remains source-bound and retains its exact legacy warning.
With opt-in, that URL is rebound, but a remaining home URL in a preserved literal/key or an unsupported
text context still warrants a warning. Both plain and JSON-escaped home spellings are checked.
Non-URL environment data such as `notifications.<n>.sender_name` is not rebound. This is not block
attribute rewriting: `attr_id_codecs` has a separate declaration/parser, and nothing here invokes
`serialize_block_attributes()` or `wp_update_post()`/`wp_unslash()`. The real post materializer writes
the already-reencoded JSON through checked SQL; compiler/materializer/PostCapture regression proves
the complete target-body fixed point and refusal before mutation.

**Why the mode's gate is asked LATE while the key's is asked by § v3.3.** The section is admitted by the
closed key set the moment the feature is declared, which gives the three distinct verdicts § v3.2
requires. The MODE cannot be staged that way — a value inside a closed vocabulary has no key to refuse by
— so `PostTypeGrammar` recognises `json` and defers, and `BodyRefGrammar::assert_body_mode_gate()` refuses
it by feature name after `validate_adapter_contract()` has run. Refusing earlier would pre-empt all three
verdicts with a fourth sentence about a body mode, telling a `spec_version: 2` author about an engine
feature when what is wrong is the version their whole document declares. The one case left for the late
gate is the one no key can express: the mode declared with no `body_refs` section at all.

**No shipped manifest declares it, so no adapter digest moves** (AGENTS.md rule 2). The grammar proof
uses the previously-rejected candidate as a FIXTURE adapter, driven through the real
`PostCapture` seam over four `post_content` values captured from a live pair through the plugin's own
write paths — `sandbox/tests/fixtures/wpforms-body/`. That provenance is the point: a hand-written
fixture body has no confirmations, no page reference and no sentinel, which is why the ledger's own
one-sentence description of this coordinate was measurably wrong until the entities were authored through
the plugin instead of through `wp post create --post_content=…`. This fixture is not a WPForms product
claim: same-site URL rebinding, native materialization/submission and location reconstruction still need
their own implementation and evidence.

### v3.21 The certificate ARM rides in the feature's roster row

**Rider: WP-6.6. Enforced today: yes, for every manifest that declares an implemented engine feature.**
`WPRISM_SPEC_VERSION` did not move. PMPro now deliberately moves its own adapter digest as the first shipped
feature consumer; adding the feature vocabulary itself still moved no unrelated adapter (AGENTS.md rule 2).
Redirection was later authored with roster-backed keys in its initial digest, again moving no unrelated
adapter. Register row R-31.

**What was measured.** § v3.2's channel admits a top-level key at LOAD. § v3.3's partition is what a
SIGNER classifies with. Those were two different sets on purpose, and the gap between them was the whole
defect: `AdapterCertification::siteRatification()` refuses a key it cannot put in the `entity` or `field`
arm, both signing profiles share that one method (§ v3.17), and `claim_from_disposition()` builds the
signed claim's `surfaces` from exactly those two lists. So an adapter that used the channel — the first
real one, `sandbox/fixtures/wpforms-lite/adapters/wpforms-lite.json`, with four feature-claimed keys —
loaded on every site, captured, planned, and could not be certified by anybody. Downstream that is not a
certification problem: uncertified blocks readiness and host promotion, host promotion is `wprism deploy`,
and `wp wprism apply` refuses the non-forceable `code_revision_stale` until a deploy has run, so `certify ->
init -> deploy -> apply` refused in that order.

It had been patched twice before, one key at a time: `theme_version_range` joined the partition with
§ v3.3 (resolution 1) and `environment` with § v3.5, each because a top-level key in no arm makes its
whole adapter unsignable. The comment recording those two patches is the specification of a wall being
hit repeatedly, not of a rule.

**The rule.** A feature's roster row classifies every top-level key it claims:
`AdapterContractGrammar::IMPLEMENTED_FEATURES` maps `keys` as `<key> => <arm>`, where an arm is one of
`AdapterCertification::certificateArms()` — `entity`, `field`, `non_surface`. `siteSurfaceSections()` asks
the three-arm partition first and the roster second, for the keys the DECLARING manifest brought through
the channel. Four properties, and each is structural rather than reviewed:

1. **The partition can never again be incomplete against the shipped grammar for a feature-admitted key.**
   `keys` is a MAP: a row cannot carry a key without an arm. The failure mode is unrepresentable rather
   than remembered.
2. **The roster is the one definition.** It is not a fourth list of keys — the base partition still owns
   its 33 — and the two sets are disjoint by refusal: a roster row naming a key the partition already
   carries throws at `feature_key_arms()`, because that would be two spellings of one arm whose winner
   depends on which lookup runs first.
3. **An arm outside the vocabulary refuses at the roster.** Not at the author's manifest: a bad arm value
   would otherwise fall through to the unclassifiable-section refusal and report the AUTHOR's document
   for the ENGINE's typo. The refusal names the feature and the key.
4. **The scope is per manifest, never engine-wide.** `body_refs` present without
   `structured-body-refs/v1` declared keeps the unclassifiable refusal, because a certificate may not
   claim coverage of a section this engine reads nothing from.

The genuinely unknown key is unchanged, byte for byte: a key in no arm and admitted by no feature this
manifest declares still refuses with "which this signer cannot classify as an entity or field surface …
teach the signer this section", which remains exactly the right remedy for a misspelling.

**The four reviewed arm decisions.**

| key | arm | why |
|---|---|---|
| `engine_features` | `non_surface` | The claim channel itself. It is a list of engine feature names and covers no state, so a certificate's `surfaces` list would be carrying a runtime assertion. Same standing as `spec_version`. |
| `declaration_evidence` | `non_surface` | Provenance rows (§ v3.14). A surface list is the state an operator is told is covered, and evidence prose is not state. It is `notes` with a checkable shape, and `notes` is non-surface. |
| `body_refs` | `field` | Id-bearing paths inside a post body (§ v3.20) — capture tokenises them, apply rebinds them. The exact standing `block_attrs` has for a block attribute, one container deeper. `post_types` stays the entity beneath it. |
| `attr_id_codecs` | `field` | A typed refinement over a declared `block_attrs` rule; the validator refuses a codec with no rule beneath it. Its standing is `block_attrs`'s exactly. |

The sweep is complete rather than wpforms-shaped: `column_codecs` (`typed-column-codecs/v1`) is `field`
for `attr_id_codecs`' reason read one section over — a codec refines one declared `authored` column of an
already-declared `authored_snapshot` table, and the bytes inside a column are a field beside the table
that is the entity. `invalidate-vocabulary/v1` claims no key and therefore classifies nothing; it widens a
value vocabulary inside `tables.<t>.invalidate[]`, which keeps the entity arm the partition already gives
it.

**What a certificate now carries.** For the wpforms fixture: entity `[post_types, tables, taxonomies]`,
field `[attr_id_codecs, block_attrs, body_refs, options, post_meta]`. That is the irreversible half —
`claim_from_disposition()` turns those two lists into the claim's `surfaces` and the claim is inside the
signed statement, so moving a key between arms invalidates every certificate already issued over an
adapter declaring it (R-31).

**Also published.** `wprism manifest-validate --emit-schema` carries, per claimed key,
`engine_features.implemented.<feature>.sections.<key>.arm` and `.grammar` — that section's own closed key
set, projected by the collaborator that validates it (`BodyRefGrammar::RECORD_*` and
`ReferenceRules::JSON_REF_*`, `AttrIdCodecGrammar::CODEC_KEYS`, `ColumnCodecGrammar::CODEC_KEYS`,
`StructuredEvidence::EVIDENCE_KEYS`/`ANSWERED_KEYS`). Before this, an author reading the engine's own
answer learned that the sections exist and had to open three engine files to learn what may go inside
one — and could learn nowhere at all whether a certificate would cover it. `feature_section_grammars()`
refuses a claimed key it cannot describe, so the document cannot go quiet about a section authors are
expected to write.

### v3.22 `manifest-provider-runtime/v1` — core owns the manifest-provider protocol

**Rider: the manifest-provider runtime consolidation work package.**
**Enforced today: yes, for a `source: "manifest"` provider whose manifest declares the feature.**
`WPRISM_SPEC_VERSION` remains 3. This feature claims no top-level key; it widens the existing
`providers[]` row with `contracts`, so the `providers` certificate classification does not move.

The duplication this removes is protocol, not plugin behavior. A declaration may carry
`"contracts": {"<capability>": <contract>}` only when it is manifest-sourced and the manifest declares
both `spec-window/v1` and `manifest-provider-runtime/v1`. The map is non-empty, follows the
`capabilities[]` list exactly and in order, and every value passes the same capability/scoped validators
used during live provider negotiation. A plugin-sourced provider cannot declare `contracts`: its code is
independently shipped and must continue to advertise its own identity and capabilities.

The manifest-owned class extends `WPrism\ManifestProviderRuntime`. Core then supplies final
`identity()`, `capabilities()`, `invoke()`, `invoke_scoped()`, and `reconcile_scoped()` methods. The
behavior file supplies protected `invoke_<capability>(array): array` and, where declared,
`reconcile_<capability>(array): array` and `project_<capability>(array): array`. Construction refuses a
missing or malformed handler before mutation. Invocation refuses a receipt without `before`, `after`, and
literal `verified: true`; scoped invocation uses the closed `invoke_after: receipt|reconcile` and
`receipt_projection: handler` vocabularies.

The boundary is intentional: core owns identity, contract validation, advertising, dispatch, scoped
receipt construction, recovery routing, and the receipt-shape floor. The digest-bound behavior file still
owns plugin API calls, plugin storage/topology knowledge, and the value-level postcondition. Moving those
semantics into core would create plugin-name branches under `agent/src`; the future optimization is for
the plugin itself to ship the same negotiated capability through `source: "plugin"`.

The shipped migration covered nine provider files across eight adapters and removed 697 physical lines of
duplicated protocol and checked-read mechanics without deleting their native effects or verifiers. Together
with PMPro's earlier 233-line whole-file absorption, that change removed 930 lines from its historical baseline.
The current residual inventory and ownership are derived from capsule manifests and runtime trees; the closed runtime contract is exercised by
`sandbox/tests/offline/adapter/regress_actions_providers.php` and each provider retains its product
regression.

### v3.23 `schema-settlement/v1` — strict observation gets a recoverable prerequisite

**Rider: the Rank Math schema-settlement and durable provider-ordering work package.**
**Enforced today: yes, for host `wprism deploy`.** `WPRISM_SPEC_VERSION`
remains 3. This feature claims no top-level key; it widens an existing provider
action with a closed phase-specific grammar. Direct `wp wprism deploy` refuses
any adapter that needs it because the agent cannot authenticate or restore the
host-retained checkpoint by itself.

The action is exactly a provider action with `phase: "schema_settle"`,
`args: []`, a distinct `readiness` capability on the same provider, and a
non-empty lexical, duplicate-free `prepares` list. Every prepared name is a
table declared by that manifest. `triggers` is forbidden. Its effects set must
equal `prepares` exactly, one row per table, with `kind: "database"`,
`mode: "restorable"`, and selector
`{scope: "database_checkpoint", type: "table", value: <name>}`. Across all
pinned manifests, one table has one schema-settlement authority. When
manifest-owned provider contracts are present, readiness is an argument-free,
idempotent, site-scoped capability that reads exactly the prepared table
surfaces and writes nothing. The preparation capability is likewise
argument-free, idempotent, and site-scoped, and both reads and writes exactly
those surfaces. These two manifest-owned contracts are load-time grammar, not
claims deferred to the target. Plugin-sourced contracts remain live-negotiated.

Schema status is deliberately separate from strict plan observation. It first
checks table presence, then invokes readiness without mutation. A missing table
whose durable table identity or canonical state proves prior authored use is
loss, not an installation opportunity, and refuses before acquiring a lease or
checkpoint. A legitimately absent table remains absent to compile and plan;
the host must settle it before the strict observer runs again.

The host phase order is fixed: read-only lifecycle and schema status; exact
artifact/checkpoint creation and authentication; code staging when required;
publication of an external provider-settlement intent; lifecycle retirement;
fresh-process lifecycle activation; schema settlement; then lifecycle
settlement under the durable promotion session. The intent is published before the first
lifecycle/provider mutation and contains the artifact hash, checkpoint,
release owner, and every ordered remaining lifecycle/provider phase. Each
successful phase advances
it atomically. Ordinary policy load refuses while it exists, so a crash cannot
turn an unrecorded partial provider transaction into normal operation. Recovery
binds the same intent into its signed checkpoint instruction, restores the
exact checkpoint, and clears the database and external intents only after
verified recovery. The database-external intent also fences host mutation:
adopt, unadopt, and retained-checkpoint prune cannot cross it, while read-only
inventory and recovery remain admitted. Recovery selects the one exact retained
checkpoint row named by provider debt before profile selection or lease writes.

Checkpoint target identity is
`sha256("wprism-database-target/v1\0" || canonical_json({host: DB_HOST,
name: DB_NAME, prefix: $table_prefix}))`. It contains no database credentials.
The host obtains this digest before export, the isolated exporter rechecks it,
and the sealer authenticates it in the first secretstream record. Verification
returns `wprism-retained-checkpoint-verification/v2` with the complete
ciphertext digest and database-target digest. Recovery compares current
wp-config before its first lease mutation; begin, reset/import, and both
provider settlement phases independently repeat the comparison. External
recovery records persist only the digest. A retained checkpoint without this
authenticated metadata is not silently upgraded and cannot authorize restore.

Immediately before provider DDL, the agent also publishes a database-local
schema intent bound to the same release pair. The readiness receipt is a map
keyed exactly by `prepares`, each value `{present, schema_hash}`, with identical
`before` and `after`. The preparation receipt uses
`{present, schema_hash, row_count, rows_sha256}`. An already-present table must
retain the exact evidence row. A previously absent table must become present
with `row_count: 0`; the phase may create schema but cannot seed authored data.
The row hash is complete, coherent, and resource-bounded: a multi-query witness
uses one repeatable-read snapshot and refuses a storage engine that cannot
provide it. Providers impose database-side row and byte bounds, reapply the
per-row bound inside every value-bearing query, and use deterministic fixed-size
primary-key keyset chunks whose maximum transferred page is intentional. A
prefix sample, raceable census, or whole-table PHP materialization is not
evidence.

This closes the Rank Math case without weakening the observer. Activation does
not create redirection tables when that module starts disabled, while a strict
plan correctly refuses to fabricate them. The adapter's provider owns the
plugin-specific installer call and audited schema knowledge; the engine owns
phase ordering, checkpoint identity, create-only verification, durable debt,
and recovery. No Rank Math branch enters `agent/src`.

### v3.24 `plugin-incompatibility/v1` — competing plugin contracts refuse before composition

**Rider: the Rank Math and Yoast composition-boundary work package.**
**Enforced today: yes, at shared policy finalization and repository authorization.** `WPRISM_SPEC_VERSION`
remains 3. The feature claims the top-level `incompatible_plugins` key with the
certificate's non-surface arm. Its value is a non-empty, lexically sorted,
duplicate-free list of exact WordPress plugin basenames in
`<directory>/<main-file>.php` or `<main-file>.php` form.

Only a manifest that owns a `plugin` may declare the list, and its own basename
is forbidden. The list narrows admissible co-installation; it grants no state,
provider, lifecycle, or filesystem authority. During aggregate policy load,
every named basename is compared with the exact `plugin` claims of the pinned
manifests. If one is present, the finalizer refuses the declaring manifest,
its plugin, the incompatible basename, and every pinned claimant by name. The
complete conflict set is sorted before the first verdict is chosen, so reversing
manifest or active-plugin order cannot choose a winner or change the message.

One declaration is sufficient. The conflicting plugin's manifest need not
repeat it, and site policy cannot override it: an adapter author is stating
that the two contracts cannot honestly share one aggregate policy, not asking
an operator to resolve redundant ownership. The only remedy is to pin one of
the incompatible adapters. The finalizer runs this guard before a compiler,
capture publisher, promotion lease, lifecycle hook, schema settlement, or
provider receives the policy. Rank Math's declaration against Yoast is the
first consumer; the participant-owned scenario executes capture and host
deploy with both manifest and active-plugin orders and proves the same refusal
with no repository publication or durable mutation debt.

The same declaration also binds canonical `active_plugins` during repository
authorization. A competing plugin does not need an adapter pin to load its native
hooks: omitting that pin cannot bypass the constraint. A present incompatible
basename emits `repository_active_plugin_incompatible` with the declaring
manifest and exact competing basename before capture publication or target
lifecycle work. This applies to state-only and code-enabled repositories alike;
code-mismatch overrides cannot bypass repository authorization. The guard uses
desired state, allowing deploy to remove a competitor from the live target when
the canonical graph is compatible. An absent or inactive competitor is allowed.

### v3.25 `scalar-reference-intersection/v1` — one value, multiple native coordinates

This feature refines an existing exact `options.<name>` declaration and adds no
top-level section, certificate arm, token kind, or version-integer change. The
v1 shape is closed: `class: "authored"`, `ref: "term"`,
`ref_same_local_id_as: ["tt"]`, and required `ref_taxonomy`, a nonempty lowercase
ASCII taxonomy name of at most 32 characters (`[a-z0-9_-]`). Optional `cast` is
only `"string"`. Array/CSV refs, structured values, sub-keys, repeated rows,
patterns, dynamic options, metadata, interpreter-returned rules, and standalone
`ref_taxonomy` are not admitted. The declaring v3 manifest must claim the
feature. The emitted feature row publishes its `value_constraint` from the
owning engine grammar.

Terms are the only canonical entity currently modeled with two physical ledger
coordinates. Arbitrary post/custom-keyspace combinations would claim an entity
relationship the engine cannot prove, so this version rejects them. WooCommerce
declares `product_cat`; no WooCommerce-specific branch enters the engine.

For a positive native integer, capture first proves real term and TT rows whose
ids both equal that integer and whose taxonomy is the declaration's exact value.
Both ledger coordinates must then resolve to the same UUID. Canonical output is
the ordinary `{{term:uuid}}` token. Compilation requires that UUID to identify
exactly one canonical term in the declared taxonomy. Apply requires both target
bindings to resolve to the same positive integer, then locks and proves its
physical term/TT tuple inside the authored transaction before writing the option.
The target integer may differ from the source integer; unrelated terms need
not have equal term/TT ids.

Native `0` and `"0"` capture as canonical integer `0`, the durable unset value.
Declared absence remains absence. Only the explicitly selected options-only
lifecycle snapshot may project a physically valid hook-created tuple as
transient absence when **both** ledger bindings are absent. Handoff binds that
missing projection to frozen desired state before ordinary apply. Generic strict
read-only capture, production export and explain never select that purpose,
including when site scope excludes the referenced taxonomy. A partial, malformed,
contradictory, or physically missing tuple never qualifies. Ordinary capture and apply refuse
with `reference_intersection_failed`; no warning/drop path, fallback keyspace,
force bypass, or native-id rewrite is authorized. Unconstrained reference rules
retain their existing dangling-reference semantics.

Site policy may relinquish the whole option as runtime/derived, or declare it
operator-provisioned env state. It cannot substitute a write-capable rule or
any `sub_keys` object for the manifest's value contract. Interpreter answers
cannot echo or replace a constrained static rule. These are boundary refusals,
not implicit merging of missing constraints. Manifest edits change adapter
identity and require recompile plus explicit re-pin.

### v3.26 `native-value-validation/v1` — explicit native metadata predicates

This feature adds no top-level section, token kind, or version-integer change.
A declaring v3 adapter can put `native_value_validation` on an authored
post/term/user metadata rule or metadata pattern. An interpreter-returned
metadata rule requires exactly one declaring, feature-enrolled v3 owner. The
only profile is the exact object
`{"profile":"wordpress-kses/v1","context":"pre_user_description"}`. Unknown
profiles, contexts, fields, non-authored classes, option declarations, and site
policy declarations refuse. The feature row publishes the engine-owned grammar.

Portable compilation checks strings of at most 1,048,576 bytes, valid UTF-8,
without C0 controls other than tab, newline and carriage return, or DEL. It
never loads WordPress or calls a native sanitizer. Compilation alone therefore
does not authorize native materialization. Capture checks raw decoded values
before tokenization and the complete canonical candidate before publication;
target Plan checks the desired canonical tree before snapshot work; Apply checks
fully resolved values, unchanged locked-context predicates, and every owned
preimage before metadata reconciliation. Repeated post/term rows are checked
individually. Every native check requires byte-identical
`wp_kses(value, 'pre_user_description')`; missing APIs and changed/non-string
output refuse. No sanitizer output replaces authored bytes.

Another adapter cannot hide a native predicate through static pin order, and an
interpreter cannot strip a constrained static rule. Site policy may exclude an
entire static owned value as runtime/derived/env, but cannot substitute a weaker
authored rule. Existing dynamic cross-owner refusals remain in force. Package
identity includes these declarations and hooks, so adoption requires normal
recompilation and explicit re-pinning after an edit.

### v3.27 `conditional-json-refs/v1` — sibling-discriminated structural references

A v3 adapter declaring this feature may add `when` to an ordinary `json_refs`
entry on an option, option subkey, metadata rule, metadata pattern, dynamic
option subkey, or attached table metadata key. It adds no top-level section or
token kind. Site-authored conditions, taxonomy descriptions and `body_refs`
are not admitted by this feature. An interpreter answer requires exactly one
v3 owner declaring the same feature and passes the same value-rule grammar.

```json
{
  "path": "$.login_target_value",
  "kind": "post",
  "cast": "string",
  "when": {"key": "login_target_type", "equals": "page", "otherwise": ["custom"]}
}
```

`when` has exactly these three keys. `key` is an exact sibling key of at most
128 bytes in the existing reference-path key alphabet. `equals` is a nonempty
UTF-8 string of at most 128 bytes without control bytes; `otherwise` lists one
to sixteen distinct strings under the same constraints, excluding `equals`.
The reference path must end at an exact key, distinct from the discriminator.
No declared reference path may also rewrite a discriminator. Existing
overlapping-reference refusals remain unchanged.

Each matched record must contain either the exact `equals` value or an exact
listed alternative. Missing, non-string and unknown discriminators refuse;
an unknown variant is never treated as portable text. Only the selected branch
uses the typed identity codec. Selected source values must be positive native
integers or canonical decimal strings within the PHP integer range, or the
existing null/empty/zero/false unset conventions. Selected canonical values
must instead be tokens in the declared keyspace or unset values. The ordinary
`cast` controls the restored representation. Explicitly unselected branches
retain their value and still pass through ordinary URL rewriting and the
secret/PII gates; the discriminator grants no clearance exception.

Capture, immutable repository validation, lint and SQL materialization use
the same sibling selection. The negotiated feature leaves unconditional
reference behavior unchanged. AIO Login 2.4.1's native redirect rule is the
measured demand: `page` stores a decimal string while `custom` stores a URL;
unconditional reference apply changed the latter to `"0"`.

### v3.28 `php-container-values/v1` — ordered native PHP option containers

A v3 adapter declaring this feature may set `php_containers: true` on a whole
authored `options` or `option_patterns` rule. The rule must also declare
`plain_data` or structured references. It cannot combine with `ref`, `cast`,
`json_encoded`, `sub_keys`, `repeated_rows` or `order_preserving`. The normal
namespace declaration remains necessary for pattern discovery. This feature
does not admit metadata, attached table metadata, dynamic option resolvers,
option-name references or site-authored codecs. A site may exclude the whole
option as runtime, derived or environment data; it cannot replace the authored
storage contract. An interpreter cannot introduce or override the codec.

The canonical option value is a closed document with exactly `format` equal
to `wprism-php-containers/v1` and `root`. Every native array or builtin
`stdClass` becomes a closed node with `kind` (`array` or `stdClass`), `order`
(a list of closed `{"key":...}` records), and `items` (the keyed encoded
values). `order` names every item exactly once in original insertion order.
PHP arrays retain integer/string key types; `stdClass` retains string property
names, including numeric properties. All containers are wrapped, so authored
keys resembling codec fields are unambiguous. Empty arrays and empty objects
remain distinct through canonical JSON sorting.

Null, booleans, integers and UTF-8 strings are ordinary leaves. A finite PHP
float becomes `{"kind":"float","value":"..."}`, using the decimal
spelling between `d:` and `;` in its canonical PHP serialization. This preserves
`2.0` versus `2` and negative zero without changing ordinary canonical JSON.
The root must be a container. The codec bounds native serialized input and
decoded text to 16 MiB, nesting depth to 64 and decoded nodes to 100,000.
Arbitrary/incomplete classes, enums, references, recursive or shared objects,
mangled properties, malformed/trailing/noncanonical serialization, binary text,
nonfinite floats and malformed portable nodes refuse. Enum screening happens
before PHP can invoke an autoloader; deserialization permits only builtin
`stdClass` and reconstruction instantiates only that class.

Existing `json_refs` paths address encoded scalar coordinates. An ID-keyed
node instead requires `key_refs.container: "php"`; its path addresses the
whole typed node, for example `$.root.items.posts`. Capture accepts only
positive native integer or canonical decimal-string IDs within PHP's integer
range. Apply requires an exact identity token in the declared keyspace and a
positive resolved integer. The generic codec rewrites `items` and `order` in
one operation. An unresolved capture key drops its complete entry with the
existing warning; duplicate resolved keys refuse. This introduces no new path
dialect or identity kind. Ordinary string leaves retain URL rebinding and
secret/PII checks. Capture, immutable compilation, lint and checked option
materialization share the representation; materialization restores native
containers and serializes them with target-local string lengths.

### v3.29 `block-attribute-values/v1` — declarative block value transport

A v3 adapter declaring this feature may declare `block_values`, a nonempty
object keyed by WordPress block name and then exact top-level attribute name.
The section uses the certificate's field arm. A block with this section has
one manifest owner across all block declarations; disjoint `block_attrs`
rules in that same manifest can coexist. Whole-block codecs and overlapping
attributes refuse. Site policy cannot supply this section. Legacy
`block_attrs` cannot carry the effective registry's internal `value` field.

An attribute declares exactly `{"class":"derived"}`, or `class:authored`
plus one of the ordinary scalar `ref` codec, `json_refs`/`key_refs`, or
`plain_data:true`. Scalar refs accept `cast:string`; list refs additionally
accept `cast:csv`. The shared structural reference dialect is unchanged;
conditional references, PHP container wrapping, custom executable codecs,
sub-key ownership, and metadata-only options are not admitted here.

Native scalar references require positive integers in their declared native
JSON type. Optional scalar references retain null, empty string and the typed
zero sentinel. Lists contain references only. Native CSV is empty or a
comma-separated sequence of positive canonical decimal integers, without
whitespace or empty members. Capture emits a token list; apply restores the
CSV string, preserving selection order and duplicates. Structured values
require arrays/objects decoded into ordinary JSON data; selected reference
leaves must have their declared scalar shape. Whole-value and nested text
leaves use the existing environment URL codec. No new identity kind exists.

Derived attributes are removed during capture. Their presence in canonical
content is an error, including a null value. Authored attributes preserve
ordinary JSON fields outside declared reference paths. Missing identity
mappings use the existing warning/drop and blocking scope gates. Canonical
references must be exact tokens in the declared keyspace; malformed shapes,
foreign tokens, raw IDs and unsupported native coercions refuse.

The same value validator runs at capture, immutable compilation, lint and
apply. Compilation reads opening block-comment attributes without WordPress
or database contact, for post bodies in blocks mode and declared block-content
widget settings. The reader preserves body bytes and limits documents to
16 MiB and 100,000 delimiters. Value validation limits nesting to 64 and nodes
to 100,000; non-finite numbers, objects outside decoded JSON and invalid UTF-8
refuse. Checked post SQL and its transaction owner retain responsibility for
atomic writes, rollback, retry and canonical recapture.

### v3.30 `block-media-derivatives/v1` — content-selected image recipes

A v3 adapter can declare `block_media_derivatives`, keyed by block name,
with lists of closed recipes. Each recipe requires `attachment`, `url`,
`width` and `height` as exact child JSON paths, a boolean `crop`,
`filename:"requested-dimensions"` and `dimension_cast:"integer"` or
`"truncate"`. Optional `path` uses the shared JSON reference walker to select
an object or list of objects; absent means the block attributes. Optional
`when:{key,equals}` requires an exact nonempty string discriminator in each
selected object. An absent or unequal discriminator selects no recipe.
There are at most 128 declarations per manifest. The same manifest must own
the attachment's `post` reference in `block_values`; the section uses the
certificate's field arm and cannot be introduced by site policy.

Capture, immutable compilation and artifact admission use the same reader
for post bodies and declared block-content widget settings. A selected
attachment must resolve to an image original of MIME JPEG, PNG, GIF or WebP.
The selected URL must identify the exact original basename plus requested
`-<width>x<height>` dimensions in the same uploads-relative directory.
Integer dimensions must already be integers; truncate additionally admits
finite numeric fractions. Negative values and dimensions above 16,384 refuse,
as do requested areas above 67,108,864 pixels. A recipe inventory has at most
4,096 transforms, 128 per attachment and 4,096 sorted consumers per transform.
Conflicting transforms for one destination and destinations colliding with
originals refuse. Existing aggregate filesystem and media bounds still apply.

`media_derivatives` in the compiled artifact binds each transform to its
attachment UUID, original path and content-addressed media blob, exact target
path and consumers. Artifact admission derives the inventory again from
immutable content and pinned declarations. The recipe identity hashes the
transform and original binding; consumer changes do not change output identity.
Artifacts without this selected feature retain their historical shape.

Apply selects effects from its actual authored work and combines desired
consumers with preserved target consumers. An edit to content can therefore
regenerate an unchanged attachment without rewriting its post row or authored
sidecars. The target observation is rechecked under native consumer locks
before authored mutation. Publication and cleanup recheck current consumers
against the committed generation work; a new unplanned transform refuses.
Scoped consumer preservation does not import those consumers into the authored
write set. Path changes that strand a preserved consumer refuse.

The locked reader borrows Capture's block, attachment-original and widget
codecs as an input projection, not as a full export. Unrelated uncaptured
terms, authors and options impose no export prerequisites on this reader.
Existing post identities require exact durable mappings without repair.
Unmanaged posts receive private comparison identities so a selected crop
cannot disappear from the consumer guard; those identities never enter the
ledger or authored write set. A stock page or a block selecting the original
has no custom recipe. Strict full export retains its identity requirements.

An existing crop outside native attachment metadata may acquire prior-file
ownership only from a manifest-declared selection observed in target content.
A desired new selection, filename prefix, runtime crop cache or equal bytes
does not grant that authority. The selection's original must independently
belong to the exact native attachment; its frozen before-image must match the
observed original blob, including when authored work replaces that original.
The global attached-file collision guard also covers selected prior crop paths.
The existing filesystem owner freezes every exact prior path, rechecks aliases,
absence or byte/identity witnesses, and seals the ownership decision in its
transaction-bound journal. Unselected neighbors retain their prior bytes.

The existing isolated native metadata generator consumes bounded journal
recipes in private staging. Requested filename dimensions are distinct from
actual output dimensions; a valid no-resize result re-encodes the original.
Generated file path, MIME, dimensions and size must agree with native output.
A native size at the same destination coalesces only if both projection and
bytes agree. New crops receive genuine generated attachment size metadata.
Publication, metadata COMMIT, stale-file removal and recovery remain phases of
the existing attachment transaction; stale crops are not removed before the
metadata commit is established. Native plugin qualification and fresh-process
convergence evidence remain required before declaring a plugin capability.

### v3.31 `key-bound-strings/v1` — strings bound to an owning map key

A v3 adapter may add `bound_strings` to the `key_refs` of a static whole
authored option or option pattern that also negotiates `php-container-values/v1`
and declares `php_containers: true` and `container: "php"`. No new top-level
section, reference kind, plugin executable, database authority or engine version
is introduced. Existing declarations retain their codec and identity bytes.

`bound_strings` is a list of one to 128 closed `{path, prefix, suffix}` objects.
Paths use the existing structured-reference dialect relative to each selected
map value, with at most 1,024 bytes and no surrounding whitespace. Prefix and
suffix are nonempty UTF-8 literals of at most 256 bytes each, without control
bytes or reserved token delimiters. They identify native syntax that is stable
across environments. Bound paths cannot overlap one another or another
structural reference/discriminator; the same position cannot have two owners.

Every selected leaf must be a UTF-8 string of at most 1 MiB, both before and
after rewriting. An absent path is allowed. A selected leaf must contain at
least one complete `prefix + owning-key + suffix` frame; every occurrence of
the prefix must begin that exact frame. A map entry permits at most 100,000
occurrences across all its selected strings. The owning key must be a canonical
positive native integer or its exact declared-kind token. Capture requires the
native phase and apply the canonical phase. Complete-frame comparison cannot
truncate a token at a suffix that also occurs inside its UUID.

Capture substitutes the map key's ordinary `{{kind:uuid}}` in each frame;
apply substitutes that same identity's target ID. Bytes outside those frames
stay with the existing text codec. For this negotiated shape, apply binds the
structural references before text detokenization, so a URL-query frame cannot
lose its token before the owning-key check. Immutable compilation and reference
scanning validate key/frame agreement through the same pure structural reader,
without an identity lookup. Conflicting frames refuse even when the owning key
would subsequently be dropped as dangling. The typed-container owner publishes
key, order and rewritten value together and validates the resulting container;
its existing collision and size guards remain in force.

### v3.32 `block-attribute-groups/v1` — exact grouped value declarations

A v3 manifest declaring both `block-attribute-values/v1` and
`block-attribute-groups/v1` may include a `groups` list inside `block_values`.
This is an alternate declaration syntax for the existing block-value surface;
it creates no new capability surface or runtime executable. Ordinary exact
block maps may coexist with groups. Without the feature, the historical exact
block-name grammar remains in force.

Each group is a closed `{blocks, attributes, value}` object. `blocks` and
`attributes` are nonempty lists of distinct exact names using the existing
block and attribute grammars. `value` uses the existing authored/reference or
derived value-rule grammar. A group declares every pair in the Cartesian
product of its two lists. A pair appearing twice, including across groups and
exact maps or under equal rules, refuses; declaration order grants no override.
There are at most 256 groups, 4,096 members in either list and 65,536 expanded
group pairs, checked before expansion. No pattern, implicit default, inheritance
or runtime plugin-schema discovery participates in this ownership decision.

`BlockValueGrammar::attribute_maps()` is the sole normalization owner. It
preserves raw manifest bytes and returns exact maps; grouped maps are sorted
by block and attribute. Runtime projection, reference-keyspace validation and
same-manifest media derivative ownership consume those maps. Existing
cross-manifest ownership and legacy whole-block/attribute collision rules apply
after normalization. Site policy cannot introduce this manifest-owned grammar.
The public feature grammar describes the grouped form; canonical manifest
identity binds the compact declaration, so changing syntax requires fresh pins.

### v3.33 `block-record-fields/v1` — authored fields within native records

A v3 manifest declaring this feature and `block-attribute-values/v1` may add
`record_fields` to an authored block value rule. It is a closed object with
`container` (`object` or `list`) and `fields` (1–256 distinct exact field names,
each matching `[A-Za-z_][A-Za-z0-9_-]{0,127}`). The existing attribute boundary
selects one record or a list of records; there is no nested projection path.

Capture checks the ordinary bounded JSON contract before discarding unlisted
immediate record fields. Retained fields preserve native key order, absence,
types and nested values; lists preserve order and duplicates. Each record must
be associative and retain at least one field. Empty lists are valid; empty
records refuse because associative JSON decoding cannot preserve their object
type. No required-field defaults, list deduplication or runtime schema lookup
is implied by this declaration.

The existing `json_refs`/`key_refs` or `plain_data` codec remains mandatory.
Scalar `ref` is incompatible. Every reference path must start with an exact
retained field; root key references and wildcard/recursive first segments
refuse rather than claiming an identity the projection could discard. Later
segments use the unchanged shared path dialect inside the retained value.

`RecordFields` owns the pure declaration and record constraints;
`BlockValueCodec` composes them with reference and text transport. Immutable
post/widget compilation, lint and Apply reject excluded canonical fields
through that same validator. Canonical inputs are never silently projected.
Retained data crosses all existing privacy and scope gates. The feature changes
neither capability surfaces nor transaction, recovery or executable authority.
Changing a package to use it intentionally changes its identity and requires
recapture, compilation and explicit new pins.

### v3.34 `encoded-text-values/v1` — native scalar text framing

A v3 manifest declaring this feature may attach
`text_encoding: {"codec":"uri-component"}` to an authored scalar text rule.
The optional `escape` member is exactly `{"text":"…","wire":"…"}`: a
single literal replacement performed before URI encoding. There is no
arbitrary transform pipeline, implicit detection or executable codec.

The feature applies to exact options, option patterns, one-level static
option subkeys, post/term/user metadata, `post_meta_patterns` and shared
`meta_patterns`. A `block_values` attribute additionally requires
`block-attribute-values/v1`. Option-name references, dynamic options and their
subkeys, attached metadata and table columns are not admitted. It claims no
new top-level section or certificate arm.

Native strings must have the exact JavaScript `encodeURIComponent` spelling:
uppercase percent escapes, `%20` for spaces, and `!'()*` left unescaped along
with the URI unreserved set. Canonical values are decoded UTF-8 scalar strings.
Capture decodes before the existing text/reference and privacy machinery;
Apply resolves ordinary canonical tokens before encoding. A source native
value must equal the encoded result of its decoded value. Form encoding,
invalid UTF-8, unsafe ASCII controls (other than tab, LF and CR), malformed or
noncanonical percent escapes and non-string values refuse without including
value bytes in the diagnostic.

Decoded text is bounded to 1 MiB and native text to 8 MiB. Each escape literal
is at most 128 bytes; `text` is nonempty UTF-8 without control bytes, and
`wire` contains only ASCII letters, digits or underscore, is strictly longer,
and does not contain `text`. Canonical text cannot contain the wire marker.
Literal replacement must also reverse exactly at partial-marker boundaries.
Both literal and URI expansion are checked before allocating their expanded
outputs. Compilation proves the native bound for canonical text; Apply proves
it again after substituting target-specific URLs.

The field cannot combine with `ref`, `cast`, `json_refs`, `key_refs`,
`json_encoded`, `plain_data`, `php_containers`, `record_fields`, `sub_keys`,
`repeated_rows`, `order_preserving` or `native_value_validation`, including
null/false instances of those fields. Ordinary field-level privacy review
still applies where the owning surface permits it. Block values grant no
privacy exception.

Static manifest ownership is exclusive. Competing adapter classifications
cannot hide a codec through pin order. Site policy may exclude a whole value
as runtime, derived or environment-local, but cannot substitute an authored
rule or change its subkeys. Interpreters cannot introduce or override this
codec. Existing rules without `text_encoding` retain their native framing.
Malformed locked metadata/option preimages refuse before reconciliation can
overwrite or remove them. Transaction, recovery and identity ownership remain
with the existing engine. Adopting the feature in a package changes its
identity and canonical representation, requiring recapture, compilation and
explicit new pins.

`EncodedText` owns framing and bounds; it does not parse CSS or call WordPress.
Separately, block publication clearance scans decoded opening-comment
attributes alongside original body text. Native quote escaping cannot conceal
credentials from Capture or immutable compilation. The same framing reader
covers block widget content while retaining its existing reviewed privacy
rules. No decoded inspection value is written back to canonical content.

### v3.35 `post-meta-invalidation/v1` — exact derived post-cache repair

A v3 manifest declaring this feature may add `on_post_write: "delete"` to
an exact `post_meta.<name>` rule with `class: "derived"`. Only these two fields
and an optional string `note` are admitted. Other operations, patterns,
option subkeys, attached metadata, block attributes and term/user metadata
cannot carry the field. The feature claims the existing post-meta field arm,
not a new executable or top-level section.

The effect is part of the selected owner's authored post/menu-item write.
Under the existing bounded metadata owner lock, the engine deletes all rows
whose key bytes exactly match the declaration, addressing observed physical
`meta_id` values. It reads back exact absence before returning and uses the
existing post-meta object-cache invalidation queue. All SQL participates in
the authored transaction's commit/rollback and recovery boundary. Missing
rows remain absent. Other owners and collation aliases remain untouched.

The declaration grants no owner discovery or standalone repair operation.
An unchanged or unselected post that is not materialized receives no repair.
Ordinary `class: "derived"` retains its preservation semantics. Native plugin
hooks are not invoked and no cache value is promoted into canonical intent.
The adapter must prove that absence makes its native reader correct.

One static manifest owns each grant. Competing exact or pattern claims,
including core, refuse during pure policy load. Site policy cannot replace
the contract; interpreter answers cannot introduce, echo or override it.
The existing compiled manifest identity and scope contract source hash bind
the declaration. A package adopting it requires recompilation and new pins.

`PostMetaInvalidation` owns the closed grammar; policy resolution owns static
authority; `ApplyFieldMaterializer` owns its bounded transactional effect.


### v3.36 `block-value-contracts/v1` — composed object values and reference integrity

A v3 manifest declaring both `block-attribute-values/v1` and
`block-value-contracts/v1` may use these constraints inside `block_values`,
including exact grouped declarations. No new top-level section or executable
lane is introduced; the existing block-values certificate arm applies.

- `object_fields` is a nonempty map of exact field names to authored value
  rules. Its rule contains only `class` and `object_fields`. Present members
  recurse through their own existing codecs; missing members remain absent.
  Unknown members, empty objects, list/scalar containers and nested `derived`
  rules refuse. The bounds are 256 fields per object, four nested member levels
  and 65,536 expanded contract rules per manifest after group expansion.
  Field names match `[A-Za-z_][A-Za-z0-9_-]{0,127}`. Existing JSON bounds apply
  to the complete native or canonical value before traversal.
- `enum` is a distinct literal codec. Its rule contains only `class` and
  `enum`, a list of 1–64 distinct integers, booleans, nulls or strings matching
  `[A-Za-z0-9_-]{0,128}`. Equality includes the JSON type. Admitted values are
  preserved exactly without text rewriting; URLs, prose and token envelopes
  cannot use this lane.
- `on_unmapped` admits only `refuse` and requires a `ref`, `json_refs` or
  `key_refs` leaf. Any unresolved source identity aborts capture instead of
  dropping a selector; forcing unresolved references cannot waive this rule.
  Target durable references retain their existing strict resolution, and
  target user references must resolve without the default-author fallback.
  Native unset scalar sentinels and empty lists retain existing semantics.

Compilation, lint, capture and materialization enforce the same recursive
value contract. Nested structured leaves retain the existing suspicious-ID
linter and all published content retains privacy clearance. These fields are
rejected in options, metadata and site policy. Manifests without the feature
and leaves without `on_unmapped` retain their previous transport behavior.

## Ledger tables (per environment, never in the repo)

| Table | Purpose |
|---|---|
| `wprism_map(uuid, entity_type, id_kind, local_id)` | typed identity map |
| `wprism_state(uuid, entity_type, content_hash)` | canonical hash at last capture/apply — drift & 3-way base |
| `wprism_kv(k, v)` | `applied_revision`, guid pins, config |
| `wprism_journal(...)` | provenance journal (Spike C; runtime data, prunable) |

## The review queue (spec v0.6 — the core loop)

Unclassified state is never silently captured *or* silently skipped; it queues for human triage:

- **`wp wprism pending --repo=<p> [--format=json]`** — the review queue. Items merge three sources: the classification **gate walk** (unclassified whole-entity scope plus post/term-meta keys on in-scope entities, with entity counts and post types — the same scope and rule lookups capture uses, so they can never disagree), **journal-observed unclassified option writes** (options are whitelist-only at capture, so the journal is what surfaces them — finding #5's answer), minus any observed name a dedicated mechanism already owns end to end — the `widget_<type>` family and the top-level `sidebars_widgets` option (SidebarState), any `dynamic_options`-declared prefix (core.json's `theme_mods_`, the active theme's row and every stale-residue row alike), and the transients core.json's own derived patterns already classify — because for those names no classification is available or required; their provenance stays visible in `wp wprism journal`, and the loud paths are untouched (a widget type with live instances and no `widgets{}` declaration still refuses capture). Annotations: a **ref hint** when the current value is a numeric id that exists in wp_posts/wp_terms (finding #9's linter seed; small ids can coincide — hints are hints; a whole value of exactly 0/1 is a flag, not a reference, and gets no hint at all), and a **secret flag** (`hard:<label>` on high-confidence patterns — Stripe/AWS/GitHub/Slack keys, PEM blocks, JWTs — or `suspicious` on key-name+shape heuristics). `proposal` is only ever the journal's capability×surface signal; with no journal evidence it is `null` — never guessed. Unclassified **term meta** is surfaced and blocks capture; classifying a key `authored` makes it representable in the spec-v2 term file's `meta` object, while target-local dispositions exclude it deliberately.
- **`wp wprism classify --repo=<p> --set='<section>:<key>=<class>[,ref=<kind>][,cast=<c>][,allow_secret=true][,allow_pii=true]; …'`** — writes rules into `site.wprism.json` policy. All decisions travel in ONE semicolon-joined `--set=` (wp-cli keeps only the last occurrence of a repeated assoc flag, and the space-separated form parses as a boolean — both documented traps). Classifying a key as `authored` while its current value matches secret/credential clearance is **refused** unless that exact row carries `allow_secret=true` (recording `allow_secret: true`); personal-data clearance likewise requires `allow_pii=true` on the exact row. The legacy `--allow-secret`/`--allow-pii` flags are single-row shorthand and refuse a multi-row set, so a target-side reread cannot transfer one row's reviewed authority to another.
- **Secret/PII clearance at capture and compilation**: every canonical candidate crosses the same recursive clearance before publication, including options; post/term/user/menu fields and bodies; ordinary, attached, and menu-item metadata; typed tables; and widgets. JSON/serialized bodies and declared typed-column containers are decoded before this walk, so nested key signals cannot hide inside valid storage framing; repository authorization repeats that decoded check over the immutable revision so Git edits (including menu `ref`) cannot bypass capture. High-confidence tokens, authored key-name-plus-credential shapes (including `Authorization`, `credential`, and `license_key`), labelled credentials in prose, snake_case/camelCase personal-data names, and embedded email/IP/phone shapes block without publishing the value. Strings over 64 KiB are scanned in bounded overlapping windows rather than skipped. Phone value matching excludes closed timestamp, ISBN, decimal, UUID, and dotted-version grammars while explicit phone keys remain protected. Exact structured rules may carry reviewed `allow_secret`/`allow_pii`; unruled prose has no blanket exception.
- **`wp wprism policy-to-manifest --repo=<p> --match=<regex> --name=<n>`** — exports matching policy rules as a canonical manifest JSON on stdout (policy is left untouched; moving rules upstream is a deliberate human act). A pinned exported manifest reproduces byte-identical captures to the policy it came from.
- **`wp wprism manifest-pin --name=<n> [--repo=<p>]`** — emits the installed manifest's copy-pasteable `{name,digest,source}` pin using the same per-manifest digest recorded by offline compilation. `--repo` names the out-of-tree adapter source explicitly, so a site-installed adapter is pinnable; the repository's own `manifests` array is never resolved either way, allowing an operator to review a legitimate manifest change, generate its new pin, then update `site.wprism.json`; until that explicit update, the old pin keeps every policy-loading command fail-closed.
- **Dangling references**: an unmapped id in a ref-typed meta value is **dropped with a warning** (array/csv: the element; scalar: the whole key), mirroring options' long-standing semantics — a raw env-local id in canonical state is indistinguishable elsewhere from a valid id and may silently point at an unrelated live entity after auto-increment reuse. Convergence comes through the repo: the corrected canonical value applies everywhere. A `block_attrs` ref (a `"kind"`/`"kind_from"` rule) that fails to map gets the identical uniform treatment: `int[]` drops just that element, a scalar drops the whole attribute key, both warning by block/attribute/id. Exact zero is the declared unset convention and normalizes silently to absence (or drops from a list), whose WordPress block-schema default is equivalent; it is never warned as dangling. A scalar ref inside an authored option `sub_keys` rule additionally treats a negative value as an environment's cached no-object sentinel and silently omits that sub-key (`custom_css_post_id=-1` is WordPress's native example); whole-option scalar refs retain exact zero as their only durable unset spelling. This includes the `wp-image-<id>` CSS class WordPress's own image/gallery/media-text/cover blocks carry alongside a `"kind":"post"` attribute ref (spec v0.16, issue #3212): it used to fail *open*, leaving the raw digits unrewritten in canonical state on an unmapped id — the one place this uniform drop-with-warning treatment didn't already hold. A `shortcode_attrs` ref (spec v0.18, issue #3259) gets the same treatment a third time: a scalar drops the whole attribute (its own leading whitespace dropped with it, so no double-space artifact); `cast: "csv"` drops just the unmapped element and rejoins the survivors on comma, dropping the whole attribute only when every element was unmapped. Rewriting splices the changed attribute's exact byte span back into the original shortcode text rather than reparsing and reserializing the whole attribute string — WordPress ships no canonical "serialize shortcode attributes back to text" function, so this is the only way to guarantee every undeclared attribute, and every declared-but-untouched byte (quote style, spacing, ordering) of a *touched* one, survives capture unchanged. A `?p=`/`?page_id=`/`?attachment_id=` url-query ref (spec v0.20, issue #3260, always kind `post` — the only kind these three parameters ever resolve to) drops the whole `separator+param=value` span, piggybacking on `Tokens::tokenize_text()`/`detokenize_text()`'s own home/uploads pass rather than needing new declarative grammar: rewriting is scoped strictly to `{{home}}`-anchored URL spans (an external URL's own unrelated `?p=` is never touched — the one place this mechanism's own safety scope is narrower than `wp wprism lint`'s matching detection, which is deliberately un-anchored; see `wp wprism lint` below).
- **Unscoped references** (spec v0.10, task #73; extended to block refs at spec v0.16, issue #3212; extended to shortcode refs at spec v0.18, issue #3259; extended to url-query refs at spec v0.20, issue #3260): an authored, ref-typed **option**, a `block_attrs` ref (including the `wp-image-<id>` class), a `shortcode_attrs` ref, or a `?p=`/`?page_id=`/`?attachment_id=` url-query ref whose id names a row that *genuinely exists* but whose post type/taxonomy is missing from policy scope is a different failure mode — a fixable scope gap, not deleted data — and **aborts capture loudly** by default (the unclassified-meta gate's posture), naming the option/post/block/shortcode/param, the raw id, the target's real type, and the exact policy key to amend. `--force-unresolved-refs` (accepted by `capture`, `plan`, and `apply` — the latter two hit the same gate through their internal env snapshot) opts back into dangling-style drop-with-warning, for any surface. A real, correctly scoped row that merely has no uuid minted yet (every fresh target environment before its first capture) is neither dangling nor unscoped and keeps the ordinary warn-and-drop path. Array-ref options, array-ref block attributes, csv-cast shortcode attributes, and url-query refs all get identical per-element treatment — scalar and array/csv never diverge in severity, on any surface. All four surfaces share the SAME entity-type-lookup implementation (`Capture::ref_target_type()`, and the shape-agnostic three-way decision built on it, `Capture::classify_unscoped_ref()`, extracted at issue #3259 specifically so later callers would not need their own hand-copy of the same logic) — one source of truth for "does this id name a real row, and what type is it," not four. `post_meta` ref-typed values do not yet have this triage (still ordinary dangling-style drop only, unconditionally) — a real, separate gap, tracked apart from this one.
- **`wp wprism lint`** — the suspicious-ref gate byte-diffing cannot provide (wrong bytes written once read back faithfully): flags `bare_id` (numeric values matching existing entity ids under rules with no declared ref), `escaped_home` (JSON-escaped env URLs the tokenizer's plain-form substitution misses), `unregistered_block_attr` (id-shaped attrs in blocks with no registry rule, and URL-shaped string attrs), `unrewritten_registered_ref` (a block attribute path *is* declared in the `block_attrs` registry but its captured value is still numeric — a declared ref whose rewrite silently didn't happen, e.g. an unmapped id or a `kind_from` dispatch that resolved to no kind — distinct from `unregistered_block_attr`, which fires when no rule exists for the path at all), `serialized_desc_ids` (id-bearing serialized term descriptions) — spec v0.18, issue #3259 — their shortcode twins `unregistered_shortcode_attr`/`unrewritten_registered_shortcode_ref`, scoped to shortcode tags a `shortcode_attrs` rule actually declares (there is no registry-independent way to enumerate "every shortcode on the system" the way `parse_blocks()` enumerates every block for free — an unbounded, deliberately out-of-scope problem), and — spec v0.20, issue #3260 — `unrewritten_url_query_ref` (a raw `?p=`/`?page_id=`/`?attachment_id=` digit anywhere in captured state). Unlike every other class here, this one is deliberately NOT scoped to a declared registry or a `{{home}}` anchor: `Tokens::tokenize_text()`'s own REWRITE is home-anchored for safety (never touch an external URL's own unrelated `?p=`), but this lint scan is a wide net with an honest caveat instead — this file's own established philosophy throughout (a genuine false positive here, a third-party URL sharing the same common parameter name, is exactly the same "small ids coincide" caveat `bare_id` already carries). Exit 1 on findings; the conformance harness runs it as a hard gate. A rule may declare `"lint_ok": true` — an explicit, auditable human review meaning "numeric but genuinely not a ref"; it works on option/meta rules (e.g. `posts_per_page`) and as a block_attrs entry (e.g. `queryId`, a query instance index — the rewriter skips such rules entirely, and lint treats it as owned rather than an unrewritten ref). The only sanctioned exemption.
- **Shortcode attributes not codec'd** (spec v0.18, issue #3259): `shortcode_attrs` deliberately covers only attributes grounded as genuine, currently-reachable references by reading the relevant shortcode callback's WordPress core source directly — not every id-shaped-looking attribute on every shortcode. The legacy `[gallery]` shortcode's `id`/`ids`/`include`/`exclude` are declared (four genuine post refs, confirmed via `gallery_shortcode()`); `[caption]`'s own `id` attribute is deliberately NOT declared — confirmed via `img_caption_shortcode()` directly, it is `sanitize_html_class()`'d and emitted verbatim as a DOM id for CSS/JS targeting only, never parsed back into a numeric attachment reference anywhere in WordPress core, so there is nothing to codec (an explicit-unsupported ruling for a different reason than "hard to build" — see `platform/adapter-library/core/manifest.json`'s own note).

  A positional rule uses `{ "kind": "post", "position": <non-negative integer>, "lookup": { "post_meta": "…", "post_type": "…" } }` and is a closed declaration: no named path, cast, type, lint exemption, or extra key is permitted, and one tag cannot mix positional and named rules. The engine locates spans with WordPress's `get_shortcode_atts_regex()` including its required whitespace/end delimiter, then applies the declared callback's argument contract. Because legacy callbacks such as CF7's `[contact-form …]` consume the first value returned by `shortcode_parse_atts()`, any named attribute on a positional tag is refused rather than allowing a later bare span to change callback semantics. Lookup values are canonical positive decimal integers in the intersection of WordPress's bare `DECIMAL` meta-query domain and PHP's integer domain (at most ten digits on 64-bit builds); source and target uniqueness checks use the same numeric cast. A positional shortcode is therefore either fully canonicalized to a post token or fails closed; raw or ambiguous alternates never remain in canonical state.

  A named hash-prefix rule uses `{ "kind": "post", "path": "id", "required": true, "lookup": { "codec": "hex-prefix", "post_meta": "_hash", "post_type": "…", "prefix_length": 7, "stored_length": 64 } }`; a callback with multiple exact native storage widths uses the mutually-exclusive `"stored_lengths": [40, 64]` form. This is not a generic truncation helper: the prefix, every admitted storage width, and the lowercase-hex alphabet are part of the declared plugin callback contract. The plural list must be strictly increasing, unique, and contain at least two widths so there is one canonical declaration for each semantic set. Source capture resolves with the database's case-insensitive collision semantics but accepts only a canonical lowercase stored value; apply checks the canonical set and the live target before the first write, then emits the prefix of the repository's authored full value. Target observation may use the sealed reverse witness only when no live owner remains; a disagreeing live owner still refuses. CF7's modern shortcode is the first consumer: 6.0.x generates a 40-byte SHA-1 `_hash`, 6.1.x generates a 64-byte SHA-256 `_hash`, upgrades retain the existing 40-byte value because CF7 adds it uniquely, and every release reads exactly the first seven characters before falling back to mutable title matching. WPrism admits both proven widths, requires the native hash identity, and refuses that fallback.

The orchestrator surfaces this loop as `wprism pending <env>` and `wprism classify <env>` (interactive stdin triage; Enter accepts a proposal, explicit keys override, secrets require typing "allow"; `--accept-proposals` for CI, which never auto-authors a secret) — see cli/README.md.

## Offline repository compilation (spec v0.13)

`wp wprism compile --repo=<p> [--out=<artifact.json>]` is the semantic merge gate. It reads one complete repository revision without constructing target-bound tokenizers or consulting the target database, parses every canonical entity into typed data (post metadata plus a distinct raw body), and emits `wprism-compiled-repository/v1`. The artifact embeds referenced media bytes, active site-policy and pinned-manifest/interpreter hashes, an exact state/media `revision_hash`, the optional independent code-payload descriptor/`code_revision`, and its own SHA-256 content address over both halves. Loading an emitted artifact verifies those hashes; a current policy/manifest mismatch refuses it, as does descriptor absence/presence that disagrees with the active policy's code declaration. Code stage re-hashes the source payload before and during target writes, so changing `code/` after compilation fails rather than mixing revisions. Every host phase also supplies the outer hash it observed at compile time. Lifecycle deploy and finalization then consume only that frozen descriptor and verified staged target; they do not reopen mutable source.

Compilation batches stable blocking diagnostics for malformed or unknown entity kinds, invalid/duplicate UUIDs, duplicate natural identities (`post_type + slug + parent`, `taxonomy + slug`, or a declared table natural key), malformed/unsupported tombstones, live+tombstone collisions, conflict markers, graph references whose target is explicitly deleted or has the wrong kind, unsafe/duplicate attachment paths, missing or mis-hashed media, schema/content mismatches, and pinned adapter constraints. ACF's schema/value checks use the same manifest-shipped interpreter trust boundary as classification—never the installed plugin. The issue #3203 policy-authorization pass is the final compiler layer and preserves its existing structured failure contract.

The compiled artifact also carries `uploads_inventory`: one deterministic row
per attachment with the exact original upload-relative path and content hash,
plus the only derivative directory/basename prefix metadata generation may
mutate. Two originals may not share a derivative root, even when their
extensions differ. `wprism plan` exposes the same rows before target mutation.
This bounded compile declaration is not wildcard deletion authority; the SSH
upload provider resolves it into exact encrypted before-images and exact
absence receipts under the signed rollback generation (see
`docs/upload-bundle.md`).

Plan, apply, and deploy construct or load this artifact before target contact and accept only the `CompiledRepository` type internally—never a raw tree array. `--compiled=<artifact.json>` reuses a previously emitted artifact. All phases consume its decoded data/body/media payload and never reopen mutable `state/` or `media/` files after compilation; a failed compilation therefore creates no ledger and performs no target read, lifecycle call, rebuild, filesystem materialization, or database write.

Apply's mandatory fresh-process post-mutation verifier receives private
temporary snapshots of that exact in-memory artifact and its already-validated
`Policy`; it does not reload `site.wprism.json`, manifests, `state/`, or `media/`
from the checkout. The child revalidates the policy shape and artifact
site/manifest hashes, requires the parent's exact outer artifact hash, and
removes both handoff files after the child exits.

## Scope resolution (issue #3344)

`wp wprism scope --repo=<p> --roots=<selectors>` resolves a bounded set of canonical entities from explicit roots and reports what it would carry. It is read-only: it compiles a revision and walks it, and it captures, promotes, and deletes nothing. Without `--contract`, its existing `wprism-scope/v1` preview shape is unchanged. The immutable contract form is consumed by bounded capture/refresh/rebase, by the separately authorized scoped plan/apply/verification workflow, and by the narrow SSH-only checkpoint profile defined below. Ordinary promotion, user-invoked rollback, and code lifecycle remain whole-revision operations; the checkpoint profile does not make the contract itself mutation authority.

A root selector is one of `post:<uuid>`, `term:<uuid>`, `table:<table>:<uuid>`, `menu:<slug>`, `sidebar:<id>`, `user-meta:<login>`, `options`, `option:<name>`, `path:<state-relative-path>`, or `all`. Multiple roots are comma-separated. `all` is the whole revision, so a full-site operation is this same model with a wider root set rather than a second code path. A selector that resolves to nothing, resolves to an entity of a different type than it names, or cannot be parsed is **refused** — a silently empty scope is indistinguishable from a correctly small one. A menu item or widget uuid is refused by naming its owning menu/sidebar, because an item is not a file and cannot be scoped away from its owner.

`option:<name>` names one authored `wp_options` row rather than the whole `options/core` surface `options` names, refusing a name that is not currently authored the same way a uuid selector refuses one that does not resolve. Its closure is attributed to that option's own declared references only — a token in a *different* option's value never joins another option's scope — computed against `ReferenceGraph`'s existing per-entity edge enumeration rather than a second walker. The immutable contract carries this virtual record identity into the record-aware capture, refresh/rebase, scoped plan/apply, and SSH checkpoint-promotion protocols. Each consumer overlays or mutates only the named option record while preserving excluded siblings in the physical `options/core` carrier. The SSH profile's encrypted whole-database checkpoint is safe for rollback only inside its all-database-writer exclusion window; it does not widen the option's forward write set.

Closure follows declared edges in exactly one direction: **outbound**. If the scope holds X and X references Y, then Y is a dependency (X is incoherent without it) and joins the scope, recording the referring entity and the exact locator that pulled it in. Edges are the ones the compiler already validates — every `{{kind:uuid}}` token in any entity's data (including option *names*, which is where `option_name_refs` puts them) or post body, `terms` assignments, and term `parent` and `relationships` — plus declared parent → child post-type descent via `post_types.<t>.children` (issue #3315). Menu-item hierarchy is enumerated too, but moves no scope and is deliberately dropped during resolution: a menu owns its items, so an item's parent is an intra-entry edge whose target is already whatever the menu carries. The engine learns every one of these from pinned declarations; an adapter that declares a new reference shape gets closure for free, and an engine branch naming a specific plugin is a defect.

The reverse direction is **not** closure and is not treated as one. An option pointing at a page does not join that page's scope by pointing. Such inbound referrers are reported separately and left out, because they are what a later scoped delete would strand.

Two properties follow from this being a read-only pass over an already-compiled revision. Compilation has already refused every dangling reference as a blocking diagnostic, so a closure over a tree that compiled cannot discover a missing dependency; the refusal scope resolution still owes is an unresolvable root. And because the walk consults only the typed IR and pinned policy, a scope is a property of a repository revision rather than of any environment — the same roots resolve identically everywhere, which is what will later let capture, plan, promote, verification, and rollback quote one scope instead of each recomputing it against a moving target.

The reference enumeration is shared: `WPrism\ReferenceGraph` is the single walker, consumed both by the compiler's reference validation and by `WPrism\ScopeClosure`. Two independent walkers would not fail loudly when only one learned a new declared shape — the validator would quietly stop guarding an edge, or a resolved scope would quietly ship without one of its dependencies.

### Immutable scope evidence (`wprism-scope-contract/v1`)

`wprism scope <env> --roots=<selectors> --contract` (the host-side public form)
emits a canonical `wprism-scope-contract/v1` object. It is **read-only evidence,
never mutation authority**. The agent accepts contract mode only under the
isolated WPrism control-plane bootstrap; the host supplies that bootstrap with
`--exec`, `--skip-plugins`, and `--skip-themes`, so ordinary plugins, themes,
and normal MU plugins cannot run before compilation. The legacy preview can
remain directly reachable for compatibility, but direct `wp wprism scope
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

`wprism capture <env> --scope-contract=<local-path>` and `wprism refresh`/`wprism
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
source bytes. Portable inactive-widget discovery is narrower still: it scans
only block posts in the contract's selected root/closure roster, identified by
their already-resolved post UUIDs, so an unrelated target post cannot grant or
deny widget ownership. Removing the final selected stored-widget reference may
omit the old `sidebar/wp_inactive_widgets` canonical row without a tombstone,
using an unpublished `wprism-inactive-overlay-deauthorization/v1` witness that
binds the exact prior row hash, source revision, and complete sorted selected
post scan. That omission is admitted only for outbound post closure (or `all`)
with no excluded inbound owner; a direct sidebar root, incomplete scan,
remaining stored reference, or excluded referrer refuses. The target inactive
assignment, widget option, and ledger mapping are never deleted. Because the
inactive carrier is shared, even a nonempty/subset replacement refuses when
the frozen contract has an excluded inbound owner; a changed subset is safe
only when all owners are selected. Active/inactive owner transfer remains an
explicit scoped-capture refusal rather than being mistaken for deauthorization.
`all` selects every identity in the associated source artifact;
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
`wprism-capture-publication/v1` swap; recovery continues to use the sealed full
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

`wprism plan <env> --scope-contract=<local-path>` and `wprism apply <env>
--scope-contract=<local-path>` use the same host validation and compact
`wprism-scope-request/v1` handoff as scoped capture. The target recompiles and
re-associates the complete contract. Planning is strict observation: it does
not repair or create ledger schema, invoke native/provider effects, or write
the target. It may load a selected provider and inspect its identity and
capabilities so the report uses the exact scoped reconciliation gate apply
will enforce. Its `wprism-scoped-plan/v1` result projects ordinary three-way buckets to
selected identities while retaining global code and recovery preconditions,
and reports hash-only selected/protected target roots, canonical surfaces,
selected declarations, and provider problems.

The scope contract remains `mutation_authority=false`. After acquiring one
target promotion lease with a random `session_id`, apply performs a second
plan/guard/target observation and seals a separate
`wprism-scoped-mutation-authority/v1`. The authority binds the exact scope and
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
selected/protected target, changed guard, missing capability, untriggered native
action, untriggered provider without a successfully negotiated operation-bound
reconciliation contract, legacy unreconciled regenerator, or attachment
metadata rebuild refuses before the first authored write. An untriggered
provider is the one narrow global-action exception: the immutable scope
contract and mutation authority already hash its full declaration and effects,
and the target must negotiate that exact capability's scoped operation and
readback before plan, apply, or recovery may admit it.

An externally checkpointed scoped promotion uses the closed
`wprism-scoped-mutation-authority/v2` extension. In addition to the v1 evidence,
it seals the signed receipt payload hash and generation plus hash-only receipt,
target, and signing-key identities and the exact `allow_deletes` capability.
The raw external identifiers never enter the ledger. A legacy/v1 authority is
valid only for ordinary scoped apply and is never a wildcard for checkpointed
promotion replay.

Direct scoped apply optionally accepts `--request-id=<id>` (8..128 ASCII
letters, digits, dots, underscores, colons, or hyphens). The caller retains the
same case-sensitive ID for retries and supplies a different ID for a separate
intended apply, including returning to an earlier artifact after intervening
work. This selects `wprism-scoped-mutation-authority/v3`: the v1 evidence plus
a closed `request` binding with format `wprism-scoped-apply-request-binding/v1`,
`request_id_hash`, `allow_deletes`, and a canonical `binding_hash`. The request
ID hash is SHA-256 of the canonical object containing format
`wprism-scoped-apply-request-id/v1` and `request_id`; the binding hash excludes
itself. Raw IDs never enter durable authority. The immutable scope evidence and
its compact handoff remain unchanged. A request identity grants no target
mutation capability: all ordinary plan, guard, lease, source, selected/protected
target, and effect gates still apply. Full apply and external promotion reject
this option; externally signed generation binding remains v2.

An explicit ID binds exactly one scope, artifact, and deletion capability on a
target. An active nonterminal request requires that exact identity before lease
recovery and again under the lease. Omitting or replacing its ID cannot adopt
the interrupted work. Terminal retries still independently re-prove the bounded
target; choosing a new ID never converts a stale receipt into current evidence.
The active session and terminal request index are reopened after acquiring the
lease, before a new generation is published or a prior terminal is archived.
A request completed since preflight returns its exact receipt after target
verification; reused intent refuses without replacing its evidence. If a
nonterminal session first appears at that boundary, the caller retries unchanged
to acquire that session's original recovery lease.
Without the option, existing v1 request and authority bytes remain unchanged.

Execution is journaled in one append-only `wprism-scoped-apply-session/v1` with
the phases `planned`, `authoring`, `authored_committed`, `effects_pending`,
`verifying`, `complete`, and `recovery_required`. Every mutation intent binds
the authority, lease generation, ordinal, action, operation, input, effect,
and before-witness hashes; every receipt repeats that binding and adds an
after-witness hash. The authored operation identity is
`wprism-scoped-authored-transaction/v2`; an active v1 author intent/receipt is
obsolete state-only evidence and refuses with checkpoint recovery rather than
being upgraded from current target bytes. For a real authored transaction,
ordinal 1's receipt and the `authored_committed` phase are one session-row CAS
inside the same database transaction as authored rows and ledger mappings. Its
domain-separated after-witness binds the selected ledger-map root read after
all authored/map writes; the repeated intent/effect binding already seals the
desired work and deletions. Thus a committed target cannot exist with an
`authoring` session and no receipt, and a rolled-back target cannot retain a
committed receipt. An already-desired no-op uses the same one-CAS phase/receipt
seal outside an authored transaction only when current selected content, map,
locked plan, and guards all equal the authority's exact pre-author witnesses.

After COMMIT and attachment publication, a second strict canonical capture
independently proves desired selected content/media, protected content/map
roots, and the selected map against ordinal 1 before effects. Every normal
`authored_committed`, `effects_pending`, and `verifying` retry repeats that
same capture and receipt check, and any observation failure first restores a
durable `recovery_required` gate; a crash immediately after recovery resume
cannot bypass it. Pre-author retries instead repeat the locked plan, guard,
pre-root, and selected-map checks. `authoring` plus desired state or
`authored_committed` without ordinal 1 is never inferred or repaired. Post-author
retries do not compare the old guard witness, because the authorized deletion
transaction can legitimately change that target state. Any mixed, protected,
obsolete, or receipt/map change becomes `recovery_required`.

Scoped native/provider effects additionally use
`wprism-scoped-effect-operation/v1`. A provider explicitly advertises scoped
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
Before any terminal ledger write, that transaction locks the complete
`wprism_map` primary range and its supremum gap, then repeats the locked inventory
after only explicitly authorized tombstone cleanup. This receipt boundary
admits at most 100,000 physical map rows: it requests one proof row beyond the
frontier and refuses before ledger mutation when that row exists, rather than
silently terminalizing an identity partition it did not fully lock.
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
terminal. Explicit direct requests use a v3 terminal index keyed only by format
and `request_id_hash`, with the immutable scope/artifact and `request_binding_hash`
in its value. Reusing one ID with changed content, scope, or delete permission
therefore collides and refuses instead of discovering a different request.
The index still binds one immutable authority archive and terminal hash; a
missing or mismatched archive refuses. Active and archived explicit requests
cannot be replayed through an implicit v1 request. Ordinary promotion, code materialization, lifecycle, user-invoked
rollback, attachment derivative generation, and legacy `regen_dependency`
remain explicitly outside this version. Triggerless native actions and
providers without negotiated scoped reconciliation remain outside; the
operation-bound provider exception above is ordinary scoped apply only. The externally
checkpointed SSH profile below is the scoped-promotion exception; it consumes
this same apply protocol without widening its selected record set.

### SSH scoped promotion checkpoint profile (v1)

`wprism promote <env> --scope-contract=<local-path>` selects a separate SSH-only
protocol. It never enters ordinary promotion's code staging, lifecycle,
upload, release, or effect-provider paths. The host validates the local
contract, sends only its compact `wprism-scope-request/v1`, compiles the frozen
artifact, and obtains a strict `wprism-scoped-plan/v1` before claiming recovery
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
`wprism-scoped-promotion-receipt/v1`. Its exclusion provider must speak v2 and
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

### Host-only redacted refresh field relation (issue #3345)

`wprism refresh <env> --field-diff` is an **unscoped, host-only** adjunct to the
ordinary private `wprism-refresh-plan/v1`. It does not alter `plan_hash`, grant
apply authority, mutate the WordPress target, or turn a Git merge into a
field-aware merge. Its sole public artifact is a canonical,
immutable `wprism-refresh-field-diff/v1` relation stored under the local Git
common-dir journal; the private plan may retain verified B/P/W source bytes,
but the public diff, machine JSON output, resolution file, field evidence in
`run.json`, receipts, and public errors are value-free. A detailed stopped-run
cause is private local operator evidence in `events/*-stopped`; it never enters
the public diff, JSON, resolution, receipt, or CLI error.

The exact top-level diff keys are `algorithm`, `authority`, `choices`,
`diff_hash`, `format`, `plan_hash`, `policy_projection_hashes`,
`production_snapshot_hash`, `records`, `redaction`, `roles`, and `summary`.
`format` and `algorithm` are both `"wprism-refresh-field-diff/v1"`,
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
`derived_field_policy`, `opaque_container`, `body_changed`,
`body_structure_changed`, or `body_block_overlap`.
`hash_status` is exactly `"withheld"`; no raw value hash is public.
`field_selector_sha256` is exactly SHA-256 of
`"wprism-refresh-field-selector/v1\0" || record_selector_sha256 || "\0" || field`.
Field labels are only
`post.author`, `post.comment_status`, `post.excerpt`, `post.menu_order`,
`post.parent`, `post.ping_status`, `post.publication`, `post.title`,
`post.modification`, `post.body.branch_blocks`,
`post.body.compatible_blocks`, `post.body.production_blocks`,
`term.description`, `term.name`, `term.parent`, and
`record`. A `fields` record is exactly `post` or `term`, and every field label
must use that entity's `post.` or `term.` prefix. The three `post.body.*`
labels name a PARTITION of a post body's changed top-level blocks by change
category, never a block position or count; each carries exactly the category
its label spells and therefore never requires a choice. A field change has
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
otherwise the record is atomic. An attachment/media record,
menu, sidebar, option/state witness, user-meta, typed-table row, tombstone,
container/list, or other opaque/structural field is one record-atomic change.

A changed post body is field-eligible only as a whole-top-level-block byte
swap, and only when all three B/P/W bodies are pure block documents that
describe the same sequence: equal top-level block counts, an equal block name
at every position, and byte-identical bytes between, before, and after the
blocks. A body that is not a pure block document — classic/freeform content
outside a block, a malformed or unterminated delimiter, an ordinary HTML
comment between blocks — is `body_changed`. A body whose sequence differs on
any role (insert, delete, reorder, retype, reflowed gap bytes) is
`body_structure_changed`. A body whose sequence aligns but where some
top-level block was changed differently on P and W is `body_block_overlap`.
All three are one record-atomic change. Otherwise each changed block joins the
`post.body.*` partition for its category and is composed automatically:
`production-only` blocks are copied verbatim into the branch scaffold and
`branch-only`/`compatible` blocks keep branch bytes. Nothing merges inside a
block, no block is moved, inserted, or removed, and no partition is ever
`conflicting`, so composition adds no field choice to any resolution.
If B is a live record and P or W is absent, field mode refuses before it emits
a diff or accepts a resolution: absence never becomes a field-mode choice and
the legacy whole-record resolver remains the available path. Mixed eligible
field and record-atomic conflicts remain resolvable in one field-resolution
run.

`wprism-refresh-field-resolution/v1` is a separate canonical immutable object
with exactly `algorithm`, `choices`, `diff_hash`, `format`, `plan_hash`,
`production_snapshot_hash`, and `resolution_hash`. Its `algorithm` is
`"wprism-refresh-field-diff/v1"`; `format` is
`"wprism-refresh-field-resolution/v1"`; every top-level binding must equal the
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
the one explicit privileged host-local comparison surface. It requires TTY
stdin and stdout. For every changed selector it derives exact
base/branch/production paths and source values from the same validated private
span bundle materialization consumes; each value is rendered with a
terminal-safe preview capped at 4096 bytes (paths at 512), its exact byte
count, and SHA-256. Invalid UTF-8 uses base64. It may also show a bounded
C0/DEL-safe authored post title, term/menu name, or path fallback beside the
opaque selector. These bytes and labels exist only in memory, bind to the same
plan/diff and private bundle, and are never returned, journaled, or added to
the public diff, machine JSON, resolution, receipt, or `run.json`. The terminal
and any transcript are therefore privileged evidence and may contain secrets
or personal data. Pipe/automation callers use the value-free canonical
`--field-resolution` file instead.
Field resolution cannot mix with any legacy `--strategy` spelling or
`--resolve` choice.

Materialization occurs only in the existing disposable worktree after the
second production snapshot observation and fresh candidate-policy check. It
never decodes and re-encodes a hybrid document: branch exact bytes are the
scaffold, selected allowlisted top-level scalar spans and whole top-level post
body blocks are copied verbatim from verified B/P/W source bytes, and all
container/list/opaque content — and every body the block rules above refuse —
stays as the selected whole record. Field-spliced records use branch media authority;
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
`sanitize_title()`-family sanitizers actually emit, issue #3437). Control characters,
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
1. **Plan live entities**: for each entity file: `create` (uuid not in map), `update` (canonical hash ≠ `wprism_state` hash), `unchanged`; environment drift remains explicit. A ledger UUID absent from state and lacking a tombstone schedules nothing.
2. **Plan tombstones**: compare the tombstone `expected_hash`, target `wprism_state` base, and current canonical environment hash. Exact base + unchanged target → `delete`; target already absent → `deleted`; missing/mismatched base, local edit, or recreation after a deletion receipt → `delete_conflict`. `--force-theirs` may override a deletion conflict but reports it loudly. Fresh and previously mapped targets therefore interpret the same repository deletion intent; absence alone never differs by ledger history.

   Every `conflict` and `delete_conflict` row carries an additive
   `conflict_view` object with `format: "wprism-plan-conflict/v1"`. Its three
   named roles are `base` (`last_synced`, sourced from `wprism_state`, with
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
   its hashed `wprism-forced-plan-override/v1` evidence separates
   `required_flags` from `supplied_flags` and uses `status=incomplete`, never
   a `FORCED` or authorization claim. Entity tombstone conflicts therefore
   cannot proceed on `--force-theirs` alone: `--with-deletes` is independently
   mandatory. Once every required flag is present, a forced ordinary conflict
   is reported in apply's human and machine warnings just like a forced
   deletion conflict; the escape hatch never hides what it overrode. If a later gate or
   convergence check prevents the normal apply summary, JSON returns the
   typed `apply_forced_override_failed` refusal with a `forced_overrides`
   list. Each entry is `wprism-forced-plan-override/v1` evidence containing only
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

   `wp wprism explain <bucket>:<entity-key> --repo=<repo>` is an additive,
   entity-row projection of a freshly rebuilt plan. Human plan output prints
   a copyable hash-safe selector below each itemized row; the machine result is
   separately versioned as `format:"wprism-explain/v1"`, so the plan/status JSON
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
3. **Reference safety**: compilation blocks surviving canonical references. Adapter guards check runtime reverse references; a missing required guard table also blocks. A guard may declare the exact topology contract `table_absence: "empty"` only for a table owned by the same manifest and reviewed as absent on a supported version. Exact information-schema absence then produces a topology-bound zero-reference witness distinct from present-empty and is re-censused at the final pre-COMMIT boundary under the signed writer exclusion. Presence still requires the ordinary InnoDB/index/locked-row proof; mixed modes, malformed enums, foreign tables, ambiguous results, and probe errors block. `--force-delete-referenced` is an explicit report-not-hide escape hatch.
4. **Canary armed**: listeners on `save_post`, `transition_post_status`, `created_term`, `wp_insert_comment` + `pre_wp_mail` + `pre_http_request`; any fire during apply = hard failure.
5. **Phase 1** — upsert rows (posts, terms) with placeholder refs, direct `$wpdb`; mint local ids; write `_wprism_uuid`.
6. **Phase 2** — resolve refs through the ledger: parents, metas, term relationships, menu structure, option values, body detokenization (block registry restores numeric types).
7. **Deletes** — only with `--with-deletes`, custom-table children before parents. The engine performs the declared cascades, then queries every exact target and attached sidecar before commit. Any survivor rolls back the transaction. Menus delete their owned menu-item posts; comments, Woo order lookups, Ninja Forms submissions, and other declared runtime references are preserved by guards rather than cascaded.
8. **Rebuild** — canary disarmed: required derived-dependency synthesis and verification, term recounts (direct SQL), attachment metadata regeneration, manifest-declared structured actions (closed native actions and pre-mutation-negotiated provider capabilities), and cache flush.
9. **Verify convergence** — recapture the live target through the canonical snapshot reader in a fresh WordPress process before any convergence metadata advances. The verifier is pinned to the exact compiled artifact used by apply, avoiding stale pre-apply plugin models and refusing a concurrently changed repository. Every entity in the compiled tree must have the same type and canonical hash. Target-only entities remain untouched because absence is not deletion authority; when `--with-deletes` is explicit, every compiled tombstone UUID must be absent. A mismatch names the failed invariant, retains `apply_in_progress`, and leaves all base hashes and `applied_revision` unadvanced. Naming it binds the operator-facing refusal, not only the verifier: the gate runs in a launched `--format=json` process whose value-free refusal envelope is the machine contract on stdout, so that process states its operator sentence on stderr too — the channel the launching apply reads and re-raises — and a launched process that halts without prose is read back from the envelope rather than reported as a bare exit code. The refusal also states that the target WAS mutated (this gate can only run after the authored transaction committed and the rebuild pass ran) and, when the run preserved ordinary environment `drift`, names those entities as the structural cause: this gate proves the whole tree while apply deliberately never writes `drift`, so a drifted target cannot converge until a capture folds it in.
10. **Receipts and retry** — only after verification passes, a successful or already-absent deletion stores the tombstone hash in `wprism_state` with entity type `deletion`; re-planning returns `deleted`, so retries are idempotent. Live hashes and `applied_revision` update atomically with clearing `apply_in_progress`.

Every agent command that advertises `--format=json` refuses through this one
envelope — the set is closed, not a growing enumeration, and an argument gate
refuses through it exactly like a policy or target-state gate. A JSON-mode
refusal wraps the stable object above in `format:"wprism-command-refusal/v1"` and
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
contribute public machine evidence. The human-facing `wprism: ` prefix is never
authority to publish an arbitrary caught Throwable: every unclassified
Throwable contributes none of its message, cause, path, or trace and sets
`details_redacted:true` (issue #3404). Human output keeps the original reviewed
operator message. Private detail is recorded when public details were redacted,
when a provider/recovery boundary carries hidden causes, when a typed refusal
retains operator-only detail, or when traversal itself is incomplete. The agent
writes a `wprism-private-refusal-evidence/v2` JSON record under the site
repository's gitignored `.wprism/refusals/`; the envelope itself never names or
carries it. V2 is a bounded Throwable graph, not a promise of a complete linear
chain: it scans at most 256 nodes and 512 edges, records at most 64 nodes,
retains at most 4,096 source bytes for each class/message/file value, and caps
graph node JSON at 131,072 bytes and the complete record at 262,144 bytes.
Every bounded field carries its original byte length, SHA-256, `*_truncated`,
and `*_encoding` witnesses. Retained valid UTF-8 is stored verbatim; any other
byte prefix is stored reversibly as base64 rather than silently substituted.
`traversal` reports node/edge/byte omissions, cycles, invalid hidden edges, and
whether scan and record are complete. Records contain class, message, origin
file:line and relationships but no trace.

The store is best-effort and writes only after binding the exact ordinary
repository root and the same qualifying regular `site.wprism.json` or
`.wprism` directory that Command reviewed. It rechecks those identities and the
private directory chain around exclusive 0600 creation. `.wprism/refusals` is
0700; its existing parent must be non-group/world-writable (historical 0755 is
valid) or sticky-bit bounded for the cross-uid test harness. Missing, stale,
linked, unsafe, or unwritable evidence storage never replaces the primary
refusal. Known scope
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
a structured `incomplete_apply` condition, so `wprism status` remains non-zero
until that retry succeeds and clears it.

The marker's value is the canonical object
`{"format":"wprism-apply-in-progress/v2","preserved_drift":[{path,type,uuid}…],"write_set":[uuid…]}`.
`preserved_drift` names exactly the entities that interrupted apply classified
as ordinary environment `drift` and therefore deliberately did not write;
`write_set` names exactly the identities of its authored work set — the
create/adopt/update/conflict rows it was authorized to mutate, locked in before
the first mutation. Reprocessing is about rows the failed run wrote, whose
`wprism_state` base is now stale; it was never about rows it did not.

So the next plan widens `unchanged` and any *unrecorded* `drift` into `update`
with `retry:true` as before, and widens a `conflict` row only when `write_set`
contains its identity. A recorded identity that still classifies as `drift`
stays in `drift`, and a three-way `conflict` on an identity outside the write
set stays in `conflict` — where the ordinary gate keeps demanding an explicit
`--force-theirs` or capture-first choice, exactly as it would on a first apply.
Both matter because the repository side of an entity can move between the two
runs (the operator recompiles), which turns a preserved row into a genuine
three-way divergence that a retry must not resolve on its own. The retry plan
keeps reporting the true `drift` and `conflict` counts, `wprism status` stays
non-zero on them, and the `incomplete_apply` reason states how many entities
the retry will not overwrite and which remedy each group needs.

The format string is the contract, and each version is read only for the claim
it makes. A `wprism-apply-in-progress/v1` marker records `preserved_drift` and no
write set at all: its recorded identities are provably not-written and carve
out of both buckets, while every other `conflict` row keeps the original
widening. A marker carrying no record at all (an older agent's, or a
hand-planted one) keeps the original whole-bucket widening rather than
inventing a preservation claim.

Snapshot/rollback is the orchestrator's job in v0. The normal host path,
`wprism promote <env>`, compiles one immutable artifact, acquires a target lease
bound to its outer `artifact_hash`, then exports the database to the target
repo's gitignored `.wprism/checkpoints/`. It then sequences code stage → lifecycle
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
intent/receipt temporary files. The capture lock serializes WPrism writers only;
every capture and recovery therefore requires non-WPrism tools to leave the
complete `state.capture*` protocol namespace untouched for its duration. The v0
PHP implementation detects stable type/hash anomalies but does not claim an
adversarial namespace-race sandbox for these siblings.
While `.wprism-init-attempt` or its transition slot exists, ordinary capture
refuses before and after publication-lock acquisition; only confirmed init
recovery may reconcile that first-publication tuple. This prevents a later
capture from replacing the receipt that the sealed init journal must verify.

The target database lease serializes WPrism promotions; it cannot exclude a
package manager, self-updater, shell user, or compromised process that writes
the code tree directly. Code stage/finalize therefore require operational
exclusion of every non-WPrism writer from `WP_CONTENT_DIR` for their duration.
The same contract applies to first initialization across the complete site
repository namespace (`.git`, `code/`, `media/`, `state/`, and every
`state.capture*` sibling) until init or retained recovery completes.
The v0 PHP materializer refuses stable symlinks, unsafe paths, type changes,
and hash-changed removals and publishes each file by temporary rename, but does
not claim `openat(O_NOFOLLOW)`-grade safety against an adversarial concurrent
directory-to-symlink swap. Only a descriptor whose complete payload was
materialized is retained as component-root deletion authority; an interrupted
partial write can require manual cleanup but cannot make an unproven root
WPrism-owned.

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

The checkpoint necessarily contains the temporary `wprism_kv.promotion_lock` row,
because it is taken under that lease. Since importing the database can replace
that same row, it cannot provide its own uninterrupted exclusion. A later
restore first requires external maintenance/exclusion for every WPrism writer,
then uses this exact row-repair order: idempotently abort the old owner/hash,
begin that owner/hash again, import the checkpoint, then idempotently abort the
row restored by the import (the final abort is required even if import fails).
For a code-enabled failure, restore/reconcile code to its known
pre-promotion revision before that sequence; database import alone is never a
complete code-and-state rollback. A failed export is not a checkpoint and gets
no import instruction.

The automatic production-SSH rollback contract is specified separately, across
the four provider slices — `docs/checkpoint-bundle.md`,
`docs/code-release-runtime.md`, `docs/upload-bundle.md`,
`docs/effect-bundle.md` — with `docs/ssh-rollback-certification.md` as the
harness that gates them. SSH adoption installs a
database-independent runtime under
`.wprism/control`, provisions public verification keys, preserves a never-reused
target generation, and verifies immutable signed receipts plus append-only
signed event chains. Status and new SSH mutations fail closed for corrupt or
nonterminal active generations. `wprism promote` selects automatic rollback only
for an SSH environment whose explicit `verified_rollback` policy and complete
checkpoint/code-release/upload/effect provider set pass runtime preflight. It
binds the immutable compiled code/upload/effect inventories into provider
preparation and receipt v2 before the
database lease or semantic mutation, journals provider operations, and releases
traffic only after signed `committed` or proof-admitted `rolled_back`. Missing
capabilities are a loud operator-directed fallback; configured-but-invalid
capabilities refuse before mutation. No filesystem layout is capability
evidence.

### Local control-plane bootstrap (`wprism-bootstrap-eligibility/v1`)

`wprism driver-capabilities <env> --operation=adopt` remains target-free. A local
driver advertises `environment.bootstrap` and `code.transfer` only when its
loader-proven machine-local entry contains exactly
`"bootstrap":{"format":"wprism-local-control-plane/v1"}` beside normalized
absolute, non-root `wp_path` and `repo_path`. The same declaration in
checked-in policy has no authority; a missing declaration is an actionable
unsupported row, while a present malformed declaration is a configuration
refusal. Docker never inherits bootstrap authority from generic shell access
or a mount.

The later local `wprism adopt` call obtains a separate canonical
`wprism-bootstrap-eligibility/v1` object with exact top-level fields `format`,
`driver`, `repo_path`, `ready`, `checks`, and `digest`. Checks are ordered
closed rows `{code,state,reason,remediation}`; `digest` is the SHA-256 of the
canonical object without that field. This report is advisory evidence, not a
write token: install repeats the bounded topology proof immediately before
staging. The proof uses a plugin/theme/MU-isolated WordPress bootstrap, requires
installed WordPress and its standard MU leaf, disjoint normalized source/WP/
repository roots, ordinary non-symlink ancestors and absent-or-ordinary
destinations, required tools/access, no stale adoption transaction, and an
entirely absent local WPrism agent, loader, target `wprism-control/`, and
repository `.wprism/` control plane. Local
bootstrap is initial-only: an installed target is refused with remediation to
use its existing update path, avoiding any race with recovery writers. It
performs no WPrism target write.

Local adoption copies the invoking checkout's fixed `agent` and recovery trees
into disposable staging, then assembles `adapter-packages/*/package/` and
`platform/adapter-library/` into staged `agent/adapter-library/`. It uploads an
archive containing exactly `agent recovery`. The new agent, loader, embedded
adapter library, and repository `.wprism` tree are staged under exclusively
created, identity-recorded paths. Recovery initialization and configured probes run only against the
staged `.wprism`; `site.wprism.json` is hard-linked into place only when absent. The
swap, exact agent/policy/authority verification, and isolated public doctor are
one rollback unit. Only a green doctor crosses a mutation-free commit barrier;
rollback copies are deleted afterward, and any cleanup failure retains evidence
without attempting restoration from a possibly partial backup. Every earlier
failure restores the prior site policy and any newly created repository or MU
leaf byte-for-byte; cleanup refuses to delete a path whose filesystem identity
changed. The exclusively uploaded local archive is consumed and removed only
while its recorded identity still matches. This out-of-band control-plane/authority seed never installs
WordPress, materializes managed code, captures canonical state, creates entity
identity/ledger rows, or authorizes a later `wprism init`.

Deploy and apply also share one target-authoritative lease in the target
database. The `wprism_kv.promotion_lock` record names a random orchestrator owner,
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
expiry. Direct `wp wprism deploy` and `wp wprism apply` calls acquire their own
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
transaction. This gate serializes WPrism writers while leaving public reads and
unrelated runtime traffic available; a global maintenance page is not implied.

## Code-half facts & deploy (v0 payload skeleton)

- `active_plugins`, `template`, `stylesheet` are **managed-class** core-manifest options: captured bespoke into `state/options/core.json` (plain portable strings — plugin file paths and theme slugs need no tokenization; their cross-environment stability *is* the invariant), and **excluded from apply's generic direct-SQL path** — a raw options UPDATE would skip activation/switch hooks while leaving WordPress believing the code is active.
- **Host `wprism deploy <env>`** compiles once, stages add/update files from the descriptor-bound payload, invokes agent `wp wprism deploy` for real lifecycle hooks, then prunes only WPrism-owned obsolete files/directories, proves the managed components contain exactly the descriptor's regular files and hashes, and records the completed code revision. Stage never claims completion; a failure before final verification leaves the prior completed revision truthful.
- **Removal ordering is lifecycle-safe**: stage retains outgoing files, the retirement process deactivates them while their hooks still exist, activation runs in a fresh process, and only finalization prunes them. A canonical active plugin/theme absent from the new source descriptor is refused before stage mutates the target.
- **Hook failure is recovery-safe**: a durable pre-hook receipt prevents state apply or a different promotion session from treating partially committed lifecycle effects as convergence. The exact retained checkpoint plus known pre-promotion code revision are the only recovery boundary; ordinary retry is intentionally refused.
- **Plan's `code_mismatch` bucket**: `missing_in_code` (canonical wants an activation whose plugin is absent from the environment's code), `outside_version_range` (a pinned manifest declares `{"plugin": "<file>", "version_range": {"min", "max"}}` and the installed version falls outside), `version_range_graduated` (WP-2.8, below), `code_revision_stale` (the compiled payload is not the last successfully verified materialization), and lifecycle mismatches (`inactive_in_environment`, `unexpected_active_plugin`, `active_plugin_order_mismatch`). Apply refuses every row except `version_range_graduated`. Lifecycle deploy owns activation/deactivation/theme reconciliation; only the orchestrated staged path may pass its expected temporary staleness through to final verification. `--force-code-mismatch` remains the report-not-hide escape hatch for ordinary direct calls.
- **Graduated `outside_version_range`** (WP-2.8): a third state between "inside the certified window" and "blocked", never a widened range and never a silent pass. `site.wprism.json` may carry an optional top-level `adapter_version_evidence` object keyed by plugin basename — the same keying the `version_range` contract uses — each entry `{"manifest", "slug", "releases": [<version>, …], "outcomes": [{"version", "outcome", "signature"}, …]}`. `releases` is the recorded upstream release ORDER (refused, never sorted, when it disagrees with `version_compare()`, because a mis-ordered list answers about the wrong interval); `outcome` is `wprism adapter boundary`'s own four-word probe vocabulary — `green`, `boot-fatal`, `round-trip-diverges`, `artifact-unresolved` — and only `green` (installed, seeded, round-tripped byte-identically under this adapter's declared surfaces) is evidence a surface survived. `LifecyclePlanner::code_mismatch()` mints `version_range_graduated` **only** when every recorded release lying outside the declared window between it and the installed version, inclusive, probed `green`; the finding then carries an `evidence` array of those exact rows and names each one with its recorded signature in its message. Absent evidence, evidence that stops short of the installed bytes, an installed release the recorded list does not name, an unreadable installed version, evidence recorded against a different adapter, and any non-green row in the interval all fall through to `outside_version_range` with its message unchanged. `deploy` and `apply` do not refuse the graduated row and never report it as forced; both report it on every run, and `wp wprism plan` renders it as its own `VERSION_RANGE_GRADUATED` block rather than under `CODE_MISMATCH`, whose remedy sentence would be false about it. The row stays in the `code_mismatch` bucket on the wire and in its count line — it is the same finding, answered — but `PlanSummary::render()`'s readiness answer subtracts it, so `wprism status` and `wprism release`'s `release_target_not_clean` gate no longer refuse over it. `--force-code-mismatch` is unchanged and is still the only way past a real `outside_version_range`. The block is bound by `site_hash()` (so an artifact compiled before the evidence landed is correctly rejected) and excluded from `state_site_hash()` for the same reason the `code` declaration is: it cannot change one byte of canonical state. It can never widen a manifest range, which stays one reviewed human edit across `adapter-packages/<name>/package/manifest.json` and its sibling `package/disposition.json`.
- During deploy's deliberately hook-firing window, a reporting-only observer records attempted `wp_mail` and outbound HTTP calls in `external_side_effects` and human warnings. It neither blocks those calls nor changes the apply canary's fixed meaning; apply still treats content hooks, mail, or HTTP as a hard failure.
- **Plan's `code_drift` bucket** (issue #3231): a narrower, separate question from `code_mismatch` above — not "is the installed version compatible with the manifest's declared range" but "did this exact plugin/theme's version change since WPrism last observed this environment," the direct code-half analogue of state's own drift concept, catching the case a wide `version_range` can't (a wp-admin one-click update landing comfortably inside a pinned range is invisible to `code_mismatch`, yet is exactly the out-of-band mutation risk this bucket exists for). The baseline it compares against is one JSON blob under `wprism_kv['code_versions']`. Every writer shares `CodeBaselineTransaction`: it proves InnoDB storage and full-width unique indexes, locks raw `active_plugins`/`stylesheet`/`template` rows followed by acceptance receipt/baseline keys in lexical order, samples plugin/theme headers directly, publishes, reads back, then repeats the raw rows and header sample before commit. Host `wprism deploy <env>` first obtains a closed v2 read-only status whose lifecycle and code-baseline axes are independent. Unforced drift refuses before mutation. With explicit `--force-code-drift`, a real terminal lifecycle `activate` uses `CodeBaselinePublication` to publish the reconciled observation; its preceding split `retire` preserves the exact prior bytes. When no lifecycle work is due, the host selects isolated `CodeBaselineAcceptance`: it revalidates the frozen artifact under a transient target fence, repeats the consent gate, invokes no plugin/provider hooks, takes no recovery checkpoint, atomically replaces the baseline plus a replay receipt, and proves the exact final observation before success. Both acceptance paths report each forced finding exactly once. `wprism capture` calls `CodeBaselineCapture` inside its existing state transaction: an absent/exact observation may publish while an unaccepted drift leaves baseline and receipt **byte-identical** and returns every finding for one `WP_CLI::warning()` per row. Capture observes the environment, it never accepts a code change: it has no `--force-code-drift` consent gate of its own, and the drift row's own remedy text names host deploy as the accept path. A capture across an unaccepted drift therefore leaves the finding standing for the next `wprism status`/`wprism plan`. Once any baseline exists, a currently active desired plugin or theme identity absent from it remains `code_baseline_missing`; an installed-but-inactive plugin or not-yet-selected theme is pending managed lifecycle work instead. Theme baseline identity is its slug, not the `template`/`stylesheet` option slot, so a standalone theme occupying both slots produces exactly one finding. Detection remains scoped to the target state's own `active_plugins`/`template`/`stylesheet`, exactly as `code_mismatch` is. Same blocking posture and escape hatch as `code_mismatch`: `deploy`/`apply` refuse while non-empty, `--force-code-drift` proceeds while still reporting every overridden finding (Architecture Rulings §1).
- **Plan category summary** (issue #3345): the plan entry point adds an additive top-level `category_summary` object with `format: "wprism-plan-category-summary/v1"`. It is a value-free projection of the unchanged detailed buckets, emitted as a fixed ordered list of categories `code`, `lifecycle`, `authored_state`, `generated_effects`, `media`, `secrets`, `environment_state`, `capabilities`, and `deletions`. Each category has closed, zero-filled count maps named `metrics`, `entity_actions`, and `contained_entities`; those facets intentionally overlap. Deletion rows live in `deletions`, not `authored_state`; attachment classification uses compiled tree/tombstone context rather than a path guess. Code metrics split compatibility, lifecycle, revision-stale, drift, and future/other findings; lifecycle deliberately overlaps its code findings and adds `incomplete_lifecycle`. Generated metrics distinguish declared effects, Apply's exact selected native/provider actions, `regen_pending`, and `incomplete_apply` without claiming execution. Capability metrics distinguish certification/source blockers, selected provider blockers, and declared-but-unselected provider problems. Nested menu-item, widget, and option deletion candidates are reduced from the same coherent target snapshot and final plan; no post-plan query or guessed cascade is allowed. The secrets category emits only `visibility: "redacted"` and never scans or counts warning text or environment names. The public label `generated` is intentional product vocabulary; the shipped policy/wire class remains `derived`, and the summary's `vocabulary` records `public_label: "generated"` plus `wire_class: "derived"` without adding a manifest class. The projection never carries canonical values, secrets, PII, target-local ids, or plugin-specific logic. Host `wprism status` strictly validates and renders the projection when present, omits it when absent or malformed, and never lets this optional display data alter plan completeness/readiness, promotion, or convergence.
- **Exact affected-surface projection:** the plan entry point also adds optional top-level `affected_surfaces`, a sorted unique list in the same `post_type:*`, `taxonomy:*`, `table:*`, `media:*`, and `option_group:<declarant>:<class>` vocabulary used by assess, contracts, rehearsal, release and verify. It is derived inside the agent from `CanonicalSurfaces::for_apply()`'s exact changed/delete/retry work—the same selection that chooses provider actions—and contains no entity id, option name, path or value. An attachment names both its post-type and media projections. If an entity kind cannot yet be projected exactly, the agent omits the field; current hosts then retain the explicitly named entity-kind superset used for older agents. When the field is present, rehearsal and release require every id to exist in the current projection and require canonical ordering/uniqueness; malformed or unknown narrowing refuses rather than falling back. This prevents a one-product release from claiming and demanding journey coverage for every declared post type while preserving conservative compatibility.
- **Bounded, paginated plan view** (issue #3345): no-flag `wp wprism plan` JSON, detailed buckets, and direct human rendering remain byte/shape-compatible. Filtered direct-plan row labels and host status plan-row labels safely normalize C0/DEL controls. An explicit `--category=<csv>`, `--action=<csv>`, `--entity=<csv>`, emitted `--cursor=<token>`, or canonical `--limit=<1..200>` request adds only `plan_view` with `format: "wprism-plan-view/v2"` beside the complete detailed plan; it never removes, reorders, or authorizes its buckets. The closed category vocabulary is the nine summary ids above; actions are `create`, `update`, `adopt`, `unchanged`, `drift`, `conflict`, `collision`, `delete`, `delete_conflict`, `deleted`; entities are `post`, `attachment`, `term`, `menu`, `sidebar`, `options`, `user_meta`, `typed_table`. CSV tokens are exact and comma-only, deduped/canonicalized in vocabulary order; OR applies within one dimension and AND across supplied dimensions. Each page defaults to and caps ordinary rows at 200. `page.next_cursor` is an opaque token binding the complete plan identities, filters, and next matching offset; following only emitted cursors enumerates the matching set without duplicates, while a changed plan or filter set refuses the token as `plan_view_cursor_stale`. `plan_view` states `authoritative: false`, normalized filters/order, exact ordinary `full`/`matching`/`offset`/`shown`/`remaining` and `forced_safety` evidence, complete action/global/readiness counters, and selected value-free refs only: closed bucket/entity/category facets, safety bit, and a hashed explain selector. The selector resolves by a unique UUID scan within the full bucket, so no source position leaks into or destabilizes the view. Display rows sort by fixed action rank then bytewise UUID; the authoritative bucket arrays retain their original order. Facets are explicit and overlapping: normal live actions are `authored_state`, attachments also `media`, drift also `environment_state`, and tombstones are `deletions` (attachments also `media`). Every drift/conflict/collision/delete-conflict/blocked-delete row bypasses filters and the page cap. Global diagnostics and readiness are always from the full plan, including `regen_context`; filtering cannot hide a blocker or affect apply, promotion, or convergence. A category request additionally requires a valid same-snapshot `category_summary`. `wprism status` forwards one normalized request to its single full-plan fetch and typed-refuses `plan_view_unavailable` if that requested index is absent, malformed, or does not bind that full snapshot; unfiltered status remains compatible with legacy agents. V2 view flags and `--scope-contract` are mutually exclusive because `wprism-scoped-plan/v1` is already a separate selected-contract projection rather than the complete detailed plan a view indexes; their combination typed-refuses `plan_view_unavailable`. Human filtered output dereferences refs only from that complete plan and strips C0/DEL control bytes from newly itemized path/title labels. Field/text/value searching, raw-value views, plugin-specific engine filters, and interactive diffs remain out of scope.
- **Bounded page-only wire:** direct `wp wprism plan ... --view-only --format=json` emits only the `wprism-plan-view/v2` envelope for machine consumers that cannot ingest detailed buckets. It requires another view argument, retains full-plan readiness/count evidence and every forced-safety row, and remains non-authoritative. Host `wprism status` deliberately keeps the additive full-plan form so it can validate every reference and counter independently.
- **Bounded assessment wire:** bare host `wprism assess <env> --format=json` remains the complete, contract-bound `wprism-assess-report/v1`. An explicit canonical `--limit=<1..200>` or emitted `--cursor=<token>` instead emits only `wprism-assess-view/v1`, marked `authoritative: false` and `redaction: "authored_values_omitted"`. Its one ordered row stream contains complete value-free surface declarations followed by the report's already-bounded unknown-name sample; `summary` retains the full assessment's readiness, selected operations, exact surface/unknown counts, stack and reduced authority, evidence and disposition agreement, so a page cannot hide a blocker. The opaque cursor's v2 wire is one explicit version byte, the next big-endian offset, and HMAC-SHA256 over that version/offset keyed by a digest of the complete report facts with `generated_at` removed (a new wall-clock stamp is not site drift) plus the operation selection; that digest is recomputed and is not carried in the token. Following emitted cursors enumerates every projected row once; changed facts, operations, offset, or authenticator refuse as `assess_view_cursor_stale`. A structurally canonical 36-byte v1 token refuses loudly as `assess_view_cursor_version_unsupported`; malformed cursors and cursors outside JSON refuse as `invalid_arguments`. Assess's output-selector grammar is closed: select no machine format, or exactly one of `--format=json`, `--format json`, and `--json`; a duplicate/mixed selector, non-JSON format, value-bearing `--json`, or detached bare `json` typed-refuses as `invalid_arguments` before target assessment or local artifact publication. This projection never replaces the complete report used by proposal/accept automation.
- **`wp wprism doctor` DISALLOW_FILE_MODS check** (issue #3231, `cli/src/Onboarding/Doctor.php`): advisory-only (never fails `doctor`'s own exit code) — reports when a target's `wp-config.php` does not `define('DISALLOW_FILE_MODS', true)`, the source-closing complement to `code_drift`'s after-the-fact detection (docs/code-half.md risk register #1).
- Operational note: with a plugin active in the DB but missing from disk, the `wp plugin deactivate` *command* refuses (it pre-resolves its argument against a disk scan) — but `wp wprism deploy` handles this case fine: it calls core's `deactivate_plugins()` directly with basenames from the option, no disk resolution on the deactivation side (verified live). Manual `update_option('active_plugins', …)` surgery is the last resort only when wprism itself is unavailable.

### Cross-branch plugin-version skew

When branches contain different versions of a plugin, integration is an ordered boundary rather than a single undifferentiated state merge:

1. Merge the code-only change first and materialize that code.
2. Run the upgraded plugin's migrations against the current database.
3. Capture and commit the migrated canonical state under the new code version.
4. Only then merge state authored by the older-version branch. Resolve any schema conflict explicitly in the new version's shape before apply.

This keeps old-schema state from being silently interpreted by new code and gives Git a reviewable conflict when both the migration and the older branch changed the same canonical entity. `make certify-merge` exercises this end to end: a v1 scalar option diverges on a state branch while a v2 code branch migrates it to an object; the integration branch must capture the v2 object before merging the v1 edit, resolve the one-file conflict without losing the edit, and re-capture byte-identically on both environments.
