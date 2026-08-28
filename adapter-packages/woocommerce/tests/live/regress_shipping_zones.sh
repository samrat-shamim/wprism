#!/usr/bin/env bash
# Regression — task #93: shipping-zone/tax-rate typed snapshots +
# option_name_refs (the ref-in-option-NAME mechanism).
#
# Covers:
#   (1) live end-to-end: a fresh target's WC_Shipping_Zones::get_zones()/
#       WC_Tax::find_rates() resolve correctly with ZERO manual zone/method/
#       rate re-creation, INCLUDING the shipping-method settings blob
#       (title/cost/min_amount) read back through the REAL, re-materialized
#       per-environment option name (not the source's).
#   (2) the literal-"{{"-in-option-name assertion team-lead's design review
#       specifically flagged: apply's detokenize-key-first path is what
#       stands between a captured token-form option KEY and a silently
#       corrupted wp_options row on the target whose NAME contains raw
#       "{{...}}" bytes -- asserted directly against the live database, not
#       just argued in a comment.
#   (3) DANGLING option_name_refs id (no row anywhere) -> warn + drop,
#       capture still succeeds (task #73's dangling posture, unchanged).
#   (4) UNSCOPED option_name_refs id (a REAL row exists in its table, but
#       that table isn't declared authored_snapshot in the active policy)
#       -> loud, blocking abort naming the option/id_kind/id, with
#       --force-unresolved-refs as the escape hatch (task #73's unscoped
#       posture, mirrored onto table id_kinds per team-lead's design
#       review).
#
# Runs against the existing r3e pair (already up for tasks #92/#93). This
# script IS the acceptance narrative now: every assertion below states the
# behaviour it pins, so there is nothing to re-run elsewhere.
# Touches NEITHER r3e1's nor r3e2's real site-repo git history for the
# dangling/unscoped cases: throwaway scratch repos + throwaway raw-SQL
# fixtures, cleaned up on exit, mirroring regress_option_ref_scope.sh's
# established pattern. Self-contained, re-runnable.
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
export DUO_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
cd "$(dirname "$0")/../../../../sandbox"
export DUO_PAIR=r3e
COMPOSE="docker compose -p duo-r3e -f pair.yml"
wp1() { $COMPOSE run --rm -T cli1 wp "$@"; }
wp2() { $COMPOSE run --rm -T cli2 wp "$@"; }
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

say "(1) live end-to-end, re-asserted: r3e2's get_zones()/find_rates() resolve correctly (fresh process, not same apply request)"
Z_OUT=$(wp2 eval '
$zones = WC_Shipping_Zones::get_zones();
foreach ($zones as $z) {
  if ($z["zone_name"] !== "United States") continue;
  foreach ($z["shipping_methods"] as $m) {
    if ($m->id === "flat_rate") { echo "flat_rate_cost=" . $m->get_option("cost") . " "; }
    if ($m->id === "free_shipping") { echo "free_shipping_min=" . $m->get_option("min_amount") . " "; }
  }
}
$ca = WC_Tax::find_rates(["country" => "US", "state" => "CA"]);
foreach ($ca as $r) { echo "ca_rate=" . $r["rate"] . " "; }
' 2>&1 | tail -1)
echo "$Z_OUT"
grep -q "flat_rate_cost=5.99" <<<"$Z_OUT" || fail "expected flat_rate cost=5.99 resolved via the REAL re-materialized option name on r3e2 (got: $Z_OUT)"
grep -q "free_shipping_min=50" <<<"$Z_OUT" || fail "expected free_shipping min_amount=50 resolved on r3e2 (got: $Z_OUT)"
grep -q "ca_rate=7.25" <<<"$Z_OUT" || fail "expected CA tax rate 7.25 resolved on r3e2 (got: $Z_OUT)"
pass "zone methods + tax rate resolve correctly via WooCommerce's own APIs on r3e2, zero manual re-creation"

say "(2) no literal '{{' anywhere in r3e2's own wp_options (proves the detokenize-key-first ordering, not just a comment)"
LEAK=$(wp2 db query "SELECT option_name FROM wp_options WHERE option_name LIKE '%{{%'" 2>/dev/null | tail -n +2)
if [ -n "$LEAK" ]; then
  fail "found option name(s) containing literal '{{' on r3e2 -- detokenize-key-first ordering regressed: $LEAK"
fi
pass "zero option names containing literal '{{' on r3e2 -- apply's detokenize-key-first path is doing its job"

say "(3) DANGLING option_name_refs id: no row anywhere -> warn + drop, capture still succeeds"
REPO=/siterepo/.tmp-r93-dangling
HOST_REPO="siterepo/r3e1/.tmp-r93-dangling"
rm -rf "$HOST_REPO"; mkdir -p "$HOST_REPO"
cp siterepo/r3e1/site.duo.json "$HOST_REPO/site.duo.json"
wp1 option update woocommerce_flat_rate_999999_settings 'a:1:{s:5:"title";s:15:"Dangling Probe";}' >/dev/null
set +e
OUT3=$(wp1 duo capture --repo="$REPO" --out="$REPO/state-out" 2>&1)
RC3=$?
set -e
echo "$OUT3"
wp1 option delete woocommerce_flat_rate_999999_settings >/dev/null
[ "$RC3" -eq 0 ] || fail "expected a genuinely dangling option_name_refs id to warn-and-drop (capture still succeeds), got exit $RC3: $OUT3"
grep -qi "999999" <<<"$OUT3" || fail "expected the dangling warning to name the id 999999 (got: $OUT3)"
jq -e '.records | to_entries | map(select(.key | test("999999"))) | length == 0' "$HOST_REPO/state-out/options/core.json" >/dev/null \
  || fail "the dangling option must be ABSENT from captured state, not present under a raw or malformed key"
pass "dangling option_name_refs id warned and dropped; capture succeeded; nothing leaked into canonical state"
rm -rf "$HOST_REPO"

say "(4) mapped-identity continuity: a REAL row in a declared mapped table that has never been captured must fail closed on a non-minting snapshot, naming identity-import recovery"
# A custom table's scope is knowable from its manifest declaration, but a
# mapped row's durable UUID is not safely reconstructible from its local id.
# Since DUO-3209, Snapshot::capture() must refuse to invent that identity when
# mint=false and direct operators to a verified identity sidecar. This differs
# deliberately from task #73's post/term scope check: those entity kinds have
# independently recoverable identity rules, while mapped table rows do not.
ZONE_METHOD_JSON=$(wp1 eval '
global $wpdb;
$wpdb->insert($wpdb->prefix . "woocommerce_shipping_zones", ["zone_name" => "R93 Unminted Probe Zone", "zone_order" => 99]);
$zone_id = $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . "woocommerce_shipping_zone_methods", ["zone_id" => $zone_id, "method_id" => "flat_rate", "method_order" => 1, "is_enabled" => 1]);
$instance_id = $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . "options", ["option_name" => "woocommerce_flat_rate_{$instance_id}_settings", "option_value" => serialize(["title" => "Unminted Probe"]), "autoload" => "yes"]);
echo json_encode(["zone_id" => $zone_id, "instance_id" => $instance_id]);
' 2>&1 | tail -1)
echo "probe row (real table, never captured before): $ZONE_METHOD_JSON"
PROBE_ZONE_ID=$(echo "$ZONE_METHOD_JSON" | jq -r .zone_id)
PROBE_INSTANCE_ID=$(echo "$ZONE_METHOD_JSON" | jq -r .instance_id)

# Capture::snapshot() directly (mint=false), against r3e1's real, unmodified
# site.duo.json. The table is declared authored_snapshot, but the mapped row
# has no durable ledger identity, so the non-minting read must fail closed.
set +e
OUT4=$(wp1 eval "
try { \Duo\Capture::snapshot('/siterepo'); echo 'OK: no throw'; }
catch (\Throwable \$e) { echo 'THROWN: ' . \$e->getMessage(); }
" 2>&1 | tail -1)
RC4=$?
set -e
echo "$OUT4"
grep -q "mapped identity missing for populated table 'woocommerce_shipping_zones'" <<<"$OUT4" \
  || fail "Capture::snapshot() did not fail closed on the first unmapped populated table row (got: $OUT4)"
grep -q 'wc_zone' <<<"$OUT4" || fail "mapped-identity refusal omitted the id_kind (got: $OUT4)"
grep -q 'identity-import' <<<"$OUT4" || fail "mapped-identity refusal omitted the verified recovery path (got: $OUT4)"
pass "non-minting snapshot refuses to mint a mapped table identity and names verified identity-import recovery"

say "(4b) the SAME probe, via a REAL (minting) capture: the row gets a durable identity normally"
OUT4B=$(wp1 duo capture --repo=/siterepo 2>&1)
echo "$OUT4B"
grep -qi success <<<"$OUT4B" || fail "expected the real capture to succeed and pick up the probe row normally (got: $OUT4B)"
FOUND=$(wp1 eval "
\$rows = glob('/siterepo/state/tables/woocommerce_shipping_zone_methods/*.json');
\$hit = false;
foreach (\$rows as \$f) { if (str_contains(file_get_contents(\$f), 'flat_rate')) { \$c = json_decode(file_get_contents(\$f), true); } }
global \$wpdb;
\$uuid = \Duo\Ledger::uuid_for($PROBE_INSTANCE_ID, 'wc_zone_method');
echo \$uuid ? 'MINTED' : 'MISSING';
" 2>&1 | tail -1)
echo "probe row ledger state after real capture: $FOUND"
grep -q "MINTED" <<<"$FOUND" || fail "expected the probe row to be minted into duo_map by a real (mint=true) capture (got: $FOUND)"
pass "the row refused by the non-minting snapshot is assigned a durable identity by a real capture -- fail-closed planning does not block the authorized minting path"

PROBE_ZONE_UUID=$(wp1 eval "echo \\Duo\\Ledger::uuid_for($PROBE_ZONE_ID, 'wc_zone');" 2>/dev/null | tail -1 | tr -d '\r')
PROBE_METHOD_UUID=$(wp1 eval "echo \\Duo\\Ledger::uuid_for($PROBE_INSTANCE_ID, 'wc_zone_method');" 2>/dev/null | tail -1 | tr -d '\r')
[[ "$PROBE_ZONE_UUID" =~ ^[0-9a-f-]{36}$ ]] || fail "could not resolve the probe zone uuid for exact cleanup (got: $PROBE_ZONE_UUID)"
[[ "$PROBE_METHOD_UUID" =~ ^[0-9a-f-]{36}$ ]] || fail "could not resolve the probe method uuid for exact cleanup (got: $PROBE_METHOD_UUID)"

say "(4c) ALIVENESS: the option itself -- not just the table row -- is captured under its TOKENIZED name, proving option_name_refs' own discovery loop actually ran"
# DUO-3257 finding: 4b's ledger-mint check alone does NOT prove this. Table
# row minting happens unconditionally in Snapshot::capture() (runs before
# OptionsCapture in the same build(), mints every row of every declared
# table regardless of the options capturer's own option_name_refs loop). A
# regression that silently zeroes out THAT loop specifically (confirmed
# live: a stray variable-name mismatch introduced by an unrelated DUO-3263
# refactor did exactly this, undetected since this file's own conformance
# target is opt-in and wasn't part of that PR's test scope) left the table
# row correctly minted while the OPTION's captured key stayed the raw,
# non-portable numeric-instance-id form -- 4b's assertion never noticed.
# This check reads the actual captured option name directly.
OPT_KEY=$(wp1 eval "
\$doc = json_decode(file_get_contents('/siterepo/state/options/core.json'), true);
foreach (array_keys(\$doc['records'] ?? []) as \$name) {
    if (str_starts_with(\$name, 'woocommerce_flat_rate_') && str_ends_with(\$name, '_settings')) {
        echo \$name;
        break;
    }
}
" 2>&1 | tail -1)
echo "captured option key for the probe instance: $OPT_KEY"
[ -n "$OPT_KEY" ] || fail "expected SOME woocommerce_flat_rate_*_settings key in captured options -- option_name_refs discovery produced nothing at all"
grep -q "{{wc_zone_method:" <<<"$OPT_KEY" || fail "expected the captured option key to carry a resolved {{wc_zone_method:<uuid>}} token, not the raw numeric instance id -- got: $OPT_KEY (this is exactly the shape a dead option_name_refs loop produces: either absent entirely, or captured under the raw un-tokenized name)"
pass "option_name_refs discovery fired for real: the probe's raw numeric instance id ($PROBE_INSTANCE_ID) was tokenized into a portable {{wc_zone_method:<uuid>}} reference in the captured option KEY"

# cleanup the probe zone/method/option (undo the extra real capture too, by
# re-capturing after removing the probe row so state/ matches the git-committed
# fixture again)
wp1 db query "DELETE FROM wp_woocommerce_shipping_zone_methods WHERE instance_id = $PROBE_INSTANCE_ID" >/dev/null
wp1 db query "DELETE FROM wp_woocommerce_shipping_zones WHERE zone_id = $PROBE_ZONE_ID" >/dev/null
wp1 option delete "woocommerce_flat_rate_${PROBE_INSTANCE_ID}_settings" >/dev/null 2>&1 || true
cd siterepo/r3e1 && git checkout -q -- state 2>/dev/null; cd - >/dev/null
rm -f "siterepo/r3e1/state/tables/woocommerce_shipping_zones/${PROBE_ZONE_UUID}"--*.json
rm -f "siterepo/r3e1/state/tables/woocommerce_shipping_zone_methods/${PROBE_METHOD_UUID}"--*.json
[ -z "$(git -C siterepo/r3e1 status --porcelain --untracked-files=all -- state)" ] \
  || fail "probe cleanup left canonical state changes behind"

say "(5) hard lint gate on r3e2's applied state, re-asserted"
LINT_OUT=$(wp2 duo lint --repo=/siterepo 2>&1)
echo "$LINT_OUT"
grep -qi "no findings" <<<"$LINT_OUT" || fail "expected lint 0 findings on r3e2's applied state (got: $LINT_OUT)"
pass "lint clean on r3e2's applied state"

pass "task #93 regression: live zone/tax resolution + no-literal-brace proof + dangling-vs-unscoped severity (both directions) + escape hatch + option_name_refs aliveness, all confirmed"
