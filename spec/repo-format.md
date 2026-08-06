# Duo Site-Repo Format — spec v1

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
- Each environment holds a **ledger** (`duo_map` table): `(uuid, entity_type, id_kind) → local_id`. `id_kind` is a distinct keyspace label: `post`, `term`, `term_taxonomy`, `user`, `comment`. Term entities map **two** kinds (`term`, `term_taxonomy`) because WordPress references both inconsistently.
- `_duo_uuid` is globally unique across posts and terms. Capture rejects invalid UUIDs, multiple identity-meta rows on one owner, or one UUID copied onto multiple owners before publishing state. The repository compiler independently rejects duplicate UUIDs across every entity kind and declared table. `duo_map` writes are contradiction-intolerant: no ordinary path deletes another mapping, changes a local id, or silently retypes an identity.
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

Current heuristic for internal links: permalink hrefs tokenize as `{{home}}/<path>` (correct while slugs match across branches). uuid-precise link tokens (`{{link:<uuid>}}`) remain reserved for a future format revision.

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
- **Attachments** add: `"file": "<upload-relative/path.ext>"` (typically `Y/M/name.ext`, but normalized upload-root paths such as plugin placeholders are valid), `"media": "<sha256>.<ext>"` (binary in `media/`), `"mime": "image/jpeg"`, `"alt": "…"` (from `_wp_attachment_image_alt`). Body = attachment description; the uniform `excerpt` field carries the caption. `_wp_attachment_metadata` is derived: regenerated after every applied attachment create or update, including retry/adoption paths.
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
- Apply reconciles the menu fully: env items of this menu whose uuid is absent from the file are removed (menu-scoped ownership).

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

### Options — `state/options/core.json`

Versioned option-record document. Every exact option classified authored (plus the three managed code-half options) has a record, so deleting a JSON key is invalid rather than ambiguous. Dynamic families have records for discovered canonical names only. A record has exactly one of three states:

- `absent`: no portable value and no deletion intent; apply leaves a target row untouched.
- `present`: carries the lossless canonical `value` and the row's exact `autoload` storage flag.
- `deleted`: durable destructive intent carrying the sha256 `expected_hash` of the prior `present` record.

```json
{
  "format": "duo-options/v1",
  "records": {
    "blogdescription": {"state": "present", "autoload": "yes", "value": ""},
    "blogname": {"state": "present", "autoload": "yes", "value": "Duo Demo"},
    "default_category": {"state": "present", "autoload": "auto", "value": "{{term:0198b0aa-...}}"},
    "page_for_posts": {"state": "absent"},
    "retired_authored_option": {
      "state": "deleted",
      "expected_hash": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
    }
  }
}
```

`null`, `false`, `""`, empty containers, and serialized structures are ordinary `present.value` values and never deletion signals. Capture converts a prior `present` record to `deleted` only after observing that exact authored row absent; it preserves a still-absent deletion record byte-for-byte, while reappearance replaces it with `present`. Apply requires `--with-deletes`, checks the tombstone's expected record hash during planning, and treats post-base edits or recreation as conflicts. Removing a required exact-name record fails compilation with its `records.<name>` location.

Every manifest surface that can author a whole option row (`options`, an authored `option_patterns`/`option_name_refs`, or a rule with authored `sub_keys`) must declare autoload storage semantics. A rule may give an exact supported value, or the manifest/site policy may declare `option_autoload: "preserve"` to authorize capture and replay of the source row's exact value. Missing declarations block policy load; create and update both write the canonical flag, never a WordPress/version-local default.

Ref-typed values inside `present.value` are tokenized per the core manifest. The core exact set includes `blogname`, `blogdescription`, `show_on_front`, `page_on_front`, `page_for_posts`, `sticky_posts`, `default_category`, `posts_per_page`, and `wp_page_for_privacy_policy`:

```json
{"state": "present", "autoload": "yes", "value": ["{{post:0198b0c7-...}}"]}
```

Exact option rules remain sufficient for fixed names. A plugin with dynamic or evolving names declares discovery ownership separately with top-level `"option_namespaces": [{"match": "^plugin_prefix_"}]`. Capture enumerates every live `wp_options.option_name` in that namespace on every run, independent of the provenance journal. Each match must resolve through the owning manifest's exact `options` rule, one of its `option_patterns`, or an explicit site override; otherwise it is pending and capture blocks. An authored `option_patterns` rule therefore captures a dynamic family, while runtime/derived/env families are enumerated and deliberately excluded. Overlapping namespace claims and cross-manifest classifications refuse rather than depending on pin order. Names outside all declared namespaces are not guessed to belong to a plugin.

Term-meta is enumerated for every in-scope term. The spec-v1 term file has no `meta` field, so both an unclassified key and a key classified `authored` block capture: the former needs a decision, while the latter names an unsupported representation rather than accepting an inert classification. Explicit `runtime`/`derived`/`env` (or managed) rules are the only non-blocking dispositions until a term-meta state format exists.

A manifest `taxonomy_patterns` entry may declare both `object_type` and `update_count_callback`. These are the version-pinned registration contract for a dynamic taxonomy that a typed-snapshot table creates after WordPress's `init` hook has already run. Apply uses the live registered taxonomy whenever it exists; only in that same-request timing gap may it construct the equivalent taxonomy contract from the manifest and invoke the declared callback. A missing or non-callable contract refuses recount instead of falling back to a generic SQL count.

### Custom tables — `state/tables/<table>/<uuid>--<slug>.json` (spec v0.10)

Typed snapshot: capture/apply for **authored custom tables** (DESIGN.md §3.3's middle tier — task #75; primary fixture `nf3_forms`/`nf3_fields`/`nf3_actions`, secondary `woocommerce_attribute_taxonomies`). A manifest's `"tables"` section declares each table as one of two classes; anything else stays the pre-existing honest-intent marker `authored_typed_snapshot_post_v1` (declared, loudly not yet captured):

- **`authored_snapshot`** — a row table with identity of its own. Declares `pk`, `id_kind` (a new typed keyspace in `duo_map`; ≤16 chars, unique across manifests), `slug_column`, `columns{}` (every non-pk, non-ref column individually classified `authored`/`runtime`/`derived`/`env` — the post-meta discipline generalized to columns), and `refs[]` (`{"column", "kind"}` FK columns resolved through the ledger). **Every live column must be accounted for** by exactly one of pk / refs / columns — an undeclared column refuses capture loudly, whether or not it holds an id this round (finding #8's FK rule made absolute).
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

- **Identity** lives in `duo_map` (`id_kind`, `local_id`) — a plugin's table never gets a `_duo_uuid`-style column added (plugins stay unmodified). Three declared modes: `"identity": {"mode": "mapped"}` (default; fresh source rows mint UUIDv7; populated target rows without mappings block, and a source with canonical UUIDs but missing mappings blocks); `"identity": {"mode": "natural_key", "column": "<col>"}` (for a confirmed-stable, human-chosen unique column: the uuid is **derived**, `UUIDv5(NAMESPACE_DUO, "<table>:<value>")`, so a missing ledger entry can be reconstructed deterministically); and `"identity": {"mode": "composite_ref", "columns": ["<ref-col-1>", "<ref-col-2>"]}` for a pure join table whose identity is derived from the two referenced entities' UUIDs. Contradictory mappings never rebind implicitly. Composite-ref ledger rows are recoverable bookkeeping because the referenced UUID tuple remains the identity truth.
- **Refs are structural at the row level** (an unmapped non-zero row ref throws, the `post_parent` category) but **optional at the sidecar level** (an unmapped meta-value ref drops with a warning, the ordinary dangling-reference category) — the two severities the dangling-reference rule below already implied but never had to distinguish.
- **`invalidate`** — declarative per-row cache invalidation run with apply, no plugin PHP in the engine: `[{"table": "nf3_upgrades", "column": "id"}, {"option_pattern": "nf_form_{id}"}]`, where `{id}` substitutes the row's resolved local id (raw deletes fire no hooks, so no canary carve-out). Blanket (non-row-keyed) caches use the existing top-level `rebuilders` mechanism instead.
- `block_attrs` rules may name a declared table's `id_kind` as their ref kind (`ninja-forms/form`'s `formID` → `{{nf3_form:<uuid>}}`); `wp duo lint` scans `tables/*/*.json` like any other canonical state; `apply --adopt-by-slug=tables` adopts matching pre-existing env rows (one shared `tables` adopt key for all declared tables — a table entity's *type* is the table name).

- **Option-name-embedded refs** (spec v0.12, task #93): a manifest may declare `"option_name_refs": [{"match": "<regex with a required named group 'id'>", "id_kind": "<declared table id_kind>", "class": "authored", "json_refs"?: [...], "key_refs"?: {...}}]` for options whose NAME (not value) embeds another declared table's local id (WooCommerce's `woocommerce_<method_id>_<instance_id>_settings`). A sibling of ordinary `option_patterns`, not a variant: namespace-backed `option_patterns` classify/capture the real option name as-is, while `option_name_refs` drive their own discovery because the canonical key must replace the matched local `id` group with a portable token. The captured canonical KEY splices the resolved ref TOKEN into the exact byte position of the matched `id` group — `woocommerce_flat_rate_{{wc_zone_method:<uuid>}}_settings` — using the existing token grammar unchanged. Apply detects a token-bearing option KEY and detokenizes it BEFORE ordinary option-rule dispatch, unconditionally — this ordering is load-bearing: skipping it silently writes a real `wp_options` row whose NAME contains literal `{{...}}` bytes. The resolved real name is re-matched against the same patterns to recover the rule governing its VALUE, which routes through structural capture/apply unconditionally, so an array-shaped settings blob gets its string leaves URL-tokenized and deep secret-scanned with zero per-plugin special-casing.
- **Severity model for name-embedded ids** (mirrors task #73's dangling-vs-unscoped split, with one structural difference stated precisely): an id resolving to no row anywhere is ordinary dangling (warn + drop). An id naming a row that exists in its declared table but has no minted uuid is loud/blocking *only* when the capture is MINTING (`Capture::run()`, never `Capture::snapshot()`) — a declared table's rows are already minted unconditionally by the table-capture pass earlier in the same build, so a declared-but-unresolved row can only legitimately arise on a non-minting snapshot (plan's drift check), which is exactly the correctly-scoped-but-unminted false-positive class task #73's own fix documents (`default_category` on a never-captured fresh install); it falls through to warn-and-drop. Stated plainly: the loud branch is a defensive invariant guard, not a routinely-reachable scenario.

Not covered: a general composite natural key made from ordinary scalar columns. `composite_ref` is deliberately narrower: exactly two structural ref columns forming a pure join identity.

### Identity disaster recovery

Mapped custom-table UUIDs are promotion metadata and must travel with the database backup. Immediately after taking a quiesced database backup, run:

```sh
wp duo identity-export --repo=/path/to/site-repo --out=/secure/backup/site.identity.json
```

The `duo-identity-ledger/v1` sidecar contains sorted identity mappings, three-way sync hashes, the applied revision, and the exact compiled repository/site/manifest hashes. It contains SHA-256 row witnesses rather than plugin-row contents. Keep it beside the matching database backup; it is environment-bound recovery material, not canonical state and should not be committed to the site repository.

After restoring that database and checking out the exact repository revision, restore identity before `plan`, `apply`, or `capture`:

```sh
wp duo identity-import --repo=/path/to/site-repo --in=/secure/backup/site.identity.json
```

Import verifies the artifact integrity hash, repository and manifest association, every embedded post/term UUID, every mapped-table row witness, tuple uniqueness, and any already-present ledger subset before one transaction restores `duo_map`, `duo_state`, and `applied_revision`. A stale database/sidecar pair, changed row, conflicting map, tampered artifact, or wrong repository blocks without partial rebinding. A populated mapped-identity table without this metadata also blocks; there is no force flag because no deterministic adoption key exists.

## `site.duo.json`

```json
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"]
  },
  "spec_version": 1
}
```

`policy` holds site-local classification overrides (same shape as manifest rules); it wins over manifests. `manifests` pins which registry manifests apply (agent looks them up in its manifest dir).

### `envs` (optional)

`site.duo.json` may declare an `"envs"` object, keyed by environment name, describing the environments that materialize this site repo for the `duo` orchestrator CLI (see [cli/README.md](../cli/README.md)). Each entry names a `transport` (`local`, `docker`, or `ssh`) and a `repo_path` — this site repo's path *as seen from inside that environment*. Entries here are shared via git and must contain no secrets; anything machine-specific or sensitive belongs instead in a gitignored, machine-local `.duo-envs.json` overlay next to it, which replaces same-named entries whole. `envs` is orchestrator convenience, not part of the branchable state contract — the agent's `wp duo …` commands (this spec's actual subject) never read it.

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

Extended manifest capabilities (spec v0.5):

- `"interpreter": "<name>"` — schema-driven classification: the named interpreter is consulted per (meta key, the entity's full meta map) *before* static rules — for plugins whose meta semantics live in data (field-group definitions), not in a static key list. **Interpreter code is part of the manifest artifact, never the engine**: the name resolves to `manifests/interpreters/<name>.php`, which must define `\Duo\Interpreters\<Name>` with `post_meta_rule(string $key, array $allMeta): ?array`; it ships, versions, and pins together with its manifest JSON. (Trust boundary: the manifests dir is operator-controlled and deploys with the agent itself, so loading it is the same trust decision as running the agent.)
- `"post_types": {"acf-field": {"class": "authored", "body": "verbatim", "phase": "early"}}` — body mode `verbatim` byte-preserves `post_content` (serialized-data bodies, where URL substitution would corrupt serialized lengths); a verbatim body containing the environment's home URL warns loudly at capture (it will not re-bind). `"phase": "early"` makes the type finalize before all others in apply phase 2 — for definition CPTs whose content interpreters read to type other entities' meta (declared ordering, never glob luck).
- `"post_types": {"product_variation": {"fields": {"title": {"class": "derived"}}}}` (spec v0.11) — per-post_type classification for **post fields**: the ~13 keys every post file carries unconditionally (title, slug, status, dates, parent, menu_order, …), distinct from post_meta/options, whose rules already carry `class`. v1 accepts exactly `"title"` with `"class": "derived"`; Policy validates field name and class at manifest load and throws on anything else — `slug` participates in file names and Apply's collision/identity checks, and no other field has a proven self-healing precedent, so both stay unsupported rather than half-supported. Manifest-only, no site-policy override (`policy.post_types` is already the flat scope list; a rule map under the same key would collide — the `body`/`phase` precedent). Semantics: capture always writes the field's current observed value into the file verbatim, never omitted — a human reading `state/` sees the truth even when it is not authoritative; apply writes it once as a new row's bootstrap value on create and never overwrites it on update, letting the plugin's own derivation stand; the field is excluded from the **hash basis** used by `duo_state`, plan's three-way compare, and drift detection (`Canon::post_hash_basis()`) — never from the file itself — so a self-healing field's divergence is structurally invisible to plan/drift/conflict, not merely hidden from one diff. Proven case: WooCommerce's `product_variation` title, recomputed hook-free via raw `$wpdb` on every load (tasks #72/#88; the residual `_product_attributes` order divergence is #123's separate bug).
- Meta ref rules may declare `"cast"`: `"string"` (ids stored as strings inside serialized arrays — the ACF shape) or `"csv"` (a `"1,2,3"` id list canonicalized to a token array, re-joined on apply). Ref kind `"user"` serializes as `user:<login>` tokens — users stay env-local; apply resolves by login and falls back to the default author with a warning.
- **Order-preserving values** (spec v0.15, task #123): a meta rule may declare `"order_preserving": true` for a value whose PHP array key order is semantically load-bearing — canonical JSON's own `ksort()`-at-every-level rule (the "Entity-per-file, deterministic serialization" line above) is only safe when no plugin reads a value's raw iteration order, and WooCommerce's variation-title generator (`WC_Product_Variation_Data_Store_CPT::read()`) reads the parent's `_product_attributes` array order directly — canonicalization was permanently reordering it on every applied target, a real (not timing-based) divergence. A declared value's key order — at every nesting level inside it, recursively — is captured and round-tripped exactly as WordPress held it, instead of being alphabetized; declaring it changes nothing else about the rule (it composes with `ref`/`json_refs`/`key_refs`/`cast` normally, applied to the fully-processed value). Scoped strictly to the declared value: every *other* key in the same document, including sibling meta keys, still sorts alphabetically as normal — this is not a document-wide behavior change. No apply-side changes were needed: `json_decode()` and `maybe_serialize()` never reorder keys on their own, so the ordinary decode → detokenize → re-serialize path was already order-preserving by construction — the capture-time `ksort()` was the only place order was ever lost. `manifests/woocommerce.json`'s `_product_attributes` is the first declared user.
- `"rebuilders": [{"command": "yoast index"}]` — wp-cli commands run in the rebuild pass after a non-empty apply: the hooks apply deliberately skips are also what maintain plugin derived state (indexables, lookup tables), so manifests declare the regeneration command instead.
- `"deletions": {"post:product": {"cascades": ["postmeta", "post_revisions", "term_relationships"], "guards": [{"table": "wc_order_product_lookup", "column": "product_id", "id_kind": "post", "reason": "orders reference this product"}]}}` — exact, adapter-owned deletion capability. `cascades` names every effect the engine will perform. Each guard declares the target id keyspace; optional `where` adds scalar predicates and optional `exclude_where` subtracts owned rows already covered by a declared cascade (for example revision child posts). A guard may also declare `source_id_kind` + `source_pk`, allowing rows whose source entities are themselves safe delete candidates in the same revision while still blocking runtime or conflicted references. Missing guard tables fail closed. Matching rows mark deletion **BLOCKED**; `apply --with-deletes` refuses unless `--force-delete-referenced`, and forced execution remains loud.
- **Structured-value refs** (spec v0.7): a meta/option rule may declare refs *inside* a JSON-or-PHP-serialized value. `"json_refs": [{"path": "$.*.*.wpseo_opengraph-image-id", "kind": "post", "cast": "string"}]` rewrites id **values** at declared paths (minimal dialect: `$` root, `.` key steps, `*` wildcard); `"key_refs": {"path": "$.*", "kind": "term"}` rewrites entity-id **keys** of the map at the declared path (the `wpseo_taxonomy_meta` shape). Everything undeclared inside the structure is byte-preserved except string leaves, which get ordinary URL tokenization; unmapped ids follow the drop-with-warning rule. The tokenizer also matches **JSON-escaped URL forms** (`https:\/\/…`, Elementor's convention): both forms collapse to one plain-spelled token, and structural re-encode restores the host convention on apply (RFC 8259 makes `/` vs `\/` equivalent inside JSON strings). `wp duo lint` treats declared paths as owned and still flags id-shaped values at *undeclared* paths inside the same structure — the linter catching manifest gaps is its purpose. `option_patterns` has a meta twin: `"meta_patterns"` (versioned keys like `_elementor_migrations_*`).
- **Dynamic taxonomy scope** (spec v0.12, task #92): a manifest may declare `"taxonomy_patterns": [{"match": "<regex>", "object_type": ["<type>", ...]}]` for taxonomies whose NAME (not a meta/option key) is dynamic per site (WooCommerce's `pa_<attribute>`, minted at runtime from a custom-table row). Unlike `option_patterns`/`meta_patterns` (which classify a key some other enumeration already produced), `taxonomy_patterns` expands the taxonomy SCOPE LIST itself: `Policy::taxonomies()` unions the exact `policy.taxonomies` list with every name in live `wp_term_taxonomy` (a `SELECT DISTINCT`) that matches a declared pattern — **scope-gated**: only pattern-matched names are ever added, never a blanket widen to whatever the database holds (the posture task #73 established for ref-typed options). Matching against the live table, not `get_taxonomies()`'s in-memory registry, is deliberate and load-bearing: a taxonomy whose registration depends on a same-request custom-table write (a typed-snapshot table's phase-1 insert) is invisible to the registry for the rest of that request even though its own term rows are already live. The declared `object_type` is a fallback consulted only when `get_taxonomy()` fails — the registry stays authoritative whenever it succeeds. This is what lets direct-SQL term-relationship writes correctly scope a just-landed dynamic taxonomy in the SAME apply request that created its defining row, with zero manual pre-provisioning.
- **Whole-entity scope gate** (DUO-3229): capture enumerates WordPress-registered public post types/taxonomies plus whole-type contracts declared by pinned manifests, then counts their live capturable rows. A type with rows must either be in `policy.post_types` / `policy.taxonomies` (including a declared taxonomy-pattern match), or carry an explicit non-authored disposition. Pinned manifest `post_types.<name>.class` is already that disposition (`shop_order` and `nf_sub` are runtime); manifest taxonomy declarations default authored unless they declare otherwise. Site-local decisions live at `policy.scope.post_type.<name>.class` or `policy.scope.taxonomy.<name>.class`. `authored` adds the name to scope; `runtime`, `derived`, or `env` records a deliberate exclusion. Missing disposition blocks capture naming the surface, entity count, and exact policy fix; it never quietly shrinks the tree. `wp duo pending` reports keys such as `scope:post_type:book`, and `wp duo classify --set='scope:post_type:book=runtime'` persists an audited exclusion.
- **Sub-keyed options** (spec v0.14, DUO-3233): a manifest option rule may declare `"sub_keys": {"<name>": {...rule...}, ...}` — NAMED sub-keys of one option's array value classified and captured/applied **independently of the whole option and of each other**, using the same rule vocabulary as a top-level option/meta rule (`class`, `ref`, `json_refs`, `key_refs`, `cast`, `allow_secret`). `sub_keys` and `class: authored` are mutually exclusive on the same rule — a whole-option-authored value has no sub-key carve-out to speak of, and mixing the two leaves "authored the whole thing" vs. "authored named pieces of it" undefined. Capture reads the live option, keeps only the entries whose sub-rule is `authored` (everything else — including any key genuinely absent from the manifest — is left out of state entirely, not merely excluded), and applies the ordinary ref/json_refs/key_refs/secret-guard machinery per sub-key exactly as it would to a same-shaped top-level rule. Apply reads the **target's own live value** of the option (defaulting to `[]` with a warning if the option is absent there), overlays only the captured sub-keys' resolved values on top of it, and writes the merged array back — every sibling key on the target, declared or not, whole-option class `env`/`runtime`/`derived` or otherwise, survives byte-for-byte untouched. Repository authorization mirrors the split: an option carrying `sub_keys` is authorized key-by-key against the declared sub-rules (any key present in a captured value with no `authored` sub-rule is refused by name — `option_sub_key` surface, not folded into the whole-option `class` check). `wp duo lint`'s bare-id scan is likewise sub-key aware: a `json_refs`/`key_refs`-declared sub-key gets the deep structural scan; a plain sub-key gets the ordinary shallow scan one level in. Motivating case: Polylang's `polylang` option and Yoast's `wpseo` option each mix authored configuration with per-environment bookkeeping that must never travel, inside the SAME option blob — `sub_keys` lets a manifest tell those apart without capturing the whole blob (silently clobbering another environment's own bookkeeping on apply) or excluding it whole (silently losing real authored configuration).
- **Adapter compatibility contract** (spec v0.15, DUO-3222/DUO-3247): a manifest MUST declare `"spec_version"` (int, the wire-format grammar it was authored against) equal to the engine's own `DUO_SPEC_VERSION` exactly — absent and declared-wrong are the same failure, both refused at load time. (This was not always true: at v1/DUO-3222, while `DUO_SPEC_VERSION` had exactly one historical value, an absent declaration was lenient — it can't be "wrong" when nothing else it could have meant existed yet — with an explicit, written pre-commitment to flip the moment a second historical value existed to be silently wrong about; DUO-3210 performed that bump, DUO-3247 actioned the pre-committed flip.) A manifest may additionally declare `"plugin"`/`"version_range"` (unchanged from spec v0.9's original mechanic) and `"theme"`/`"theme_version_range"`, the exact same `{min,max}` (min inclusive, max exclusive) shape mirrored for themes: one theme per manifest, matching one plugin per manifest. `Policy::load()` rejects, at load time, before any target contact: a `plugin`/`theme` declared without a well-formed matching range (no latest/wildcard/unbounded support is certifiable); a malformed range (missing min/max, non-string, min not strictly less than max); and two pinned manifests naming the same plugin or theme with different ranges (conflicting ownership — manifest precedence may never depend on pin order, so this is refused outright, with no composition/override grammar in v1). The compiled artifact (`RepositoryCompiler`) records a `resolved_adapters` array — one row per pinned manifest, carrying its name, a per-manifest content digest (the same bytes `manifest_hash()` already folds into its one combined hash, now also exposed individually), and its declared identity/range facts. This is compilation staying honest about what it validated — declaration validity and non-ambiguity, reproducible and artifact-hashed — not a live-environment match, which stays `Deploy::code_mismatch()`'s job: it checks a declared `theme_version_range` against the environment's actual installed theme version exactly as it already does for plugins, producing the identical `outside_version_range`/`missing_in_code` finding shape with `kind: "theme"`.

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
- **Dangling references**: an unmapped id in a ref-typed meta value is **dropped with a warning** (array/csv: the element; scalar: the whole key), mirroring options' long-standing semantics — a raw env-local id in canonical state is indistinguishable elsewhere from a valid id and may silently point at an unrelated live entity after auto-increment reuse. Convergence comes through the repo: the corrected canonical value applies everywhere. A `block_attrs` ref (a `"kind"`/`"kind_from"` rule) that fails to map gets the identical uniform treatment: `int[]` drops just that element, a scalar drops the whole attribute key, both warning by block/attribute/id — block refs don't yet get the unscoped-vs-dangling triage below, which remains options/post_meta-only.
- **Unscoped references** (spec v0.10, task #73): an authored, ref-typed **option** whose id names a row that *genuinely exists* but whose post type/taxonomy is missing from policy scope is a different failure mode — a fixable scope gap, not deleted data — and **aborts capture loudly** by default (the unclassified-meta gate's posture), naming the option, the raw id, the target's real type, and the exact policy key to amend. `--force-unresolved-refs` (accepted by `capture`, `plan`, and `apply` — the latter two hit the same gate through their internal env snapshot) opts back into dangling-style drop-with-warning. A real, correctly scoped row that merely has no uuid minted yet (every fresh target environment before its first capture) is neither dangling nor unscoped and keeps the ordinary warn-and-drop path. Array-ref options get identical per-element treatment — scalar and array never diverge in severity.
- **`wp duo lint`** — the suspicious-ref gate byte-diffing cannot provide (wrong bytes written once read back faithfully): flags `bare_id` (numeric values matching existing entity ids under rules with no declared ref), `escaped_home` (JSON-escaped env URLs the tokenizer's plain-form substitution misses), `unregistered_block_attr` (id-shaped attrs in blocks with no registry rule, and URL-shaped string attrs), `unrewritten_registered_ref` (a block attribute path *is* declared in the `block_attrs` registry but its captured value is still numeric — a declared ref whose rewrite silently didn't happen, e.g. an unmapped id or a `kind_from` dispatch that resolved to no kind — distinct from `unregistered_block_attr`, which fires when no rule exists for the path at all), and `serialized_desc_ids` (id-bearing serialized term descriptions). Exit 1 on findings; the conformance harness runs it as a hard gate. A rule may declare `"lint_ok": true` — an explicit, auditable human review meaning "numeric but genuinely not a ref"; it works on option/meta rules (e.g. `posts_per_page`) and as a block_attrs entry (e.g. `queryId`, a query instance index — the rewriter skips such rules entirely, and lint treats it as owned rather than an unrewritten ref). The only sanctioned exemption.

The orchestrator surfaces this loop as `duo pending <env>` and `duo classify <env>` (interactive stdin triage; Enter accepts a proposal, explicit keys override, secrets require typing "allow"; `--accept-proposals` for CI, which never auto-authors a secret) — see cli/README.md.

## Offline repository compilation (spec v0.13)

`wp duo compile --repo=<p> [--out=<artifact.json>]` is the semantic merge gate. It reads one complete repository revision without constructing target-bound tokenizers or consulting the target database, parses every canonical entity into typed data (post metadata plus a distinct raw body), and emits `duo-compiled-repository/v1`. The artifact embeds referenced media bytes, active site-policy and pinned-manifest/interpreter hashes, an exact source-revision hash, and its own SHA-256 content address. Loading an emitted artifact verifies those hashes; a current policy/manifest mismatch refuses it.

Compilation batches stable blocking diagnostics for malformed or unknown entity kinds, invalid/duplicate UUIDs, duplicate natural identities (`post_type + slug + parent`, `taxonomy + slug`, or a declared table natural key), malformed/unsupported tombstones, live+tombstone collisions, conflict markers, graph references whose target is explicitly deleted or has the wrong kind, unsafe/duplicate attachment paths, missing or mis-hashed media, schema/content mismatches, and pinned adapter constraints. ACF's schema/value checks use the same manifest-shipped interpreter trust boundary as classification—never the installed plugin. The DUO-3203 policy-authorization pass is the final compiler layer and preserves its existing structured failure contract.

Plan, apply, and deploy construct or load this artifact before target contact and accept only the `CompiledRepository` type internally—never a raw tree array. `--compiled=<artifact.json>` reuses a previously emitted artifact. All phases consume its decoded data/body/media payload and never reopen mutable `state/` or `media/` files after compilation; a failed compilation therefore creates no ledger and performs no target read, lifecycle call, rebuild, filesystem materialization, or database write.

## Apply semantics (v1)

0. **Offline compilation + repository authorization**: compile the complete immutable revision into the verified artifact above before any target contact. Its final layer checks every repository-carried post-meta key, option, typed-table column, and attached-meta key against current policy. The three managed code options and managed menu/attachment fields are accepted only through named dedicated routes. Unknown, runtime, derived, environment, stale-policy, and misplaced managed fields preserve the stable `{ok:false,error:"repository_authorization_failed",diagnostics:[...]}` contract; all earlier semantic failures return `{ok:false,error:"repository_compilation_failed",diagnostics:[...]}`.
1. **Plan live entities**: for each entity file: `create` (uuid not in map), `update` (canonical hash ≠ `duo_state` hash), `unchanged`; environment drift remains explicit. A ledger UUID absent from state and lacking a tombstone schedules nothing.
2. **Plan tombstones**: compare the tombstone `expected_hash`, target `duo_state` base, and current canonical environment hash. Exact base + unchanged target → `delete`; target already absent → `deleted`; missing/mismatched base, local edit, or recreation after a deletion receipt → `delete_conflict`. `--force-theirs` may override a deletion conflict but reports it loudly. Fresh and previously mapped targets therefore interpret the same repository deletion intent; absence alone never differs by ledger history.
3. **Reference safety**: compilation blocks surviving canonical references. Adapter guards check runtime reverse references; a missing required guard table also blocks. `--force-delete-referenced` is an explicit report-not-hide escape hatch.
4. **Canary armed**: listeners on `save_post`, `transition_post_status`, `created_term`, `wp_insert_comment` + `pre_wp_mail` + `pre_http_request`; any fire during apply = hard failure.
5. **Phase 1** — upsert rows (posts, terms) with placeholder refs, direct `$wpdb`; mint local ids; write `_duo_uuid`.
6. **Phase 2** — resolve refs through the ledger: parents, metas, term relationships, menu structure, option values, body detokenization (block registry restores numeric types).
7. **Deletes** — only with `--with-deletes`, custom-table children before parents. The engine performs the declared cascades, then queries every exact target and attached sidecar before commit. Any survivor rolls back the transaction. Menus delete their owned menu-item posts; comments, Woo order lookups, Ninja Forms submissions, and other declared runtime references are preserved by guards rather than cascaded.
8. **Receipts and retry** — a successful or already-absent deletion stores the tombstone hash in `duo_state` with entity type `deletion`; re-planning returns `deleted`, so retries are idempotent. Live hashes and `applied_revision` update normally.
9. **Rebuild** — canary disarmed: term recounts (direct SQL), attachment metadata regeneration, cache flush; harness-level: rewrite flush, thumbnail regen.

Every direct database mutation and transaction boundary is checked for
WordPress's `false` failure result; zero affected rows remains a valid
UPDATE/DELETE result, while an insert without a positive generated id fails
before identity can enter the ledger. Apply writes an environment-local
`apply_in_progress` marker before the first target mutation and clears it only
after all required rebuilders succeed. If a post-commit rebuild fails, base
hashes and `applied_revision` do not advance; the marker makes the next apply
reprocess canonical entities (including attachment metadata) rather than
mistaking byte-equal authored rows for a completed promotion. Plan exposes the
marker as a structured `incomplete_apply` condition, so `duo status` remains
non-zero until that retry succeeds and clears it.

Snapshot/rollback is the orchestrator's job in v0. The normal host path,
`duo promote <env>`, compiles one immutable artifact, exports the database to
the target repo's gitignored `.duo/checkpoints/`, then sequences deploy → apply
against that same artifact. A failed phase stops all later phases and prints the
checkpoint plus the exact transport-shaped `wp db import` recovery command.

## Code-half facts & deploy (spec v0.9 — docs/proposals/code-half.md phase 1)

- `active_plugins`, `template`, `stylesheet` are **managed-class** core-manifest options: captured bespoke into `state/options/core.json` (plain portable strings — plugin file paths and theme slugs need no tokenization; their cross-environment stability *is* the invariant), and **excluded from apply's generic direct-SQL path** — a raw options UPDATE would skip activation/switch hooks while leaving WordPress believing the code is active.
- **`wp duo deploy --repo=<p>`** is the one sanctioned side-effect step: it reconciles activation state to canonical via real `activate_plugin()`/`switch_theme()` calls, deliberately outside the canary window, and is idempotent. Ordering: deploy code → migrations fire via activation → then `apply` state.
- **Plan's `code_mismatch` bucket**: `missing_in_code` (canonical wants an activation whose plugin is absent from the environment's code), `outside_version_range` (a pinned manifest declares `{"plugin": "<file>", "version_range": {"min", "max"}}` and the installed version falls outside), and lifecycle mismatches (`inactive_in_environment`, `unexpected_active_plugin`, `active_plugin_order_mismatch`). Apply refuses every row. Deploy refuses missing/incompatible code but owns reconciliation of lifecycle rows through real activation/deactivation/theme APIs plus an exact, verified `active_plugins` ordering write. `--force-code-mismatch` remains the report-not-hide escape hatch for missing/incompatible code. `code_revision_stale` is phase 2 (requires a materialization transport).
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
