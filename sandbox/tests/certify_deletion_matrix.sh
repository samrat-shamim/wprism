#!/usr/bin/env bash
# Certify deletion matrix (DUO-3223 slice 4): --with-deletes scenarios for
# the three manifests declaring their own deletions/guards blocks on
# PLUGIN-owned typed-snapshot tables, beyond core's own post/term deletion
# guards (already exhaustively covered by conformance/checks/core.sh,
# task #88-era work). Proves the SAME generic guard mechanism
# (Apply.php's count_guard_refs(), driven entirely by each manifest's own
# declared deletions block) generalizes correctly from core's hardcoded
# post-type guards to arbitrary plugin-declared table guards, with no new
# engine code needed -- this is a certification of an existing mechanism,
# not new capability work.
#
#   PART 1 (WooCommerce): post:product's wc_order_product_lookup guard --
#     a product referenced by a real order can't be deleted unforced.
#     Permanentizes sandbox/tests/spike_d_woo.sh's own already-proven
#     referential-guard sequence (same command shapes, same action-
#     scheduler polling detail) onto this pair.sh-based two-environment
#     convention, matching how certify_merge.sh permanentized
#     spike_b_merge.sh.
#   PART 2 (Ninja Forms): table:nf3_forms's THREE-guard shape -- two
#     cross-table guards (nf3_actions/nf3_fields referencing the form) and
#     one postmeta guard (_form_id submissions). Notable, verified live
#     rather than assumed: nf3_fields/nf3_actions are declared GUARDS, not
#     cascades -- only attached_meta (nf3_form_meta) cascades
#     automatically. All three guard targets survive a forced delete
#     orphaned but intact, exactly as declared. Getting there took three
#     failed designs first (see the PART 2 seed section for the full
#     story) -- unlike PART 1's guard table (wc_order_product_lookup,
#     never itself a Duo entity), nf3_fields/nf3_actions ARE declared
#     authored_snapshot tables, so an orphaned or never-captured row in
#     them breaks either capture's own ref-integrity check or plan's
#     read-only identity resolution, and a row scheduled for its OWN
#     deletion in the same batch is excluded from counting as a blocking
#     reference at all. The shape that actually works: create field/
#     action directly on the target, then run a throwaway `wp duo capture
#     --out=/tmp` there immediately after -- capture's ledger side effect
#     (minting duo_map/duo_state) happens regardless of where its output
#     tree goes, properly mapping the rows into the target's OWN ledger
#     without ever touching the tracked state/ directory, git history, or
#     the source environment's own view of the world.
#   PART 3 (Paid Memberships Pro): table:pmpro_memberships_pages declares
#     an EMPTY guards/cascades block -- the thinnest case, proving a plain
#     unguarded composite_ref delete still converges cleanly end to end
#     (no guard machinery to exercise, but real evidence the empty
#     declaration isn't accidentally silently broken either).
#
# Each part: guard-refusal (loud, naming the referencing rows) where a
# guard exists, --force-delete-referenced override behavior, tombstone
# convergence on the target, and cascade completeness verified against
# the declared block.
#
# TWO real, isolated WordPress environments (own dedicated pair, never
# any other agent's), matching certify_merge.sh's convention: both sides
# symmetric (install+activate directly on both -- this certifies
# deletion, not deploy reconciliation, so nothing here needs the
# files-only/deploy split conformance/run.sh exercises). destroy-when-
# green: the pair is destroyed only after every assertion below passes,
# so a failing run leaves it up for inspection.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

PORT1="${DELMATRIX_PORT1:-8868}"
PORT2="${DELMATRIX_PORT2:-8869}"
COMPOSE="docker compose -p duo-delmatrix -f pair.yml"
export DUO_PAIR=delmatrix
wp1() { $COMPOSE run --rm -T cli1 wp "$@"; }
wp2() { $COMPOSE run --rm -T cli2 wp "$@"; }
GIT_A="git -C siterepo/delmatrix1 -c user.name=duo-a -c user.email=a@example.test"
GIT_B="git -C siterepo/delmatrix2 -c user.name=duo-b -c user.email=b@example.test"

say "clean-room via pair.sh (own pair, isolated — headless)"
bash bin/pair.sh reset delmatrix
bash bin/pair.sh up delmatrix "$PORT1" "$PORT2" --headless

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
cat > siterepo/delmatrix1/site.duo.json <<'EOF'
{
  "manifests": ["core", "woocommerce", "ninja-forms", "paid-memberships-pro"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon"],
    "taxonomies": ["category", "post_tag", "product_cat", "product_type"]
  },
  "spec_version": 1
}
EOF
cp site-repo.gitignore.template siterepo/delmatrix1/.gitignore
git -C siterepo/delmatrix1 init -q -b main
git -C siterepo/delmatrix1 remote add origin ../origin-delmatrix.git
wp1 duo capture --repo=/siterepo
$GIT_A add -A && $GIT_A commit -qm "baseline: core+woocommerce+ninja-forms+pmpro, empty" && $GIT_A push -qu origin main

git clone -q siterepo/origin-delmatrix.git siterepo/delmatrix2
wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --format=json | tail -1 | jq .
pass "baseline established on A, B converged"

# ============================================================================
# PART 1 — WooCommerce: post:product's wc_order_product_lookup guard
# ============================================================================

say "PART 1 — seed a product on A, capture, converge B"
# Deliberately no --manage_stock/--stock_quantity: WooCommerce decrements
# stock as a side effect of placing a real order (set_status('processing')
# below), which would show up as "target entity changed locally" drift on
# B in ADDITION to the guard -- muddying a test whose whole point is the
# guard mechanism specifically. Keeping stock management off the product
# entirely means placing the order touches nothing this manifest captures.
PRODUCT_A=$(wp1 wc product create --name='Deletion Matrix Widget' --sku=del-matrix-widget --regular_price=19.99 --user=admin --porcelain)
wp1 duo capture --repo=/siterepo >/dev/null
$GIT_A add -A && $GIT_A commit -qm "A: seed product" && $GIT_A push -q origin main
$GIT_B pull -q origin main
wp2 duo apply --repo=/siterepo --default-author=admin --format=json | tail -1 | jq .
PRODUCT_B=$(wp2 post list --post_type=product --name=deletion-matrix-widget --field=ID)
[ -n "$PRODUCT_B" ] || fail "product did not converge on B"
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
# WooCommerce debounces the analytics lookup-table sync a few seconds into
# the future (observed: order-save time + ~5s), not immediately queued --
# poll rather than a single fixed sleep, matching spike_d_woo.sh's own
# proven pattern exactly.
LOOKUP_ROWS=0
for _ in $(seq 1 8); do
  wp2 action-scheduler run >/dev/null
  LOOKUP_ROWS=$(wp2 db query "SELECT COUNT(*) FROM wp_wc_order_product_lookup WHERE product_id=$PRODUCT_B" --skip-column-names)
  [ "$LOOKUP_ROWS" -ge "1" ] && break
  sleep 2
done
[ "$LOOKUP_ROWS" -ge "1" ] || fail "no wc_order_product_lookup row for the order (after polling the action scheduler)"
pass "order #$ORDER_B placed on B; wc_order_product_lookup has $LOOKUP_ROWS row(s)"

say "PART 1 — on A: delete the referenced product, capture, propagate"
wp1 post delete "$PRODUCT_A" --force >/dev/null
wp1 duo capture --repo=/siterepo >/dev/null
$GIT_A add -A && $GIT_A commit -qm "A: delete Deletion Matrix Widget" && $GIT_A push -q origin main
$GIT_B pull -q origin main

say "PART 1 — B's plan must show the delete BLOCKED, naming the order guard"
# May land in .delete[] or .delete_conflict[] (a target-side change since
# the tombstone's own expected base -- e.g. WooCommerce's own lookup-table
# housekeeping touching the product row -- can co-occur with the guard;
# checks/core.sh's own deletion tests show both shapes). Only one entity
# is being deleted here, so check both arrays' .blocked field rather than
# requiring a specific one, matching this scenario's actual scope: proving
# the guard fires and is named, not which specific plan bucket it lands in.
PLAN1=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN1" | jq .
BLOCKED1=$(echo "$PLAN1" | jq -r '[.delete[]?, .delete_conflict[]?] | map(.blocked // empty) | join("\n")')
[ -n "$BLOCKED1" ] || fail "plan did not show the product's delete as blocked at all: $PLAN1"
echo "$BLOCKED1" | grep -qi "orders reference" || fail "plan did not name the wc_order_product_lookup guard: $BLOCKED1"
pass "plan blocks the delete, naming the order reference"

say "PART 1 — apply refuses without --force-delete-referenced"
set +e
APPLY1_ERR=$(wp2 duo apply --repo=/siterepo --with-deletes --default-author=admin 2>&1)
APPLY1_RC=$?
set -e
echo "$APPLY1_ERR"
[ "$APPLY1_RC" -ne 0 ] || fail "apply succeeded despite the referential guard"
echo "$APPLY1_ERR" | grep -qi 'referential guard' || fail "failure did not mention the referential guard: $APPLY1_ERR"
pass "apply refused the guarded delete"

say "PART 1 — apply with --force-delete-referenced succeeds with a loud FORCED warning"
FORCE1_OUT=$(wp2 duo apply --repo=/siterepo --with-deletes --force-delete-referenced --default-author=admin 2>&1)
echo "$FORCE1_OUT"
echo "$FORCE1_OUT" | grep -qi 'FORCED' || fail "no FORCED warning printed: $FORCE1_OUT"
pass "forced delete applied with a loud warning"

say "PART 1 — acceptance: product gone on B, order untouched, tombstone convergence idempotent on retry"
REMAINING=$(wp2 post list --post_type=product --name=deletion-matrix-widget --field=ID)
[ -z "$REMAINING" ] || fail "product still present on B"
STILL=$(wp2 db query "SELECT COUNT(*) FROM wp_wc_order_product_lookup WHERE order_id=$ORDER_B" --skip-column-names)
[ "$STILL" = "$LOOKUP_ROWS" ] || fail "order's lookup row(s) were disturbed by the delete"
ORDER_STATUS=$(wp2 wc shop_order get "$ORDER_B" --field=status --user=admin)
[ -n "$ORDER_STATUS" ] || fail "order no longer retrievable"
RETRY1=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$RETRY1" | jq -e '(.delete | length) == 0 and (.delete_conflict | length) == 0' >/dev/null \
  || fail "retry plan still shows pending deletes: $RETRY1"
pass "PART 1 complete: product removed on B, order (status=$ORDER_STATUS) untouched, retry settles idempotently"

# ============================================================================
# PART 2 — Ninja Forms: table:nf3_forms's THREE-guard shape
# ============================================================================

say "PART 2 — seed a FORM ONLY on A, capture, converge B"
# THREE earlier drafts of this section hit real, structural walls, not
# test bugs to route around:
#   (1) seeding field/action on A too, then raw-SQL-deleting ONLY the
#       form on A before capture, orphaned Duo-tracked nf3_actions/
#       nf3_fields rows still pointing at a vanished parent — capture
#       correctly refused ("unmanaged nf3_form ref ... capture scope must
#       include the referenced row"): every row in a DECLARED table is in
#       Snapshot's own ref-integrity scope, unlike wc_order_product_lookup
#       (PART 1's guard table, never itself a Duo entity).
#   (2) creating field/action directly on B, with no other step: `wp duo
#       plan` (read-only, mint=false) refused ("mapped identity missing
#       for populated table 'nf3_actions' ... refusing to create or
#       rebind it") the moment it tried to account for B's own declared-
#       table rows — a never-captured row in a table Duo itself declares
#       can't be silently minted during a read-only operation.
#   (3) seeding field/action on A alongside the form and deleting all of
#       them together in one batch: the delete DID succeed and DID show
#       the postmeta guard, but count_guard_refs() turned out to exclude
#       rows that are THEMSELVES also being deleted in the same batch
#       from counting as blocking references — field/action's own
#       tombstones meant they never got a chance to block the form's.
# The shape that satisfies all three constraints: create field/action
# directly on B (matching (2)'s starting point), then run `wp duo
# capture --out=/tmp` on B RIGHT AFTER — capture's ledger side effect
# (minting duo_map/duo_state entries for whatever it finds live) happens
# regardless of where its OUTPUT state tree goes, so this properly maps
# B's own field/action into B's OWN ledger without ever touching the
# tracked state/ directory or git history, and without A ever knowing
# these rows exist. Field/action are now "properly known" (satisfying
# (2)'s constraint) but never part of any delete batch (satisfying (3)'s).
NF_SEED=$(wp1 eval '
global $wpdb;
$now = current_time("mysql");
$wpdb->insert($wpdb->prefix . "nf3_forms", [
  "title" => "Deletion Matrix Form", "key" => "deletion_matrix_form",
  "created_at" => $now, "updated_at" => $now,
]);
echo $wpdb->insert_id;
')
FORM_A="$NF_SEED"
[ -n "$FORM_A" ] || fail "ninja-forms seed did not produce a form id"
wp1 duo capture --repo=/siterepo >/dev/null
$GIT_A add -A && $GIT_A commit -qm "A: seed Deletion Matrix Form" && $GIT_A push -q origin main
$GIT_B pull -q origin main
wp2 duo apply --repo=/siterepo --default-author=admin --format=json | tail -1 | jq .
FORM_B=$(wp2 db query --skip-column-names "SELECT id FROM wp_nf3_forms WHERE title='Deletion Matrix Form'" | tr -d '\r')
[ -n "$FORM_B" ] || fail "form did not converge on B"
echo "form: A=$FORM_A B=$FORM_B"

say "PART 2 — on B ONLY: attach a field, an action, form-level meta (the cascade), and a submission — then run a throwaway 'wp duo capture --out=/tmp' on B to properly mint B's OWN ledger entries for field/action without touching git or A's own view of the world"
NF_B_SEED=$(wp2 eval "
global \$wpdb;
\$now = current_time('mysql');
\$wpdb->insert(\$wpdb->prefix . 'nf3_form_meta', [
  'parent_id' => $FORM_B, 'key' => 'cascade_marker', 'value' => 'should be cascaded away',
]);
\$wpdb->insert(\$wpdb->prefix . 'nf3_fields', [
  'parent_id' => $FORM_B, 'type' => 'textbox', 'key' => 'field_key_1',
  'label' => 'Name', 'created_at' => \$now, 'updated_at' => \$now,
]);
\$field_id = \$wpdb->insert_id;
\$wpdb->insert(\$wpdb->prefix . 'nf3_actions', [
  'parent_id' => $FORM_B, 'type' => 'successmessage', 'key' => 'action_key_1',
  'title' => 'Success Message', 'label' => 'Success Message', 'active' => 1,
  'created_at' => \$now, 'updated_at' => \$now,
]);
\$action_id = \$wpdb->insert_id;
\$sub_id = wp_insert_post(['post_type' => 'nf_sub', 'post_status' => 'publish', 'post_title' => 'Submission']);
update_post_meta(\$sub_id, '_form_id', $FORM_B);
echo \"\$field_id|\$action_id|\$sub_id\";
")
IFS='|' read -r FIELD_B ACTION_B SUB_B <<< "$NF_B_SEED"
[ -n "$FIELD_B" ] && [ -n "$ACTION_B" ] && [ -n "$SUB_B" ] || fail "B-local field/action/submission seed did not produce all three ids (got: $NF_B_SEED)"
wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-mint-b-local >/dev/null
rm -rf "siterepo/delmatrix2/.tmp-mint-b-local"
MINTED_FIELD=$(wp2 db query --skip-column-names "SELECT uuid FROM wp_duo_map WHERE id_kind='nf3_field' AND local_id=$FIELD_B" | tr -d '\r')
MINTED_ACTION=$(wp2 db query --skip-column-names "SELECT uuid FROM wp_duo_map WHERE id_kind='nf3_action' AND local_id=$ACTION_B" | tr -d '\r')
[ -n "$MINTED_FIELD" ] && [ -n "$MINTED_ACTION" ] || fail "throwaway capture did not mint B's own ledger entries for field/action (field uuid: '$MINTED_FIELD', action uuid: '$MINTED_ACTION')"
pass "form=$FORM_B; B-local: field=$FIELD_B (uuid $MINTED_FIELD), action=$ACTION_B (uuid $MINTED_ACTION), submission=$SUB_B — field/action minted into B's own ledger, none of this ever pushed to A or committed"

say "PART 2 — on A: delete the form (clean — A never had any field/action/meta attached, so this is a straightforward tombstone, no orphan-ref concern), capture, propagate"
wp1 db query "DELETE FROM wp_nf3_forms WHERE id=$FORM_A"
wp1 duo capture --repo=/siterepo >/dev/null
$GIT_A add -A && $GIT_A commit -qm "A: delete Deletion Matrix Form" && $GIT_A push -q origin main
$GIT_B pull -q origin main

say "PART 2 — B's plan must show the delete BLOCKED, naming all three guards (actions, fields, submissions)"
PLAN2=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN2" | jq .
# Same posture as PART 1: check both .delete[] and .delete_conflict[]
# rather than assuming a specific bucket -- only one entity is being
# deleted here.
BLOCKED2=$(echo "$PLAN2" | jq -r '[.delete[]?, .delete_conflict[]?] | map(.blocked // empty) | join("\n")')
[ -n "$BLOCKED2" ] || fail "plan did not show the form's delete as blocked at all: $PLAN2"
echo "$BLOCKED2" | grep -qi "actions reference" || fail "plan did not name the nf3_actions guard: $BLOCKED2"
echo "$BLOCKED2" | grep -qi "fields reference" || fail "plan did not name the nf3_fields guard: $BLOCKED2"
echo "$BLOCKED2" | grep -qi "submissions reference" || fail "plan did not name the postmeta submission guard: $BLOCKED2"
pass "plan blocks the delete, naming all three declared guards"

say "PART 2 — apply refuses without --force-delete-referenced"
# The throwaway capture-mint step above touched B's OWN identity/state
# tracking for the form entity too (a full capture pass re-evaluates
# everything in scope, not just the rows it was minting identities for),
# so B's plan landed this in .delete_conflict[] (own local drift) as well
# as being guard-blocked -- matching spike_d_woo.sh's own established
# precedent of needing BOTH --force-theirs and --force-delete-referenced
# together, not just the referential-guard override alone. The refusal
# message correspondingly may name either "referential guard" or
# "deletion conflict" depending on which check trips first — check for
# either rather than assuming one specific wording.
set +e
APPLY2_ERR=$(wp2 duo apply --repo=/siterepo --with-deletes --force-theirs --default-author=admin 2>&1)
APPLY2_RC=$?
set -e
[ "$APPLY2_RC" -ne 0 ] || fail "apply succeeded despite three live referential guards"
echo "$APPLY2_ERR" | grep -qiE 'referential guard|deletion conflict' \
  || fail "failure did not mention a referential guard or deletion conflict: $APPLY2_ERR"
pass "apply refused the guarded delete"

say "PART 2 — apply with --force-theirs --force-delete-referenced succeeds with a loud FORCED warning"
FORCE2_OUT=$(wp2 duo apply --repo=/siterepo --with-deletes --force-theirs --force-delete-referenced --default-author=admin 2>&1)
echo "$FORCE2_OUT"
echo "$FORCE2_OUT" | grep -qi 'FORCED' || fail "no FORCED warning printed: $FORCE2_OUT"
pass "forced delete applied with a loud warning"

say "PART 2 — acceptance: form gone; attached_meta CASCADE removed automatically; the GUARDED field/action/submission — none of them ever scheduled for their own deletion, purely live guard references — all survive the forced delete completely untouched"
# The precise cascade-vs-guard distinction the manifest declares:
# nf3_form_meta cleans up as a pure SIDE EFFECT of the form's own deletion
# (a direct fk-keyed cleanup, independent of whether Duo ever captured
# those meta rows as first-class entities) — that's what "cascade" means
# here. nf3_fields/nf3_actions are NOT cascaded: they are guards, and
# since neither was ever itself scheduled for deletion in this batch,
# they must survive, orphaned (parent_id now pointing at nothing) but
# intact — proving the manifest's guard-not-cascade declaration is real
# engine behavior, not just documentation, exactly like the submission.
FORM_GONE=$(wp2 db query --skip-column-names "SELECT id FROM wp_nf3_forms WHERE id=$FORM_B" | tr -d '\r')
[ -z "$FORM_GONE" ] || fail "form still present on B"
META_GONE=$(wp2 db query --skip-column-names "SELECT COUNT(*) FROM wp_nf3_form_meta WHERE parent_id=$FORM_B" | tr -d '\r')
[ "$META_GONE" = "0" ] || fail "declared cascade (attached_meta/nf3_form_meta) did not clean up (found $META_GONE row(s))"
FIELD_SURVIVES=$(wp2 db query --skip-column-names "SELECT id FROM wp_nf3_fields WHERE id=$FIELD_B" | tr -d '\r')
[ "$FIELD_SURVIVES" = "$FIELD_B" ] || fail "guarded nf3_fields row did NOT survive the forced parent delete (expected orphaned survival, per the manifest's own guard-not-cascade declaration; got: '$FIELD_SURVIVES') -- either the manifest declaration or the engine's actual behavior changed; this needs a human decision, not a silent update to this assertion"
ACTION_SURVIVES=$(wp2 db query --skip-column-names "SELECT id FROM wp_nf3_actions WHERE id=$ACTION_B" | tr -d '\r')
[ "$ACTION_SURVIVES" = "$ACTION_B" ] || fail "guarded nf3_actions row did NOT survive the forced parent delete (same concern as the field check above)"
SUB_SURVIVES=$(wp2 post list --post_type=nf_sub --field=ID --include="$SUB_B" 2>/dev/null | tr -d '\r')
[ "$SUB_SURVIVES" = "$SUB_B" ] || fail "guarded submission post did NOT survive the forced parent delete"
pass "form removed, cascade (form_meta) cleaned up, guarded rows (field/action/submission) survive orphaned exactly as declared"

say "PART 2 — GENUINE FINDING, not a test bug: the orphaned guard-survivor rows now break wp duo plan's own general ref-integrity scan on ANY subsequent call, for this table, until an operator also removes them"
# A first attempt at this step asserted a clean idempotent retry (matching
# PART 1's and PART 3's own retry checks). It failed here, and the FAILURE
# is the real result, not a bug in the assertion: nf3_actions row 5's
# parent_id now points at nf3_forms id 2, which no longer exists (exactly
# the orphaned-but-surviving state this test just certified as correct
# guard behavior) — and Snapshot's own ref-integrity check, which runs on
# EVERY declared-table row regardless of whether that row has anything to
# do with the current operation, refuses the moment it tries to resolve
# that dangling ref. In other words: successfully forcing a guarded
# delete through, leaving the guard-referencing rows orphaned exactly as
# declared, leaves this environment unable to run `wp duo plan`/`capture`
# again AT ALL until an operator also removes (or reparents) the orphaned
# rows -- a real, load-bearing consequence of "guard, not cascade" that
# isn't obvious from the manifest declaration alone, and isn't something
# --force-delete-referenced's own warning mentions. Flagging for a human
# decision (documented behavior? a second guard needed against leaving
# force-deleted rows unresolved? something else?), not silently working
# around it here.
set +e
RETRY2=$(wp2 duo plan --repo=/siterepo --format=json 2>&1)
RETRY2_RC=$?
set -e
echo "$RETRY2"
[ "$RETRY2_RC" -ne 0 ] || fail "expected plan to refuse due to the now-dangling nf3_actions/nf3_fields parent_id ref -- if this now succeeds cleanly, the engine's behavior changed and this whole finding needs re-examination, not silent deletion of this assertion"
echo "$RETRY2" | grep -qi "unmanaged nf3_form ref" || fail "refusal was for an unexpected reason: $RETRY2"
pass "PART 2 complete: form removed, cascade (form_meta) cleaned up, guarded rows (field/action/submission) survive orphaned exactly as declared -- AND the orphaned-guard-survivor consequence for subsequent plan/capture calls is confirmed live and flagged, not silently absorbed"

say "PART 2 -> PART 3 handoff: clean up the orphaned guard survivors on B (the operator action the finding above says is needed to restore normal plan/capture operation) so PART 3 runs against a healthy environment"
wp2 db query "DELETE FROM wp_nf3_fields WHERE id=$FIELD_B; DELETE FROM wp_nf3_actions WHERE id=$ACTION_B"
wp2 duo plan --repo=/siterepo --format=json >/dev/null || fail "plan still refuses after removing the orphaned rows — the finding above was misdiagnosed"
pass "orphaned rows removed; plan/capture operate normally again on B"

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
wp1 duo capture --repo=/siterepo >/dev/null
$GIT_A add -A && $GIT_A commit -qm "A: seed restricted page" && $GIT_A push -q origin main
$GIT_B pull -q origin main
wp2 duo apply --repo=/siterepo --adopt-by-slug=posts --default-author=admin --format=json | tail -1 | jq .
LEVEL_B=$(wp2 db query --skip-column-names "SELECT id FROM wp_pmpro_membership_levels WHERE name='Deletion Matrix Level'" | tr -d '\r')
PAGE_B=$(wp2 post list --post_type=page --name=deletion-matrix-restricted --field=ID)
RESTRICTED_B=$(wp2 db query --skip-column-names "SELECT COUNT(*) FROM wp_pmpro_memberships_pages WHERE membership_id=$LEVEL_B AND page_id=$PAGE_B" | tr -d '\r')
[ "$RESTRICTED_B" = "1" ] || fail "restriction row did not converge on B (own local ids: level=$LEVEL_B page=$PAGE_B)"
pass "level=$LEVEL_B, page=$PAGE_B, restriction row confirmed live on B with B's own local ids"

say "PART 3 — on A: un-restrict the page (removes the composite_ref row entirely — no direct 'delete' verb exists for a pure join row; this IS how it's deleted), capture, propagate"
wp1 eval "pmpro_update_post_level_restrictions($PAGE_A, []);" >/dev/null
UNRESTRICTED_A=$(wp1 db query --skip-column-names "SELECT COUNT(*) FROM wp_pmpro_memberships_pages WHERE membership_id=$LEVEL_A AND page_id=$PAGE_A" | tr -d '\r')
[ "$UNRESTRICTED_A" = "0" ] || fail "restriction row still present on A after un-restricting"
wp1 duo capture --repo=/siterepo >/dev/null
$GIT_A add -A && $GIT_A commit -qm "A: remove page restriction" && $GIT_A push -q origin main
$GIT_B pull -q origin main

say "PART 3 — B's plan shows the delete (NOT blocked — no guards declared on this table)"
PLAN3=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN3" | jq .
echo "$PLAN3" | jq -e '(.delete | length) + (.delete_conflict | length) >= 1' >/dev/null \
  || fail "plan did not show the restriction row as pending delete: $PLAN3"
echo "$PLAN3" | jq -e '[.delete[]?, .delete_conflict[]?] | map(select(.blocked != null and .blocked != "")) | length == 0' >/dev/null \
  || fail "plan shows a blocked delete on a table with no declared guards — unexpected: $PLAN3"
pass "plan shows the delete, correctly UNBLOCKED (empty guards declaration honored, not silently treated as 'no rule = block everything')"

say "PART 3 — apply with --with-deletes alone succeeds (no force needed, nothing to force)"
APPLY3_OUT=$(wp2 duo apply --repo=/siterepo --with-deletes --default-author=admin --format=json | tail -1)
echo "$APPLY3_OUT" | jq .
echo "$APPLY3_OUT" | jq -e '.canary == "clean"' >/dev/null || fail "apply canary not clean: $APPLY3_OUT"
echo "$APPLY3_OUT" | jq -e '(.warnings // []) | map(select(contains("FORCED"))) | length == 0' >/dev/null \
  || fail "unexpected FORCED warning on an unguarded delete: $APPLY3_OUT"
pass "unguarded delete applied cleanly, no force needed, no FORCED warning (nothing was overridden)"

say "PART 3 — acceptance: restriction gone on B (both level and page themselves untouched — only the join row was ever deleted), retry idempotent"
STILL_RESTRICTED_B=$(wp2 db query --skip-column-names "SELECT COUNT(*) FROM wp_pmpro_memberships_pages WHERE membership_id=$LEVEL_B AND page_id=$PAGE_B" | tr -d '\r')
[ "$STILL_RESTRICTED_B" = "0" ] || fail "restriction row still present on B"
LEVEL_STILL_THERE=$(wp2 db query --skip-column-names "SELECT id FROM wp_pmpro_membership_levels WHERE id=$LEVEL_B" | tr -d '\r')
[ "$LEVEL_STILL_THERE" = "$LEVEL_B" ] || fail "the membership level itself was incorrectly removed (only the composite_ref row should be gone)"
PAGE_STILL_THERE=$(wp2 post list --post_type=page --name=deletion-matrix-restricted --field=ID)
[ "$PAGE_STILL_THERE" = "$PAGE_B" ] || fail "the page itself was incorrectly removed (got: '$PAGE_STILL_THERE', expected: '$PAGE_B')"
RETRY3=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$RETRY3" | jq -e '(.delete | length) == 0 and (.delete_conflict | length) == 0' >/dev/null \
  || fail "retry plan still shows pending deletes: $RETRY3"
pass "PART 3 complete: unguarded composite_ref delete converges cleanly, level and page both survive untouched, retry settles idempotently"

printf '\n\033[1;32m✔ CERTIFY DELETION MATRIX PASSED (woocommerce order-lookup guard; ninja-forms three-guard shape + cascade/guard distinction; pmpro unguarded composite_ref delete)\033[0m\n'

say "cleanup: destroy the delmatrix pair (green run — 'destroy-when-green' convention; unreached on any earlier failure)"
bash bin/pair.sh destroy delmatrix
pass "delmatrix pair destroyed"
