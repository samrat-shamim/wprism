seed_woocommerce_content() {
  # Reuse the standalone WooCommerce fixture: products, coupon, media,
  # global attributes, shipping methods, tax, and a source-only HPOS order.
  wp_conf1() { wp1 "$@"; }
  wp_env() {
    local env="$1"; shift
    case "$env" in
      conf1) wp1 "$@" ;;
      conf2) wp2 "$@" ;;
      *) fail "unknown WooCommerce seed environment: $env" ;;
    esac
  }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/woocommerce.sh
  unset -f wp_conf1 wp_env
}

postdeploy_woocommerce_content() {
  wp_conf2() { wp2 "$@"; }
  . conformance/postdeploy/woocommerce.sh
  unset -f wp_conf2
}

check_woocommerce_content() {
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local WOOCOMMERCE_BOUNDARY_ONLY=1
  local WOOCOMMERCE_EXPECTED_VERSION="$WOO_VERSION"
  . conformance/checks/woocommerce.sh
}

woocommerce_boundary_observation() {
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  observe_woocommerce conf2
}

woocommerce_boundary_storage_hash() {
  # The projection remains callable while the extension is absent, so it
  # distinguishes retained state from a false-green missing-code refusal.
  wp2 eval '
    global $wpdb;
    $queries = [
      "catalog_posts" => "SELECT * FROM {$wpdb->posts} WHERE post_type IN (\"attachment\",\"product\",\"product_variation\",\"shop_coupon\") ORDER BY ID",
      "catalog_meta" => "SELECT pm.* FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN (\"attachment\",\"product\",\"product_variation\",\"shop_coupon\") ORDER BY pm.meta_id",
      "catalog_comments" => "SELECT c.* FROM {$wpdb->comments} c INNER JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE p.post_type IN (\"product\",\"product_variation\") ORDER BY c.comment_ID",
      "catalog_commentmeta" => "SELECT cm.* FROM {$wpdb->commentmeta} cm INNER JOIN {$wpdb->comments} c ON c.comment_ID=cm.comment_id INNER JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE p.post_type IN (\"product\",\"product_variation\") ORDER BY cm.meta_id",
      "catalog_terms" => "SELECT t.* FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE tt.taxonomy LIKE \"product\\_%\" OR tt.taxonomy LIKE \"pa\\_%\" ORDER BY t.term_id",
      "catalog_taxonomy" => "SELECT * FROM {$wpdb->term_taxonomy} WHERE taxonomy LIKE \"product\\_%\" OR taxonomy LIKE \"pa\\_%\" ORDER BY term_taxonomy_id",
      "catalog_relationships" => "SELECT tr.* FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tt.taxonomy LIKE \"product\\_%\" OR tt.taxonomy LIKE \"pa\\_%\" ORDER BY tr.object_id,tr.term_taxonomy_id",
      "catalog_termmeta" => "SELECT tm.* FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=tm.term_id WHERE tt.taxonomy LIKE \"product\\_%\" OR tt.taxonomy LIKE \"pa\\_%\" ORDER BY tm.meta_id",
      "options" => "SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name IN (\"duo_target_environment_neighbor\",\"pickup_location_pickup_locations\",\"woocommerce_calc_taxes\",\"woocommerce_maybe_regenerate_images_hash\",\"woocommerce_paypal_settings\",\"woocommerce_pickup_location_settings\",\"woocommerce_price_num_decimals\",\"woocommerce_thumbnail_cropping\",\"woocommerce_thumbnail_cropping_custom_height\",\"woocommerce_thumbnail_cropping_custom_width\",\"woocommerce_thumbnail_image_width\") ORDER BY option_name",
      "attributes" => "SELECT * FROM {$wpdb->prefix}woocommerce_attribute_taxonomies ORDER BY attribute_id",
      "zones" => "SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zones ORDER BY zone_id",
      "zone_locations" => "SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zone_locations ORDER BY location_id",
      "zone_methods" => "SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zone_methods ORDER BY instance_id",
      "tax_classes" => "SELECT * FROM {$wpdb->prefix}wc_tax_rate_classes ORDER BY tax_rate_class_id",
      "tax_rates" => "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_id",
      "tax_locations" => "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rate_locations ORDER BY location_id",
      "product_lookup" => "SELECT * FROM {$wpdb->prefix}wc_product_meta_lookup ORDER BY product_id",
      "attribute_lookup" => "SELECT * FROM {$wpdb->prefix}wc_product_attributes_lookup ORDER BY product_or_parent_id,product_id,taxonomy,term_id",
      "category_lookup" => "SELECT * FROM {$wpdb->prefix}wc_category_lookup ORDER BY category_tree_id,category_id",
      "download_directories" => "SELECT * FROM {$wpdb->prefix}wc_product_download_directories ORDER BY id",
      "target_orders" => "SELECT * FROM {$wpdb->prefix}wc_orders WHERE billing_email=\"target-runtime@example.test\" ORDER BY id",
      "target_order_addresses" => "SELECT a.* FROM {$wpdb->prefix}wc_order_addresses a INNER JOIN {$wpdb->prefix}wc_orders o ON o.id=a.order_id WHERE o.billing_email=\"target-runtime@example.test\" ORDER BY a.id",
      "target_order_operational" => "SELECT o.* FROM {$wpdb->prefix}wc_order_operational_data o INNER JOIN {$wpdb->prefix}wc_orders p ON p.id=o.order_id WHERE p.billing_email=\"target-runtime@example.test\" ORDER BY o.id",
      "target_order_meta" => "SELECT m.* FROM {$wpdb->prefix}wc_orders_meta m INNER JOIN {$wpdb->prefix}wc_orders o ON o.id=m.order_id WHERE o.billing_email=\"target-runtime@example.test\" ORDER BY m.id",
      "target_session" => "SELECT * FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key=\"duo-target-runtime-session\" ORDER BY session_id",
      "target_action" => "SELECT a.* FROM {$wpdb->prefix}actionscheduler_actions a WHERE a.hook=\"duo_woo_target_runtime_probe\" ORDER BY a.action_id",
      "target_action_group" => "SELECT g.* FROM {$wpdb->prefix}actionscheduler_groups g INNER JOIN {$wpdb->prefix}actionscheduler_actions a ON a.group_id=g.group_id WHERE a.hook=\"duo_woo_target_runtime_probe\" ORDER BY g.group_id",
    ];
    $state = [];
    foreach ($queries as $key => $sql) {
      $wpdb->last_error = "";
      $rows = $wpdb->get_results($sql, ARRAY_A);
      if ($wpdb->last_error !== "") { throw new RuntimeException("WooCommerce lifecycle storage read failed for " . $key); }
      if (count($rows) > 50000) { throw new RuntimeException("WooCommerce lifecycle storage projection exceeded its fixture bound"); }
      $state[$key] = $rows;
    }
    $state["media_files"] = [];
    foreach (get_posts(["post_type"=>"attachment","post_status"=>"any","numberposts"=>50001,"fields"=>"ids","orderby"=>"ID","order"=>"ASC"]) as $attachment_id) {
      $file = get_attached_file((int) $attachment_id, true);
      $state["media_files"][] = ["id" => (int) $attachment_id, "present" => is_string($file) && is_file($file), "sha256" => is_string($file) && is_file($file) ? hash_file("sha256", $file) : null];
    }
    if (count($state["media_files"]) > 50000) { throw new RuntimeException("WooCommerce lifecycle media projection exceeded its fixture bound"); }
    echo hash("sha256", wp_json_encode($state, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  '
}

check_woocommerce_boundary_lifecycle() { # <exact-version> <verified-artifact>
  local version="$1" artifact="$2" expected_sha before_native reactivated_native
  local before_uninstall absent_after_uninstall missing_before missing_after missing_rc missing_out lifecycle_diff
  expected_sha=$(jq -r --arg version "$version" '.plugins.woocommerce[$version].sha256' conformance/artifacts.lock.json)
  [[ "$expected_sha" =~ ^[0-9a-f]{64}$ ]] || fail "WooCommerce $version lifecycle artifact has no exact lock digest"
  [ "$(wp2 eval "echo hash_file('sha256','$artifact');")" = "$expected_sha" ] || fail "WooCommerce $version lifecycle artifact digest moved before reinstall proof"
  [ "$(wp2 eval 'echo defined("WC_REMOVE_ALL_DATA") && true === WC_REMOVE_ALL_DATA ? "yes" : "no";')" = no ] || fail "WooCommerce $version default-retention lifecycle premise unexpectedly enables destructive cleanup"
  before_native=$(woocommerce_boundary_observation)
  require_observed_nonempty "WooCommerce $version native state before deactivation" "$before_native"
  wp2 plugin deactivate woocommerce >/dev/null
  wp2 plugin is-active woocommerce >/dev/null 2>&1 && fail "WooCommerce $version deactivation premise did not land"
  wp2 duo deploy --repo=/siterepo >/dev/null
  wp2 plugin is-active woocommerce >/dev/null || fail "WooCommerce $version deploy did not reactivate the exact plugin"
  reactivated_native=$(woocommerce_boundary_observation)
  [ "$reactivated_native" = "$before_native" ] || fail "WooCommerce $version deactivate/reactivate changed native authored or target-runtime state"
  wp2 plugin deactivate woocommerce >/dev/null
  before_uninstall=$(woocommerce_boundary_storage_hash)
  require_observed_nonempty "WooCommerce $version raw storage before default uninstall" "$before_uninstall"
  wp2 plugin uninstall woocommerce >/dev/null
  wp2 plugin is-installed woocommerce >/dev/null 2>&1 && fail "WooCommerce $version uninstall left plugin code installed"
  absent_after_uninstall=$(woocommerce_boundary_storage_hash)
  [ "$absent_after_uninstall" = "$before_uninstall" ] || fail "WooCommerce $version default uninstall changed retained authored or target-runtime storage"
  missing_before="$absent_after_uninstall"; missing_rc=0
  missing_out=$(wp2 duo deploy --repo=/siterepo 2>&1) || missing_rc=$?
  require_duo_answered "WooCommerce $version deploy with code absent" human "$missing_out"
  [ "$missing_rc" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$missing_out" || fail "WooCommerce $version missing-code deploy did not refuse at compatibility: $missing_out"
  missing_after=$(woocommerce_boundary_storage_hash)
  [ "$missing_after" = "$missing_before" ] || fail "WooCommerce $version missing-code refusal partially changed retained storage"
  wp2 plugin install "$artifact" --force >/dev/null
  [ "$(wp2 plugin get woocommerce --field=version)" = "$version" ] || fail "WooCommerce exact reinstall reported the wrong version at $version"
  wp2 duo deploy --repo=/siterepo >/dev/null
  wp2 plugin is-active woocommerce >/dev/null || fail "WooCommerce $version exact reinstall was not active after deploy"
  check_woocommerce_content
  wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-woo-lifecycle-final >/dev/null
  lifecycle_diff=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-woo-lifecycle-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-woo-lifecycle-final"
  [ -z "$lifecycle_diff" ] || fail "WooCommerce $version exact-reinstall recapture lost byte identity: $lifecycle_diff"
  pass "WooCommerce $version deactivate/reactivate, retained-data uninstall, absent-code refusal, digest-bound exact reinstall, native readback, recapture, and retry are clean"
}

check_woocommerce_product_delete_refusal() { # <exact-version>
  local version="$1" repo="siterepo/${PAIR}2" product order lookup_before lookup_after
  local product_file product_uuid expected_hash expected_revision source_path backup
  local before_tree after_tree before_head after_head before_origin after_origin plan_rc apply_rc force_rc plan_out apply_out force_out retry
  product=$(wp2 eval '$id=(int) wc_get_product_id_by_sku("CONF-WIDGET-1"); echo $id;')
  require_fixture_ids product
  order=$(wp2 eval "
\$product = wc_get_product($product);
if (!\$product) { throw new RuntimeException('WooCommerce deletion fixture product is absent'); }
\$order = wc_create_order(); \$order->add_product(\$product, 1); \$order->calculate_totals(); \$order->set_status('processing'); \$order->save(); echo \$order->get_id();")
  require_fixture_ids order
  lookup_before=0
  for _ in $(seq 1 8); do
    wp2 action-scheduler run >/dev/null
    lookup_before=$(wp2 db query "SELECT COUNT(*) FROM wp_wc_order_product_lookup WHERE order_id=$order AND product_id=$product" --skip-column-names)
    require_observed_nonempty "WooCommerce $version deletion order lookup count" "$lookup_before"
    [ "$lookup_before" -ge 1 ] && break
    sleep 2
  done
  [ "$lookup_before" -ge 1 ] || fail "WooCommerce $version deletion fixture did not materialize its native order-product lookup"
  product_file=$(find "$repo/state/posts/product" -type f -name '*--conformance-widget.md' -print -quit)
  [ -n "$product_file" ] || fail "WooCommerce $version deletion fixture cannot locate the captured product state"
  product_uuid=$(basename "$product_file" | cut -d- -f1-5)
  [[ "$product_uuid" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]] || fail "WooCommerce $version deletion fixture has a malformed product UUID"
  expected_hash=$(shasum -a 256 "$product_file" | awk '{print $1}')
  expected_revision=$(wp2 eval 'echo \Duo\RepositoryCompiler::compile("/siterepo", \Duo\Policy::load("/siterepo"))->revision_hash();')
  require_observed_nonempty "WooCommerce $version deletion expected revision" "$expected_revision"
  source_path="posts/product/$(basename "$product_file")"; backup="$repo/.tmp-woocommerce-product-delete.md"
  mkdir -p "$repo/state/deletions"; mv "$product_file" "$backup"
  jq -n --arg expected_hash "$expected_hash" --arg expected_revision "$expected_revision" --arg source_path "$source_path" --arg uuid "$product_uuid" \
    '{expected_hash:$expected_hash,expected_revision:$expected_revision,format:"duo-deletion/v1",kind:"post",source_path:$source_path,type:"product",uuid:$uuid}' > "$repo/state/deletions/$product_uuid.json"
  before_tree=$(git -C "$repo" status --porcelain); before_head=$(git -C "$repo" rev-parse HEAD); before_origin=$(git -C "$repo" rev-parse refs/remotes/origin/main)
  set +e
  plan_rc=0; plan_out=$(wp2 duo plan --repo=/siterepo --format=json 2>&1) || plan_rc=$?
  require_duo_answered "WooCommerce $version deletion refusal plan" json "$plan_out"
  apply_rc=0; apply_out=$(wp2 duo apply --repo=/siterepo --with-deletes --default-author=admin 2>&1) || apply_rc=$?
  require_duo_answered "WooCommerce $version deletion refusal apply" human "$apply_out"
  force_rc=0; force_out=$(wp2 duo apply --repo=/siterepo --with-deletes --force-delete-referenced --default-author=admin 2>&1) || force_rc=$?
  require_duo_answered "WooCommerce $version forced deletion refusal apply" human "$force_out"
  set -e
  [ "$plan_rc" -ne 0 ] && [ "$apply_rc" -ne 0 ] && [ "$force_rc" -ne 0 ] || fail "WooCommerce $version accepted unsupported product deletion"
  for output in "$plan_out" "$apply_out" "$force_out"; do grep -Fq 'deletion intent for post:product is unsupported' <<<"$output" || fail "WooCommerce $version deletion refusal did not name the missing capability: $output"; done
  [ "$(wp2 eval '$id=(int) wc_get_product_id_by_sku("CONF-WIDGET-1"); echo $id;')" = "$product" ] || fail "WooCommerce $version refusal changed the target product identity"
  lookup_after=$(wp2 db query "SELECT COUNT(*) FROM wp_wc_order_product_lookup WHERE order_id=$order AND product_id=$product" --skip-column-names)
  require_observed_nonempty "WooCommerce $version retained order lookup count" "$lookup_after"
  [ "$lookup_after" = "$lookup_before" ] || fail "WooCommerce $version refusal changed the native order-product lookup"
  [ "$(wp2 wc shop_order get "$order" --field=status --user=admin)" = processing ] || fail "WooCommerce $version refusal changed the target HPOS order"
  after_tree=$(git -C "$repo" status --porcelain); [ "$after_tree" = "$before_tree" ] || fail "WooCommerce $version refusal mutated the authored deletion intent"
  after_head=$(git -C "$repo" rev-parse HEAD); after_origin=$(git -C "$repo" rev-parse refs/remotes/origin/main)
  [ "$after_head" = "$before_head" ] && [ "$after_origin" = "$before_origin" ] || fail "WooCommerce $version refusal moved the disposable repository revision"
  rm "$repo/state/deletions/$product_uuid.json"; rmdir "$repo/state/deletions"; mv "$backup" "$product_file"
  retry=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
  require_duo_answered "WooCommerce $version deletion retry plan" json "$retry"
  echo "$retry" | jq -e '(.delete | length) == 0 and (.delete_conflict | length) == 0' >/dev/null || fail "WooCommerce $version deletion retry did not settle after intent removal: $retry"
  [ -z "$(git -C "$repo" status --porcelain)" ] || fail "WooCommerce $version deletion fixture did not restore its disposable repository state"
  pass "WooCommerce $version product deletion refuses before product/order/repository mutation and retry settles"
}
