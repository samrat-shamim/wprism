#!/usr/bin/env bash
# WooCommerce render/API-level acceptance (task #92): byte-identical
# canonical state alone doesn't prove pa_size/pa_color's zero-provisioning
# claim — this check confirms conf2's own WooCommerce runtime (a FRESH
# process, exactly the acceptance criterion's own wording) resolves
# wc_get_attribute_taxonomies() and the variable product's variations
# correctly, using WHATEVER local ids conf2 assigned them (never conf1's),
# with zero manual pre-provisioning step anywhere in run.sh's own flow —
# the same live proof already run on the dedicated r3e pair
# (sandbox/tests/regress_pa_attributes.sh), now folded into the ordinary
# conformance sweep.
#
# Invoked by conformance/run.sh after a clean apply, from the sandbox/
# directory; $COMPOSE/fail/pass are exported by run.sh itself (matching
# every other checks/*.sh file's convention — see checks/ninja-forms.sh).
set -euo pipefail

API_OUT=$($COMPOSE run --rm -T cli2 wp eval '
$taxes = wc_get_attribute_taxonomies();
$names = array_map(fn($t) => $t->attribute_name, $taxes);
sort($names);
echo implode(",", $names) . "|";

$products = get_posts(["post_type" => "product", "posts_per_page" => -1]);
$variable_count = 0;
$variation_total = 0;
$purchasable_variation = false;
foreach ($products as $p) {
  $prod = wc_get_product($p->ID);
  if (!$prod || $prod->get_type() !== "variable") continue;
  $variable_count++;
  $vars = $prod->get_available_variations();
  $variation_total += count($vars);
  foreach ($prod->get_children() as $child_id) {
    $variation = wc_get_product($child_id);
    if ($variation && $variation->is_purchasable()) { $purchasable_variation = true; }
  }
}
echo "$variable_count|$variation_total|" . ($purchasable_variation ? "yes" : "no");
' 2>&1 | tail -1)
echo "conf2 attribute/variation check: $API_OUT"

echo "$API_OUT" | grep -q "conf-color,conf-size" \
  || fail "conf2's wc_get_attribute_taxonomies() does not list both conf-size and conf-color (got: $API_OUT) -- taxonomy_patterns zero-provisioning did not hold"

IFS='|' read -r _ VAR_COUNT VARIATION_TOTAL PURCHASABLE <<< "$API_OUT"
[ "$VAR_COUNT" -ge 1 ] || fail "conf2 has zero variable products resolving via wc_get_product() (got: $API_OUT)"
[ "$VARIATION_TOTAL" -ge 2 ] || fail "conf2's variable product resolved fewer than 2 available variations (got: $API_OUT)"
[ "$PURCHASABLE" = "yes" ] || fail "conf2 has no purchasable variation at all (got: $API_OUT) -- _manage_stock/_stock_status/_regular_price did not round-trip correctly per variation"

pass "conf2 resolves both attribute taxonomies + the variable product's variations via WooCommerce's own APIs, zero manual pre-provisioning, at least one variation purchasable"

TERM_META_OUT=$($COMPOSE run --rm -T cli2 wp eval '
$term = get_term_by("slug", "conformance-widgets", "product_cat");
$id = $term ? (int) get_term_meta($term->term_id, "thumbnail_id", true) : 0;
$attachment = $id ? get_post($id) : null;
$file = $id ? get_attached_file($id) : "";
echo ($term ? "term" : "missing-term") . "|$id|" . ($attachment ? $attachment->post_type : "missing") . "|" . (($file && is_file($file)) ? "file" : "missing-file");
' 2>&1 | tail -1)
echo "conf2 product-category thumbnail check: $TERM_META_OUT"
echo "$TERM_META_OUT" | grep -Eq '^term\|[1-9][0-9]*\|attachment\|file$' \
  || fail "conf2 product_cat thumbnail_id did not resolve to a local attachment with a real media file (got: $TERM_META_OUT)"
pass "conf2 product_cat thumbnail_id resolves through termmeta to its own local attachment and media file"
