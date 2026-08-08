# Duo — Branchable WordPress

*Design document v0 — 2026-08-05. Status: approved direction, spike phase.*

Duo makes a WordPress site **branchable like code** — branch, edit, merge, promote — under three constraints:

1. **Plugins/themes work completely unmodified** (no ecosystem cooperation required).
2. **Authored content/configuration lives in a git repository** and propagates across branches.
3. **Runtime data** (orders, comments, form entries, sessions, caches) **stays environment-local and unharmed**.

This document was produced from a first-principles brainstorm and then hardened by an independent adversarial review before any code was written — see [docs/design-review-v0.md](docs/design-review-v0.md) for the full 27-finding register (prior art verified: VersionPress archived 2020, Mergebot shut down 2018, Pantheon multidev docs). The findings are folded into every section below.

## 1. First principles: what *is* a WordPress site's state?

Classify every piece of state along three orthogonal axes:

| Axis | Values |
|---|---|
| **Author** — who writes it | developer (code) / site-admin (content, settings) / the world or the machine (visitors, cron, plugins acting autonomously) / **external systems** (scheduled imports, ERP/PIM syncs) |
| **Portability** — does it mean the same thing elsewhere? | env-independent (a page, a plugin setting) / env-bound (siteurl, API keys, salts, file paths) |
| **Derivability** — source or derived? | source (canonical) / derived-regenerable (transients, object cache, thumbnails, rewrite rules, lookup tables) |

**The branchable partition:** `human-authored ∧ portable ∧ source`. Everything else is **env-local** (runtime, env-bound), **rebuilt** (derived), or **re-synced** (externally-sourced data re-syncs per environment rather than propagating through git).

Duo is machinery to (1) enforce this partition on a system that never made the distinction, (2) represent the branchable partition canonically in git, (3) materialize it into any environment safely.

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

1. **Core schema rules** — WP core's tables/keys are fully known; classify exactly. Includes the full map of *ref-typed option keys* (`page_on_front`, `page_for_posts`, `sticky_posts`, `default_category`, `site_icon`/`custom_logo`, `theme_mods_{theme}` internals like `nav_menu_locations`/`custom_css_post_id`, `widget_*`/`sidebars_widgets`, `wp_page_for_privacy_policy`). Shipped as [manifests/core.json](manifests/core.json).
2. **Plugin manifests** — versioned artifacts **pinned to plugin version ranges** (Woo HPOS moved orders to custom tables — a manifest for Woo 7 must not claim Woo 9), field/meta-key granular (Woo: products authored, `_stock`/`total_sales` runtime, `woocommerce_db_version` env-local, `woocommerce_*_page_id` ref-typed). Site repositories may additionally content-pin each manifest with the exact per-manifest digest already recorded in compiled artifacts; a changed manifest, interpreter, or external disposition then refuses at policy load until the reviewed pin is explicitly updated (`wp duo manifest-pin` emits the copy-pasteable object). Name-only pins remain supported for deliberate incremental adoption. Manifests can be *interpreters* where semantics are schema-driven: the ACF manifest reads field-group definitions in the repo to type meta values — and interpreter *code* ships inside `manifests/interpreters/` as part of the manifest artifact, never in the engine tree (the engine holds only the plugin-blind loading contract). The required post-meta hook may be joined by optional term-meta and user-meta hooks; each sees the owning object's complete meta map, wins over static rules, and returns null to defer without widening older interpreters. Users remain environment-local non-entities: user-meta runtime/env/derived classifications stay target-local, while explicitly `authored` keys use a login-keyed, PII/secret-gated sidecar that never mints a user UUID or creates a missing user. A separate [disposition registry](manifests/dispositions.json) prevents a manifest from certifying itself: every shipped manifest is certified, experimental, or excluded with explicit versions, scope, lifecycle/deletion semantics, unsupported surfaces, and evidence. Experimental/excluded pins keep readiness non-green and cannot enter host promotion. The content-addressed reference bundle embeds the matrix and refuses certified claims whose named tests are absent. Guarantees are explicitly scoped to certified entries, with loud degradation elsewhere.
3. **Site policy overrides** — `policy.yml` in the site repo.
4. **Provenance observation** (proposal generator, *never* authority) — the capture agent journals writes at **(table, key/column) granularity** with the executing hook stack and calling-plugin attribution (sampled backtraces). The authored signal is **actor capability × surface**: an actor with `edit_posts`/`manage_options` *on an authoring surface* (wp-admin settings POST, editor REST save, customizer save). Bare "authenticated" never proposes authored — logged-in customers write runtime data constantly (checkout, quiz attempts, front-end forums), and `admin-ajax.php` is `is_admin()` even for subscribers. Cron classifies by executing hook context (production cron often runs *via wp-cli*, so "CLI ⇒ authored" is wrong; authored CLI ops need an explicit intent flag). Cron-borne *authored* content (scheduled imports, scheduled publishing) is handled by the external/re-sync class + import-plugin manifests, not guessed.
5. **Loud-and-blocking default** — unclassified data never silently enters *or* silently skips the repo: capture/merge/apply **fail (or demand explicit ack)** when in-scope entities have pending-unclassified writes; unclassified drift appears in the plan preview. A soak window auto-*proposes* classifications (write frequency, anon-write ratio, value churn) so human review is triage, not authorship — and accepted decisions persist to `policy.yml`, shareable upstream as draft manifests. Real usage crowdsources coverage as *review*, not authorship (Mergebot built a schema-generator for exactly this reason). *Status: built and spike-proven (Spike F) — `wp duo pending` (journal-evidenced proposals, ref hints, secret flags) → `wp duo classify` / interactive `duo classify <env>` → `wp duo policy-to-manifest`; see spec "The review queue".*

Secret guard: pattern denylists first (`sk_live_`, `AKIA`, `ghp_`, …) + manifest env-bound defaults; entropy heuristics only as backstop. Suspected secrets are forced env-bound and blocked from commit. *As built:* the guard is capture-time-**blocking** for authored option/post-meta/user-meta values (rule-level `allow_secret: true` is the explicit escape hatch), while post **bodies** are warn-only by design — people legitimately write about credentials; that asymmetry is a deliberate product decision, not an omission. User-meta adds a separate conservative recursive PII gate with an exact-rule `allow_pii: true` escape hatch.

### 3.2 Stable identity + reference rewriting (vs. identity)

- Mint a UUID per entity at capture, stored invisibly (`postmeta`/`termmeta` `_duo_uuid`); options keyed by name. Canonical files keyed `uuid + slug`.
- Per-environment **ledger**: `(uuid, entity_type, id_kind) ↔ local id` — `id_kind` distinguishes `term_id` / `term_taxonomy_id` / post / user / comment keyspaces from day one; every rewriter declares which kind a field holds.
- **Structure-aware rewriters** (never regex-on-strings): a **block-rewriter registry** keyed by block name with typed attribute paths — `core/image` (`"id":N`, `wp-image-N`), `core/block {"ref":N}` (reusable blocks), `core/navigation {"ref":N}`, gallery `"ids":[…]`, query-loop taxQuery term ids, cover media — plus href-form internal links (`?page_id=N`, permalinks); declared shortcodes; JSON-path ref declarations in manifests for serialized blobs.
- **FSE content is first-class**: `wp_template`, `wp_template_part`, `wp_navigation`, `wp_block` CPTs are authored entities (block themes store site structure in the DB).
- **Menus**: `_menu_item_object_id` is polymorphic (typed by `_menu_item_type`); menu-item parents are self-referential post ids; menu→location assignment lives in `theme_mods`. The core manifest handles all three.
- `post_author` and all user refs (a general **user-token ref type**: login/email) — users are env-local; apply maps by login, falls back to a configured default author with a plan warning; never auto-creates users.
- `guid` is env-derived: generated deterministically per env on first apply, pinned in the ledger, excluded from diffs.
- Plan-time **slug-uniqueness check** on `(post_type, slug, parent)` so two branches creating `/about/` resolve explicitly instead of silently auto-suffixing.

### 3.3 Canonical repo representation (vs. opacity; the thing that makes merge possible)

- **Entity-per-file**, deterministic serialization: sorted keys; PHP-serialize → canonical structured form *for plain data only*. Non-plain serializations (PHP objects `O:…`, e.g. Beaver Builder) get a **verbatim-preservation path**: original bytes kept, refs rewritten only via safe in-place token substitution, or refused with a loud warning — never round-tripped through a foreign format. **Crack in the sorted-keys assumption, closed (grind round 3, task #123 → DUO-3214)**: sorting is only safe when no consumer reads a value's raw iteration order — WooCommerce's variation-title generator reads `_product_attributes`' array order directly, so canonicalization permanently reordered it on every applied target (causation-proven, signature confirmed on two fixtures). *As built:* an opt-in `"order_preserving": true` declaration on a meta rule (spec v0.13, sibling to `json_refs`/`cast`) wraps the captured value in `Duo\OrderPreserved`, which `Canon::normalize()` recognizes and recurses through without ever calling `ksort()` — scoped to the declared value and everything nested inside it, never a document-wide behavior change. `manifests/woocommerce.json`'s `_product_attributes` is the first (and, per a manifest-set audit of the shipped manifests, so far only confirmed) user; the apply side needed no changes at all, since `json_decode()`/`maybe_serialize()` were already order-preserving by construction — `Canon::normalize()`'s `ksort()` was the only place order was ever lost. The r1b/r3a grind scripts' anagram-checked title carve-out is gone: both now assert true zero-exclusion byte identity, confirmed live on two independent attribute orderings.
- **Option tombstones authorize from one immutable tree**: `duo-options/v2` may retain a prior option's value/autoload as a hash-bound `classification_witness`, but only when the current interpreter rule explicitly marks that value as the minimum deletion-classification context. The current pinned interpreter still re-derives authorship; the wire never trusts a bare provenance flag. The first use is ACF's low-sensitivity `_options_<name> → field_…` shadow pointer, which lets a cold clone classify both halves after ACF atomically removes the value and shadow rows without retaining the deleted authored value itself. Witnesses are never applied as desired data, and ordinary non-authored tombstones remain blocked.
- **Post files are front-matter + raw body**: fields/meta/terms as canonical-JSON front matter, `post_content` as raw Gutenberg HTML below the `---` separator. Rationale: the body merges in git as plain lines (an escaped-into-a-string body would make every content merge a conflict), while remaining byte-faithful to what plugins expect.
- **URLs/paths tokenized at capture** (`{{home}}`, `{{media:uuid}}`, `{{link:uuid}}`) — serialized-length corruption can't happen because we operate on parsed structures and re-bind per environment at apply. JSON-escaped URL forms (`https:\/\/…` inside `_elementor_data`-style meta) were originally a gap (docs/frontier/elementor.md found it empirically); the tokenizer now matches both spellings and collapses them to one plain-spelled token, with structural re-encode restoring the host convention on apply (spec v0.7).
- **Media**: binaries content-addressed (sha256), stored via git LFS in real site repos (plain files in the sandbox); the attachment *entity* is a normal authored file; `_wp_attachment_metadata` is derived → regenerated.
- **Custom tables**: opaque snapshots are **refused** for tables with FK-like columns (int columns whose values join posts/terms/users) — authored custom tables almost always embed local ids (WPML `icl_translations`, Ninja Forms `nf3_*`), so pick-side replay would corrupt silently. The middle tier — **typed snapshot** with column-level ref declarations — is now implemented (grind round 2, task #75): manifest-declared `tables` with per-column classification, ledger-only identity (mapped UUIDv7 or natural-key-derived UUIDv5), structural row refs vs optional sidecar refs, and declarative cache invalidation; proven on Ninja Forms (`nf3_*`) and `woocommerce_attribute_taxonomies` (spec v0.10); extended in round 3 to the WooCommerce shipping-zone/tax-rate families plus **option-name-embedded refs** (`option_name_refs`, spec v0.12 — options whose *name* carries another table's id, pattern-discovered and re-materialized per environment). Known grammar edges filed with acceptance criteria: derived tables with hard availability dependencies (#124), composite primary keys (#125), sidecar PK name override (#126). WPML/Polylang are manifest-mandatory, never opaque-eligible.

Site-repo layout (full contract in [spec/repo-format.md](spec/repo-format.md)):

```
site.duo.json                # policy: classifications, env defs, manifest pins
code/                        # optional v0 code half: exact vendored bytes
  wp-content/plugins/
  wp-content/themes/
  wp-content/mu-plugins/     # site-owned mu-plugins; Duo's agent is out-of-band
state/
  options/<group>.json
  posts/<type>/<uuid>--<slug>.md    # canonical-JSON front matter + raw block-HTML body
  terms/<taxonomy>/<uuid>--<slug>.json
  menus/<slug>.json
  sidebars/<sidebar_id>.json
media/<sha256>.<ext>         # LFS
```

The per-env ledger (applied revision, typed uuid↔id map, drift hashes) lives in the environment's DB (`duo_*` tables), **not** the repo. Env-bound values bind via per-env config, never committed.

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
canonical entities. `Apply.php` plans and mutates canonical database state; it
never copies, deletes, or claims code paths. `CodeStateContract.php` is the sole
cross-half validator: it proves that the three database-held lifecycle
identities can be satisfied by the opaque code descriptor. Each half keeps its
own revision and completion marker. The outer compiled artifact binds them so a
promotion cannot mix code from one revision with state from another.

- **`duo capture`** — ledger-vs-DB diff is the ground truth for *what* changed; the provenance journal explains *why* (classification proposals). Reviewed entries become canonical files/commits. "git add -p for the database." *As built (DUO-3213):* every read runs inside one InnoDB `START TRANSACTION WITH CONSISTENT SNAPSHOT` (refused loudly upfront on any non-InnoDB table in scope — MyISAM has no MVCC to back the guarantee) with a bounded retry on deadlock/lock-wait-timeout; the candidate tree is built and validated in a staging directory and published only via an atomic two-step rename swap (`agent/src/Publish.php`) — the previous tree is never touched until the swap succeeds, a crash/disk-full/kill at any earlier point leaves it completely intact, and a per-destination capture lock serializes concurrent publishers (fails cleanly, non-blocking). Ledger hash advancement moves to strictly after the swap.
- **`duo deploy`** — compiles one immutable artifact, copies descriptor-verified additions/updates from the opt-in `code/wp-content` payload, runs plugin/theme lifecycle reconciliation while outgoing code still exists, then prunes only Duo-owned obsolete component paths and records `code_revision` after target-byte verification. The completed descriptor/revision and removal of every temporary stage marker publish in one database transaction, so an interrupted handoff remains exactly retryable. This first transport intentionally owns neither WordPress core nor the agent executing it; Composer/full-webroot builders can be added later by emitting the same descriptor contract.
- **`duo apply`** — three-way against the env's ledger → **terraform-style plan preview** (creates/updates/deletes + drift + unclassified warnings) → DB snapshot backup → **two-phase apply as the engine default** (insert rows with placeholder refs, then a resolve/fixup pass — post_parent chains, menu-item parents, reusable-block nesting form graphs with possible cycles) via direct low-level writes (no WP hooks ⇒ no emails/webhooks re-fire) → **rebuild pass**: core rebuilders (taxonomy counts through each registered taxonomy's count callback, future-post cron, comment recounts, lookup regeneration, rewrite rules, object cache flush, attachment metadata after both creates and updates) + per-plugin **rebuilders declared in manifests** (Yoast indexables, Woo `product_meta_lookup`, Elementor CSS) — because the hooks we skip are also what maintain derived state → ledger update.
- **Concurrency**: apply races live traffic (a stock decrement mid-apply). *As built (DUO-3217/code-half skeleton):* a bounded target-DB row serializes handoff across deploy/apply processes, a durable latest-session identity plus strict continuation prevents an obsolete checkpoint from creating a fresh row or advertising stale recovery, and a per-process database advisory fence covers long hooks/filesystem walks even when the row TTL passes mid-call. Apply re-plans under that fence immediately before writes and re-runs reverse-reference guards at each delete. Public reads and unrelated runtime writes remain available. A broader traffic-pausing mu-plugin write gate remains production-promotion work rather than being implied by this scoped gate.
- **Guards**: referential checks before deletion only for closed, declared selectors; unsupported Woo product/variation and shipping/tax deletion intent refuses before repository mutation rather than pretending the open extension graph is enumerable. Cross-partition invariant (`active_plugins` ⊆ plugins in `code/`); drift detection with capture-first workflow.
- **Branch** = git branch + an environment materialized from it. Default feature-env workflow (Pantheon's content-freeze exists for a reason): **materialize fresh from a prod snapshot + apply the branch delta**, branch TTLs, drift report as CI status, prod-content-is-truth as default conflict bias for runtime-adjacent disputes.
- **Merge** = git merge of canonical text — entity-level three-way; deterministic formatting keeps most merges clean; genuine editorial conflicts surface exactly like code conflicts. (v2: field-aware merge drivers for ordered structures like menus.) Plugin version skew across branches: merge **code first**, run migrations, re-capture, then merge state (capture records plugin versions; apply warns on mismatch).

## 4. Architecture components

1. **Repo format spec** ([spec/repo-format.md](spec/repo-format.md)) — the versioned contract; the most important artifact.
2. **`duo` CLI** — host-agnostic orchestration for capture, plan, code deploy, state apply, and composed promotion over local, Docker, and SSH transports. Git remains the branch/merge layer.
3. **mu-plugin capture agent** ([agent/](agent/)) — (table,key)-granular write journaling with hook-stack provenance, ledger tables, apply executor + write-gate, hook-fire canary; transparent to plugins. Journal runs as learning mode that decays to sampling once classifications stabilize (the wpdb `query` filter is noisy: it fires on reads and can't see `insert_id`/rollbacks — hence ledger-diff as ground truth). Fallback checksum-scan reconciler for direct-mysqli writers.
4. **Manifest registry** ([manifests/](manifests/)) — core rules + top-plugin manifests, versioned and pinned to plugin ranges, plus a separate exact-coverage disposition matrix. Conformance runs supply evidence; only externally ratified entries become certified capabilities. Draft-manifest generator fed by the provenance/review pipeline.
5. **Merge layer** — git-native first; custom merge drivers later.
6. **Env orchestration** — Duo owns sequencing, immutable artifacts, and target leases, not infrastructure provisioning. [sandbox/](sandbox/) Docker environments are development/test fixtures only.

**v0 implementation decisions** (deferred at planning, decided at scaffold):

- **Engine language: PHP, packaged as a wp-cli command set inside the agent.** Running in-process with WordPress gives native `unserialize`, the official block parser (`parse_blocks`/`serialize_blocks`), and `$wpdb` — the three things the engine lives on. The *orchestrator* CLI ([cli/](cli/)) is also dependency-free PHP for v0.5: WP shops always have PHP, and shipping without a composer/npm toolchain matters more for a drop-in tool than CLI-framework ergonomics (revisit if a daemon/UI emerges). It wraps per-env `wp duo …` over local/docker/ssh transports; git stays git.
- **Canonical format: canonical JSON (sorted keys, pretty, LF) for structured entities; front-matter+raw-body for posts.** JSON keeps the drop-in mu-plugin dependency-free (no YAML parser ships with WP/PHP) and makes byte-determinism trivial; git-mergeability of post *bodies* — the part that matters — is preserved by the front-matter+raw-body format (see 3.3). YAML stays an open revisit as a mechanical `spec_version` bump. Spike A's capture-twice determinism test validates the emitter.

## 5. Non-goals

- Multi-master replication (two prods diverging) — one authored truth, many materializations.
- Versioning runtime data (orders, comments, analytics).
- Requiring anything from plugin authors.
- Multisite (v1). Opaque custom-table snapshots (refused on FK-bearing tables, by principle); typed snapshot shipped early (grind round 2) — see §3.3.
- Silent best-effort coverage of unknown plugins — out of principle, not just scope.

## 6. Prior art & lessons (verified — sources in [docs/design-review-v0.md](docs/design-review-v0.md))

- **VersionPress** (git-tracked DB, VPIDs; archived 2020 — "a great proof-of-concept" needing "several person-years"): the mechanics work; per-plugin definitions + maintenance killed it. Its planned community-definitions repo never materialized — so "community manifests" must be *review* of auto-generated drafts, not authorship, and the manifest treadmill is a budgeted permanent cost for top-N plugins.
- **Mergebot** (Delicious Brains; shut down 2018): built exactly this manifest layer (public `mergebot-schemas` + a schema-generator) and still couldn't meet its own quality bar — hence the scoped-guarantee posture and CI conformance harness.
- **Pantheon / WP Engine multidev**: branch environments exist; DB is clone-only, "code up, content down," content freeze during long branches. The merge path is exactly the market gap — and their content-freeze discipline informs our default branch workflow.
- **Bedrock/Composer**: strong dependency/build inputs for one deployment mode, but not by themselves a target materialization, ownership, lifecycle-ordering, or premium/private-code contract. Duo can consume a future Composer build through the same opaque descriptor boundary without coupling the state engine to Bedrock.
- **WXR / wp-cli search-replace**: lossy exactly where v0 aims (serialized meta, menu assignments) — validates the uuid + structure-aware-rewriter + tokenization bet; menus stay in v0 as the canary.
- **ACF local JSON, GF form export**: the ecosystem accepts config-as-files.

## 7. v0 scope decisions (2026-08-04)

1. **Product form: host-agnostic toolchain.** CLI + mu-plugin + repo format spec, working against any environment via wp-cli/SSH/HTTP bridge. No hosting/orchestration platform in v1.
2. **Merge model: entity-level git merge.** Canonical text + git's native 3-way; field-aware merge drivers deferred to v2.
3. **Capture model: continuous journal + explicit commit.** Provenance-tagged journaling; `duo capture` reviews → commits. No auto-commits.
4. **v1 certified scope.** Core content/FSE, WooCommerce, ACF, Yoast, Elementor, Polylang, Contact Form 7, and Ninja Forms at the exact ranges in the disposition registry. Paid Memberships Pro and The Events Calendar remain experimental; synthetic Duo fixtures are excluded. Experimental declarations may be exercised by tests but never advertised as promotion-ready capability.

## 8. Risk register (summary — full text in docs/design-review-v0.md)

- **Existential #1 — silent authored-data loss** via an unreviewed classification queue → the loud-and-blocking gate (3.1.5); must never regress to quiet skipping.
- **Existential #2 — the manifest treadmill** under an absolute reliability bar (killed both predecessors) → scoped guarantees, version-pinned manifests, CI conformance harness, draft-manifest generation.
- Provenance false signals (authenticated ≠ author; cron-borne authored content; wp-cli cron transport) → capability×surface signal, hook-context classification, external/re-sync class.
- Journal overhead & blind spots (reads, rollbacks, direct mysqli) → learning-mode→sampling decay, ledger-diff ground truth, checksum reconciler.
- Apply vs. live traffic races → write-gate + optimistic checks.
- Derived-state staleness after hook-free apply → manifest-declared rebuilders + core rebuild pass.
- Page-builder payloads (Elementor JSON-escaped URLs; Beaver Builder PHP objects) → escaped-form tokenizer + verbatim-preservation path.
- Secrets in options → pattern denylists + env-bound defaults, commit block.
- Revisions/auto-drafts excluded as derived; `_edit_lock`/`_edit_last` runtime.

## 9. v0 spikes & acceptance

1. **Spike A — round-trip** (identity + canonicalization + safe apply): two disposable dockerized WP envs. Scope pinned tight: posts/pages, **terms/taxonomies** (menus *are* terms — typed ledger from day one), menus, media, and a **whitelist of ~8 options** (`blogname`, `blogdescription`, `show_on_front`, `page_on_front`, `page_for_posts`, `sticky_posts`, `default_category`) — forces option→post/term ref rewriting without the serialized ocean; widgets/customizer explicitly excluded. Includes the user-token fallback for `post_author`.
2. **Spike B — merge** (the reason the product exists; required v0 exit criterion): edit X in A; edit Y *plus a conflicting field of X* in B; capture both; git-merge; apply to both. Assert: convergence, the conflict surfaced as a git conflict, B-local drift appears in the plan.
3. **Spike C — provenance journal** (classification): mu-plugin journaling live writes with WooCommerce active; simulate admin edits + anonymous checkouts; measure proposal accuracy against the Woo manifest as ground truth + write-path overhead.

Acceptance criteria:

- **Round-trip**: canonical(A) == canonical(B after apply); **capture A twice → zero diff** (determinism); B's pre-existing runtime rows byte-identical.
- **Side-effect canary**: zero WP hook fires during apply (`save_post`, `transition_post_status`, `created_term`, `wp_insert_comment`) + mail/HTTP interceptors show zero emissions ("no mails fired" alone is trivially true under direct SQL — the hook assertion is the real claim).
- **Derived-state rebuilt**: B's category archive actually renders the applied post with correct term counts after the rebuild pass.
- **Merge**: the divergent-edit scenario converges; conflict surfaces in git; drift shows in plan.
- **Journal overhead**: p95 added latency per admin write under threshold; ~zero on anonymous requests once sampling kicks in.
- **Woo deletion boundary**: deleting product X on the source makes capture fail before repository mutation; the generic deletion engine remains certified through closed core/plugin-specific and synthetic contracts, while Woo deletion stays unsupported until extension-complete reverse references and semantic effects are representable.
