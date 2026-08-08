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
- target derived index rows and Store API price/attribute filters, including
  exact `wc_product_meta_lookup` and `wc_product_attributes_lookup` values;
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
- native WordPress alphabetical `active_plugins` order accepted after
  provider-first lifecycle activation, while a custom-without-Woo dependency
  request is refused before target mutation;
- ordinary state drift/conflict resolution and target code drift healing;
- dependency-aware extension removal while Woo/ACF and runtime order data stay;
- exact v1 code/state plus a maintenance-held, pair-local pre-order database
  checkpoint restore performed before the public v1 promotion;
- byte-identical final recapture, clean status, and scoped pair/database
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
canonical product record and target boundary before the final byte-identical
recapture; the refused Woo deletion never promoted a tombstone. The rollback
scope is intentionally honest: it restores the database and v1 repository
inputs captured at that boundary; it is not a claim of physical erasure or a
rollback of unrelated external systems.

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
deletion authority. Apply dispatches the manifest product regenerator only for
the affected product/variation batch; that batch verifies effective prices,
product and global-attribute lookup rows, and exact per-product sale schedules
with lease heartbeats around its bounded loops. Manifest cache commands are
selected only when the current authored table or option surfaces intersect
their exact triggers. The legacy whole-catalog `WooCommerceContract::rebuild()`
path is not an automatic promotion authority. In particular,
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
while hosted CI is disabled. The live command is intentionally not wired into `Makefile` by this scenario;
the owner should authorize a unique `ECOMMERCE_PAIR` and ports before running
it.
