# Duo Site-Repo Format — spec v0

*Status: draft, exercised by Spike A/B. Everything here is versioned; `spec_version` in `site.duo.json` pins it.*

A **site repo** is a git repository holding the branchable partition of one WordPress site: code, canonical state, media, and policy. Environments (any WP install with the Duo agent) materialize it; their runtime data never enters it.

## Layout

```
site.duo.json                # spec_version, manifest pins, site policy
code/                        # the code half (Bedrock-style; out of scope for the spikes)
  wp-content/plugins/
  wp-content/themes/
  wp-content/mu-plugins/duo/ # the agent
state/                       # canonical authored state (this spec's core)
  options/core.json
  posts/<post_type>/<uuid>--<slug>.md
  terms/<taxonomy>/<uuid>--<slug>.json
  menus/<slug>.json
media/<sha256>.<ext>         # content-addressed binaries (git LFS in real repos)
```

## Canonical serialization (v0 decision)

**Canonical JSON**: UTF-8, LF, keys sorted lexicographically at every level, 2-space pretty-print, `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, trailing newline.

Rationale vs YAML: the agent is a drop-in mu-plugin and must not vendor dependencies (no YAML parser ships with WP/PHP); JSON gives a byte-deterministic emitter for free. Human-mergeability of the one thing that needed it — post bodies — is preserved by the front-matter+body format below (bodies are raw lines, never escaped into a string). YAML remains an open revisit if editor ergonomics demand it; it would be a mechanical format bump of `spec_version`.

Determinism is a hard requirement: **capturing the same site twice must produce byte-identical trees** (acceptance-tested). Nothing environment-specific may appear in `state/` — that's what tokens are for.

## Identity

- Every entity carries a **UUIDv7**, minted at first capture, stored invisibly in the environment (`postmeta`/`termmeta` key `_duo_uuid`). Options are identified by option name.
- Entity filenames are `<uuid>--<slug>.<ext>`. The uuid is identity; the slug is a human affordance (renames change the filename's slug half; tooling treats uuid as the key).
- Each environment holds a **ledger** (`duo_map` table): `(uuid, entity_type, id_kind) → local_id`. `id_kind` is a distinct keyspace label: `post`, `term`, `term_taxonomy`, `user`, `comment`. Term entities map **two** kinds (`term`, `term_taxonomy`) because WordPress references both inconsistently.
- Users are **not** entities: user references serialize as `user:<user_login>` tokens; apply resolves by login and falls back to a configured default author with a warning. Never auto-created.
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

v0 heuristic for internal links: permalink hrefs tokenize as `{{home}}/<path>` (correct while slugs match across branches). uuid-precise link tokens (`{{link:<uuid>}}`) are reserved for v1.

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
  "modified_gmt": "2026-08-05 10:00:00",
  "parent": "{{post:0198b0d1-...}}",
  "ping_status": "closed",
  "slug": "about",
  "status": "publish",
  "terms": {
    "category": ["0198b0aa-..."]
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
- `terms` maps taxonomy → ordered list of term uuids.
- Excluded fields: `guid` (env-derived), `comment_count`/`post_password`-empty-noise (derived/default), revisions and auto-drafts (never captured).
- **Attachments** add: `"file": "<Y/M/name.ext>"` (upload-relative path), `"media": "<sha256>.<ext>"` (binary in `media/`), `"mime": "image/jpeg"`, `"alt": "…"` (from `_wp_attachment_image_alt`). Body = attachment description; the uniform `excerpt` field carries the caption. `_wp_attachment_metadata` is derived: regenerated on apply.

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

### Menus — `state/menus/<slug>.json`

A menu file owns the `nav_menu` term **and** its `nav_menu_item` posts (they never appear under `state/posts/`). Ordered `items` array (order = `menu_order`); this file is the known v2 candidate for a field-aware merge driver.

```json
{
  "items": [
    {
      "attr_title": "",
      "classes": [],
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
- `parent` is a menu-item uuid (self-referential); apply is two-phase (create items, then resolve parents).
- `locations` records this menu's slots in the active theme's `theme_mods` (`nav_menu_locations`) — the one theme-mod key the core manifest classifies authored in v0.
- Apply reconciles the menu fully: env items of this menu whose uuid is absent from the file are removed (menu-scoped ownership).

### Options — `state/options/core.json`

Flat map, only keys classified authored. v0 whitelist (the pinned 8): `blogname`, `blogdescription`, `show_on_front`, `page_on_front`, `page_for_posts`, `sticky_posts`, `default_category`, plus `posts_per_page`. Ref-typed values are tokenized per the core manifest:

```json
{
  "blogname": "Duo Demo",
  "default_category": "{{term:0198b0aa-...}}",
  "page_on_front": "{{post:0198b0c0-...}}",
  "show_on_front": "page",
  "sticky_posts": ["{{post:0198b0c7-...}}"]
}
```

## `site.duo.json`

```json
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"]
  },
  "spec_version": 0
}
```

`policy` holds site-local classification overrides (same shape as manifest rules); it wins over manifests. `manifests` pins which registry manifests apply (agent looks them up in its manifest dir).

## Manifests (registry format)

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

Classes: `authored` (captured), `runtime` / `derived` / `env` (excluded; `derived` additionally implies "regenerate on apply" where a rebuilder exists). Anything unmatched by manifest+policy is **unclassified → loud abort**.

## Ledger tables (per environment, never in the repo)

| Table | Purpose |
|---|---|
| `duo_map(uuid, entity_type, id_kind, local_id)` | typed identity map |
| `duo_state(uuid, entity_type, content_hash)` | canonical hash at last capture/apply — drift & 3-way base |
| `duo_kv(k, v)` | `applied_revision`, guid pins, config |
| `duo_journal(...)` | provenance journal (Spike C; runtime data, prunable) |

## Apply semantics (v0)

1. **Plan**: for each entity file: `create` (uuid not in map), `update` (canonical hash ≠ `duo_state` hash), `unchanged`; ledger'd uuids absent from state → `delete` (listed; executed only with `--with-deletes`). Env drift = env's current canonical hash ≠ `duo_state` hash → surfaced per entity.
2. **Canary armed**: listeners on `save_post`, `transition_post_status`, `created_term`, `wp_insert_comment` + `pre_wp_mail` + `pre_http_request`; any fire during apply = hard failure.
3. **Phase 1** — upsert rows (posts, terms) with placeholder refs, direct `$wpdb`; mint local ids; write `_duo_uuid`.
4. **Phase 2** — resolve refs through the ledger: parents, metas, term relationships, menu structure, option values, body detokenization (block registry restores numeric types).
5. **Rebuild** — canary disarmed: term recounts (direct SQL), attachment metadata regeneration, cache flush; harness-level: rewrite flush, thumbnail regen.
6. Ledger + `duo_state` hashes updated; `applied_revision` set.

Snapshot/rollback is the orchestrator's job in v0 (`wp db export` before apply).
