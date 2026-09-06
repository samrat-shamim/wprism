#!/usr/bin/env bash
# Certify deletion matrix (issue #3223 slice 4): three plugin deletion
# boundaries beyond core's own post/term deletion guards (already
# exhaustively covered by conformance/checks/core.sh, task #88-era work).
# WooCommerce now proves unsupported product intent refuses before mutation;
# Ninja Forms proves its safe child-row cascades and its fail-closed parent
# boundary; Paid Memberships Pro proves the generic unguarded typed-table
# delete mechanism. This is certification of declared contracts, not new
# deletion-engine authority.
#
#   PART 1 (WooCommerce): product deletion's fail-closed boundary. A real
#     order proves why a partial guard is insufficient; review, download,
#     subscription, and arbitrary extension custom-table references are not
#     exhaustively representable, so neither deletion force flag is authority.
#   PART 2 (Ninja Forms): nf3_fields and nf3_actions are independently safe
#     child selectors whose attached metadata cascades exactly. The parent
#     nf3_forms selector is deliberately absent: shipped parent_id columns
#     are unindexed, so the required InnoDB next-key/gap-lock boundary does
#     not exist on an unmodified plugin install. Whole-form disappearance
#     must refuse during capture without publishing partial child tombstones,
#     and a hand-authored parent tombstone must be rejected by plan/apply
#     before target mutation.
#   PART 3 (Paid Memberships Pro): table:pmpro_memberships_pages declares
#     an EMPTY guards/cascades block -- the thinnest case, proving a plain
#     unguarded composite_ref delete still converges cleanly end to end
#     (no guard machinery to exercise, but real evidence the empty
#     declaration isn't accidentally silently broken either).
#
# Each part proves its declared boundary: unsupported intent refuses loudly
# before mutation, while supported typed-row tombstones converge and verify
# their exact cascade effects.
#
# TWO real, isolated WordPress environments (own dedicated pair, never
# any other agent's), matching certify_merge.sh's convention: both sides
# symmetric (install+activate directly on both -- this certifies
# deletion, not deploy reconciliation, so nothing here needs the
# files-only/deploy split conformance/run.sh exercises). destroy-when-
# green: the pair is destroyed only after every assertion below passes,
# so a failing run leaves it up for inspection.
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/
WPRISM_CERTIFICATION_MANIFESTS_JSON='["core","woocommerce","ninja-forms","paid-memberships-pro"]'

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
. lib/pair_db.sh
pair_db_select_engine
. conformance/asserts.sh

command -v jq >/dev/null || fail "jq required"

PORT1="${DELMATRIX_PORT1:-8868}"
PORT2="${DELMATRIX_PORT2:-8869}"
COMPOSE="docker compose -p wprism-delmatrix -f pair.yml -f pair.journal.yml"
export WPRISM_PAIR=delmatrix
wp1() { $COMPOSE run --rm -T cli1 wp "$@"; }
wp2() { $COMPOSE run --rm -T cli2 wp "$@"; }
GIT_A="git -C siterepo/delmatrix1 -c user.name=wprism-a -c user.email=a@example.test"
GIT_B="git -C siterepo/delmatrix2 -c user.name=wprism-b -c user.email=b@example.test"

say "clean-room via pair.sh (own pair, isolated — headless)"
bash bin/pair.sh reset delmatrix
bash bin/pair.sh up delmatrix "$PORT1" "$PORT2" --headless --journal

say "install + activate woocommerce, ninja-forms, paid-memberships-pro on both sides (symmetric — certifies deletion, not deploy reconciliation)"
wp1 plugin install woocommerce --activate >/dev/null
wp2 plugin install woocommerce --activate >/dev/null
wp1 plugin install ninja-forms --activate >/dev/null
wp2 plugin install ninja-forms --activate >/dev/null
wp1 plugin install https://github.com/strangerstudios/paid-memberships-pro/archive/refs/tags/3.8.3.zip --activate --force >/dev/null
wp2 plugin install https://github.com/strangerstudios/paid-memberships-pro/archive/refs/tags/3.8.3.zip --activate --force >/dev/null
wp1 wc hpos enable >/dev/null
wp2 wc hpos enable >/dev/null

# Ninja Forms mints its own "Contact Me" sample form on activation --
# independently on EACH side (mapped identity, no natural key: two
# activation-created rows can never be recognized as "the same" form —
# Snapshot.php's identity-modes docblock names nf3_forms specifically).
# Remove both sides' own copy before any capture/apply touches nf3_forms,
# same fix conformance/seeds/ninja-forms.sh + conformance/postdeploy/
# ninja-forms.sh already established and proved live.
say "remove each side's own independently-activation-created 'Contact Me' form (mapped-identity hazard, same fix as the conformance harness)"
read -r -d '' REMOVE_CONTACT_ME_PHP <<'PHPEOF' || true
<?php
global $wpdb;
$id = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}nf3_forms WHERE title = 'Contact Me'");
if ($id) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_field_meta WHERE parent_id IN (SELECT id FROM {$wpdb->prefix}nf3_fields WHERE parent_id = %d)", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_action_meta WHERE parent_id IN (SELECT id FROM {$wpdb->prefix}nf3_actions WHERE parent_id = %d)", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_fields WHERE parent_id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_actions WHERE parent_id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_form_meta WHERE parent_id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_upgrades WHERE id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_forms WHERE id = %d", $id));
}
PHPEOF
mkdir -p siterepo/delmatrix1 siterepo/delmatrix2
printf '%s' "$REMOVE_CONTACT_ME_PHP" > siterepo/delmatrix1/.tmp-remove-contact-me.php
printf '%s' "$REMOVE_CONTACT_ME_PHP" > siterepo/delmatrix2/.tmp-remove-contact-me.php
wp1 eval-file /siterepo/.tmp-remove-contact-me.php
wp2 eval-file /siterepo/.tmp-remove-contact-me.php
rm -f siterepo/delmatrix1/.tmp-remove-contact-me.php siterepo/delmatrix2/.tmp-remove-contact-me.php
pass "both sides' auto-created Contact Me form removed"

say "init site repo (core+woocommerce+ninja-forms+paid-memberships-pro), baseline capture on A, converge B"
git init --bare -b main siterepo/origin-delmatrix.git >/dev/null
cat > siterepo/delmatrix1/site.wprism.json <<'EOF'
{
  "manifests": ["core", "woocommerce", "ninja-forms", "paid-memberships-pro"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon"],
    "taxonomies": ["category", "post_tag", "product_cat", "product_type"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template siterepo/delmatrix1/.gitignore
git -C siterepo/delmatrix1 init -q -b main
git -C siterepo/delmatrix1 remote add origin ../origin-delmatrix.git
wp1 wprism capture --repo=/siterepo
$GIT_A add -A && $GIT_A commit -qm "baseline: core+woocommerce+ninja-forms+pmpro, empty" && $GIT_A push -qu origin main

git clone -q siterepo/origin-delmatrix.git siterepo/delmatrix2
wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --format=json | tail -1 | jq .
pass "baseline established on A, B converged"

# ============================================================================
# PART 1 — WooCommerce: product deletion is unsupported, fail closed
# ============================================================================

say "PART 1 — seed a product on A, capture, converge B"
# Deliberately no --manage_stock/--stock_quantity: WooCommerce decrements
# stock as a side effect of placing a real order (set_status('processing')
# below), which would show up as "target entity changed locally" drift on
# B in ADDITION to the guard -- muddying a test whose whole point is the
# guard mechanism specifically. Keeping stock management off the product
# entirely means placing the order touches nothing this manifest captures.
PRODUCT_A=$(wp1 wc product create --name='Deletion Matrix Widget' --sku=del-matrix-widget --regular_price=19.99 --user=admin --porcelain)
wp1 wprism capture --repo=/siterepo >/dev/null
$GIT_A add -A && $GIT_A commit -qm "A: seed product" && $GIT_A push -q origin main
$GIT_B pull -q origin main
wp2 wprism apply --repo=/siterepo --default-author=admin --format=json | tail -1 | jq .
PRODUCT_B=$(wp2 post list --post_type=product --name=deletion-matrix-widget --field=ID)
require_fixture_ids PRODUCT_B # wprism-premise-owner: woocommerce
echo "product: A=$PRODUCT_A B=$PRODUCT_B"

say "PART 1 — place a real order on B against the product (populates wc_order_product_lookup via WooCommerce's own action-scheduler async job)"
ORDER_B=$(wp2 eval "
\$order = wc_create_order();
\$product = wc_get_product($PRODUCT_B);
\$order->add_product(\$product, 1);
\$order->calculate_totals();
\$order->set_status('processing');
\$order->save();
echo \$order->get_id();
")
require_fixture_ids ORDER_B # wprism-premise-owner: woocommerce
# WooCommerce debounces the analytics lookup-table sync a few seconds into
# the future (observed: order-save time + ~5s), not immediately queued --
# poll rather than a single fixed sleep, matching spike_d_woo.sh's own
# proven pattern exactly.
LOOKUP_ROWS=0
for _ in $(seq 1 8); do
  wp2 action-scheduler run >/dev/null
  LOOKUP_ROWS=$(wp2 db query "SELECT COUNT(*) FROM wp_wc_order_product_lookup WHERE product_id=$PRODUCT_B" --skip-column-names)
  require_observed_nonempty "target WooCommerce order-product lookup count" "$LOOKUP_ROWS" # wprism-premise-owner: woocommerce
  [ "$LOOKUP_ROWS" -ge "1" ] && break
  sleep 2
done
[ "$LOOKUP_ROWS" -ge "1" ] || fail "no wc_order_product_lookup row for the order (after polling the action scheduler)"
pass "order #$ORDER_B placed on B; wc_order_product_lookup has $LOOKUP_ROWS row(s)"

say "PART 1 — hand-author a valid product tombstone; plan/apply must refuse before mutation"
PRODUCT_FILE=$(find siterepo/delmatrix2/state/posts/product -name '*--deletion-matrix-widget.md' -print -quit)
[ -n "$PRODUCT_FILE" ] || fail "captured product state file missing"
PRODUCT_UUID=$(basename "$PRODUCT_FILE" | cut -d- -f1-5)
EXPECTED_HASH=$(shasum -a 256 "$PRODUCT_FILE" | awk '{print $1}')
EXPECTED_REVISION=$(wp2 eval 'echo \WPrism\RepositoryCompiler::compile("/siterepo", \WPrism\Policy::load("/siterepo"))->revision_hash();')
require_observed_nonempty "target expected repository revision for deletion tombstone" "$EXPECTED_REVISION" # wprism-premise-owner: woocommerce
SOURCE_PATH="posts/product/$(basename "$PRODUCT_FILE")"
PRODUCT_BACKUP="siterepo/delmatrix2/.tmp-unsupported-product.md"
mkdir -p siterepo/delmatrix2/state/deletions
mv "$PRODUCT_FILE" "$PRODUCT_BACKUP"
jq -n \
  --arg expected_hash "$EXPECTED_HASH" \
  --arg expected_revision "$EXPECTED_REVISION" \
  --arg source_path "$SOURCE_PATH" \
  --arg uuid "$PRODUCT_UUID" \
  '{expected_hash:$expected_hash,expected_revision:$expected_revision,format:"wprism-deletion/v1",kind:"post",source_path:$source_path,type:"product",uuid:$uuid}' \
  > "siterepo/delmatrix2/state/deletions/$PRODUCT_UUID.json"

set +e
PLAN1_RC=0; PLAN1_ERR=$(wp2 wprism plan --repo=/siterepo --format=json 2>&1) || PLAN1_RC=$?
require_wprism_answered "target WooCommerce deletion refusal plan" json "$PLAN1_ERR" # wprism-premise-owner: woocommerce
APPLY1_RC=0; APPLY1_ERR=$(wp2 wprism apply --repo=/siterepo --with-deletes --default-author=admin 2>&1) || APPLY1_RC=$?
require_wprism_answered "target WooCommerce deletion refusal apply" human "$APPLY1_ERR" # wprism-premise-owner: woocommerce
FORCE1_RC=0; FORCE1_ERR=$(wp2 wprism apply --repo=/siterepo --with-deletes --force-delete-referenced --default-author=admin 2>&1) || FORCE1_RC=$?
require_wprism_answered "target WooCommerce forced deletion refusal apply" human "$FORCE1_ERR" # wprism-premise-owner: woocommerce
set -e
[ "$PLAN1_RC" -ne 0 ] && [ "$APPLY1_RC" -ne 0 ] && [ "$FORCE1_RC" -ne 0 ] \
  || fail "product deletion was accepted by plan/apply or the force flag"
for OUT in "$PLAN1_ERR" "$APPLY1_ERR" "$FORCE1_ERR"; do
  grep -Fq 'deletion intent for post:product is unsupported' <<<"$OUT" \
    || fail "product deletion refusal did not name the missing capability: $OUT"
done
pass "plan, apply, and forced apply all refuse unsupported product deletion"

say "PART 1 — acceptance: product and order stayed untouched; restore supported tree"
REMAINING=$(wp2 post list --post_type=product --name=deletion-matrix-widget --field=ID)
require_observed_nonempty "target retained WooCommerce product id" "$REMAINING" # wprism-premise-owner: woocommerce
[ "$REMAINING" = "$PRODUCT_B" ] || fail "product changed across refused deletion"
STILL=$(wp2 db query "SELECT COUNT(*) FROM wp_wc_order_product_lookup WHERE order_id=$ORDER_B" --skip-column-names)
require_observed_nonempty "target retained WooCommerce order lookup count" "$STILL" # wprism-premise-owner: woocommerce
[ "$STILL" = "$LOOKUP_ROWS" ] || fail "order's lookup row(s) were disturbed by the delete"
ORDER_STATUS=$(wp2 wc shop_order get "$ORDER_B" --field=status --user=admin)
require_observed_nonempty "target retained WooCommerce order status" "$ORDER_STATUS" # wprism-premise-owner: woocommerce
rm "siterepo/delmatrix2/state/deletions/$PRODUCT_UUID.json"
rmdir siterepo/delmatrix2/state/deletions
mv "$PRODUCT_BACKUP" "$PRODUCT_FILE"
RETRY1=$(wp2 wprism plan --repo=/siterepo --format=json | tail -1)
require_wprism_answered "target WooCommerce deletion retry plan" json "$RETRY1" # wprism-premise-owner: woocommerce
echo "$RETRY1" | jq -e '(.delete | length) == 0 and (.delete_conflict | length) == 0' >/dev/null \
  || fail "retry plan still shows pending deletes: $RETRY1"
pass "PART 1 complete: product and order (status=$ORDER_STATUS) untouched; supported tree settles idempotently"

# ============================================================================
# PART 2 — Ninja Forms: child deletes supported, parent delete refused
# ============================================================================

say "PART 2 — seed one form with one field/action and attached metadata on A, capture, converge B"
NF_SEED=$(wp1 eval '
global $wpdb;
$now = current_time("mysql");
$wpdb->insert($wpdb->prefix . "nf3_forms", [
  "title" => "Deletion Matrix Form", "key" => "deletion_matrix_form",
  "created_at" => $now, "updated_at" => $now,
]);
$form_id = $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . "nf3_fields", [
  "parent_id" => $form_id, "type" => "textbox", "key" => "field_key_1",
  "label" => "Name", "created_at" => $now, "updated_at" => $now,
]);
$field_id = $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . "nf3_field_meta", [
  "parent_id" => $field_id, "key" => "label", "value" => "Name",
  "meta_key" => "label", "meta_value" => "Name",
]);
$wpdb->insert($wpdb->prefix . "nf3_actions", [
  "parent_id" => $form_id, "type" => "successmessage", "key" => "action_key_1",
  "title" => "Success Message", "label" => "Success Message", "active" => 1,
  "created_at" => $now, "updated_at" => $now,
]);
$action_id = $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . "nf3_action_meta", [
  "parent_id" => $action_id, "key" => "message", "value" => "Thanks",
  "meta_key" => "message", "meta_value" => "Thanks",
]);
echo "$form_id|$field_id|$action_id";
')
IFS='|' read -r FORM_A FIELD_A ACTION_A <<< "$NF_SEED"
[ -n "$FORM_A" ] && [ -n "$FIELD_A" ] && [ -n "$ACTION_A" ] \
  || fail "ninja-forms seed did not produce form/field/action ids (got: $NF_SEED)"
wp1 wprism capture --repo=/siterepo >/dev/null
$GIT_A add -A && $GIT_A commit -qm "A: seed Ninja Forms deletion graph" && $GIT_A push -q origin main
$GIT_B pull -q origin main
wp2 wprism apply --repo=/siterepo --default-author=admin --format=json | tail -1 | jq .
FORM_B=$(wp2 db query --skip-column-names "SELECT id FROM wp_nf3_forms WHERE title='Deletion Matrix Form'" | tr -d '\r')
FIELD_B=$(wp2 db query --skip-column-names "SELECT id FROM wp_nf3_fields WHERE parent_id=$FORM_B AND label='Name'" | tr -d '\r')
ACTION_B=$(wp2 db query --skip-column-names "SELECT id FROM wp_nf3_actions WHERE parent_id=$FORM_B AND label='Success Message'" | tr -d '\r')
require_fixture_ids FORM_B FIELD_B ACTION_B # wprism-premise-owner: ninja-forms
pass "form/field/action converged with target-local ids form=$FORM_B field=$FIELD_B action=$ACTION_B"

say "PART 2 — delete only the supported child rows on A; capture and apply their exact attached-meta cascades"
wp1 db query "
  DELETE FROM wp_nf3_field_meta WHERE parent_id=$FIELD_A;
  DELETE FROM wp_nf3_action_meta WHERE parent_id=$ACTION_A;
  DELETE FROM wp_nf3_fields WHERE id=$FIELD_A;
  DELETE FROM wp_nf3_actions WHERE id=$ACTION_A;
" >/dev/null
CHILD_CAPTURE=$(wp1 wprism capture --repo=/siterepo --format=json | tail -1)
require_wprism_answered "source child deletion capture" json "$CHILD_CAPTURE" # wprism-premise-owner: ninja-forms
[ "$(jq -r '.counts.deletion' <<<"$CHILD_CAPTURE")" = 2 ] \
  || fail "child removal did not capture exactly two tombstones: $CHILD_CAPTURE"
$GIT_A add -A && $GIT_A commit -qm "A: delete supported Ninja Forms children" && $GIT_A push -q origin main
$GIT_B pull -q origin main
CHILD_APPLY=$(wp2 wprism apply --repo=/siterepo --with-deletes --default-author=admin --format=json | tail -1)
require_wprism_answered "target child deletion apply" json "$CHILD_APPLY" # wprism-premise-owner: ninja-forms
echo "$CHILD_APPLY" | jq -e '.canary == "clean" and .verification.result == "pass"' >/dev/null \
  || fail "supported child deletion did not converge: $CHILD_APPLY"
FIELD_COUNT_B=$(wp2 db query --skip-column-names "SELECT COUNT(*) FROM wp_nf3_fields WHERE id=$FIELD_B" | tr -d '[:space:]')
require_observed_nonempty "target nf3_fields deletion count" "$FIELD_COUNT_B" # wprism-premise-owner: ninja-forms
[ "$FIELD_COUNT_B" = 0 ] \
  || fail "supported nf3_fields deletion left the row behind"
FIELD_META_COUNT_B=$(wp2 db query --skip-column-names "SELECT COUNT(*) FROM wp_nf3_field_meta WHERE parent_id=$FIELD_B" | tr -d '[:space:]')
require_observed_nonempty "target nf3_field_meta cascade count" "$FIELD_META_COUNT_B" # wprism-premise-owner: ninja-forms
[ "$FIELD_META_COUNT_B" = 0 ] \
  || fail "nf3_fields attached-meta cascade left rows behind"
ACTION_COUNT_B=$(wp2 db query --skip-column-names "SELECT COUNT(*) FROM wp_nf3_actions WHERE id=$ACTION_B" | tr -d '[:space:]')
require_observed_nonempty "target nf3_actions deletion count" "$ACTION_COUNT_B" # wprism-premise-owner: ninja-forms
[ "$ACTION_COUNT_B" = 0 ] \
  || fail "supported nf3_actions deletion left the row behind"
ACTION_META_COUNT_B=$(wp2 db query --skip-column-names "SELECT COUNT(*) FROM wp_nf3_action_meta WHERE parent_id=$ACTION_B" | tr -d '[:space:]')
require_observed_nonempty "target nf3_action_meta cascade count" "$ACTION_META_COUNT_B" # wprism-premise-owner: ninja-forms
[ "$ACTION_META_COUNT_B" = 0 ] \
  || fail "nf3_actions attached-meta cascade left rows behind"
FORM_COUNT_B=$(wp2 db query --skip-column-names "SELECT COUNT(*) FROM wp_nf3_forms WHERE id=$FORM_B" | tr -d '[:space:]')
require_observed_nonempty "target retained nf3_forms parent count" "$FORM_COUNT_B" # wprism-premise-owner: ninja-forms
[ "$FORM_COUNT_B" = 1 ] \
  || fail "child deletion disturbed the retained parent form"
pass "supported nf3_fields/nf3_actions tombstones converged with exact attached-meta cascades"

say "PART 2 — whole-form disappearance refuses atomically during source capture"
wp1 db query "DELETE FROM wp_nf3_forms WHERE id=$FORM_A" >/dev/null
STATE_STATUS_BEFORE=$(git -C siterepo/delmatrix1 status --porcelain --untracked-files=all -- state)
[ -z "$STATE_STATUS_BEFORE" ] || fail "canonical state dirty before parent refusal: $STATE_STATUS_BEFORE"
TOMBSTONES_BEFORE=$(find siterepo/delmatrix1/state/deletions -type f -name '*.json' | wc -l | tr -d '[:space:]')
set +e
PARENT_CAPTURE_ERR=$(wp1 wprism capture --repo=/siterepo --format=json 2>&1)
PARENT_CAPTURE_RC=$?
set -e
[ "$PARENT_CAPTURE_RC" -ne 0 ] || fail "capture accepted unsupported table:nf3_forms deletion"
grep -Fq 'deletion intent for table:nf3_forms is unsupported' <<<"$PARENT_CAPTURE_ERR" \
  || fail "capture refusal did not name table:nf3_forms: $PARENT_CAPTURE_ERR"
STATE_STATUS_AFTER=$(git -C siterepo/delmatrix1 status --porcelain --untracked-files=all -- state)
[ "$STATE_STATUS_AFTER" = "$STATE_STATUS_BEFORE" ] \
  || fail "failed parent capture changed canonical state: $STATE_STATUS_AFTER"
TOMBSTONES_AFTER=$(find siterepo/delmatrix1/state/deletions -type f -name '*.json' | wc -l | tr -d '[:space:]')
[ "$TOMBSTONES_AFTER" = "$TOMBSTONES_BEFORE" ] \
  || fail "failed parent capture published a partial tombstone ($TOMBSTONES_BEFORE -> $TOMBSTONES_AFTER)"
RESTORE_PARENT=$(wp1 wprism apply --repo=/siterepo --force-theirs --default-author=admin --format=json | tail -1)
echo "$RESTORE_PARENT" | jq -e '.canary == "clean" and .verification.result == "pass"' >/dev/null \
  || fail "canonical form could not restore the source after refusal: $RESTORE_PARENT"
pass "source capture refused table:nf3_forms atomically and canonical apply restored the source"

say "PART 2 — a hand-authored parent tombstone is rejected by plan/apply before target mutation"
FORM_FILE=$(find siterepo/delmatrix2/state/tables/nf3_forms -name '*--deletion-matrix-form.json' -print -quit)
[ -n "$FORM_FILE" ] || fail "captured parent form state file missing"
FORM_UUID=$(jq -r '.uuid' "$FORM_FILE")
FORM_EXPECTED_HASH=$(shasum -a 256 "$FORM_FILE" | awk '{print $1}')
FORM_EXPECTED_REVISION=$(wp2 eval 'echo \WPrism\RepositoryCompiler::compile("/siterepo", \WPrism\Policy::load("/siterepo"))->revision_hash();')
require_observed_nonempty "target expected repository revision for parent refusal" "$FORM_EXPECTED_REVISION" # wprism-premise-owner: ninja-forms
FORM_SOURCE_PATH="tables/nf3_forms/$(basename "$FORM_FILE")"
FORM_BACKUP="siterepo/delmatrix2/.tmp-unsupported-nf3-form.json"
mkdir -p siterepo/delmatrix2/state/deletions
mv "$FORM_FILE" "$FORM_BACKUP"
jq -n \
  --arg expected_hash "$FORM_EXPECTED_HASH" \
  --arg expected_revision "$FORM_EXPECTED_REVISION" \
  --arg source_path "$FORM_SOURCE_PATH" \
  --arg uuid "$FORM_UUID" \
  '{expected_hash:$expected_hash,expected_revision:$expected_revision,format:"wprism-deletion/v1",kind:"table",source_path:$source_path,type:"nf3_forms",uuid:$uuid}' \
  > "siterepo/delmatrix2/state/deletions/$FORM_UUID.json"
set +e
PARENT_PLAN_RC=0; PARENT_PLAN_ERR=$(wp2 wprism plan --repo=/siterepo --format=json 2>&1) || PARENT_PLAN_RC=$?
require_wprism_answered "target parent deletion refusal plan" json "$PARENT_PLAN_ERR" # wprism-premise-owner: ninja-forms
PARENT_APPLY_RC=0; PARENT_APPLY_ERR=$(wp2 wprism apply --repo=/siterepo --with-deletes --default-author=admin 2>&1) || PARENT_APPLY_RC=$?
require_wprism_answered "target parent deletion refusal apply" human "$PARENT_APPLY_ERR" # wprism-premise-owner: ninja-forms
PARENT_FORCE_RC=0; PARENT_FORCE_ERR=$(wp2 wprism apply --repo=/siterepo --with-deletes --force-delete-referenced --default-author=admin 2>&1) || PARENT_FORCE_RC=$?
require_wprism_answered "target forced parent deletion refusal apply" human "$PARENT_FORCE_ERR" # wprism-premise-owner: ninja-forms
set -e
[ "$PARENT_PLAN_RC" -ne 0 ] && [ "$PARENT_APPLY_RC" -ne 0 ] && [ "$PARENT_FORCE_RC" -ne 0 ] \
  || fail "unsupported parent deletion was accepted by plan/apply or force"
for OUT in "$PARENT_PLAN_ERR" "$PARENT_APPLY_ERR" "$PARENT_FORCE_ERR"; do
  grep -Fq 'deletion intent for table:nf3_forms is unsupported' <<<"$OUT" \
    || fail "parent deletion refusal did not name table:nf3_forms: $OUT"
done
FORM_COUNT_AFTER_REFUSAL=$(wp2 db query --skip-column-names "SELECT COUNT(*) FROM wp_nf3_forms WHERE id=$FORM_B" | tr -d '[:space:]')
require_observed_nonempty "target nf3_forms parent count after refused deletion" "$FORM_COUNT_AFTER_REFUSAL" # wprism-premise-owner: ninja-forms
[ "$FORM_COUNT_AFTER_REFUSAL" = 1 ] \
  || fail "refused parent intent mutated the target form"
rm "siterepo/delmatrix2/state/deletions/$FORM_UUID.json"
mv "$FORM_BACKUP" "$FORM_FILE"
FINAL_PLAN2=$(wp2 wprism plan --repo=/siterepo --format=json | tail -1)
require_wprism_answered "target parent restoration plan" json "$FINAL_PLAN2" # wprism-premise-owner: ninja-forms
echo "$FINAL_PLAN2" | jq -e '(.delete | length) == 0 and (.delete_conflict | length) == 0' >/dev/null \
  || fail "restored parent state did not settle: $FINAL_PLAN2"
pass "PART 2 complete: child deletion converges; unsupported parent intent refuses before publication or mutation"

# ============================================================================
# PART 3 — Paid Memberships Pro: table:pmpro_memberships_pages, EMPTY guards
# ============================================================================

say "PART 3 — seed a membership level + a restricted page (composite_ref: membership_id+page_id, no surrogate pk) on A, converge B"
LEVEL_A=$(wp1 eval '
global $wpdb;
$wpdb->insert($wpdb->pmpro_membership_levels, [
  "name" => "Deletion Matrix Level", "description" => "slice 4 fixture",
  "confirmation" => "", "allow_signups" => 1, "initial_payment" => 0,
  "billing_amount" => 0, "cycle_number" => 0, "cycle_period" => "Month",
  "billing_limit" => 0, "trial_amount" => 0, "trial_limit" => 0,
  "expiration_number" => 0, "expiration_period" => "Year",
]);
echo $wpdb->insert_id;
')
[ -n "$LEVEL_A" ] || fail "could not create the membership level on A"
PAGE_A=$(wp1 post create --post_type=page --post_title='Deletion Matrix Restricted' --post_name=deletion-matrix-restricted --post_status=publish --porcelain)
wp1 eval "pmpro_update_post_level_restrictions($PAGE_A, [$LEVEL_A]);" >/dev/null
RESTRICTED_A=$(wp1 db query --skip-column-names "SELECT COUNT(*) FROM wp_pmpro_memberships_pages WHERE membership_id=$LEVEL_A AND page_id=$PAGE_A" | tr -d '\r')
[ "$RESTRICTED_A" = "1" ] || fail "restriction row not created on A"
wp1 wprism capture --repo=/siterepo >/dev/null
$GIT_A add -A && $GIT_A commit -qm "A: seed restricted page" && $GIT_A push -q origin main
$GIT_B pull -q origin main
wp2 wprism apply --repo=/siterepo --adopt-by-slug=posts --default-author=admin --format=json | tail -1 | jq .
LEVEL_B=$(wp2 db query --skip-column-names "SELECT id FROM wp_pmpro_membership_levels WHERE name='Deletion Matrix Level'" | tr -d '\r')
PAGE_B=$(wp2 post list --post_type=page --name=deletion-matrix-restricted --field=ID)
RESTRICTED_B=$(wp2 db query --skip-column-names "SELECT COUNT(*) FROM wp_pmpro_memberships_pages WHERE membership_id=$LEVEL_B AND page_id=$PAGE_B" | tr -d '\r')
require_fixture_ids LEVEL_B PAGE_B # wprism-premise-owner: paid-memberships-pro
require_observed_nonempty "target initial PMPro restriction count" "$RESTRICTED_B" # wprism-premise-owner: paid-memberships-pro
[ "$RESTRICTED_B" = "1" ] || fail "restriction row did not converge on B (own local ids: level=$LEVEL_B page=$PAGE_B)"
pass "level=$LEVEL_B, page=$PAGE_B, restriction row confirmed live on B with B's own local ids"

say "PART 3 — on A: un-restrict the page (removes the composite_ref row entirely — no direct 'delete' verb exists for a pure join row; this IS how it's deleted), capture, propagate"
wp1 eval "pmpro_update_post_level_restrictions($PAGE_A, []);" >/dev/null
UNRESTRICTED_A=$(wp1 db query --skip-column-names "SELECT COUNT(*) FROM wp_pmpro_memberships_pages WHERE membership_id=$LEVEL_A AND page_id=$PAGE_A" | tr -d '\r')
[ "$UNRESTRICTED_A" = "0" ] || fail "restriction row still present on A after un-restricting"
wp1 wprism capture --repo=/siterepo >/dev/null
$GIT_A add -A && $GIT_A commit -qm "A: remove page restriction" && $GIT_A push -q origin main
$GIT_B pull -q origin main

say "PART 3 — B's plan shows the delete (NOT blocked — no guards declared on this table)"
PLAN3=$(wp2 wprism plan --repo=/siterepo --format=json | tail -1)
require_wprism_answered "target PMPro deletion plan" json "$PLAN3" # wprism-premise-owner: paid-memberships-pro
echo "$PLAN3" | jq .
echo "$PLAN3" | jq -e '(.delete | length) + (.delete_conflict | length) >= 1' >/dev/null \
  || fail "plan did not show the restriction row as pending delete: $PLAN3"
echo "$PLAN3" | jq -e '[.delete[]?, .delete_conflict[]?] | map(select(.blocked != null and .blocked != "")) | length == 0' >/dev/null \
  || fail "plan shows a blocked delete on a table with no declared guards — unexpected: $PLAN3"
pass "plan shows the delete, correctly UNBLOCKED (empty guards declaration honored, not silently treated as 'no rule = block everything')"

say "PART 3 — apply with --with-deletes alone succeeds (no force needed, nothing to force)"
APPLY3_OUT=$(wp2 wprism apply --repo=/siterepo --with-deletes --default-author=admin --format=json | tail -1)
require_wprism_answered "target PMPro deletion apply" json "$APPLY3_OUT" # wprism-premise-owner: paid-memberships-pro
echo "$APPLY3_OUT" | jq .
echo "$APPLY3_OUT" | jq -e '.canary == "clean"' >/dev/null || fail "apply canary not clean: $APPLY3_OUT"
echo "$APPLY3_OUT" | jq -e '(.warnings // []) | map(select(contains("FORCED"))) | length == 0' >/dev/null \
  || fail "unexpected FORCED warning on an unguarded delete: $APPLY3_OUT"
pass "unguarded delete applied cleanly, no force needed, no FORCED warning (nothing was overridden)"

say "PART 3 — acceptance: restriction gone on B (both level and page themselves untouched — only the join row was ever deleted), retry idempotent"
STILL_RESTRICTED_B=$(wp2 db query --skip-column-names "SELECT COUNT(*) FROM wp_pmpro_memberships_pages WHERE membership_id=$LEVEL_B AND page_id=$PAGE_B" | tr -d '\r')
require_observed_nonempty "target PMPro restriction count after delete" "$STILL_RESTRICTED_B" # wprism-premise-owner: paid-memberships-pro
[ "$STILL_RESTRICTED_B" = "0" ] || fail "restriction row still present on B"
LEVEL_STILL_THERE=$(wp2 db query --skip-column-names "SELECT id FROM wp_pmpro_membership_levels WHERE id=$LEVEL_B" | tr -d '\r')
require_observed_nonempty "target retained PMPro membership level id" "$LEVEL_STILL_THERE" # wprism-premise-owner: paid-memberships-pro
[ "$LEVEL_STILL_THERE" = "$LEVEL_B" ] || fail "the membership level itself was incorrectly removed (only the composite_ref row should be gone)"
PAGE_STILL_THERE=$(wp2 post list --post_type=page --name=deletion-matrix-restricted --field=ID)
require_observed_nonempty "target retained PMPro page id" "$PAGE_STILL_THERE" # wprism-premise-owner: paid-memberships-pro
[ "$PAGE_STILL_THERE" = "$PAGE_B" ] || fail "the page itself was incorrectly removed (got: '$PAGE_STILL_THERE', expected: '$PAGE_B')"
RETRY3=$(wp2 wprism plan --repo=/siterepo --format=json | tail -1)
require_wprism_answered "target PMPro deletion retry plan" json "$RETRY3" # wprism-premise-owner: paid-memberships-pro
echo "$RETRY3" | jq -e '(.delete | length) == 0 and (.delete_conflict | length) == 0' >/dev/null \
  || fail "retry plan still shows pending deletes: $RETRY3"
pass "PART 3 complete: unguarded composite_ref delete converges cleanly, level and page both survive untouched, retry settles idempotently"

printf '\n\033[1;32m✔ CERTIFY DELETION MATRIX PASSED (woocommerce product deletion fail-closed; ninja-forms child cascades + parent refusal; pmpro unguarded composite_ref delete)\033[0m\n'

say "cleanup: destroy the delmatrix pair (green run — 'destroy-when-green' convention; unreached on any earlier failure)"
bash bin/pair.sh destroy delmatrix
pass "delmatrix pair destroyed"
