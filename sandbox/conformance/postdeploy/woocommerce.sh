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
# DUO-3381: the premise, asserted before the behavior. This order IS the
# fixture checks/woocommerce.sh's "target order disappeared" assertion is
# about — if the eval above silently hands back nothing (a `docker compose
# run` starved under host load, never a non-zero exit), that check fails
# later with an accusation against apply for a row this hook never created.
require_fixture_ids TARGET_ORDER_ID
# Keep the compatibility copy current so run.sh's generic HPOS setup gate can
# re-run its preflight successfully. The authoritative order remains in HPOS.
wp_conf2 wc hpos sync >/dev/null
# Same discipline for the session/queue rows, read back through the same
# tables checks/woocommerce.sh counts, inside the SAME eval (no extra
# container run) — their assertion ("Woo runtime sovereignty failed for
# reviews, sessions, or queues") is equally unable to tell an unwritten
# fixture from a target row that apply wrongly removed.
TARGET_RUNTIME=$(wp_conf2 eval '
global $wpdb;
$wpdb->replace($wpdb->prefix . "woocommerce_sessions", [
  "session_key" => "duo-target-runtime-session",
  "session_value" => "a:1:{s:5:\"probe\";s:6:\"target\";}",
  "session_expiry" => time() + 7200,
], ["%s", "%s", "%d"]);
as_schedule_single_action(time() + 7200, "duo_woo_target_runtime_probe", [], "duo-woo-runtime");
echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key=\"duo-target-runtime-session\"")
  . "|" . (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook=\"duo_woo_target_runtime_probe\"");
')
require_fixture_state "conf2's target-only runtime session/queue rows" "1|1" "$TARGET_RUNTIME"
echo "woocommerce postdeploy: target_runtime_order=$TARGET_ORDER_ID (HPOS enabled and compatibility-synced before apply)"
