#!/usr/bin/env bash
# The target-only Review Order page proves ordinary apply preserves a distinct
# slug/type/parent identity even when an authored option stops selecting it.
# Remove only that test-owned row before the generic canonical recapture.
set -euo pipefail

woocommerce_postapply_review_page_sentinel() {
  local target_ids_file sentinel_id observed

  command -v wp_conf2 >/dev/null \
    || fail 'WooCommerce post-apply hook requires the target WordPress command boundary'
  : "${CONF_REPO2:?WooCommerce post-apply hook requires CONF_REPO2}"
  target_ids_file="$CONF_REPO2/.tmp-woocommerce-target.json"
  [ -f "$target_ids_file" ] \
    || fail "WooCommerce target identity premise is missing: $target_ids_file"
  sentinel_id=$(jq -er '.hostile_review_page' "$target_ids_file")
  require_fixture_ids sentinel_id

  observed=$(wp_conf2 eval '
$id = '"$sentinel_id"';
$post = get_post($id);
if (!$post instanceof WP_Post || (int) $post->ID !== $id) {
    throw new RuntimeException("target-local Review Order sentinel identity changed");
}
echo wp_json_encode([
    "id" => (int) $post->ID,
    "option_points_here" => (int) get_option("woocommerce_review_order_page_id", 0) === $id,
    "post" => [
        "content" => $post->post_content,
        "parent" => (int) $post->post_parent,
        "slug" => $post->post_name,
        "status" => $post->post_status,
        "title" => $post->post_title,
        "type" => $post->post_type,
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
') || fail 'WooCommerce target-local Review Order sentinel could not be observed after apply'
  require_observed_nonempty 'WooCommerce target-local Review Order sentinel after apply' "$observed"
  jq -se --argjson id "$sentinel_id" '
    length == 1 and .[0] == {
      id:$id,
      option_points_here:false,
      post:{
        content:"<!-- wp:shortcode -->[woocommerce_review_order]<!-- /wp:shortcode -->",
        parent:0,
        slug:"hostile-review-route",
        status:"publish",
        title:"Hostile selected review page",
        type:"page"
      }
    }
  ' <<<"$observed" >/dev/null \
    || fail 'WooCommerce apply mutated, selected, or deleted the distinct target-local Review Order page'
  pass 'WooCommerce preserved the distinct target-local Review Order page while selecting the authored canonical route'

  wp_conf2 eval '
global $wpdb;
$id = '"$sentinel_id"';
if (!wp_delete_post($id, true)) {
    throw new RuntimeException("target-local Review Order sentinel cleanup failed");
}
$counts = [
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID=%d", $id)),
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d", $id)),
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id=%d", $id)),
];
if ($counts !== [0, 0, 0]) {
    throw new RuntimeException("target-local Review Order sentinel cleanup retained durable owner rows");
}
' >/dev/null || fail 'WooCommerce target-local Review Order sentinel could not be removed after its preservation proof'
  pass 'WooCommerce removed the test-owned Review Order sentinel before canonical recapture'
}

woocommerce_postapply_review_page_sentinel
unset -f woocommerce_postapply_review_page_sentinel
