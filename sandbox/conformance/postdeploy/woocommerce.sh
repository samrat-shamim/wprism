#!/usr/bin/env bash
# WooCommerce target-only runtime fixture. Deployment has activated the
# plugin, but apply has not run yet. Enable HPOS first, then author one order
# that canonical state must neither delete nor replace with conf1's order.
set -euo pipefail

wp_conf2 wc hpos enable >/dev/null
TARGET_ORDER_ID=$(wp_conf2 eval '
$existing = wc_get_orders(["billing_email" => "target-runtime@example.test", "limit" => 1, "return" => "ids"]);
if ($existing) { echo (int) $existing[0]; return; }
$order = wc_create_order();
$order->set_billing_email("target-runtime@example.test");
$order->calculate_totals();
$order->save();
echo $order->get_id();
')
# Keep the compatibility copy current so run.sh's generic HPOS setup gate can
# re-run its preflight successfully. The authoritative order remains in HPOS.
wp_conf2 wc hpos sync >/dev/null
wp_conf2 eval '
global $wpdb;
$wpdb->replace($wpdb->prefix . "woocommerce_sessions", [
  "session_key" => "duo-target-runtime-session",
  "session_value" => "a:1:{s:5:\"probe\";s:6:\"target\";}",
  "session_expiry" => time() + 7200,
], ["%s", "%s", "%d"]);
as_schedule_single_action(time() + 7200, "duo_woo_target_runtime_probe", [], "duo-woo-runtime");
' >/dev/null
echo "woocommerce postdeploy: target_runtime_order=$TARGET_ORDER_ID (HPOS enabled and compatibility-synced before apply)"
