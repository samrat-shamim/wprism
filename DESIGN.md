# WPrism — Branchable WordPress

*Design document v0 — 2026-08-05. Status: founding design record — the direction here is ratified and built. The normative contract is [spec/repo-format.md](spec/repo-format.md); certified product claims come only from the [generated capability document](docs/capabilities.md); current direction lives in [docs/roadmap.md](docs/roadmap.md). Where this narrative and the spec disagree, the spec wins; known drift is annotated inline as "as built".*

WPrism makes a WordPress site **branchable like code** — branch, edit, merge, promote — under three constraints:

1. **Plugins/themes work completely unmodified** (no ecosystem cooperation required).
2. **Authored content/configuration lives in a git repository** and propagates across branches.
3. **Runtime data** (orders, comments, form entries, sessions, caches) **stays environment-local and unharmed**.

This document was produced from a first-principles brainstorm and then hardened by an independent adversarial review before any code was written — see [the original design review](docs/history/design-review-v0.md) for the full 27-finding register (prior art verified: VersionPress archived 2020, Mergebot shut down 2018, Pantheon multidev docs). The findings are folded into every section below.

## 1. First principles: what *is* a WordPress site's state?

Classify every piece of state along three orthogonal axes:

| Axis | Values |
|---|---|
| **Author** — who writes it | developer (code) / site-admin (content, settings) / the world or the machine (visitors, cron, plugins acting autonomously) / **external systems** (scheduled imports, ERP/PIM syncs) |
| **Portability** — does it mean the same thing elsewhere? | env-independent (a page, a plugin setting) / env-bound (siteurl, API keys, salts, file paths) |
| **Derivability** — source or derived? | source (canonical) / derived-regenerable (transients, object cache, thumbnails, rewrite rules, lookup tables) |

**The branchable partition:** `human-authored ∧ portable ∧ source`. Everything else is **env-local** (runtime, env-bound), **rebuilt** (derived), or **re-synced** (externally-sourced data re-syncs per environment rather than propagating through git).

WPrism is machinery to (1) enforce this partition on a system that never made the distinction, (2) represent the branchable partition canonically in git, (3) materialize it into any environment safely.

Examples: `blogname` → authored · `_transient_*` → derived · `siteurl` → env-bound · a page → authored · a Woo order → runtime · a Woo product → authored **except its `_stock` meta → runtime** (classification is field-granular) · ERP-synced products → external/re-sync · `cron` option → runtime · API key in options → env-bound secret. Field-granularity reaches **post fields themselves** since round 3 (spec v0.11): a plugin-owned, self-healing field like a variation's `post_title` classifies `derived` per post type — captured verbatim for humans, excluded from the hash basis plan/drift compare against, never overwritten on update (tasks #72/#88).

**Posture principle (the meta-lesson from prior-art deaths):** loud, blocking, *scoped* guarantees — never quiet best-effort. For a state-merge tool, 95% correct is negative value: the failing 5% is silent corruption.

## 2. The four hard problems (why "just git the DB" fails)

1. **Conflation** — the same tables mix partitions: `wp_options` holds `blogname` + transients + cron; `wp_posts` mixes pages, nav items, FSE templates, and (pre-HPOS) Woo orders; `wp_users` mixes editors and customers. → classification must be row/key/field-granular.
2. **Identity** — AUTO_INCREMENT ids are environment-local but referenced everywhere: postmeta, menu items, serialized options, Gutenberg block attrs, shortcodes. And the ID space is **typed**: `term_id` vs `term_taxonomy_id` vs post/user/comment ids are distinct keyspaces used inconsistently. Two branches inserting rows = guaranteed collisions on merge.
3. **Opacity** — plugins store arbitrary serialized/custom-table data with zero declared semantics, and must work unmodified.
4. **Side effects** — writing through WP APIs fires hooks (emails, webhooks, cron scheduling); but hooks are also what maintain *derived* state (Yoast indexables, Woo lookup tables, term counts). Apply must neither re-fire effects nor leave derived state stale. Capture must not disturb the running site.

## 3. Design answers

### 3.1 Layered classification policy (vs. conflation & opacity)

Precedence order:

1. **Core schema rules** — WP core's tables/keys are fully known; classify exactly. Includes the full map of *ref-typed option keys* (`page_on_front`, `page_for_posts`, `sticky_posts`, `default_category`, `site_icon`/`custom_logo`, `theme_mods_{theme}` internals like `nav_menu_locations`/`custom_css_post_id`, `widget_*`/`sidebars_widgets`, `wp_page_for_privacy_policy`). Shipped from [platform/adapter-library/core/manifest.json](platform/adapter-library/core/manifest.json).
2. **Plugin manifests** — versioned artifacts **pinned to plugin version ranges** (Woo HPOS moved orders to custom tables — a manifest for Woo 7 must not claim Woo 9), field/meta-key granular (Woo: products authored, `_stock`/`total_sales` runtime, `woocommerce_db_version` env-local, `woocommerce_*_page_id` ref-typed). Site repositories may additionally content-pin each manifest with the exact per-manifest digest already recorded in compiled artifacts; a changed manifest, interpreter, or external disposition then refuses at policy load until the reviewed pin is explicitly updated (`wp wprism manifest-pin` emits the copy-pasteable object). Name-only pins remain supported for deliberate incremental adoption. Manifests can be *interpreters* where semantics are schema-driven: the ACF manifest reads field-group definitions in the repo to type meta values — and interpreter *code* ships beside it in `adapter-packages/acf/package/runtime/`, as part of the adapter artifact, never in the engine tree (the engine holds only the plugin-blind loading contract). The required post-meta hook may be joined by optional term-meta and user-meta hooks; each sees the owning object's complete meta map, wins over static rules, and returns null to defer without widening older interpreters. Users remain environment-local non-entities: user-meta runtime/env/derived classifications stay target-local, while explicitly `authored` keys use a login-keyed, PII/secret-gated sidecar that never mints a user UUID or creates a missing user. Each capsule keeps its reviewed `package/disposition.json` separate from `package/manifest.json`, so declaration cannot imply certification. The assembler combines those packages with [platform/adapter-library/](platform/adapter-library/) into the installed `agent/adapter-library/`; package-local conformance and participant-declared integration scenarios remain non-shipping evidence. (*as built:* the old generated registry and evidence bundles were retired. `wprism capabilities` projects `wprism-capability-report/v1` from reviewed dispositions and a live target probe, the [capability document](docs/capabilities.md) explains the claim model, `tools/capability-doc.php render` computes current rows, and `make release-gate` validates their package-owned sources. Version-mismatched, multisite, uncovered, experimental and excluded claims remain non-green; *expired evidence* no longer exists as a state, while plugin `version_range` gating survives as `code_mismatch`.) Plugin execution without source modification is reported separately from branchable authored-state support.
3. **Site policy overrides** — `policy.yml` in the site repo.
4. **Provenance observation** (proposal generator, *never* authority) — the capture agent journals writes at **(table, key/column) granularity** with the executing hook stack and calling-plugin attribution (sampled backtraces). The authored signal is **actor capability × surface**: an actor with `edit_posts`/`manage_options` *on an authoring surface* (wp-admin settings POST, editor REST save, customizer save). Bare "authenticated" never proposes authored — logged-in customers write runtime data constantly (checkout, quiz attempts, front-end forums), and `admin-ajax.php` is `is_admin()` even for subscribers. Cron classifies by executing hook context (production cron often runs *via wp-cli*, so "CLI ⇒ authored" is wrong; authored CLI ops need an explicit intent flag). Cron-borne *authored* content (scheduled imports, scheduled publishing) is handled by the external/re-sync class + import-plugin manifests, not guessed.
5. **Loud-and-blocking default** — unclassified data never silently enters *or* silently skips the repo: capture/merge/apply **fail (or demand explicit ack)** when in-scope entities have pending-unclassified writes; unclassified drift appears in the plan preview. A soak window auto-*proposes* classifications (write frequency, anon-write ratio, value churn) so human review is triage, not authorship — and accepted decisions persist to `policy.yml`, shareable upstream as draft manifests. Real usage crowdsources coverage as *review*, not authorship (Mergebot built a schema-generator for exactly this reason). *Status: built and spike-proven (Spike F) — `wp wprism pending` (journal-evidenced proposals, ref hints, secret flags) → `wp wprism classify` / interactive `wprism classify <env>` → `wp wprism policy-to-manifest`; see spec "The review queue".*

Secret guard: pattern denylists first (`sk_live_`, `AKIA`, `ghp_`, …) + manifest env-bound defaults; key/name-plus-shape heuristics are the backstop. *As built:* capture blocks high-confidence tokens, credential-shaped declared values, and labelled credentials embedded anywhere in canonical prose or nested data. The same clearance is re-run by repository authorization so a Git edit cannot bypass capture. PII clearance likewise covers every canonical surface (structured key signals plus embedded email, IP, and phone shapes). A structured authored rule may carry an exact reviewed `allow_secret: true` or `allow_pii: true`; unruled post/term/menu prose instead must be removed, redacted, or excluded with its owning type. Environment bindings stay outside the repository.

### 3.2 Stable identity + reference rewriting (vs. identity)

- Mint a UUID per entity at capture, stored invisibly (`postmeta`/`termmeta` `_wprism_uuid`); options keyed by name. Canonical files keyed `uuid + slug`.
- Per-environment **ledger**: `(uuid, entity_type, id_kind) ↔ local id` — `id_kind` is a closed keyspace label (*as built:* `post`, `term`, `term_taxonomy`, manifest-declared table kinds, and the per-type widget families); every rewriter declares which kind a field holds. Users never enter the ledger — they are non-entities resolved by `user:<login>` token — and comments are runtime data, not entities.
- **Structure-aware rewriters** (never regex-on-strings): a **block-rewriter registry** keyed by block name with typed attribute paths — `core/image` (`"id":N`, `wp-image-N`), `core/block {"ref":N}` (reusable blocks), `core/navigation {"ref":N}`, gallery `"ids":[…]`, query-loop taxQuery term ids, cover media — plus href-form internal links (`?page_id=N`, permalinks); declared shortcodes; JSON-path ref declarations in manifests for serialized blobs.
- **FSE content is first-class**: `wp_template`, `wp_template_part`, `wp_navigation`, `wp_block` CPTs are authored entities (block themes store site structure in the DB).
- **Menus**: `_menu_item_object_id` is polymorphic (typed by `_menu_item_type`); menu-item parents are self-referential post ids; menu→location assignment lives in `theme_mods`. The core manifest handles all three.
- `post_author` and all user refs (a general **user-token ref type**: login/email) — users are env-local; apply maps by login, falls back to a configured default author with a plan warning; never auto-creates users.
- `guid` is env-derived: generated deterministically per env on first apply, pinned in the ledger, excluded from diffs.
- Plan-time **slug-uniqueness check** on `(post_type, slug, parent)` so two branches creating `/about/` resolve explicitly instead of silently auto-suffixing.

### 3.3 Canonical repo representation (vs. opacity; the thing that makes merge possible)

- **Entity-per-file**, deterministic serialization: sorted keys; PHP-serialize → canonical structured form *for plain data only*. Non-plain serializations (PHP objects `O:…`, e.g. Beaver Builder) get a **verbatim-preservation path**: original bytes kept, refs rewritten only via safe in-place token substitution, or refused with a loud warning — never round-tripped through a foreign format. *As built:* the shipped preservation forms are per-post-type whole-body verbatim (`body: "verbatim"`) and opaque-byte sidecar meta (spec "Entity files"); a general in-place ref-substitution path for PHP-object serializations has not shipped, and such values remain outside certified scope. **Crack in the sorted-keys assumption, closed (grind round 3, task #123 → issue #3214)**: sorting is only safe when no consumer reads a value's raw iteration order — WooCommerce's variation-title generator reads `_product_attributes`' array order directly, so canonicalization permanently reordered it on every applied target (causation-proven, signature confirmed on two fixtures). *As built:* an opt-in `"order_preserving": true` declaration on a meta rule (spec v0.13, sibling to `json_refs`/`cast`) wraps the captured value in `WPrism\OrderPreserved`, which `Canon::normalize()` recognizes and recurses through without ever calling `ksort()` — scoped to the declared value and everything nested inside it, never a document-wide behavior change. `adapter-packages/woocommerce/package/manifest.json`'s `_product_attributes` is the first (and, per an adapter-library audit, so far only confirmed) user; the apply side needed no changes at all, since `json_decode()`/`maybe_serialize()` were already order-preserving by construction — `Canon::normalize()`'s `ksort()` was the only place order was ever lost. The r1b/r3a grind scripts' anagram-checked title carve-out is gone: both now assert true zero-exclusion byte identity, confirmed live on two independent attribute orderings.
- **Option tombstones authorize from one immutable tree**: `wprism-options/v2` may retain a prior option's value/autoload as a hash-bound `classification_witness`, but only when the current interpreter rule explicitly marks that value as the minimum deletion-classification context. The current pinned interpreter still re-derives authorship; the wire never trusts a bare provenance flag. The first use is ACF's low-sensitivity `_options_<name> → field_…` shadow pointer, which lets a cold clone classify both halves after ACF atomically removes the value and shadow rows without retaining the deleted authored value itself. Witnesses are never applied as desired data, and ordinary non-authored tombstones remain blocked.
- **Post files are front-matter + raw body**: fields/meta/terms as canonical-JSON front matter, `post_content` as raw Gutenberg HTML below the `---` separator. Rationale: the body merges in git as plain lines (an escaped-into-a-string body would make every content merge a conflict), while remaining byte-faithful to what plugins expect.
- **URLs/paths tokenized at capture** (*as built:* `{{home}}`, `{{uploads}}`, `{{post:<uuid>}}`, `{{term:<uuid>}}`, `{{tt:<uuid>}}`; media binaries are referenced by their sha256 filename rather than a token, and uuid-precise `{{link:<uuid>}}` remains reserved for a future format revision) — serialized-length corruption can't happen because we operate on parsed structures and re-bind per environment at apply. JSON-escaped URL forms (`https:\/\/…` inside `_elementor_data`-style meta) were originally a gap (found empirically: `_elementor_data`'s stored bytes are `5c 2f` — a literal backslash then `/` — because Elementor encodes it without `JSON_UNESCAPED_SLASHES`, so the plain-string tokenizer never matched); the tokenizer now matches both spellings and collapses them to one plain-spelled token, with structural re-encode restoring the host convention on apply (spec v0.7).
- **Media**: binaries content-addressed (sha256), stored via git LFS in real site repos (plain files in the sandbox); the attachment *entity* is a normal authored file; `_wp_attachment_metadata` is derived → regenerated.
- **Custom tables**: opaque snapshots are **refused** for tables with FK-like columns (int columns whose values join posts/terms/users) — authored custom tables almost always embed local ids (WPML `icl_translations`, Ninja Forms `nf3_*`), so pick-side replay would corrupt silently. The middle tier — **typed snapshot** with column-level ref declarations — is now implemented (grind round 2, task #75): manifest-declared `tables` with per-column classification, ledger-only identity (mapped UUIDv7 or natural-key-derived UUIDv5), structural row refs vs optional sidecar refs, and declarative cache invalidation; proven on Ninja Forms (`nf3_*`) and `woocommerce_attribute_taxonomies` (spec v0.10); extended in round 3 to the WooCommerce shipping-zone/tax-rate families plus **option-name-embedded refs** (`option_name_refs`, spec v0.12 — options whose *name* carries another table's id, pattern-discovered and re-materialized per environment). Known grammar edges filed with acceptance criteria: derived tables with hard availability dependencies (#124), composite primary keys (#125), sidecar PK name override (#126). WPML/Polylang are manifest-mandatory, never opaque-eligible.

Site-repo layout (full contract in [spec/repo-format.md](spec/repo-format.md)):

```
site.wprism.json                # policy: classifications, env defs, manifest pins
code/                        # optional v0 code half: exact vendored bytes
  wp-content/plugins/
  wp-content/themes/
  wp-content/mu-plugins/     # site-owned mu-plugins; WPrism's agent is out-of-band
state/
  options/core.json
  posts/<type>/<uuid>--<slug>.md    # canonical-JSON front matter + raw block-HTML body
  terms/<taxonomy>/<uuid>--<slug>.json
  user-meta/<sha256(login)>.json
  menus/<slug>.json
  sidebars/<sidebar_id>.json
  deletions/<uuid>.json             # explicit, versioned deletion intent
media/<sha256>.<ext>         # LFS
```

The per-env ledger (applied revision, typed uuid↔id map, drift hashes) lives in the environment's DB (`wprism_*` tables), **not** the repo. Env-bound values bind via per-env config, never committed.

### 3.4 Safe materialization — GitOps semantics (vs. side effects)

The repo is the declarative source of truth for the branchable partition; environments are materializations.

The code and state halves are separate engines with one deliberately narrow
lifecycle bridge:

```text
code/  -> Code descriptor -> stage / verify / finalize -> code_revision
                              |
                              +-- active_plugins / template / stylesheet
                              |
state/ -> State artifact  -> plan / canary / apply      -> applied revision

               artifact_hash binds both revisions for promotion ordering
```

`Code.php` inventories and mutates executable files; it never parses or applies
canonical entities. `Apply.php` plans and mutates canonical database state; its write path
never copies, deletes, or claims code paths (the read-only plan preview
consults code target-compatibility rows for mismatch diagnostics only). `CodeStateContract.php` is the sole
cross-half validator: it proves that the three database-held lifecycle
identities can be satisfied by the opaque code descriptor. Each half keeps its
own revision and completion marker. The outer compiled artifact binds them so a
promotion cannot mix code from one revision with state from another.

- **`wprism capture`** — ledger-vs-DB diff is the ground truth for *what* changed; the provenance journal explains *why* (classification proposals). Reviewed entries become canonical files/commits. "git add -p for the database." *As built (issue #3213):* every read runs inside one InnoDB `START TRANSACTION WITH CONSISTENT SNAPSHOT` (refused loudly upfront on any non-InnoDB table in scope — MyISAM has no MVCC to back the guarantee) with a bounded retry on deadlock/lock-wait-timeout. Identity/map changes, candidate staging, lint/compile gates, the atomic two-rename tree swap, ledger hash advancement, and a destination-bound commit marker now share that transaction's publication protocol. The old tree is retained until the commit marker is durable; recovery keeps the candidate when the marker committed and restores the backup (or removes a first-capture candidate) when it did not, while contradictory evidence fails closed. A per-destination lock serializes publishers. **Operational tradeoff:** the consistent-snapshot transaction remains open while canonical files are written, hashed, and compiled, so very large sites hold MVCC history and capture's own row locks longer; schedule capture accordingly and monitor database undo/history pressure.
- **`wprism deploy`** — compiles one immutable artifact, copies descriptor-verified additions/updates from the opt-in `code/wp-content` payload, runs plugin/theme lifecycle reconciliation while outgoing code still exists, then prunes only WPrism-owned obsolete component paths and records `code_revision` after target-byte verification. The completed descriptor/revision and removal of every temporary stage marker publish in one database transaction, so an interrupted handoff remains exactly retryable. This first transport intentionally owns neither WordPress core nor the agent executing it; Composer/full-webroot builders can be added later by emitting the same descriptor contract.
- **`wprism apply`** — three-way against the env's ledger → **terraform-style plan preview** (creates/updates/deletes + drift + unclassified warnings) → DB snapshot backup → **two-phase apply as the engine default** (insert rows with placeholder refs, then a resolve/fixup pass — post_parent chains, menu-item parents, reusable-block nesting form graphs with possible cycles) via direct low-level writes (no WP hooks ⇒ no emails/webhooks re-fire) → **rebuild pass**: core rebuilders (*as built:* taxonomy counts through each registered taxonomy's count callback when post/term/menu work or deletions can affect counts (conservative full recount during incomplete-Apply recovery), future-post cron, object cache flush, attachment metadata after both creates and updates — comment recounts and rewrite-rule flushes are not part of the shipped pass; comments are runtime and unmanaged) + per-plugin **rebuilders declared in manifests**, selected by exact changed surfaces when a manifest supplies triggers. WooCommerce product/variation writes use a bounded product batch for effective prices, `wc_product_meta_lookup`, and per-product sale schedules. `wc_category_lookup` and `wc_product_attributes_lookup` are deliberately outside the automatic verified guarantee because WooCommerce 11.0.0 exposes no bounded independent value oracle for either surface; operators must regenerate and verify them explicitly. These boundaries matter because the hooks we skip are also what maintain derived state → ledger update.
- **Concurrency**: apply races live traffic (a stock decrement mid-apply). *As built (issue #3217/code-half skeleton):* a bounded target-DB row serializes handoff across deploy/apply processes, a durable latest-session identity plus strict continuation prevents an obsolete checkpoint from creating a fresh row or advertising stale recovery, and a per-process database advisory fence covers long hooks/filesystem walks even when the row TTL passes mid-call. Apply re-plans under that fence immediately before writes and re-runs reverse-reference guards at each delete. Public reads and unrelated runtime writes remain available. A broader traffic-pausing mu-plugin write gate remains production-promotion work rather than being implied by this scoped gate.
- **Guards**: referential checks before deletion only for closed, declared selectors. Woo standalone-product deletion is admitted only when the Woo live tree matches both an exact adapter-reviewed executable identity and its site v2 agreement, every other executable owner (active/network plugin, active parent/child theme, MU plugin, and drop-in) has an exact code-identity-bound v2 agreement, the signed external writer exclusion remains exact through the final pre-COMMIT check, and locked guards prove no comment, child-post, grouped-product, order, download, menu, reservation, or stock-notification reference. The deletion plan selects a dedicated database-contained cleanup for lookup rows, pending sale actions, and reviewed transients; it does not inherit the ordinary product/variation rebuild's irreversible hook surface. A guard may declare `table_absence: "empty"` only for a table the same manifest owns: an exact information-schema census then binds physical absence as a distinct zero-reference witness and re-censuses it immediately before commit. Present tables retain the ordinary metadata/InnoDB/index/row-lock proof; mixed required/absence-means-empty declarations, probe errors, and near matches refuse. Variations, non-forceable references, missing/unreadable owner evidence, required guard-table absence, global attributes, and shipping/tax deletion remain loud refusals. Cross-partition invariant (`active_plugins` ⊆ plugins in `code/`); drift detection with capture-first workflow.
- **Branch** = git branch + an environment materialized from it. Default feature-env workflow (Pantheon's content-freeze exists for a reason): **materialize fresh from a prod snapshot + apply the branch delta**, branch TTLs, drift report as CI status, prod-content-is-truth as default conflict bias for runtime-adjacent disputes.
- **Merge** = git merge of canonical text — entity-level three-way; deterministic formatting keeps most merges clean; genuine editorial conflicts surface exactly like code conflicts. (v2: field-aware Git merge drivers for ordered structures like menus.) The separate host-only refresh/rebase resolver may compose its explicitly allowlisted scalar groups from verified B/P/W snapshots; it is not a Git merge driver and keeps ordered/opaque structures record-atomic. Plugin version skew across branches: merge **code first**, run migrations, re-capture, then merge state (capture records plugin versions; apply warns on mismatch).

## 4. Architecture components

1. **Repo format spec** ([spec/repo-format.md](spec/repo-format.md)) — the versioned contract; the most important artifact.
2. **`wprism` CLI** — host-agnostic orchestration for capture, plan, code deploy, state apply, and composed promotion over local, Docker, and SSH transports. Git remains the branch/merge layer.
3. **mu-plugin capture agent** ([agent/](agent)) — (table,key)-granular write journaling with hook-stack provenance, ledger tables, apply executor + write-gate, hook-fire canary; transparent to plugins. Journal runs as learning mode that decays to sampling once classifications stabilize (the wpdb `query` filter is noisy: it fires on reads and can't see `insert_id`/rollbacks — hence ledger-diff as ground truth). Fallback checksum-scan reconciler for direct-mysqli writers.
4. **Adapter library** ([adapter-packages/](adapter-packages), [platform/adapter-library/](platform/adapter-library/)) — core rules plus version-pinned plugin capsules, each with a separate reviewed disposition and package-local evidence. Adoption embeds the assembled result in `agent/adapter-library/`; authoring one adapter does not edit the engine or a global registry. Conformance runs are live checks a claim must pass, not evidence records sealed to a run; certification still requires a reviewed disposition with a written reason.
5. **Merge layer** — git-native first; custom merge drivers later.
6. **Env orchestration** — WPrism owns sequencing, immutable artifacts, and target leases, not infrastructure provisioning. [sandbox/](sandbox) Docker environments are development/test fixtures only.

**v0 implementation decisions** (deferred at planning, decided at scaffold):

- **Engine language: PHP, packaged as a wp-cli command set inside the agent.** Running in-process with WordPress gives native `unserialize`, the official block parser (`parse_blocks`/`serialize_blocks`), and `$wpdb` — the three things the engine lives on. The *orchestrator* CLI ([cli/](cli)) is also dependency-free PHP for v0.5: WP shops always have PHP, and shipping without a composer/npm toolchain matters more for a drop-in tool than CLI-framework ergonomics (revisit if a daemon/UI emerges). It wraps per-env `wp wprism …` over local/docker/ssh transports; git stays git.
- **Canonical format: canonical JSON (sorted keys, pretty, LF) for structured entities; front-matter+raw-body for posts.** JSON keeps the drop-in mu-plugin dependency-free (no YAML parser ships with WP/PHP) and makes byte-determinism trivial; git-mergeability of post *bodies* — the part that matters — is preserved by the front-matter+raw-body format (see 3.3). YAML stays an open revisit as a mechanical `spec_version` bump. Spike A's capture-twice determinism test validates the emitter.

## 5. Non-goals

- Multi-master replication (two prods diverging) — one authored truth, many materializations.
- Versioning runtime data (orders, comments, analytics).
- Requiring anything from plugin authors.
- Multisite (v1). Opaque custom-table snapshots (refused on FK-bearing tables, by principle); typed snapshot shipped early (grind round 2) — see §3.3.
- Silent best-effort coverage of unknown plugins — out of principle, not just scope.

## 6. Prior art & lessons (verified — sources in [the original design review](docs/history/design-review-v0.md))

- **VersionPress** (git-tracked DB, VPIDs; archived 2020 — "a great proof-of-concept" needing "several person-years"): the mechanics work; per-plugin definitions + maintenance killed it. Its planned community-definitions repo never materialized — so "community manifests" must be *review* of auto-generated drafts, not authorship, and the manifest treadmill is a budgeted permanent cost for top-N plugins.
- **Mergebot** (Delicious Brains; shut down 2018): built exactly this manifest layer (public `mergebot-schemas` + a schema-generator) and still couldn't meet its own quality bar — hence the scoped-guarantee posture and conformance harness. (*as built:* the treadmill mitigation is now **review plus live tests**. Of the four mitigations named in §8's existential #2, three stand — scoped guarantees, version-pinned manifests, draft-manifest generation — and the conformance harness stands as a live gate. What was retired is the sealed evidence record that once bound each claim to a content-addressed bundle: a claim is now declared and reviewed inside its adapter capsule, then exercised by package-local or participant-declared integration evidence. That is weaker in kind than a bound record, and the lesson these two predecessors teach is precisely why it must stay honest about being weaker rather than keep the old vocabulary.)
- **Pantheon / WP Engine multidev**: branch environments exist; DB is clone-only, "code up, content down," content freeze during long branches. The merge path is exactly the market gap — and their content-freeze discipline informs our default branch workflow.
- **Bedrock/Composer**: strong dependency/build inputs for one deployment mode, but not by themselves a target materialization, ownership, lifecycle-ordering, or premium/private-code contract. WPrism can consume a future Composer build through the same opaque descriptor boundary without coupling the state engine to Bedrock.
- **WXR / wp-cli search-replace**: lossy exactly where v0 aims (serialized meta, menu assignments) — validates the uuid + structure-aware-rewriter + tokenization bet; menus stay in v0 as the canary.
- **ACF local JSON, GF form export**: the ecosystem accepts config-as-files.

## 7. v0 scope decisions (2026-08-04)

1. **Product form: host-agnostic toolchain.** CLI + mu-plugin + repo format spec, working against any environment via wp-cli/SSH/HTTP bridge. No hosting/orchestration platform in v1.
2. **Merge model: entity-level git merge.** Canonical text + git's native 3-way; field-aware merge drivers deferred to v2.
3. **Capture model: continuous journal + explicit commit.** Provenance-tagged journaling; `wprism capture` reviews → commits. No auto-commits.
4. **v1 certified scope.** Exactly the entries, ranges, operations, and surfaces in [docs/capabilities.md](docs/capabilities.md), never a duplicate handwritten list here. Experimental declarations may be exercised by tests but never advertised as promotion-ready capability. The "never a duplicate handwritten list" rule is why `tools/capability-doc.php` projects that document and the README summary from `adapter-packages/*/package/{manifest,disposition}.json`, `platform/adapter-library/`, and `agent/wprism.php`, and why `make release-gate` byte-compares the result.

## 8. Risk register (summary — full text in docs/history/design-review-v0.md)

- **Existential #1 — silent authored-data loss** via an unreviewed classification queue → the loud-and-blocking gate (3.1.5); must never regress to quiet skipping.
- **Existential #2 — the manifest treadmill** under an absolute reliability bar (killed both predecessors) → scoped guarantees, version-pinned manifests, CI conformance harness, draft-manifest generation. (*as built:* all four stand; the conformance harness runs as a live gate rather than as a producer of sealed evidence — see §6's Mergebot annotation.)
- Provenance false signals (authenticated ≠ author; cron-borne authored content; wp-cli cron transport) → capability×surface signal, hook-context classification, external/re-sync class.
- Journal overhead & blind spots (reads, rollbacks, direct mysqli) → learning-mode→sampling decay, ledger-diff ground truth, checksum reconciler.
- Apply vs. live traffic races → write-gate + optimistic checks.
- Derived-state staleness after hook-free apply → manifest-declared structured actions (closed native actions and plugin-owned provider capabilities) + core rebuild pass.
- Page-builder payloads (Elementor JSON-escaped URLs; Beaver Builder PHP objects) → escaped-form tokenizer + verbatim-preservation path.
- Secrets in options → pattern denylists + env-bound defaults, commit block.
- Revisions/auto-drafts excluded as derived; `_edit_lock`/`_edit_last` runtime.

## 9. v0 spikes & acceptance

The original v0 spikes established the acceptance criteria below. The shared
prototype scripts have been retired after their product paths gained permanent
coverage: `conformance-core`, `certify-merge`, `conformance-woocommerce`, the
WooCommerce capsule's offline/live suites, and the provenance/classification
regressions. The ACF capsule retains its cited spike-E evidence. Current
capability sources and named tests govern claims; an old spike result is not
a current release gate.

1. **Spike A — round-trip** (identity + canonicalization + safe apply): two disposable dockerized WP envs. Scope pinned tight: posts/pages, **terms/taxonomies** (menus *are* terms — typed ledger from day one), menus, media, and a **whitelist of ~8 options** (`blogname`, `blogdescription`, `show_on_front`, `page_on_front`, `page_for_posts`, `sticky_posts`, `default_category`) — forces option→post/term ref rewriting without the serialized ocean; widgets/customizer explicitly excluded. Includes the user-token fallback for `post_author`.
2. **Spike B — merge** (the reason the product exists; required v0 exit criterion): edit X in A; edit Y *plus a conflicting field of X* in B; capture both; git-merge; apply to both. Assert: convergence, the conflict surfaced as a git conflict, B-local drift appears in the plan.
3. **Spike C — provenance journal** (classification): mu-plugin journaling live writes with WooCommerce active; simulate admin edits + anonymous checkouts; measure proposal accuracy against the Woo manifest as ground truth + write-path overhead.

Acceptance criteria:

- **Round-trip**: canonical(A) == canonical(B after apply); **capture A twice → zero diff** (determinism); B's pre-existing runtime rows byte-identical.
- **Side-effect canary**: zero WP hook fires during apply (`save_post`, `transition_post_status`, `created_term`, `wp_insert_comment`) + mail/HTTP interceptors show zero emissions ("no mails fired" alone is trivially true under direct SQL — the hook assertion is the real claim).
- **Derived-state rebuilt**: B's category archive actually renders the applied post with correct term counts after the rebuild pass.
- **Merge**: the divergent-edit scenario converges; conflict surfaces in git; drift shows in plan.
- **Journal overhead**: p95 added latency per admin write under threshold; ~zero on anonymous requests once sampling kicks in.
- **Woo deletion boundary**: an unreferenced standalone product deletes only under the adapter's all-executable-owner contract and a signed external writer exclusion reverified at plan, transaction, each destructive unit, and final pre-COMMIT. The official 11.0.0 stock-notification-table absence is bound by the reviewed `table_absence: "empty"` topology witness and must still be absent at that final boundary; if present, it takes the same non-forceable InnoDB/index/row-lock proof as 11.0.1. A deletion-only provider then removes the exact product's lookup rows, cancels only its pending reviewed sale actions, clears the reviewed transient set, and proves the database-contained postcondition. Core rows and those Woo projections must be absent and recapture byte-identical. Variation tombstones, a native or non-forceable reference, or any missing, unreadable, or code-mismatched owner agreement keep the plan blocked and apply byte-still.
