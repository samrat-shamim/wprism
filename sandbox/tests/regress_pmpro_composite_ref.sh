#!/usr/bin/env bash
# Regression — DUO-3235: LIVE, docker-based round-trip proof for BOTH halves
# of the typed-snapshot grammar extension, against the SHIPPED
# manifests/paid-memberships-pro.json (not a synthetic declaration —
# proving the actual deliverable):
#
#   - task #125 (composite primary keys): pmpro_memberships_pages
#     (membership_id, page_id — no surrogate id column) is PMPro's REAL
#     content-restriction mechanism (pmpro_update_post_level_restrictions(),
#     the code behind the real wp-admin "Require Membership" meta box).
#     New identity.mode=composite_ref (agent/src/Snapshot.php).
#   - task #126 (sidecar PK override): pmpro_membership_levelmeta's real PK
#     column is `meta_id`, not `id` — the new `id_column` manifest field.
#
# Complements, not replaces, sandbox/tests/regress_composite_ref.php's
# offline FakeWpdb harness (fast, no docker, proves the tuple-identity
# logic and every schema-assertion invariant in isolation). THIS script
# proves what the offline harness structurally cannot (Apply.php is out of
# its dependency boundary by design — see Snapshot.php's own docblock,
# "Engine boundary"): a real round-trip through the actual `wp duo`
# CLI/plan/apply pipeline to a FRESH target, resolving both composite
# columns to the target's OWN local ids (necessarily different numbers
# from the source, on a real second WordPress+MySQL install), a real
# byte-identical recapture, a real `wp duo lint` pass, and the "no update
# bucket, only create/delete" plan-bucket claim Snapshot.php's own docblock
# defers to this file for.
#
# Also proves filename-determinism across environments (added after PR #13/
# DUO-3239 found and fixed a real bug this file's own original methodology
# structurally could not see: the composite_ref slug used to be built from
# THIS environment's own local ids, which are never portable — every
# cross-environment recapture produced a spurious filename divergence,
# caught only by conformance/run.sh's directory-tree `diff -r`, since this
# file's own recapture check diffs two already-known file PATHS by content,
# never a directory listing). See step (5)'s own comment for the pinned
# invariant.
#
# Self-contained: uses sandbox/bin/pair.sh (docs/sandbox.md), its own
# scratch pair (created and destroyed by this script, never touching any
# other agent's live pair). Ports 8932/8933 (PORT_BASE 8930 was assigned
# for this dispatch; 8930/8931 were this session's own exploratory pair,
# already destroyed before authoring this script).
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

PAIR=asnaprt
PORT1=8932
PORT2=8933
export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2" DUO_CODEBIND_PLUGIN=""

wp1() { docker compose -p "duo-$PAIR" -f pair.yml run --rm -T cli1 wp "$@"; }
wp2() { docker compose -p "duo-$PAIR" -f pair.yml run --rm -T cli2 wp "$@"; }
GIT_1="git -C siterepo/${PAIR}1 -c user.name=duo-$PAIR -c user.email=$PAIR@example.test"

cleanup() {
  bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
}
trap cleanup EXIT

say "bring up scratch pair '$PAIR' ($PORT1/$PORT2)"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2"
pass "pair '$PAIR' ready"

# PMPro was permanently removed from wp.org on 2024-10-17 (see manifests/
# paid-memberships-pro.json's own notes) — install the official GitHub
# release tag, exactly matching this manifest's own pinned evidence version.
say "install + activate Paid Memberships Pro 3.8.3 (official GitHub tag) on both sides"
wp1 plugin install https://github.com/strangerstudios/paid-memberships-pro/archive/refs/tags/3.8.3.zip --activate >/dev/null
wp2 plugin install https://github.com/strangerstudios/paid-memberships-pro/archive/refs/tags/3.8.3.zip --activate >/dev/null
wp1 plugin is-active paid-memberships-pro >/dev/null || fail "PMPro not active on side 1"
wp2 plugin is-active paid-memberships-pro >/dev/null || fail "PMPro not active on side 2"
pass "paid-memberships-pro active on both sides"

# Seed via PMPro's OWN real functions, not hand-rolled SQL for the parts
# that have one (matching every prior grind round's discipline):
#   - pmpro_generatePages() — the real Setup Wizard / admin "create missing
#     pages" function.
#   - a real WP page to restrict.
#   - one membership level via direct $wpdb->insert (PMPro's admin save
#     handler, adminpages/levels/save-level.php, itself just does this —
#     there is no separate "public API" wrapper worth calling through).
#   - update_pmpro_membership_level_meta() — PMPro's OWN real meta-write
#     function (includes/functions.php) — the DUO-3235/task #126 proving
#     value lives here, in pmpro_membership_levelmeta.
#   - pmpro_update_post_level_restrictions() — the REAL function behind the
#     wp-admin "Require Membership" meta box. The DUO-3235/task #125 proving
#     fact lives here, in pmpro_memberships_pages.
say "(1) seed real content on side 1: PMPro system pages, one level, one meta key, a real page restriction"
read -r -d '' SEED_PHP <<'PHPEOF' || true
<?php
global $wpdb;

// pmpro_generatePages($pages) takes an ARRAY (name => title), not zero args
// — confirmed by reading includes/functions.php:3948 directly against this
// SAME 3.8.3 tag (an earlier grind round's shorthand implied a no-arg call;
// re-verified empirically rather than trusted, per this project's own
// "empirics before classification" rule). The exact 9-name/title shape
// mirrored here is the plugin's OWN real default set, read from
// adminpages/pagesettings.php's "no page_name in the request" branch —
// the same 9 names this manifest's own pmpro_*_page_id options are keyed on.
$created = pmpro_generatePages([
    'account' => 'Membership Account',
    'billing' => 'Membership Billing',
    'cancel' => 'Membership Cancel',
    'checkout' => 'Membership Checkout',
    'confirmation' => 'Membership Confirmation',
    'invoice' => 'Membership Orders',
    'levels' => 'Membership Levels',
    'login' => 'Log In',
    'member_profile_edit' => 'Your Profile',
]);
echo "pmpro_generatePages(): " . count((array) $created) . " page(s) touched\n";

$level_id = $wpdb->insert($wpdb->pmpro_membership_levels, [
    'name' => 'Composite Ref Test Level',
    'description' => 'DUO-3235 regression fixture',
    'confirmation' => 'Welcome to the composite_ref regression level.',
    'allow_signups' => 1,
    'initial_payment' => 19.99,
    'billing_amount' => 0,
    'cycle_number' => 0,
    'cycle_period' => 'Month',
    'billing_limit' => 0,
    'trial_amount' => 0,
    'trial_limit' => 0,
    'expiration_number' => 0,
    'expiration_period' => 'Year',
]) ? $wpdb->insert_id : 0;
if (!$level_id) {
    fwrite(STDERR, "failed to insert membership level\n");
    exit(1);
}
echo "level_id=$level_id\n";

// task #126 proving value — a real, distinctive authored string in the
// EXACT table (pmpro_membership_levelmeta) whose PK column (meta_id, not
// id) is this issue's second half.
update_pmpro_membership_level_meta($level_id, 'membership_account_message', 'DUO-3235 composite_ref regression marker');

$page_id = wp_insert_post([
    'post_title' => 'Composite Ref Test Page',
    'post_status' => 'publish',
    'post_type' => 'page',
    'post_content' => 'Restricted content for the DUO-3235 regression.',
]);
if (!$page_id || is_wp_error($page_id)) {
    fwrite(STDERR, "failed to create test page\n");
    exit(1);
}
echo "page_id=$page_id\n";

// task #125 proving fact — the REAL restriction mechanism (the code behind
// wp-admin's "Require Membership" meta box), writing straight into
// pmpro_memberships_pages (membership_id, page_id — composite PK, no
// surrogate id column).
pmpro_update_post_level_restrictions($page_id, [$level_id]);
$restricted = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d",
    $level_id, $page_id
));
if ($restricted !== 1) {
    fwrite(STDERR, "expected exactly 1 pmpro_memberships_pages row after restriction, got $restricted\n");
    exit(1);
}
echo "restriction row confirmed live: membership_id=$level_id page_id=$page_id\n";
PHPEOF
printf '%s' "$SEED_PHP" > "siterepo/${PAIR}1/.tmp-seed.php"
wp1 eval-file /siterepo/.tmp-seed.php
rm -f "siterepo/${PAIR}1/.tmp-seed.php"
LEVEL_ID_1=$(wp1 db query "SELECT id FROM wp_pmpro_membership_levels WHERE name='Composite Ref Test Level'" --skip-column-names 2>/dev/null | tr -d '\r')
PAGE_ID_1=$(wp1 db query "SELECT ID FROM wp_posts WHERE post_title='Composite Ref Test Page' AND post_type='page'" --skip-column-names 2>/dev/null | tr -d '\r')
[ -n "$LEVEL_ID_1" ] && [ -n "$PAGE_ID_1" ] || fail "failed to read back seeded level/page ids on side 1"
pass "side 1 seeded: level_id=$LEVEL_ID_1 page_id=$PAGE_ID_1, restriction live, level meta key set"

say "site repo: pin the SHIPPED paid-memberships-pro manifest (the actual deliverable, not a synthetic declaration)"
rm -rf "siterepo/origin-$PAIR.git" "siterepo/${PAIR}1/.git" "siterepo/${PAIR}2" "siterepo/${PAIR}1/state" "siterepo/${PAIR}1/site.duo.json"
git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
# Site-policy-neutralize core's ref-typed options that a stock WP install
# always carries real (non-zero) values for but this test's narrow
# post_types=[page]/taxonomies=[] scope doesn't track — task #73's own
# unscoped-ref gate correctly refuses otherwise (a real, in-scope-able but
# currently-out-of-scope target, not dangling data). Identical mitigation to
# sandbox/tests/regress_snapshot_meta.sh's own site policy for the same
# reason; this test isn't about core's category/post scoping at all.
#
# "post"/"category" added to post_types/taxonomies (this test originally
# shipped without them): DUO-3229's fail-closed unscoped-entity-type gate
# landed after this fixture was first written — WordPress's own default
# "Hello World" post and "Uncategorized" category are capturable entities
# on every fresh install, and DUO-3229 correctly refuses to leave them
# silently out of policy scope. Reconfirmed live against a much-advanced
# main (through DUO-3216/#22) that this is still required, not a stale
# assumption. Same mitigation already applied to
# sandbox/tests/regress_tec_regen.sh for the identical reason.
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "paid-memberships-pro"],
  "policy": {
    "options": {
      "default_category": {"class": "env", "required": false},
      "page_for_posts": {"class": "env", "required": false},
      "page_on_front": {"class": "env", "required": false},
      "sticky_posts": {"class": "env", "required": false},
      "wp_page_for_privacy_policy": {"class": "env", "required": false}
    },
    "post_meta": {},
    "post_types": ["page", "post"],
    "taxonomies": ["category"]
  },
  "spec_version": 1
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
$GIT_1 init -q -b main
$GIT_1 remote add origin "../origin-$PAIR.git"
$GIT_1 add -A
$GIT_1 commit -qm "policy: core + paid-memberships-pro, post_types=[page]"
$GIT_1 push -qu origin main
pass "site repo initialized, pinning the shipped manifest"

say "(2) capture on side 1"
wp1 duo capture --repo=/siterepo
LEVEL_FILE=$(ls "siterepo/${PAIR}1/state/tables/pmpro_membership_levels/"*.json)
PAGES_FILE=$(ls "siterepo/${PAIR}1/state/tables/pmpro_memberships_pages/"*.json)
[ -n "$LEVEL_FILE" ] || fail "expected a captured pmpro_membership_levels row"
[ -n "$PAGES_FILE" ] || fail "expected a captured pmpro_memberships_pages row"
jq -e '.meta.membership_account_message == "DUO-3235 composite_ref regression marker"' "$LEVEL_FILE" >/dev/null \
  || fail "task #126: expected the levelmeta sidecar's authored key to be captured via the new id_column override (file: $(jq -c .meta "$LEVEL_FILE"))"
pass "task #126 proven at capture: pmpro_membership_levelmeta round-tripped through the id_column=meta_id override cleanly"
COLS=$(jq -c '.columns' "$PAGES_FILE")
echo "captured pmpro_memberships_pages columns: $COLS"
jq -e '.columns.membership_id | startswith("{{pmpro_level:")' "$PAGES_FILE" >/dev/null || fail "membership_id should be a pmpro_level token"
jq -e '.columns.page_id | startswith("{{post:")' "$PAGES_FILE" >/dev/null || fail "page_id should be a post token"
jq -e '.columns | has("modified") | not' "$PAGES_FILE" >/dev/null || fail "the runtime 'modified' column must never appear in canonical state"
pass "task #125 proven at capture: the composite-PK restriction fact captured as a real canonical entity, both columns tokenized, no surrogate id anywhere"

CAPTURED_UUID=$(jq -r '.uuid' "$PAGES_FILE")
$GIT_1 add -A
$GIT_1 commit -qm "capture: composite_ref regression fixture"
$GIT_1 push -q origin main

say "(3) clone into side 2 (a genuinely fresh, empty-of-this-content target), plan, apply"
git clone -q "siterepo/origin-$PAIR.git" "siterepo/${PAIR}2"
REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
PLAN1=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN1"
echo "$PLAN1" | jq -e '.create | length >= 2' >/dev/null || fail "expected at least 2 creates (level + restriction row) on a fresh target (plan: $PLAN1)"
APPLY1=$(wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV" --with-deletes --adopt-by-slug=posts,terms,menus,tables --format=json | tail -1)
echo "$APPLY1"
echo "$APPLY1" | jq -e '.canary == "clean"' >/dev/null || fail "expected a clean canary on the first apply"
pass "applied to side 2, canary clean"

LEVEL_ID_2=$(wp2 db query "SELECT id FROM wp_pmpro_membership_levels WHERE name='Composite Ref Test Level'" --skip-column-names 2>/dev/null | tr -d '\r')
PAGE_ID_2=$(wp2 db query "SELECT ID FROM wp_posts WHERE post_title='Composite Ref Test Page' AND post_type='page'" --skip-column-names 2>/dev/null | tr -d '\r')
[ -n "$LEVEL_ID_2" ] && [ -n "$PAGE_ID_2" ] || fail "level/page did not land on side 2"
say "(4) THE core cross-environment proof: side 2's own local ids for the SAME two entities"
echo "side 1: level_id=$LEVEL_ID_1 page_id=$PAGE_ID_1   |   side 2: level_id=$LEVEL_ID_2 page_id=$PAGE_ID_2"
if [ "$LEVEL_ID_1" = "$LEVEL_ID_2" ] && [ "$PAGE_ID_1" = "$PAGE_ID_2" ]; then
  echo "note: side 2's local ids happened to coincide with side 1's (both fresh installs, same insert order) — the proof below (resolving to WHATEVER side 2's own ids are, and matching the byte-identical recapture) still holds; a coincidental match is not a false pass because nothing in the assertions below hardcodes side 1's numbers."
fi
RESTRICTED_2=$(wp2 db query "SELECT COUNT(*) FROM wp_pmpro_memberships_pages WHERE membership_id=$LEVEL_ID_2 AND page_id=$PAGE_ID_2" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$RESTRICTED_2" = "1" ] || fail "expected exactly 1 pmpro_memberships_pages row on side 2 keyed on ITS OWN local ids (got $RESTRICTED_2)"
pass "task #125 proven live: the restriction row landed on side 2 resolved to side 2's OWN local ids on BOTH columns — the composite fact is genuinely portable, not a copied pair of stale numbers"

MSG_2=$(wp2 db query "SELECT meta_value FROM wp_pmpro_membership_levelmeta WHERE pmpro_membership_level_id=$LEVEL_ID_2 AND meta_key='membership_account_message'" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$MSG_2" = "DUO-3235 composite_ref regression marker" ] || fail "task #126: authored levelmeta value did not land on side 2 (got '$MSG_2')"
pass "task #126 proven live: the levelmeta sidecar (real PK column meta_id) round-tripped its authored key to side 2 correctly"

say "(5) byte-identical recapture on side 2"
wp2 duo capture --repo=/siterepo
LEVEL_FILE_2=$(ls "siterepo/${PAIR}2/state/tables/pmpro_membership_levels/"*.json)
PAGES_FILE_2=$(ls "siterepo/${PAIR}2/state/tables/pmpro_memberships_pages/"*.json)
diff -u "$LEVEL_FILE" "$LEVEL_FILE_2" >/dev/null || fail "pmpro_membership_levels file diverged between side 1 and recaptured side 2"
diff -u "$PAGES_FILE" "$PAGES_FILE_2" >/dev/null || fail "pmpro_memberships_pages file diverged between side 1 and recaptured side 2"
RECAPTURED_UUID=$(jq -r '.uuid' "$PAGES_FILE_2")
[ "$RECAPTURED_UUID" = "$CAPTURED_UUID" ] || fail "recaptured uuid ($RECAPTURED_UUID) differs from the original ($CAPTURED_UUID) — the SAME two referenced entities must derive the SAME uuid regardless of environment"
pass "byte-identical recapture, including the SAME uuid derived independently on side 2 from ITS OWN local ids — proves the uuid-over-referenced-tuple design, not just asserted"

# FILENAME-DETERMINISM PROOF (PR #13/DUO-3239 finding, amerge): this test's
# own file DISCOVERY (the `ls .../*.json` glob two lines above) finds
# "whatever file is there" and was therefore structurally blind to a
# spurious cross-environment FILENAME divergence — content-only `diff -u`
# against two already-known paths can never notice the paths themselves
# differ. That's exactly how a real bug shipped silently in DUO-3235's
# original composite_ref slug (built from THIS environment's own local
# ids, which are never portable and differ by construction on every
# cross-environment round-trip) and went uncaught here, only surfacing
# later via conformance/run.sh's directory-tree `diff -r`. Pin the
# invariant directly, in THIS file, so it can never silently regress here
# again: side 1's and side 2's own captured filenames for the SAME
# composite_ref entity must be byte-identical, not merely their contents.
BASENAME_1=$(basename "$PAGES_FILE")
BASENAME_2=$(basename "$PAGES_FILE_2")
[ "$BASENAME_1" = "$BASENAME_2" ] || fail "cross-environment FILENAME divergence for the same composite_ref entity: side 1 captured '$BASENAME_1', recaptured side 2 produced '$BASENAME_2' — the slug is not portable (regression of the DUO-3239/PR #13 fix: capture_composite_table()'s slug must derive from the referenced entities' own portable uuids, never this environment's local ids)"
pass "filename-determinism confirmed: side 1 and recaptured side 2 produced the IDENTICAL filename ($BASENAME_1) for the same composite_ref entity, despite genuinely different local ids underneath — a directory listing, not just file content, is now provably portable"

say "(6) wp duo lint — hard gate, zero findings expected"
LINT_OUT=$(wp2 duo lint --repo=/siterepo 2>&1)
LINT_RC=0
wp2 duo lint --repo=/siterepo >/dev/null 2>&1 || LINT_RC=$?
echo "$LINT_OUT"
[ "$LINT_RC" -eq 0 ] || fail "wp duo lint found findings (exit $LINT_RC) — see output above"
pass "lint: zero findings"

say "(7) plan-bucket proof: re-running plan against a fully-converged target shows NO update bucket for these entities (identity-is-the-fact, no separate update path — Snapshot.php's own docblock claim)"
PLAN2=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN2"
echo "$PLAN2" | jq -e '.update | length == 0' >/dev/null || fail "expected update==0 on a fully-converged re-plan (plan: $PLAN2)"
echo "$PLAN2" | jq -e '.unchanged | length >= 2' >/dev/null || fail "expected the level + restriction row to both show unchanged (plan: $PLAN2)"
pass "confirmed: update bucket is empty; both composite_ref-touched entities show unchanged, not update"

say "(8) change WHICH page is restricted on side 1 — proves identity-is-the-tuple: this must appear as delete-old-uuid + create-new-uuid, never an in-place update of the SAME uuid"
read -r -d '' RESTRICT_DIFFERENT_PAGE_PHP <<PHPEOF || true
<?php
global \$wpdb;
\$level_id = $LEVEL_ID_1;
\$old_page_id = $PAGE_ID_1;
\$new_page_id = wp_insert_post([
    'post_title' => 'Composite Ref Second Page',
    'post_status' => 'publish',
    'post_type' => 'page',
    'post_content' => 'A second page, restricted in place of the first.',
]);
if (!\$new_page_id || is_wp_error(\$new_page_id)) { fwrite(STDERR, "failed to create second page\n"); exit(1); }
pmpro_update_post_level_restrictions(\$old_page_id, []); // un-restrict the original page
pmpro_update_post_level_restrictions(\$new_page_id, [\$level_id]); // restrict the NEW page instead
echo "new_page_id=\$new_page_id\n";
PHPEOF
printf '%s' "$RESTRICT_DIFFERENT_PAGE_PHP" > "siterepo/${PAIR}1/.tmp-swap.php"
wp1 eval-file /siterepo/.tmp-swap.php
rm -f "siterepo/${PAIR}1/.tmp-swap.php"
wp1 duo capture --repo=/siterepo
NEW_PAGES_FILE=$(ls "siterepo/${PAIR}1/state/tables/pmpro_memberships_pages/"*.json)
NEW_UUID=$(jq -r '.uuid' "$NEW_PAGES_FILE")
[ "$NEW_UUID" != "$CAPTURED_UUID" ] || fail "restricting a DIFFERENT page produced the SAME uuid — identity-over-the-tuple is broken"
[ -f "$PAGES_FILE" ] && fail "the OLD restriction's file should be gone from side 1's own working tree after recapture (still present: $PAGES_FILE)"
pass "confirmed: a changed tuple is a genuinely DIFFERENT uuid ($CAPTURED_UUID -> $NEW_UUID), never an in-place identity mutation"
$GIT_1 add -A
$GIT_1 commit -qm "capture: swap the restricted page"
$GIT_1 push -q origin main
git -C "siterepo/${PAIR}2" pull -q origin main
REV2=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
PLAN3=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN3"
# 2 creates, not 1: the new page POST is also a fresh entity (never captured
# before) alongside the new pmpro_memberships_pages tuple — both land in the
# SAME create bucket, which is fine; the load-bearing assertion is that the
# TUPLE change itself is delete+create, never update.
echo "$PLAN3" | jq -e '(.update | length) == 0 and (.create | length) == 2 and (.delete | length) == 1' >/dev/null \
  || fail "expected exactly 2 creates (new page + new tuple) + 1 delete (old tuple) + 0 update for the swap (plan: $PLAN3)"
echo "$PLAN3" | jq -e '.create | any(.type == "pmpro_memberships_pages")' >/dev/null \
  || fail "expected the new tuple specifically among the creates (plan: $PLAN3)"
echo "$PLAN3" | jq -e '.delete | any(.type == "pmpro_memberships_pages")' >/dev/null \
  || fail "expected the old tuple specifically among the deletes (plan: $PLAN3)"
pass "plan confirms: the tuple swap is delete-old-uuid + create-new-uuid + zero updates — exactly the 'no update bucket' semantics, proven under a genuine identity change, not just a no-op"

say "(9) apply the swap with --with-deletes; confirm side 2's DB reflects it exactly"
APPLY2=$(wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV2" --with-deletes --format=json | tail -1)
echo "$APPLY2"
echo "$APPLY2" | jq -e '.canary == "clean"' >/dev/null || fail "expected a clean canary on the swap apply"
OLD_STILL_RESTRICTED=$(wp2 db query "SELECT COUNT(*) FROM wp_pmpro_memberships_pages WHERE membership_id=$LEVEL_ID_2 AND page_id=$PAGE_ID_2" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$OLD_STILL_RESTRICTED" = "0" ] || fail "delete_row() failed to remove the OLD tuple on side 2 (got $OLD_STILL_RESTRICTED rows)"
NEW_PAGE_ID_2=$(wp2 db query "SELECT ID FROM wp_posts WHERE post_title='Composite Ref Second Page' AND post_type='page'" --skip-column-names 2>/dev/null | tr -d '\r')
[ -n "$NEW_PAGE_ID_2" ] || fail "the new page did not land on side 2"
NEW_RESTRICTED_2=$(wp2 db query "SELECT COUNT(*) FROM wp_pmpro_memberships_pages WHERE membership_id=$LEVEL_ID_2 AND page_id=$NEW_PAGE_ID_2" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$NEW_RESTRICTED_2" = "1" ] || fail "the NEW tuple did not land on side 2 (got $NEW_RESTRICTED_2 rows)"
pass "delete_row()'s unpack-and-delete proven live: old tuple gone, new tuple present, both keyed on side 2's OWN local ids"

say "all proofs green"
pass "DUO-3235 fully verified live: composite_ref identity mode (task #125) and the id_column sidecar override (task #126), both against the SHIPPED manifest, real PMPro 3.8.3, a genuinely independent second environment"
