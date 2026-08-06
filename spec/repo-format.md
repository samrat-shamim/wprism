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
- **Attachments** add: `"file": "<upload-relative/path.ext>"` (typically `Y/M/name.ext`, but normalized upload-root paths such as plugin placeholders are valid), `"media": "<sha256>.<ext>"` (binary in `media/`), `"mime": "image/jpeg"`, `"alt": "…"` (from `_wp_attachment_image_alt`). Body = attachment description; the uniform `excerpt` field carries the caption. `_wp_attachment_metadata` is derived: regenerated on apply.

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

### Custom tables — `state/tables/<table>/<uuid>--<slug>.json` (spec v0.10)

Typed snapshot: capture/apply for **authored custom tables** (DESIGN.md §3.3's middle tier — task #75; primary fixture `nf3_forms`/`nf3_fields`/`nf3_actions`, secondary `woocommerce_attribute_taxonomies`). A manifest's `"tables"` section declares each table as one of two classes; anything else stays the pre-existing honest-intent marker `authored_typed_snapshot_post_v1` (declared, loudly not yet captured):

- **`authored_snapshot`** — a row table with identity of its own. Declares `pk`, `id_kind` (a new typed keyspace in `duo_map`; ≤16 chars, unique across manifests), `slug_column`, `columns{}` (every non-pk, non-ref column individually classified `authored`/`runtime`/`derived`/`env` — the post-meta discipline generalized to columns), and `refs[]` (`{"column", "kind"}` FK columns resolved through the ledger). **Every live column must be accounted for** by exactly one of pk / refs / columns — an undeclared column refuses capture loudly, whether or not it holds an id this round (finding #8's FK rule made absolute).
- **`authored_snapshot_meta`** — an EAV sidecar with no independent identity: `attached_to: {table, column}`, `key_column`/`value_column` (plus `legacy_key_column`/`legacy_value_column` where the plugin double-writes a back-compat pair), a `keys{}` map for known runtime/ref key names, and a `default_class` for the open-ended remainder — an audited escape hatch of the same species as `lint_ok`/`allow_secret`, because an EAV key space is unbounded by construction while a table's columns are static schema. Sidecar rows fold into the owning row's file as its `meta` map and reconcile as an owned key-set on apply, exactly like postmeta; they get no file, no uuid, and no `duo_map` entry of their own.

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

- **Identity** lives *only* in `duo_map` (`id_kind`, `local_id`) — a plugin's table never gets a `_duo_uuid`-style column added (plugins stay unmodified). Two declared modes: `"identity": {"mode": "mapped"}` (default; fresh rows mint UUIDv7 — if the ledger is ever lost, identity for these rows is genuinely gone, stated plainly) and `"identity": {"mode": "natural_key", "column": "<col>"}` (for a confirmed-stable, human-chosen unique column: the uuid is **derived**, `UUIDv5(NAMESPACE_DUO, "<table>:<value>")`, so even a fully lost ledger self-heals on recapture — determinism plus `duo_map`'s existing rebind semantics compose into recovery).
- **Refs are structural at the row level** (an unmapped non-zero row ref throws, the `post_parent` category) but **optional at the sidecar level** (an unmapped meta-value ref drops with a warning, the ordinary dangling-reference category) — the two severities the dangling-reference rule below already implied but never had to distinguish.
- **`invalidate`** — declarative per-row cache invalidation run with apply, no plugin PHP in the engine: `[{"table": "nf3_upgrades", "column": "id"}, {"option_pattern": "nf_form_{id}"}]`, where `{id}` substitutes the row's resolved local id (raw deletes fire no hooks, so no canary carve-out). Blanket (non-row-keyed) caches use the existing top-level `rebuilders` mechanism instead.
- `block_attrs` rules may name a declared table's `id_kind` as their ref kind (`ninja-forms/form`'s `formID` → `{{nf3_form:<uuid>}}`); `wp duo lint` scans `tables/*/*.json` like any other canonical state; `apply --adopt-by-slug=tables` adopts matching pre-existing env rows (one shared `tables` adopt key for all declared tables — a table entity's *type* is the table name).

- **Option-name-embedded refs** (spec v0.12, task #93): a manifest may declare `"option_name_refs": [{"match": "<regex with a required named group 'id'>", "id_kind": "<declared table id_kind>", "class": "authored", "json_refs"?: [...], "key_refs"?: {...}}]` for options whose NAME (not value) embeds another declared table's local id (WooCommerce's `woocommerce_<method_id>_<instance_id>_settings`). A sibling of `option_patterns`, not a variant: `option_patterns` only classifies a key some other enumeration already discovered — `Capture::build_options()` is exact-whitelist-only and never consults it for DISCOVERY — whereas `option_name_refs` entries drive their OWN discovery pass (one full scan of live `wp_options` names per capture against every declared, anchored pattern). The captured canonical KEY splices the resolved ref TOKEN into the exact byte position of the matched `id` group — `woocommerce_flat_rate_{{wc_zone_method:<uuid>}}_settings` — using the existing token grammar unchanged. Apply detects a token-bearing option KEY and detokenizes it BEFORE ordinary option-rule dispatch, unconditionally — this ordering is load-bearing: skipping it silently writes a real `wp_options` row whose NAME contains literal `{{...}}` bytes. The resolved real name is re-matched against the same patterns to recover the rule governing its VALUE, which routes through structural capture/apply unconditionally, so an array-shaped settings blob gets its string leaves URL-tokenized and deep secret-scanned with zero per-plugin special-casing.
- **Severity model for name-embedded ids** (mirrors task #73's dangling-vs-unscoped split, with one structural difference stated precisely): an id resolving to no row anywhere is ordinary dangling (warn + drop). An id naming a row that exists in its declared table but has no minted uuid is loud/blocking *only* when the capture is MINTING (`Capture::run()`, never `Capture::snapshot()`) — a declared table's rows are already minted unconditionally by the table-capture pass earlier in the same build, so a declared-but-unresolved row can only legitimately arise on a non-minting snapshot (plan's drift check), which is exactly the correctly-scoped-but-unminted false-positive class task #73's own fix documents (`default_category` on a never-captured fresh install); it falls through to warn-and-drop. Stated plainly: the loud branch is a defensive invariant guard, not a routinely-reachable scenario.

Not covered (unchanged): a composite (multi-column) natural key for a declared table's identity mode — relevant to the shipping-zone-family tables, where no single column is independently unique (task #125, not blocking).

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
- `"rebuilders": [{"command": "yoast index"}]` — wp-cli commands run in the rebuild pass after a non-empty apply: the hooks apply deliberately skips are also what maintain plugin derived state (indexables, lookup tables), so manifests declare the regeneration command instead.
- `"delete_guards": {"post:product": [{"table": "wc_order_product_lookup", "column": "product_id", "reason": "orders reference this product"}]}` — plan-time referential check: matching rows mark the delete **BLOCKED**; `apply --with-deletes` refuses unless `--force-delete-referenced`.
- **Structured-value refs** (spec v0.7): a meta/option rule may declare refs *inside* a JSON-or-PHP-serialized value. `"json_refs": [{"path": "$.*.*.wpseo_opengraph-image-id", "kind": "post", "cast": "string"}]` rewrites id **values** at declared paths (minimal dialect: `$` root, `.` key steps, `*` wildcard); `"key_refs": {"path": "$.*", "kind": "term"}` rewrites entity-id **keys** of the map at the declared path (the `wpseo_taxonomy_meta` shape). Everything undeclared inside the structure is byte-preserved except string leaves, which get ordinary URL tokenization; unmapped ids follow the drop-with-warning rule. The tokenizer also matches **JSON-escaped URL forms** (`https:\/\/…`, Elementor's convention): both forms collapse to one plain-spelled token, and structural re-encode restores the host convention on apply (RFC 8259 makes `/` vs `\/` equivalent inside JSON strings). `wp duo lint` treats declared paths as owned and still flags id-shaped values at *undeclared* paths inside the same structure — the linter catching manifest gaps is its purpose. `option_patterns` has a meta twin: `"meta_patterns"` (versioned keys like `_elementor_migrations_*`).
- **Dynamic taxonomy scope** (spec v0.12, task #92): a manifest may declare `"taxonomy_patterns": [{"match": "<regex>", "object_type": ["<type>", ...]}]` for taxonomies whose NAME (not a meta/option key) is dynamic per site (WooCommerce's `pa_<attribute>`, minted at runtime from a custom-table row). Unlike `option_patterns`/`meta_patterns` (which classify a key some other enumeration already produced), `taxonomy_patterns` expands the taxonomy SCOPE LIST itself: `Policy::taxonomies()` unions the exact `policy.taxonomies` list with every name in live `wp_term_taxonomy` (a `SELECT DISTINCT`) that matches a declared pattern — **scope-gated**: only pattern-matched names are ever added, never a blanket widen to whatever the database holds (the posture task #73 established for ref-typed options). Matching against the live table, not `get_taxonomies()`'s in-memory registry, is deliberate and load-bearing: a taxonomy whose registration depends on a same-request custom-table write (a typed-snapshot table's phase-1 insert) is invisible to the registry for the rest of that request even though its own term rows are already live. The declared `object_type` is a fallback consulted only when `get_taxonomy()` fails — the registry stays authoritative whenever it succeeds. This is what lets direct-SQL term-relationship writes correctly scope a just-landed dynamic taxonomy in the SAME apply request that created its defining row, with zero manual pre-provisioning.
- **Whole-entity scope gate** (DUO-3229): capture enumerates WordPress-registered public post types/taxonomies plus whole-type contracts declared by pinned manifests, then counts their live capturable rows. A type with rows must either be in `policy.post_types` / `policy.taxonomies` (including a declared taxonomy-pattern match), or carry an explicit non-authored disposition. Pinned manifest `post_types.<name>.class` is already that disposition (`shop_order` and `nf_sub` are runtime); manifest taxonomy declarations default authored unless they declare otherwise. Site-local decisions live at `policy.scope.post_type.<name>.class` or `policy.scope.taxonomy.<name>.class`. `authored` adds the name to scope; `runtime`, `derived`, or `env` records a deliberate exclusion. Missing disposition blocks capture naming the surface, entity count, and exact policy fix; it never quietly shrinks the tree. `wp duo pending` reports keys such as `scope:post_type:book`, and `wp duo classify --set='scope:post_type:book=runtime'` persists an audited exclusion.

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

Compilation batches stable blocking diagnostics for malformed or unknown entity kinds, invalid/duplicate UUIDs, duplicate natural identities (`post_type + slug + parent`, `taxonomy + slug`, or a declared table natural key), conflict markers, graph references whose target was deleted or has the wrong kind, unsafe/duplicate attachment paths, missing or mis-hashed media, schema/content mismatches, and pinned adapter constraints. ACF's schema/value checks use the same manifest-shipped interpreter trust boundary as classification—never the installed plugin. The DUO-3203 policy-authorization pass is the final compiler layer and preserves its existing structured failure contract.

Plan, apply, and deploy construct or load this artifact before target contact and accept only the `CompiledRepository` type internally—never a raw tree array. `--compiled=<artifact.json>` reuses a previously emitted artifact. All phases consume its decoded data/body/media payload and never reopen mutable `state/` or `media/` files after compilation; a failed compilation therefore creates no ledger and performs no target read, lifecycle call, rebuild, filesystem materialization, or database write.

## Apply semantics (v0)

0. **Offline compilation + repository authorization**: compile the complete immutable revision into the verified artifact above before any target contact. Its final layer checks every repository-carried post-meta key, option, typed-table column, and attached-meta key against current policy. The three managed code options and managed menu/attachment fields are accepted only through named dedicated routes. Unknown, runtime, derived, environment, stale-policy, and misplaced managed fields preserve the stable `{ok:false,error:"repository_authorization_failed",diagnostics:[...]}` contract; all earlier semantic failures return `{ok:false,error:"repository_compilation_failed",diagnostics:[...]}`.
1. **Plan**: for each entity file: `create` (uuid not in map), `update` (canonical hash ≠ `duo_state` hash), `unchanged`; ledger'd uuids absent from state → `delete` (listed; executed only with `--with-deletes`). Env drift = env's current canonical hash ≠ `duo_state` hash → surfaced per entity.
2. **Canary armed**: listeners on `save_post`, `transition_post_status`, `created_term`, `wp_insert_comment` + `pre_wp_mail` + `pre_http_request`; any fire during apply = hard failure.
3. **Phase 1** — upsert rows (posts, terms) with placeholder refs, direct `$wpdb`; mint local ids; write `_duo_uuid`.
4. **Phase 2** — resolve refs through the ledger: parents, metas, term relationships, menu structure, option values, body detokenization (block registry restores numeric types).
5. **Rebuild** — canary disarmed: term recounts (direct SQL), attachment metadata regeneration, cache flush; harness-level: rewrite flush, thumbnail regen.
6. Ledger + `duo_state` hashes updated; `applied_revision` set.

Snapshot/rollback is the orchestrator's job in v0 (`wp db export` before apply).

## Code-half facts & deploy (spec v0.9 — docs/proposals/code-half.md phase 1)

- `active_plugins`, `template`, `stylesheet` are **managed-class** core-manifest options: captured bespoke into `state/options/core.json` (plain portable strings — plugin file paths and theme slugs need no tokenization; their cross-environment stability *is* the invariant), and **excluded from apply's generic direct-SQL path** — a raw options UPDATE would skip activation/switch hooks while leaving WordPress believing the code is active.
- **`wp duo deploy --repo=<p>`** is the one sanctioned side-effect step: it reconciles activation state to canonical via real `activate_plugin()`/`switch_theme()` calls, deliberately outside the canary window, and is idempotent. Ordering: deploy code → migrations fire via activation → then `apply` state.
- **Plan's `code_mismatch` bucket**: `missing_in_code` (canonical wants an activation whose plugin is absent from the environment's code) and `outside_version_range` (a pinned manifest declares `{"plugin": "<file>", "version_range": {"min", "max"}}` and the installed version falls outside — two `version_compare()` calls, deliberately not a semver parser). Both `deploy` and `apply` refuse while the bucket is non-empty, unless `--force-code-mismatch`, which proceeds but still reports the findings. `code_revision_stale` is phase 2 (requires a materialization transport).
- Operational note: with a plugin active in the DB but missing from disk, the `wp plugin deactivate` *command* refuses (it pre-resolves its argument against a disk scan) — but `wp duo deploy` handles this case fine: it calls core's `deactivate_plugins()` directly with basenames from the option, no disk resolution on the deactivation side (verified live). Manual `update_option('active_plugins', …)` surgery is the last resort only when duo itself is unavailable.
