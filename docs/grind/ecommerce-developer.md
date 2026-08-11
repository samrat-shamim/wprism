# Ecommerce developer grind

`sandbox/tests/grind_ecommerce_developer.sh` is the clean-room ecommerce
scenario. It uses an HTTP-enabled `sandbox/bin/pair.sh` workflow and does not
run as part of a normal static test pass. A live run requires an explicit pair
authorization because it starts Docker, uses the shared MariaDB service, and
probes the two published frontends. The script first preserves its invalid
pair-name and pre-existing-root refusals, then requires a clean primary
checkout or a standalone exact-HEAD clone. Linked worktrees, dirty checkouts,
and stale `sandbox/.env` canonical mount paths are refused before any live
mutation. Cleanup removes host paths only after pair destruction plus Docker
resource and database absence are all verified; an uncertain teardown fails
the run and preserves its exact paths for diagnosis.

## DUO-3337 move matrix

The machine-readable [DUO-3337 move matrix](../../sandbox/tests/grind_ecommerce_developer.matrix.json)
is the acceptance index for this proof. Every row has the same fields:
move ID, intent, public command, status, code delta, authored-state delta,
generated-state policy, required capabilities, semantic plan, expected write
set, failure semantics, convergence rule, rollback rule, harness/evidence
anchors, acceptance statement, and (for a gap) bounded Linear routing.
`exercised` means the live grind has a source anchor; `reproduced_gap` means
the live grind proves a fail-closed unsupported/refusal boundary; `planned_gap`
means the proposed public move is explicitly marked unavailable and routed but
not claimed by this harness.

The current machine-checked report contains 17 exercised moves, 7 planned
gaps, and 2 reproduced fail-closed boundaries. The regression recomputes those
counts from the rows so the summary cannot drift from the evidence index.

The exercised slice includes state-only capture, managed code deploy and
apply, Woo products/variations/taxonomies/media/options, ACF, ordered menus,
extension migration/lifecycle, bounded generated-index and queue work,
explicit dependency-aware plugin removal, semantic preview, drift/conflict
handling, compatibility refusal, complete promotion, recovery, exact rollback, and final semantic
recapture. The Action Scheduler row is intentionally separate from the
bounded native WordPress-cron row, which names one `publish_future_post` event
and refuses to drain unrelated scheduler work. The menu proof is anchored by
`source_wp menu` and `assert_ecommerce_menu` and is checked again after v1
apply and exact rollback.

The menu row is the newly harvested convergence gap: it was absent at
`da93360` and red in the static contract, then commit `54ea408` added the
`source_wp menu` seed, `assert_ecommerce_menu` target checks, v1-apply proof,
exact-rollback proof, and static assertions. It is green in this matrix; the
intentional `reproduced_gap` refusal rows do not substitute for that
before-fix/after-fix convergence path.

The explicit gaps are initialization/adoption (DUO-3336), branch
materialization (DUO-3324), authored-user synchronization (DUO-3344),
refresh/rebase live-grind coverage (the public workflow landed in DUO-3343), scoped promotion (DUO-3344), and provider-backed
plugin/theme replacement (DUO-3357). The proof now exercises a reviewed
parent/child theme upgrade with failure/retry, a non-forceable incompatible
downgrade, unsafe parent-removal refusal, dependency-safe child removal after
a public theme switch, and exact rollback while preserving theme settings,
navigation, and media. The separate WordPress-cron move is routed to DUO-3359.
Reproduced boundaries include unsupported Woo deletion (DUO-3338),
compatibility refusal (DUO-3326), and dependency refusal (DUO-3338). These
statuses prevent the current clone-based setup from being mistaken for
coverage of the missing public workflows.

The live proof is discoverable, but remains opt-in because it starts Docker
and uses shared MariaDB resources:

```sh
make grind-ecommerce-developer-live \
  ECOMMERCE_PAIR=ecom3337 PORT1=9100 PORT2=9101
```

All three values are required and must be unique/free for the authorized run;
the target is deliberately outside `regress-offline-all`. Concurrent agents
may keep their own pairs running: the live harness delegates the locked,
dynamic host-capacity decision to `pair.sh up` instead of imposing a stale
zero-other-pairs rule.

The scenario installs the digest-pinned WooCommerce 11.0.0 and ACF 6.8.7
artifacts on both sides. The author side creates a synthetic Woo catalog with
simple, variable, grouped, and variation products; category/tag and global
attributes; a coupon; media; ACF field schema/value; COD merchant settings;
shipping methods; CA tax; and Woo configuration. `product_visibility` is
asserted as Woo-owned derived/runtime data and is deliberately absent from
canonical taxonomy policy.

The first capture is state-only. Code is then opted into by copying the
verified Woo/ACF bytes plus the in-house v1 extension and parent/child theme
into `code/`. The author installation activates the extension and switches
themes through WordPress APIs before an ordinary `duo capture`, so lifecycle
hooks and the captured managed options are real developer actions.

The ACF check binds the complete group/field schema: group key, title,
product location, ordering/display options and active flag, plus the single
field's key, name, type, parent group ID, order, required flag, and conditional
logic. The first v1 target apply also hashes the complete managed Woo/ACF/
extension/parent/child code tree on both sides and requires manifest equality.

Woo product quantities and stock statuses are treated as runtime data. The
source variation quantities (including the blue tee's source quantity of 8)
are not expected to copy. After the first target apply, the scenario assigns a
different target-local inventory picture and explicitly rebuilds the stock
status; the lookup assertions then require exact target quantities, prices,
SKUs, tax classes, attribute terms, and `in_stock` rows. The cap's authored
`manage_stock` flag is asserted separately from its target-only quantity.

That runtime move exposed a second-order boundary: WooCommerce product saves
also advance `post_modified` and `post_modified_gmt`, even when only
target-local stock changed. The Woo manifest therefore classifies those two
front-matter fields as derived for products and variations. Duo still captures
their current values for operator visibility and uses them when creating a new
row, but timestamp-only differences neither create state drift nor overwrite
an existing environment's persistence timestamps. Other post types retain the
default authored timestamp behavior.

The extension requires WooCommerce, owns a runtime events table, and requires
an env-owned gateway secret. Its option contract is deliberately site-local
in `site.duo.json`: synthetic project code is not smuggled into the external
registry as a self-certified shipped adapter. Exact code bytes and the
`Requires Plugins` header still govern materialization and lifecycle ordering.
The source and target secrets are intentionally different; only the target is
provisioned through public `duo env-set`, and the secret is checked to stay out
of canonical state at every capture/apply/rollback checkpoint. ACF is installed and active on the author, initially
inactive on the target, and its product field value and schema are verified
after promotion. The target frontend is exercised over bounded curl requests:
the parent body class, child template marker, and dependency-ordered
parent/child stylesheet output must all be present. The extension's public
`/wp-json/duo-commerce/v1/status` endpoint is checked for exact HTTP 200,
extension version, schema, and Woo availability.

Storefront acceptance is deliberately two-layered. The Store API price and
global-attribute filters used to diagnose derived lookup rows are executed
in-process, then repeated through bounded public HTTP requests against
`/wp-json/wc/store/v1/products`, including empty negative filters. This catches
rewrite, web-server, and bootstrap failures an in-process REST dispatch cannot.

The live sequence covers:

- initial state-only capture, code deploy, state apply, HPOS enablement, and
  bidirectional runtime sovereignty checks;
- a source-only customer, order, and extension event are created before
  capture and pinned by exact identity/content snapshots. A real target-only
  customer/order/event is then pinned after v1 apply. Every migration, retry,
  refusal, and rollback checkpoint proves each side's runtime data is
  unchanged, absent from the opposite side, and absent from generated
  repository state. Complete normalized customer/order/event inventories catch
  unexpected copied or transformed rows instead of relying only on known
  marker strings. Order baselines include Woo's object view plus exact HPOS
  order, address, operational, metadata, statistics, product-lookup,
  order-item, and order-item-meta rows. The v1 event baseline is retained separately from the v2
  shape; after source-table migration its identity/label/time must remain
  unchanged and its new `context` value must be the empty default;
- a sacrificial global-attribute product carried through v1/v2, then deleted
  through public Woo tooling at the end of the source sequence. Duo capture
  refuses the unsupported `post:product` disappearance with the exact
  fail-closed diagnostic; the canonical state tree, local/published repository
  identities, target product and both lookup roots, catalog, code, and runtime
  probes remain unchanged, and no Duo tombstone is created or promoted;
- Woo product/variation/grouped/shipping/tax creation and update paths remain
  covered by the catalog and author-update probes. Positive Woo deletion
  authority is intentionally not claimed: generic child/typed-row deletion
  machinery is exercised by offline synthetic/certification regressions, while
  relation repair and provider-cache cleanup remain manual adapter gaps;
- target derived product-meta index rows and Store API price/attribute
  behavior, without treating Store API filtering as an independent
  `wc_product_attributes_lookup` value oracle;
- missing-file compile refusal and retry;
- explicit extension deactivation followed by a deliberately inactive-to-active
  v2 broken activation, checkpoint recovery, and fixed v2 migration. The
  failed checkpoint path, run owner, compiled artifact hash, canonical artifact
  bytes, checkpoint SHA-256, recovery lease, and import are bound to one
  promotion identity;
- a real author-side grouped-child price and merchandising edit captured and
  promoted through Duo, with the grouped root's exact min/max lookup refreshed
  while target-only runtime order/stock survives;
- pinned WooCommerce 10.9.4 source compatibility refusal before
  `promotion-begin`, with the entire target plugin tree and code revision
  unchanged;
- generic plugin/theme `Requires PHP` / `Requires at least` enforcement from
  exact target control-plane evidence: an inactive vendored extension with an
  incompatible requirement is a non-forceable plan/deploy refusal before
  `promotion-begin`, with code, lifecycle trace, lease/session, and revision
  unchanged; the fixed v2 extension and themes carry compatible requirements
  and pass the same preflight before their reviewed promotion;
- native WordPress alphabetical `active_plugins` order accepted after
  provider-first lifecycle activation, while a custom-without-Woo dependency
  request is refused before target mutation;
- ordinary state drift/conflict resolution and target code drift healing;
- dependency-aware extension removal while Woo/ACF and runtime order data stay;
- exact v1 code/state plus a maintenance-held, pair-local pre-order database
  checkpoint restore performed before the public v1 promotion;
- derived-aware semantic final recapture, clean status, and scoped pair/database
  cleanup.

The custom extension fixture checks every schema probe, CREATE, ALTER,
settings write, schema-marker write, and post-write readback. Its Docker-free
regression fault-injects each boundary and proves retry-safe recovery from
partial progress without falsely advancing the schema. The deliberately
broken v2 variant retains those guarantees before throwing its controlled
activation failure.

No production transactions or secrets are used. The source and target runtime
rows are synthetic probes only: source customer/order/event data is created
before the first capture, while a real target customer/order/event is created
after the v1 checkpoint. None is captured into canonical state or copied to
the opposite site. The final rollback enters WordPress maintenance mode,
verifies the retained checkpoint bytes, and imports the pre-order checkpoint
through Duo's isolated control-plane `db import` operation. This order
matters: the checkpoint puts the custom extension back in `active_plugins`
while its v1 files are still absent from the target, so the public `duo
promote` must stage the exact v1 code before its lifecycle window. Maintenance
remains held across both the import and promote and is released only after
promote succeeds. A failed import/promote leaves the disposable pair
fail-closed; the exit trap keeps maintenance held when teardown is uncertain.
The restore explicitly removes the target-only customer/order while
preserving the v1 target event that was present when the checkpoint was taken.

The v1 rollback checkpoint keeps both a host-temp copy of the complete v1
canonical `state/` tree and a pair-local SQL dump. Its SHA-256 is recorded when
the dump is made, compared with the retained host copy, and rechecked
immediately before import. The isolated control-plane import skips ordinary
plugins, themes, and user MU code, while the following public promote stages
the restored v1 files before lifecycle activation. This restores the v1
canonical product record and target boundary before the final semantic
recapture; raw observations may differ only in manifest-declared derived fields,
while authored/non-derived state and paths remain identical. The refused Woo
deletion never promoted a tombstone. The rollback
scope is intentionally honest: it restores the database and v1 repository
inputs captured at that boundary; it is not a claim of physical erasure or a
rollback of unrelated external systems.

The final comparator compiles both complete trees, then reparses post front
matter with object insertion order intact and removes only top-level fields
classified as `derived` by the pinned policy before directly encoding the
projection. Nested authored metadata such as WooCommerce
`_product_attributes` therefore remains order-sensitive; non-post state,
deletion intents, identity-bearing paths, and post bodies remain strict.

Every deploy receipt is selected as exactly one new `deploy-<run>.json` file
relative to the pre-deploy inventory. Every promote receipt is derived from
the command's printed `promote-<run>.sql` checkpoint, requires that non-empty
pair-local checkpoint, and is verified by recomputing the artifact hash with
the repository's `Duo\Canon::encode` contract after removing
`artifact_hash`. This verifies the receipt content and associates the receipt
and checkpoint by their exact run name; it does not claim that the SQL bytes
are cryptographically included in the promotion receipt.

The deletion boundary is intentionally explicit: ordinary Woo public deletion
can remove a source product, but capture refuses before mutating canonical
state or Duo's `duo_map`, `duo_state`, and `duo_kv` ledgers because no exhaustive Woo/extension reverse-reference authority is
declared. Operators must perform relation repair and migration manually (for
example grouped `_children`, download/subscription/order references, and
shipping/tax cache/API effects) until a future version-pinned adapter contract
exists. The generic synthetic regressions remain the honest place for
guard/tombstone ordering; this live scenario proves the public Woo/manual
boundary and target preservation.

Automatic Woo derived-state repair is similarly scoped, but independently of
deletion authority. Apply dispatches the manifest product provider only for
the affected product/variation batch; that batch verifies effective prices,
`wc_product_meta_lookup`, and exact per-product sale schedules with lease
heartbeats around its bounded loops. `wc_product_attributes_lookup` is an
explicit manual regeneration/verification boundary. Manifest cache commands are
selected only when the current authored table or option surfaces intersect
their exact triggers. The legacy whole-catalog `WooCommerceContract::rebuild()`
projection is deleted from engine core outright (DUO-3341); the bounded batch
regeneration above is the only automatic projection path. In particular,
`wc_category_lookup` remains derived but outside this certification because
WooCommerce 11.0.0 exposes only a public whole-catalog regeneration method;
an operator must repair and verify that table explicitly until a bounded
public contract exists.

Persistent external object-cache behavior is not certified by this scenario;
the pair uses WordPress's stock cache implementation. The manifest's cache
flush effect is exercised, but Redis/Memcached availability, eviction, and
cross-node invalidation require a separate environment-specific certification.

Offline validation is:

```sh
bash sandbox/tests/regress_ecommerce_developer_static.sh
php sandbox/tests/regress_ecommerce_extension_migration.php
php sandbox/tests/regress_capture_atomicity.php
```

That check runs shell syntax validation, lints every fixture PHP file, checks
the pinned fixture/JSON/lifecycle contracts, and mutation-tests the checkout,
receipt, runtime-isolation, ACF-schema, frontend, REST, and rollback guards.
They are Docker-free; the bounded HTTP/database assertions remain live-only.

These suites are also part of `make regress-offline-all`, the local merge gate
while hosted CI is disabled. The `grind-ecommerce-developer-live` Make target is intentionally gated by explicit
`ECOMMERCE_PAIR`, `PORT1`, and `PORT2` values;
the owner should authorize a unique `ECOMMERCE_PAIR` and ports before running
it.
