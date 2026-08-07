#!/usr/bin/env bash
# Regression — DUO-3246: duo_map.entity_type/duo_state.entity_type shipped
# as VARCHAR(32). A table-row entity's entity_type IS its declared table
# name (Snapshot.php's own docblock/row_tables()) -- not a short, freely-
# chosen abbreviation the way id_kind is -- so a plugin's own long table
# name (woocommerce_shipping_zone_locations, 35 chars;
# woocommerce_shipping_zone_methods, 33) silently truncated on INSERT
# (MySQL's non-strict default), harmless-latent until DUO-3209's identity-
# contradiction guard started comparing stored-vs-computed entity_type on
# every Ledger::set() and refusing the mismatch on the very next recapture.
# Reported live on PR #14's head via a grind-r1b run (team-lead).
#
# Covers:
#   (1) the ACTUAL reported scenario: fresh shipping-zone capture, then a
#       recapture (the exact operation that threw "identity contradiction
#       ... already typed woocommerce_shipping_zone_locati; refusing to
#       retype it as woocommerce_shipping_zone_locations" pre-fix) is now
#       clean.
#   (2) declaration-time budget assert: a manifest declaring a table name
#       over Snapshot::MAX_ENTITY_TYPE_LEN refuses to load, loudly, rather
#       than silently truncating on the next capture.
#   (3) migration repair: an already-captured row (real, live-backed
#       local_id) has its entity_type hand-corrupted back to the old
#       truncated value, simulating an environment that captured before
#       this fix shipped; Snapshot::repair_truncated_entity_types() must
#       repair it -- verified directly against the database, not inferred
#       from the absence of an error.
#
# Own dedicated pair, brought up and destroyed by this script -- never
# touches r3e or any other agent's live pair.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

PAIR=amergety
PORT1=8944
PORT2=8945
export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2"
COMPOSE="docker compose -p duo-$PAIR -f pair.yml"
wp1() { $COMPOSE run --rm -T cli1 wp "$@"; }
GIT_1="git -C siterepo/${PAIR}1 -c user.name=duo-$PAIR -c user.email=$PAIR@example.test"

cleanup() {
  bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  # pair.sh destroy only tears down docker state; this is a throwaway
  # scratch pair (unlike r1b/r3e's long-lived seeded fixtures), so its
  # host-side site-repo directories are ours to remove too.
  rm -rf "siterepo/${PAIR}1" "siterepo/${PAIR}2" "siterepo/origin-$PAIR.git"
}
trap cleanup EXIT

say "bring up scratch pair '$PAIR' ($PORT1) and install WooCommerce (+HPOS)"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2"
wp1 plugin install woocommerce --activate >/dev/null
wp1 wc hpos enable >/dev/null || fail "could not enable HPOS"
pass "pair '$PAIR' ready, WooCommerce active, HPOS on"

say "(1a) seed a shipping zone WITH A LOCATION (the exact table that overflowed: woocommerce_shipping_zone_locations, 35 chars)"
wp1 option update woocommerce_calc_taxes yes >/dev/null
ZONE_ID=$(wp1 wc shipping_zone create --name='Truncation Regression Zone' --order=1 --user=admin --porcelain)
wp1 eval "\$z = new WC_Shipping_Zone($ZONE_ID); \$z->add_location('US', 'country'); \$z->save();" >/dev/null
FLAT_INSTANCE=$(wp1 wc shipping_zone_method create "$ZONE_ID" --method_id=flat_rate --enabled=true --order=1 --user=admin --porcelain)
wp1 eval "
\$flat = WC_Shipping_Zones::get_shipping_method($FLAT_INSTANCE);
\$flat->instance_settings['title'] = 'Flat rate'; \$flat->instance_settings['cost'] = '5.99';
update_option(\$flat->get_instance_option_key(), \$flat->instance_settings);
" >/dev/null
pass "zone=$ZONE_ID, location=US, flat_rate method=$FLAT_INSTANCE"

say "(1b) init site repo, pin core+woocommerce"
rm -rf "siterepo/origin-$PAIR.git" "siterepo/${PAIR}1/.git" "siterepo/${PAIR}1/state" "siterepo/${PAIR}1/site.duo.json"
git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "woocommerce"],
  "policy": {"options": {}, "post_meta": {}, "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon"], "taxonomies": ["category", "post_tag", "product_cat", "product_type"]},
  "spec_version": 1
}
EOF
printf '.tmp*\n' > "siterepo/${PAIR}1/.gitignore"
$GIT_1 init -q -b main
$GIT_1 remote add origin "../origin-$PAIR.git"
$GIT_1 add -A
$GIT_1 commit -qm "policy: core + woocommerce"
pass "site repo initialized"

say "(1c) FIRST capture -- must succeed regardless of the bug (nothing to contradict yet on an empty ledger)"
wp1 duo capture --repo=/siterepo || fail "first capture failed unexpectedly"
LOC_FILE=$(ls "siterepo/${PAIR}1/state/tables/woocommerce_shipping_zone_locations/"*.json 2>/dev/null | head -1)
[ -n "$LOC_FILE" ] || fail "expected a captured woocommerce_shipping_zone_locations row"
pass "first capture succeeded, location row captured: $(basename "$LOC_FILE")"

say "(1d) confirm entity_type landed FULL, not truncated, in duo_map (direct DB check, not inferred from absence of error)"
ENTITY_TYPES=$(wp1 db query "SELECT DISTINCT entity_type FROM wp_duo_map WHERE entity_type LIKE 'woocommerce_shipping_zone%'" --skip-column-names 2>/dev/null | tr -d '\r')
echo "$ENTITY_TYPES"
echo "$ENTITY_TYPES" | grep -q '^woocommerce_shipping_zone_locations$' \
  || fail "expected the FULL 'woocommerce_shipping_zone_locations' (35 chars) in duo_map, got: $ENTITY_TYPES"
echo "$ENTITY_TYPES" | grep -q 'woocommerce_shipping_zone_locati$' \
  && fail "found the OLD truncated 32-char value still present -- widening did not take effect"
pass "entity_type is the full, untruncated table name in duo_map"

say "(1e) THE ACTUAL REPORTED BUG: recapture (a second Ledger::set() for the SAME uuid) -- pre-fix this threw 'identity contradiction ... refusing to retype'"
OUT2=$(wp1 duo capture --repo=/siterepo 2>&1) || fail "recapture failed: $OUT2"
echo "$OUT2" | grep -qi "identity contradiction" && fail "recapture hit the identity-contradiction guard -- the bug is NOT fixed: $OUT2"
pass "recapture succeeded cleanly -- the exact scenario from team-lead's grind-r1b report is fixed"

say "(2) declaration-time budget assert: a manifest declaring a table name over 64 chars refuses to load, loudly"
LONGNAME="this_is_a_deliberately_oversized_fake_table_name_for_the_regression_test"
[ "${#LONGNAME}" -gt 64 ] || fail "test setup bug: LONGNAME must itself exceed 64 chars (is ${#LONGNAME})"
HOST_REPO="siterepo/${PAIR}1/.tmp-toolong"
rm -rf "$HOST_REPO"; mkdir -p "$HOST_REPO"
cat > "$HOST_REPO/site.duo.json" <<EOF
{
  "manifests": ["core", "woocommerce"],
  "policy": {
    "options": {}, "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon"],
    "taxonomies": ["category", "post_tag", "product_cat", "product_type"],
    "tables": {
      "$LONGNAME": {"class": "authored_snapshot", "pk": "id", "id_kind": "toolong", "columns": {}, "refs": []}
    }
  },
  "spec_version": 1
}
EOF
# capture (not plan): plan is apply-side and requires a pre-existing state/
# dir to diff against; capture is the environment-bound entry point that
# calls Snapshot::capture() -> assert_row_schema() for every declared
# table BEFORE it reads a single row, which is exactly the choke point
# under test here.
set +e
OUT_ASSERT=$(wp1 duo capture --repo=/siterepo/.tmp-toolong 2>&1)
RC_ASSERT=$?
set -e
echo "$OUT_ASSERT"
[ "$RC_ASSERT" -ne 0 ] || fail "expected capture to refuse a >64-char declared table name, but it succeeded"
echo "$OUT_ASSERT" | grep -q "$LONGNAME" || fail "expected the refusal to name the offending table (got: $OUT_ASSERT)"
echo "$OUT_ASSERT" | grep -qi "64" || fail "expected the refusal to mention the 64-char budget (got: $OUT_ASSERT)"
rm -rf "$HOST_REPO"
pass "declaration-time assert refuses loudly, naming the table and the budget -- never silently truncates going forward"

say "(3) migration repair: corrupt the ALREADY-CAPTURED real row's entity_type back to the old truncated width, simulating an environment that captured before this fix shipped"
# Note: a hand-planted row with a MADE-UP local_id gets removed by
# Ledger::prune_dead_table_map() (Capture::run() prunes dead ledger
# mappings before Snapshot::capture() ever runs the repair) -- correct
# behavior on the engine's part, and discovered live while writing this
# test. The faithful repro is corrupting the entity_type of a row that
# already has a REAL, live-backed local_id: exactly what an environment
# that captured under the old VARCHAR(32) column actually has.
TRUNCATED='woocommerce_shipping_zone_locati'
[ "${#TRUNCATED}" -eq 32 ] || fail "test setup bug: TRUNCATED must be exactly 32 chars (is ${#TRUNCATED})"
REAL_UUID=$(wp1 db query "SELECT uuid FROM wp_duo_map WHERE entity_type='woocommerce_shipping_zone_locations' AND id_kind='wc_zone_loc' LIMIT 1" --skip-column-names 2>/dev/null | tr -d '\r')
[ -n "$REAL_UUID" ] || fail "expected an already-captured wc_zone_loc row in duo_map from steps (1c)/(1e)"
wp1 db query "UPDATE wp_duo_map SET entity_type='$TRUNCATED' WHERE uuid='$REAL_UUID'"
wp1 db query "UPDATE wp_duo_state SET entity_type='$TRUNCATED' WHERE uuid='$REAL_UUID'" || true
BEFORE=$(wp1 db query "SELECT entity_type FROM wp_duo_map WHERE uuid='$REAL_UUID'" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$BEFORE" = "$TRUNCATED" ] || fail "corruption did not land as expected (got: $BEFORE)"
pass "corrupted the real row back to pre-fix state: uuid=$REAL_UUID entity_type='$TRUNCATED' (32 chars)"

OUT3=$(wp1 duo capture --repo=/siterepo 2>&1) || fail "capture (which runs the repair) failed: $OUT3"
echo "$OUT3" | grep -qi "identity contradiction" && fail "capture hit the identity-contradiction guard against the corrupted row -- repair did not run before Ledger::set(): $OUT3"
AFTER=$(wp1 db query "SELECT entity_type FROM wp_duo_map WHERE uuid='$REAL_UUID'" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$AFTER" = "woocommerce_shipping_zone_locations" ] \
  || fail "expected the corrupted row's entity_type to be repaired to the full 'woocommerce_shipping_zone_locations', got: '$AFTER'"
pass "migration repair confirmed: '$TRUNCATED' -> '$AFTER', verified directly against the database, same uuid throughout ($REAL_UUID)"

printf '\n\033[1;32m✔ entity_type width regression passed\033[0m\n'
