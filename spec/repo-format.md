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
- Meta ref rules may declare `"cast"`: `"string"` (ids stored as strings inside serialized arrays — the ACF shape) or `"csv"` (a `"1,2,3"` id list canonicalized to a token array, re-joined on apply). Ref kind `"user"` serializes as `user:<login>` tokens — users stay env-local; apply resolves by login and falls back to the default author with a warning.
- `"rebuilders": [{"command": "yoast index"}]` — wp-cli commands run in the rebuild pass after a non-empty apply: the hooks apply deliberately skips are also what maintain plugin derived state (indexables, lookup tables), so manifests declare the regeneration command instead.
- `"delete_guards": {"post:product": [{"table": "wc_order_product_lookup", "column": "product_id", "reason": "orders reference this product"}]}` — plan-time referential check: matching rows mark the delete **BLOCKED**; `apply --with-deletes` refuses unless `--force-delete-referenced`.
- **Structured-value refs** (spec v0.7): a meta/option rule may declare refs *inside* a JSON-or-PHP-serialized value. `"json_refs": [{"path": "$.*.*.wpseo_opengraph-image-id", "kind": "post", "cast": "string"}]` rewrites id **values** at declared paths (minimal dialect: `$` root, `.` key steps, `*` wildcard); `"key_refs": {"path": "$.*", "kind": "term"}` rewrites entity-id **keys** of the map at the declared path (the `wpseo_taxonomy_meta` shape). Everything undeclared inside the structure is byte-preserved except string leaves, which get ordinary URL tokenization; unmapped ids follow the drop-with-warning rule. The tokenizer also matches **JSON-escaped URL forms** (`https:\/\/…`, Elementor's convention): both forms collapse to one plain-spelled token, and structural re-encode restores the host convention on apply (RFC 8259 makes `/` vs `\/` equivalent inside JSON strings). `wp duo lint` treats declared paths as owned and still flags id-shaped values at *undeclared* paths inside the same structure — the linter catching manifest gaps is its purpose. `option_patterns` has a meta twin: `"meta_patterns"` (versioned keys like `_elementor_migrations_*`).

## Ledger tables (per environment, never in the repo)

| Table | Purpose |
|---|---|
| `duo_map(uuid, entity_type, id_kind, local_id)` | typed identity map |
| `duo_state(uuid, entity_type, content_hash)` | canonical hash at last capture/apply — drift & 3-way base |
| `duo_kv(k, v)` | `applied_revision`, guid pins, config |
| `duo_journal(...)` | provenance journal (Spike C; runtime data, prunable) |

## The review queue (spec v0.6 — the core loop)

Unclassified state is never silently captured *or* silently skipped; it queues for human triage:

- **`wp duo pending --repo=<p> [--format=json]`** — the review queue. Items merge three sources: the classification **gate walk** (unclassified post/term-meta keys on in-scope entities, with entity counts and post types — the same scope and rule lookups capture uses, so they can never disagree), **journal-observed unclassified option writes** (options are whitelist-only at capture, so the journal is what surfaces them — finding #5's answer), and annotations: a **ref hint** when the current value is a numeric id that exists in wp_posts/wp_terms (finding #9's linter seed; small ids can coincide — hints are hints), and a **secret flag** (`hard:<label>` on high-confidence patterns — Stripe/AWS/GitHub/Slack keys, PEM blocks, JWTs — or `suspicious` on key-name+shape heuristics). `proposal` is only ever the journal's capability×surface signal; with no journal evidence it is `null` — never guessed. Unclassified **term meta** is surfaced but does not abort capture: v0 term files carry no meta values at all, so there is nothing a classification could yet make capturable.
- **`wp duo classify --repo=<p> --set='<section>:<key>=<class>[,ref=<kind>][,cast=<c>]; …'`** — writes rules into `site.duo.json` policy. All decisions travel in ONE semicolon-joined `--set=` (wp-cli keeps only the last occurrence of a repeated assoc flag, and the space-separated form parses as a boolean — both documented traps). Classifying a key as `authored` while its current value hard-matches a secret pattern is **refused** unless `--allow-secret` (which records `allow_secret: true` on the rule).
- **Secret guard at capture**: any authored-classified option/post-meta string value that hard-matches a secret pattern **aborts capture** naming the key (the rule-level `allow_secret: true` is the escape hatch for false positives); post bodies warn loudly but never block. Values over 64KB are skipped.
- **`wp duo policy-to-manifest --repo=<p> --match=<regex> --name=<n>`** — exports matching policy rules as a canonical manifest JSON on stdout (policy is left untouched; moving rules upstream is a deliberate human act). A pinned exported manifest reproduces byte-identical captures to the policy it came from.
- **Dangling references**: an unmapped id in a ref-typed meta value is **dropped with a warning** (array/csv: the element; scalar: the whole key), mirroring options' long-standing semantics — a raw env-local id in canonical state is indistinguishable elsewhere from a valid id and may silently point at an unrelated live entity after auto-increment reuse. Convergence comes through the repo: the corrected canonical value applies everywhere.
- **`wp duo lint`** — the suspicious-ref gate byte-diffing cannot provide (wrong bytes written once read back faithfully): flags `bare_id` (numeric values matching existing entity ids under rules with no declared ref), `escaped_home` (JSON-escaped env URLs the tokenizer's plain-form substitution misses), `unregistered_block_attr` (id-shaped attrs in blocks with no registry rule, and URL-shaped string attrs), and `serialized_desc_ids` (id-bearing serialized term descriptions). Exit 1 on findings; the conformance harness runs it as a hard gate. A rule may declare `"lint_ok": true` — an explicit, auditable human review meaning "numeric but genuinely not a ref"; it works on option/meta rules (e.g. `posts_per_page`) and as a block_attrs entry (e.g. `queryId`, a query instance index — the rewriter skips such rules entirely). The only sanctioned exemption.

The orchestrator surfaces this loop as `duo pending <env>` and `duo classify <env>` (interactive stdin triage; Enter accepts a proposal, explicit keys override, secrets require typing "allow"; `--accept-proposals` for CI, which never auto-authors a secret) — see cli/README.md.

## Apply semantics (v0)

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
