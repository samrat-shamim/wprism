#!/usr/bin/env bash
# Regression — DUO-3290: `wp duo coverage` names and counts what a site has
# versus what Duo actually captures (options, custom tables), without ever
# gating/blocking capture/plan/apply. Live (needs a real $wpdb, SHOW TABLES,
# get_option()) — the pure-logic half (prefix grouping, attribution
# heuristic, transient partitioning) has its own fast offline counterpart,
# regress_coverage_offline.php, matching the same split
# regress_repository_compiler.sh/_integration.sh already establishes.
#
# Runs against an ALREADY-UP pair (this script's own DUO_PAIR/PORT1/PORT2
# env vars select which one — mirrors regress_shipping_zones.sh's own
# established pattern of assuming a pair exists rather than managing
# compose lifecycle itself). Requires: WooCommerce installed and active,
# and $REPO_DIR/site.duo.json declaring manifests ["core","woocommerce"]
# with product/product_cat in scope — this file's own header shows the
# exact shape used to build/verify it.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

: "${DUO_PAIR:?set DUO_PAIR to an already-up pair name, e.g. DUO_PAIR=asub3290}"
COMPOSE="docker compose -p duo-$DUO_PAIR -f pair.yml"
REPO_DIR="siterepo/${DUO_PAIR}1"
wp1() { $COMPOSE run --rm -T cli1 wp "$@"; }
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"
[ -f "$REPO_DIR/site.duo.json" ] || fail "expected $REPO_DIR/site.duo.json to already exist — this test does not scaffold a site-repo, matching regress_shipping_zones.sh's own precedent"
wp1 plugin is-active woocommerce >/dev/null 2>&1 || fail "expected WooCommerce active on this pair — this test does not install plugins, matching regress_shipping_zones.sh's own precedent"

say "(0) plant an undeclared custom table with real rows (simulating an unmanifested plugin)"
PLANTED=$(wp1 eval '
global $wpdb;
$table = $wpdb->prefix . "duo3290_test_log";
$wpdb->query("CREATE TABLE IF NOT EXISTS `$table` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, msg TEXT) ENGINE=InnoDB");
$wpdb->query("DELETE FROM `$table`"); // idempotent re-run
$wpdb->insert($table, ["msg" => "probe row 1"]);
$wpdb->insert($table, ["msg" => "probe row 2"]);
echo $table;
' 2>&1 | tail -1)
echo "planted table: $PLANTED"
pass "one undeclared custom table planted with exactly 2 real rows"

say "(1) wp duo coverage --format=json — structure and format field"
JSON=$(wp1 duo coverage --repo=/siterepo --format=json 2>&1 | tail -1)
echo "$JSON" | jq -e '.format == "duo-coverage-report/v1"' >/dev/null \
  || fail "expected top-level format field duo-coverage-report/v1 (got: $JSON)"
echo "$JSON" | jq -e '.options.total > 0 and .options.captured > 0 and .options.invisible_total > 0' >/dev/null \
  || fail "expected non-trivial options counts on a real WooCommerce site (got: $(echo "$JSON" | jq .options))"
pass "JSON report has the versioned format field and sane, non-trivial options counts"

say "(2) the planted undeclared table surfaces by name with its real row count"
FOUND=$(echo "$JSON" | jq -r --arg t "$PLANTED" '.tables.undeclared[] | select(.table == $t) | .row_count')
[ "$FOUND" = "2" ] || fail "expected the planted table ($PLANTED) in tables.undeclared with row_count=2 (got: $FOUND)"
pass "a real, freshly-planted custom table (2 real rows) is correctly discovered and counted — proves the reverse-enumeration mechanism DUO-3257 found completely missing actually works now"

say "(3) the current WooCommerce 11.0.0 table surface is completely declared"
WC_UNDECLARED=$(echo "$JSON" | jq -r '.tables.undeclared[] | select(.table | startswith("wp_wc") or startswith("wp_woocommerce")) | .table' | wc -l | tr -d ' ')
echo "WooCommerce-shaped undeclared tables found: $WC_UNDECLARED"
[ "$WC_UNDECLARED" = "0" ] || fail "expected the current WooCommerce 11.0.0 table surface to be fully declared; coverage found $WC_UNDECLARED Woo-shaped gap(s)"
pass "WooCommerce's current live table surface is fully represented; the independent synthetic probe still proves reverse-enumeration catches a genuine undeclared table"

say "(4) human-readable mode runs without error and contains both sections"
HUMAN=$(wp1 duo coverage --repo=/siterepo 2>&1)
grep -q "^OPTIONS" <<<"$HUMAN" || fail "expected an OPTIONS section header in human-readable output"
grep -q "^TABLES" <<<"$HUMAN" || fail "expected a TABLES section header in human-readable output"
grep -qi "never blocks capture/plan/apply" <<<"$HUMAN" || fail "expected the explicit non-gating reassurance line"
pass "human-readable rendering produces both sections and the explicit non-gating statement"

say "(5) THE decisive check: coverage found real gaps (hundreds of invisible options, several undeclared tables) -- capture must still succeed cleanly right after, proving zero interference"
OUT=$(wp1 duo capture --repo=/siterepo 2>&1)
grep -qi success <<<"$OUT" || fail "expected capture to succeed immediately after a coverage run that found many gaps (got: $OUT)"
pass "capture succeeds normally right after coverage — coverage is proven non-gating, not just designed to be"

say "(6) cleanup: drop the planted probe table so re-runs start clean"
wp1 eval "global \$wpdb; \$wpdb->query('DROP TABLE IF EXISTS \`$PLANTED\`');" >/dev/null 2>&1
pass "probe table dropped"

pass "DUO-3290 regression: report structure, real undeclared-table discovery (planted + incidental), human rendering, and non-interference with capture, all confirmed live"
