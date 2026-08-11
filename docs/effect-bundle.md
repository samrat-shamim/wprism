# Lifecycle and rebuild effect rollback contracts

The effect bundle is the lifecycle/rebuild-action/regenerator slice of verified
SSH rollback. It compiles every possible mutation into an immutable inventory,
prepares prior evidence while maintenance exclusion is held, binds that
evidence to the signed rollback receipt, and refuses undeclared runtime effects.
It is consumed by the integrated automatic profile: `duo promote` passes the
compiled plan inventory into receipt preparation and uses the provider's live
receipt evidence and inverse operation under the signed generation fence.

## Manifest grammar

Plugin/theme lifecycle effects use top-level `lifecycle_effects`. A rebuild
action (DUO-3338's structured `actions` channel, which retired the free-form
`rebuilders` command strings) uses `actions[].effects`; a row-keyed regenerator
uses
`post_types.<type>.regen_dependency.effects`. Each is a non-empty list. Every
effect has exact `id`, `kind`, `mode`, and `selector` fields:

```json
{
  "id": "probe-http",
  "kind": "http",
  "mode": "prevented",
  "prevention": "receipt_outbox",
  "selector": {
    "scope": "external",
    "type": "url_prefix",
    "value": "https://example.test/hooks/"
  }
}
```

Modes have intentionally different proof obligations:

- `restorable` is limited to `database_checkpoint` selectors naming an exact
  table or option. The encrypted checkpoint is its before-image.
- `reversible` requires a version-pinned `adapter` object with exact `id`,
  `version`, `inverse`, `inverse_inputs`, `verifier`, and `verifier_inputs`.
  Inverse and verifier input lists are non-empty receipt field names.
- `prevented` is limited to mail, HTTP, and queue effects with
  `prevention: receipt_outbox`. A reporting observer is not prevention.
- `irreversible` is an explicit automatic-profile blocker. A generic
  `plugin_lifecycle` selector must use this mode because arbitrary third-party
  hooks cannot truthfully claim a bounded inverse.

Selectors use exact `{scope,type,value}` objects. Wildcards, control bytes,
traversal, unbounded or query-bearing URLs, secret-shaped values, missing
fields, and unknown fields are rejected during policy compilation. Missing
lifecycle/action/regenerator declarations compile to explicit
`irreversible` rows; they never silently disappear from the inventory.

The compiled artifact and plan carry a deterministically sorted
`effects_inventory`. Engine-owned future-post scheduling and taxonomy counts
are checkpoint-restorable. The external object cache is reversible only
through its pinned inverse/readback contract.

## WooCommerce boundary

The committed WooCommerce manifest declares plugin lifecycle as
`irreversible`. Its rebuild actions and product regenerators name the exact
checkpoint-restorable database surfaces they can mutate, including the real
`_transient_*` option rows used by WordPress rather than Woo's logical
transient names. The attribute-taxonomy and shipping declarations bind the
specific `wc_attribute_taxonomies` and `shipping-transient-version` logical
keys to bounded provider resources; neither uses the generic `transient`
namespace as a selector.

Each product regenerator declares the four fixed product transient keys
(`wc_products_onsale`, `wc_featured_products`, `wc_outofstock_count`, and
`wc_low_stock_count`) and both value/timeout rows for each, plus the value and
timeout rows for `product-transient-version`. It also carries one explicit,
wildcard-free provider-resource aggregate whose finite families are:
`product`, `object`, `products`, and `product_objects` cache groups;
product-specific transient families `wc_product_children`,
`wc_var_prices`, `wc_related`, `wc_child_has_weight`, and
`wc_child_has_dimensions`; the product transient version; and
the exact attribute-registry transient `wc_attribute_taxonomies`; and
`wc_layered_nav_counts`. The aggregate also names the finite generic
WordPress groups touched by `clean_post_cache()` and
`clean_object_term_cache()` (`posts`, `post_meta`, `terms`, and `general`),
plus the dynamic `post_parent:<id>` key within `posts` and
`<taxonomy>_relationships` group families. The selector's declarative
`members` object contains exact values and finite templates; the only core
typed placeholders are `{positive_uint}` (a canonical positive decimal id of
1–19 digits, without leading zeroes) and `{slug}` (a bounded, lower-case or
case-lacking WordPress-style identifier — Unicode letters and decimal
digits, e.g. a multibyte WooCommerce ≥11.0.0 attribute taxonomy name, not
ASCII-only; see `spec/repo-format.md` for the exact grammar, DUO-3437).
The first four groups and the family names receive concrete runtime keys (for
example, `post_parent:42` within `posts` and `pa_color_relationships` for a
product attribute).
The relationship family is restricted to Woo's fixed product taxonomies
(`product_type`, `product_visibility`, `product_cat`, `product_tag`, and
`product_shipping_class`) plus concrete `pa_<slug>` attribute taxonomies.
Within those groups, the callback boundary covers the product-id,
`post_parent:<id>`, and `last_changed` keys in `posts`, the object id in
`post_meta`, `last_changed` in `terms`, and `wp_get_archives` in `general`.
The aggregate is the single bounded authority for those dynamic names; an
observer maps only a concrete `{scope,type,value}` selector against its
declared exact/template members and rejects every other key. The aggregate
itself is not a member and cannot be observed as a substitute for one of its
concrete resources. No selector or member template contains a wildcard or an
unbounded id/attribute pattern; unknown placeholders, duplicate members,
extra selector keys, and malformed templates fail during policy/inventory
validation.
The canonical concrete event form is
`woocommerce-product-cache-event:v1:group=product;id=42`,
`...:transient=wc_layered_nav_counts_pa_color`, or
`...:cache_group=pa_color_relationships;key=42`; direct compact forms such as
`product_42` and `wc_var_prices_42` are accepted only for this aggregate.
Malformed ids, unknown families, wildcard values, and generic namespaces are
refused before the provider is called.

Woo 11.0.0's `wc_delete_product_transients()` also refreshes
`product-transient-version` through `get_transient_version('product', true)`
and fires `woocommerce_delete_product_transients`. The manifest separately
declares the exact WordPress read/set filters and actions for that key (the
`pre_transient_*`, `transient_*`, `pre_set_transient_*`,
`expiration_of_transient_*`, `set_transient_*`, generic `set_transient`, and
deprecated `setted_transient` boundaries), as well as
`woocommerce_product_read` and `woocommerce_updated_product_price`.
`WC_Cache_Helper::invalidate_attribute_count(array_keys($product->get_attributes()))`
queues the concrete `wc_layered_nav_counts_<attribute>` keys. Live product and
variation objects supply their own keys; deletion-only batches conservatively
invalidate the finite registered Woo taxonomy set because the deleted object
cannot be loaded after raw SQL deletion. A variation also invalidates its
parent's fixed/specific transients and product cache, matching Woo's public
11.x clear-cache behavior.

The ordinary, manual `duo promote` path remains supported. Operators may
continue to use it with an explicit review/rollback plan; the effect bundle
does not turn WooCommerce's public lifecycle or dynamic caches into a claim
of automatic reversibility.

The exact inventory is pinned by
`sandbox/tests/regress_woocommerce_effect_contract.php`: both product types
name `posts`, `postmeta`, both lookup tables, every fixed transient value and
timeout row, both product-version rows, and the attribute-taxonomy transient
value/timeout rows as checkpoint-restorable. The
offline product fake exercises fixed deletes, concrete id-suffixed product
families, product-version get/set, concrete fixed-delete callbacks, and
concrete layered-nav invalidation; it
does not leave `wc_delete_product_transients()` or attribute invalidation as a
false-green no-op. Runtime observations therefore carry concrete IDs and
attribute names and reconcile to the declared aggregate's finite family list.
The attribute transient command declares the exact WordPress
`delete_transient_wc_attribute_taxonomies` and `deleted_transient` callback
boundaries. The shipping invalidator declares the exact transient read/set
filters and actions for `shipping-transient-version`, including generic
`set_transient` and deprecated `setted_transient`. It immediately reads the
version back with `get_transient_version('shipping', false)` and fails if the
returned value differs from the refresh result; a truthy refresh return alone
is not persistence proof. All corresponding external-cache rows remain
explicit automatic blockers until a bounded inverse and fresh readback are
proven. Each regenerator separately declares the attribute-registry transient
delete/get/set callbacks, including the generic `deleted_transient`,
`set_transient`, and `setted_transient` actions, because that refresh executes
in the regenerator phase rather than the standalone rebuild phase.

Scope boundary: the `clean_post_cache` and `clean_object_term_cache` callback
boundaries are declared as irreversible hooks, and the finite generic groups
they touch are included in the Woo provider aggregate for observation. The
adapter's direct `wp_cache_delete('lookup_table', 'object_<id>')` is likewise
covered by the bounded `object` family. No other generic WordPress cache
group, and not the unbounded WordPress `transient` namespace, is claimed by
this contract; an undeclared callback or group remains a hard observation
refusal rather than hidden rollback authority.

Woo's `woocommerce_before_attribute_delete`, `woocommerce_attribute_deleted`,
`woocommerce_flush_rewrite_rules`, and
`woocommerce_shipping_zone_method_added` hooks are intentionally not in this
inventory: typed attribute deletion and shipping CRUD setters are unsupported
raw-table paths here, so those callbacks are not invoked by the shipped
adapter.

## Target preparation and receipt binding

Configure an absolute provider argv vector:

```json
{
  "rollback_recovery": {
    "effect_provider": ["/opt/duo/bin/effect-provider", "production"]
  }
}
```

`recovery-probe` requires canonical evidence that the provider supports
database-checkpoint mapping, external resources, receipt outbox prevention,
and inverse readback without exposing credentials. After maintenance exclusion
is held, the controller sends a signed `duo-effect-bundle-request/v1` containing
the compiled inventory. Any irreversible, malformed, incompatible, secret-
shaped, undeclared, or unbounded effect blocks before the provider and before
code mutation.

Any `restorable` inventory row additionally requires the recovery
`checkpoint_provider`. Duo checks this before creating effect artifacts or
invoking the effect provider's `prepare` action, because the effect provider
cannot restore database rows. A read-only capability probe may already have
run while Duo established recovery readiness.

For accepted rows the provider writes immutable prior evidence. Restorable
rows name their checkpoint coverage, reversible rows bind exact inverse and
fresh-verifier input hashes plus prior state, and prevented rows reserve a
receipt-specific outbox identity. Duo independently validates and hashes both
the compile inventory and prior evidence. The metadata digest becomes the
receipt's provider-owned `lifecycle_receipts_sha256`; callers cannot supply it.

## Runtime reconciliation and rollback

Every actual lifecycle, rebuild-action, or regenerator effect must reconcile by
manifest, phase, effect id, kind, and exact selector to the prepared inventory.
An undeclared or broadened effect is a hard refusal and does not add authority.
For prevented effects, the provider must return a receipt-bound outbox digest
and attest that the external call was prevented. Observation alone is rejected.

`effects_inverse` runs only in `rolling_back` with an exact signed prepared
operation and input digest. The provider executes idempotent inverses using the
frozen receipt inputs. Duo then starts a second provider process for
`verify-prior`; its readback digest must exactly equal the inverse result.
Database rows remain the checkpoint executor's responsibility. External
caches, search indexes, cron services, queues, and files remain distinct
provider resources and cannot hide behind database coverage.

The offline certification is `make regress-effect-bundle` and is included in
`make regress-offline-all`.
