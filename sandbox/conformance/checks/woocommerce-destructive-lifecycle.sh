#!/usr/bin/env bash
# This fragment is sourced only after checks/woocommerce.sh's retained-data
# uninstall branch.  It proves the opposite native contract: WC_REMOVE_ALL_DATA
# destroys Woo-owned state, so recovery may rely only on the database-matched
# backup, never on an identity sidecar being silently treated as a replacement.

_woocommerce_destructive_context=1
for _woocommerce_destructive_symbol in fail pass require_duo_answered require_observed_nonempty \
  wp_conf2 observe_woocommerce_adoption woocommerce_storage_hash; do
  declare -F "$_woocommerce_destructive_symbol" >/dev/null 2>&1 || _woocommerce_destructive_context=0
done
for _woocommerce_destructive_value in CONF_REPO1 CONF_REPO2 COMPOSE WOO_SHA WOO_ARTIFACT; do
  [ -n "${!_woocommerce_destructive_value:-}" ] || _woocommerce_destructive_context=0
done
command -v jq >/dev/null 2>&1 || _woocommerce_destructive_context=0
if [ "$_woocommerce_destructive_context" -ne 1 ]; then
  printf '%s\n' 'woocommerce destructive lifecycle fragment was sourced outside checks/woocommerce.sh lifecycle context' >&2
  unset _woocommerce_destructive_context _woocommerce_destructive_symbol _woocommerce_destructive_value
  return 1 2>/dev/null || exit 1
fi
unset _woocommerce_destructive_context _woocommerce_destructive_symbol _woocommerce_destructive_value

check_woocommerce_destructive_lifecycle() {
  local remove_all_db="$CONF_REPO2/.tmp-woocommerce-remove-all.sql"
  local remove_all_identity="$CONF_REPO2/.tmp-woocommerce-remove-all-identity.json"
  local remove_all_helper="$CONF_REPO2/.tmp-woocommerce-remove-all.php"
  local remove_all_mu='/var/www/html/wp-content/mu-plugins/duo-woocommerce-remove-all.php'
  local preimage_capture="$CONF_REPO2/.tmp-woocommerce-remove-all-preimage"
  local final_capture="$CONF_REPO2/.tmp-woocommerce-remove-all-final"
  local preimage_storage preimage_observation preimage_identity destructive_residue
  local reinstall_deploy lost_plan lost_plan_rc lost_apply lost_apply_rc stale_identity stale_identity_rc
  local refusal_identity restored_observation restored_plan restored_apply repeat_plan repeat_apply capture_diff

  # Capture the exact target preimage before exporting the database-bound
  # identity sidecar.  This gives database import—not a new capture or apply—
  # sole credit for restoring every authored, lookup, and runtime byte.
  wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-woocommerce-remove-all-preimage >/dev/null
  [ -d "$preimage_capture" ] || fail 'WooCommerce destructive-uninstall preimage capture was not written'
  preimage_storage=$(woocommerce_storage_hash)
  preimage_observation=$(observe_woocommerce_adoption)
  preimage_identity=$(wp_conf2 eval '
    global $wpdb;
    $state = [
      "map" => $wpdb->get_results("SELECT uuid,entity_type,id_kind,local_id FROM {$wpdb->prefix}duo_map ORDER BY uuid,id_kind", ARRAY_A),
      "state" => $wpdb->get_results("SELECT uuid,entity_type,content_hash FROM {$wpdb->prefix}duo_state ORDER BY uuid", ARRAY_A),
      "kv" => $wpdb->get_results("SELECT k,v FROM {$wpdb->prefix}duo_kv ORDER BY k", ARRAY_A),
    ];
    echo hash("sha256", wp_json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  ')
  require_observed_nonempty 'WooCommerce destructive-uninstall storage preimage' "$preimage_storage"
  require_observed_nonempty 'WooCommerce destructive-uninstall runtime preimage' "$preimage_observation"
  require_observed_nonempty 'WooCommerce destructive-uninstall identity preimage' "$preimage_identity"

  wp_conf2 duo identity-export --repo=/siterepo --out=/siterepo/.tmp-woocommerce-remove-all-identity.json >/dev/null
  jq -e '.format == "duo-identity-ledger/v1" and (.maps | length) > 0 and (.states | length) > 0' \
    "$remove_all_identity" >/dev/null \
    || fail 'WooCommerce destructive-uninstall identity export omitted the populated ledger'
  wp_conf2 db export /siterepo/.tmp-woocommerce-remove-all.sql --add-drop-table >/dev/null
  [ -s "$remove_all_db" ] && [ -s "$remove_all_identity" ] \
    || fail 'WooCommerce destructive-uninstall database or identity backup is empty'

  # WC_REMOVE_ALL_DATA is intentionally a wp-config/mu-plugin decision.  Keep
  # the one-purpose control outside the archive and remove it immediately
  # after uninstall so an exact reinstall cannot inherit test authorization.
  wp_conf2 eval '
    $bytes = "<?php\ndefined(\"WC_REMOVE_ALL_DATA\") || define(\"WC_REMOVE_ALL_DATA\", true);\n";
    if (false === file_put_contents("/siterepo/.tmp-woocommerce-remove-all.php", $bytes)) {
      throw new RuntimeException("could not write WooCommerce destructive-uninstall control");
    }
  ' >/dev/null
  [ -s "$remove_all_helper" ] || fail 'WooCommerce destructive-uninstall control helper is empty'
  $COMPOSE run --rm -T --user=0 cli2 install -m 0644 \
    /siterepo/.tmp-woocommerce-remove-all.php "$remove_all_mu" \
    || fail 'could not install the WooCommerce destructive-uninstall control'
  rm -f "$remove_all_helper"
  wp_conf2 plugin deactivate woocommerce >/dev/null
  wp_conf2 plugin uninstall woocommerce >/dev/null
  wp_conf2 plugin is-installed woocommerce >/dev/null 2>&1 \
    && fail 'WooCommerce destructive uninstall left plugin code installed'

  destructive_residue=$(wp_conf2 eval '
    global $wpdb;
    $tables = [];
    foreach ([
      "woocommerce_attribute_taxonomies", "wc_product_meta_lookup", "wc_orders",
      "wc_order_addresses", "wc_order_operational_data", "wc_orders_meta",
    ] as $suffix) {
      $tables[$suffix] = null === $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->prefix . $suffix)) ? 0 : 1;
    }
    echo wp_json_encode([
      "catalog_posts" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN (\"product\",\"product_variation\",\"shop_coupon\")"),
      "catalog_taxonomy" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN (\"product_cat\",\"product_tag\",\"product_shipping_class\",\"product_type\",\"product_visibility\") OR taxonomy LIKE \"pa\\_%\""),
      "woocommerce_options" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name REGEXP \"^woocommerce_\""),
      "shared_target_action" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook=\"duo_woo_target_runtime_probe\""),
      "neighbor" => get_option("duo_target_environment_neighbor"),
      "tables" => $tables,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  ')
  jq -e '
    .catalog_posts == 0 and .catalog_taxonomy == 0 and .woocommerce_options == 0 and
    .tables.woocommerce_attribute_taxonomies == 0 and .tables.wc_product_meta_lookup == 0 and
    .tables.wc_orders == 0 and .tables.wc_order_addresses == 0 and
    .tables.wc_order_operational_data == 0 and .tables.wc_orders_meta == 0 and
    .shared_target_action == 1 and .neighbor == "target-neighbor-preserved"
  ' <<<"$destructive_residue" >/dev/null \
    || fail "WooCommerce destructive uninstall left owned catalog/options/lookup/HPOS state or crossed an unrelated boundary: $destructive_residue"
  [ -s "$remove_all_db" ] && [ -s "$remove_all_identity" ] && [ -d "$preimage_capture" ] \
    || fail 'WooCommerce destructive uninstall crossed the retained recovery backups'
  $COMPOSE run --rm -T --user=0 cli2 rm -f "$remove_all_mu" \
    || fail 'could not remove the WooCommerce destructive-uninstall control'
  [ ! -e "$remove_all_helper" ] || fail 'WooCommerce destructive-uninstall control helper survived installation'

  [ "$(wp_conf2 eval "echo hash_file('sha256','$WOO_ARTIFACT');")" = "$WOO_SHA" ] \
    || fail 'WooCommerce destructive-uninstall exact reinstall artifact digest moved'
  wp_conf2 plugin install "$WOO_ARTIFACT" --force >/dev/null
  [ "$(wp_conf2 plugin get woocommerce --field=version)" = 11.0.1 ] \
    || fail 'WooCommerce destructive-uninstall reinstall reported wrong version'
  reinstall_deploy=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
  require_duo_answered 'WooCommerce deploy after destructive uninstall and exact reinstall' json "$reinstall_deploy"
  wp_conf2 plugin is-active woocommerce >/dev/null \
    || fail 'WooCommerce destructive-uninstall reinstall did not reactivate exact code'

  refusal_identity=$(wp_conf2 eval '
    global $wpdb;
    $state = [
      "map" => $wpdb->get_results("SELECT uuid,entity_type,id_kind,local_id FROM {$wpdb->prefix}duo_map ORDER BY uuid,id_kind", ARRAY_A),
      "state" => $wpdb->get_results("SELECT uuid,entity_type,content_hash FROM {$wpdb->prefix}duo_state ORDER BY uuid", ARRAY_A),
      "kv" => $wpdb->get_results("SELECT k,v FROM {$wpdb->prefix}duo_kv ORDER BY k", ARRAY_A),
    ];
    echo hash("sha256", wp_json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  ')
  lost_plan_rc=0
  lost_plan=$(wp_conf2 duo plan --repo=/siterepo 2>&1) || lost_plan_rc=$?
  require_duo_answered 'WooCommerce plan after destructive uninstall' human "$lost_plan"
  [ "$lost_plan_rc" -ne 0 ] \
    && grep -Eqi 'canonical mapped identity|refusing to (create|infer|rebind)' <<<"$lost_plan" \
    || fail "WooCommerce destructive-uninstall plan did not refuse before minting replacement identities: $lost_plan"
  [ "$(wp_conf2 eval '
    global $wpdb; $state=[
      "map"=>$wpdb->get_results("SELECT uuid,entity_type,id_kind,local_id FROM {$wpdb->prefix}duo_map ORDER BY uuid,id_kind",ARRAY_A),
      "state"=>$wpdb->get_results("SELECT uuid,entity_type,content_hash FROM {$wpdb->prefix}duo_state ORDER BY uuid",ARRAY_A),
      "kv"=>$wpdb->get_results("SELECT k,v FROM {$wpdb->prefix}duo_kv ORDER BY k",ARRAY_A),
    ]; echo hash("sha256",wp_json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  ' )" = "$refusal_identity" ] \
    || fail 'WooCommerce destructive-uninstall plan minted or rewrote identities before refusal'
  lost_apply_rc=0
  lost_apply=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || lost_apply_rc=$?
  require_duo_answered 'WooCommerce apply after destructive uninstall' human "$lost_apply"
  [ "$lost_apply_rc" -ne 0 ] \
    && grep -Eqi 'canonical mapped identity|refusing to (create|infer|rebind)' <<<"$lost_apply" \
    || fail "WooCommerce destructive-uninstall apply did not refuse before minting replacement identities: $lost_apply"
  [ "$(wp_conf2 eval '
    global $wpdb; $state=[
      "map"=>$wpdb->get_results("SELECT uuid,entity_type,id_kind,local_id FROM {$wpdb->prefix}duo_map ORDER BY uuid,id_kind",ARRAY_A),
      "state"=>$wpdb->get_results("SELECT uuid,entity_type,content_hash FROM {$wpdb->prefix}duo_state ORDER BY uuid",ARRAY_A),
      "kv"=>$wpdb->get_results("SELECT k,v FROM {$wpdb->prefix}duo_kv ORDER BY k",ARRAY_A),
    ]; echo hash("sha256",wp_json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  ' )" = "$refusal_identity" ] \
    || fail 'WooCommerce destructive-uninstall apply minted or rewrote identities before refusal'
  stale_identity_rc=0
  stale_identity=$(wp_conf2 duo identity-import --repo=/siterepo --in=/siterepo/.tmp-woocommerce-remove-all-identity.json 2>&1) \
    || stale_identity_rc=$?
  require_duo_answered 'WooCommerce stale identity sidecar after destructive uninstall' human "$stale_identity"
  [ "$stale_identity_rc" -ne 0 ] \
    && grep -Eq 'embedded identity does not verify|identity sidecar witness mismatch' <<<"$stale_identity" \
    || fail "WooCommerce destructive uninstall accepted a stale identity sidecar: $stale_identity"
  [ "$(wp_conf2 eval '
    global $wpdb; $state=[
      "map"=>$wpdb->get_results("SELECT uuid,entity_type,id_kind,local_id FROM {$wpdb->prefix}duo_map ORDER BY uuid,id_kind",ARRAY_A),
      "state"=>$wpdb->get_results("SELECT uuid,entity_type,content_hash FROM {$wpdb->prefix}duo_state ORDER BY uuid",ARRAY_A),
      "kv"=>$wpdb->get_results("SELECT k,v FROM {$wpdb->prefix}duo_kv ORDER BY k",ARRAY_A),
    ]; echo hash("sha256",wp_json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  ' )" = "$refusal_identity" ] \
    || fail 'WooCommerce stale identity sidecar partially rewrote the live ledger'

  # Importing the pre-uninstall database is the only authorized recovery.
  # It restores its matching ledger, code baseline, active plugin list, HPOS
  # option, authored catalog, lookup tables, and target-only runtime together.
  wp_conf2 db import /siterepo/.tmp-woocommerce-remove-all.sql >/dev/null
  [ "$(wp_conf2 plugin get woocommerce --field=version)" = 11.0.1 ] \
    && wp_conf2 plugin is-active woocommerce >/dev/null \
    || fail 'WooCommerce database recovery did not restore the exact active-plugin preimage'
  [ "$(wp_conf2 eval 'echo get_option("woocommerce_custom_orders_table_enabled") === "yes" ? "yes" : "no";')" = yes ] \
    || fail 'WooCommerce database recovery did not restore the HPOS preimage'
  [ "$(woocommerce_storage_hash)" = "$preimage_storage" ] \
    || fail 'WooCommerce database recovery did not restore the exact catalog/options/lookup preimage'
  restored_observation=$(observe_woocommerce_adoption)
  [ "$restored_observation" = "$preimage_observation" ] \
    || fail "WooCommerce database recovery did not restore the exact authored/runtime preimage: $restored_observation"
  [ "$(wp_conf2 eval '
    global $wpdb; $state=[
      "map"=>$wpdb->get_results("SELECT uuid,entity_type,id_kind,local_id FROM {$wpdb->prefix}duo_map ORDER BY uuid,id_kind",ARRAY_A),
      "state"=>$wpdb->get_results("SELECT uuid,entity_type,content_hash FROM {$wpdb->prefix}duo_state ORDER BY uuid",ARRAY_A),
      "kv"=>$wpdb->get_results("SELECT k,v FROM {$wpdb->prefix}duo_kv ORDER BY k",ARRAY_A),
    ]; echo hash("sha256",wp_json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  ' )" = "$preimage_identity" ] \
    || fail 'WooCommerce database recovery did not restore its database-matched identity ledger'

  restored_plan=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
  require_duo_answered 'WooCommerce plan after destructive-uninstall database recovery' json "$restored_plan"
  jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict,.code_mismatch,.code_drift,.incomplete_apply,.incomplete_lifecycle,.regen_pending,.regen_context] | map(length) | add) == 0' \
    <<<"$restored_plan" >/dev/null \
    || fail "WooCommerce destructive-uninstall database recovery left planned work: $restored_plan"
  restored_apply=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
  require_duo_answered 'WooCommerce zero apply after destructive-uninstall database recovery' json "$restored_apply"
  jq -e '.canary == "clean" and (.actions | length) == 0' <<<"$restored_apply" >/dev/null \
    || fail "WooCommerce destructive-uninstall database recovery reran actions: $restored_apply"
  wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-woocommerce-remove-all-final >/dev/null
  capture_diff=$(diff -r "$preimage_capture" "$final_capture" || true)
  [ -z "$capture_diff" ] \
    || fail "WooCommerce destructive-uninstall database recovery did not recapture byte-identically: $capture_diff"
  repeat_plan=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
  require_duo_answered 'WooCommerce repeated plan after destructive-uninstall recovery' json "$repeat_plan"
  jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict,.code_mismatch,.code_drift,.incomplete_apply,.incomplete_lifecycle,.regen_pending,.regen_context] | map(length) | add) == 0' \
    <<<"$repeat_plan" >/dev/null \
    || fail "WooCommerce destructive-uninstall repeated plan was not a no-op: $repeat_plan"
  repeat_apply=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
  require_duo_answered 'WooCommerce repeated apply after destructive-uninstall recovery' json "$repeat_apply"
  jq -e '.canary == "clean" and (.actions | length) == 0' <<<"$repeat_apply" >/dev/null \
    || fail "WooCommerce destructive-uninstall repeated apply reran actions: $repeat_apply"

  $COMPOSE run --rm -T --user=0 cli2 rm -f "$remove_all_mu" \
    || fail 'could not clean up the WooCommerce destructive-uninstall control'
  rm -f "$remove_all_db" "$remove_all_identity" "$remove_all_helper"
  rm -rf "$preimage_capture" "$final_capture"
  [ ! -e "$remove_all_db" ] && [ ! -e "$remove_all_identity" ] && [ ! -e "$remove_all_helper" ] \
    && [ ! -e "$preimage_capture" ] && [ ! -e "$final_capture" ] \
    || fail 'WooCommerce destructive-uninstall recovery left temporary backups or helpers behind'
  pass 'WC_REMOVE_ALL_DATA removes Woo-owned catalog/options/lookup/HPOS state; stale recovery refuses, exact database restore recovers the preimage, and repeated operations are no-ops'
}
