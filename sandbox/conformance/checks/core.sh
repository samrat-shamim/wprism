#!/usr/bin/env bash
# DUO-3209: copied/invalid embedded identity blocks before state publication.
set -euo pipefail

# DUO-3264: dynamic_options.theme_mods -- proof beyond the generic
# byte-diff already run above in run.sh (which only proves conf1's
# captured tokens equal conf2's captured tokens; it can't see whether the
# target's LIVE blob merged sub-keys into conf2's own pre-existing content
# correctly, or whether a previously-active theme's own row genuinely
# never entered state/ at all). Deliberately placed FIRST in this file,
# before any of the DUO-3209/3210 tests below delete the shared
# conformance-logo attachment (the "branch edit" block) -- that later
# deletion is what exercises Apply::apply_option_sub_keys()'s sub-key
# tombstone fix (also DUO-3264, found live via this exact interaction: a
# stale custom_logo/header_image/header_image_data left behind on conf2
# after conf1's own recapture correctly stopped reporting them), so this
# block intentionally runs against the fully-populated, pre-deletion state
# rather than duplicating that proof.
CONF2_MODS=$(wp_conf2 option get theme_mods_twentytwentyfive --format=json)
echo "$CONF2_MODS" | jq -e '.background_color == "3c8c3c"' >/dev/null \
  || fail "theme_mods_twentytwentyfive.background_color did not apply correctly on conf2: $CONF2_MODS"
echo "$CONF2_MODS" | jq -e '.custom_logo | type == "number"' >/dev/null \
  || fail "theme_mods_twentytwentyfive.custom_logo did not re-resolve to a local attachment id on conf2: $CONF2_MODS"
CONF2_LOGO_ID=$(echo "$CONF2_MODS" | jq -r '.custom_logo')
[ "$(wp_conf2 post get "$CONF2_LOGO_ID" --field=post_type 2>/dev/null)" = "attachment" ] \
  || fail "theme_mods_twentytwentyfive.custom_logo ($CONF2_LOGO_ID) does not point at a real attachment on conf2"
echo "$CONF2_MODS" | jq -e --arg port "$CONF2_PORT" '.header_image | contains("localhost:" + $port)' >/dev/null \
  || fail "theme_mods_twentytwentyfive.header_image was not rewritten to conf2's own domain: $CONF2_MODS"
echo "$CONF2_MODS" | jq -e '.header_image_data.attachment_id == .custom_logo' >/dev/null \
  || fail "theme_mods_twentytwentyfive.header_image_data.attachment_id did not re-resolve consistently with custom_logo: $CONF2_MODS"
CONF2_CSS_ID=$(echo "$CONF2_MODS" | jq -r '.custom_css_post_id')
[ "$(wp_conf2 post get "$CONF2_CSS_ID" --field=post_type 2>/dev/null)" = "custom_css" ] \
  || fail "theme_mods_twentytwentyfive.custom_css_post_id ($CONF2_CSS_ID) does not point at a real custom_css post on conf2"
[ "$(wp_conf2 post get "$CONF2_CSS_ID" --field=post_content 2>/dev/null)" = 'body { background: #3c8c3c; }' ] \
  || fail "custom_css post content did not round-trip to conf2"
pass "theme_mods_twentytwentyfive's declared authored sub-keys (background_color, custom_logo, header_image, header_image_data, custom_css_post_id) all apply correctly on conf2, ref-typed fields re-resolved to conf2's own local ids"

echo "$CONF2_MODS" | jq -e '.sidebars_widgets.data."sidebar-1" | length > 0' >/dev/null \
  || fail "conf2's own pre-existing sidebars_widgets did not survive the theme_mods sub-key merge untouched: $CONF2_MODS"
echo "$CONF2_MODS" | jq -e '.wp_classic_sidebars."sidebar-1".name == "Footer"' >/dev/null \
  || fail "conf2's own pre-existing wp_classic_sidebars did not survive the theme_mods sub-key merge untouched: $CONF2_MODS"
pass "conf2's own runtime-excluded sub-keys (sidebars_widgets, wp_classic_sidebars) survived the merge into the live blob untouched -- sub_keys apply is a merge, never a whole-value replace"

jq -e '.records | has("theme_mods_twentytwentyone") | not' "$CONF_REPO1/state/options/core.json" >/dev/null \
  || fail "theme_mods_twentytwentyone (a previously-active theme's own row) leaked into captured state -- residue exclusion failed"
pass "theme_mods_twentytwentyone (residue: a previously-active, now-inactive theme's own row) never entered captured state, exactly as declared"

PENDING2=$(wp_conf2 duo pending --repo=/siterepo --format=json)
[ "$PENDING2" = "[]" ] \
  || fail "wp duo pending on conf2 is no longer empty -- the ~18 core widget_<type> names + sidebars_widgets must stay silent-by-declaration (explicitly classified runtime), not become newly unclassified: $PENDING2"
pass "wp duo pending remains empty post-apply for every CORE-registered widget option -- silent-by-declaration (explicitly classified runtime, DUO-3264 corrected-baseline ruling), not silent-by-omission (the original ruling's premise, empirically found false and superseded)"

# DUO-3264 corrected-baseline ruling (superseding the split ruling's own
# original premise that these were already loud): declaring the
# ^sidebars_widgets$/^widget_ namespaces and classifying only the ~18
# CORE-registered names runtime, by exact name (never a ^widget_ PATTERN
# classification, which would blindly swallow a third party's own widget
# type into the same silent bucket), buys a real guarantee for free --
# live-verified here, not assumed: a widget type this manifest never named
# still lands in-namespace with no classification, which turns into
# unclassified -> loud pending -> capture-blocking, the correct posture
# for authored content this pass never evaluated.
say "(DUO-3264) live probe: an unknown, non-core widget type gates loudly, then classifies clean once declared"
wp_conf1 option update widget_regress_fake_type '{"2":{"title":"Regress Fake"}}' --format=json >/dev/null

FAKE_PENDING=$(wp_conf1 duo pending --repo=/siterepo --format=json)
echo "$FAKE_PENDING" | jq -e 'any(.section == "options" and .key == "widget_regress_fake_type")' >/dev/null \
  || fail "unknown widget_regress_fake_type did not surface in wp duo pending: $FAKE_PENDING"

FAKE_RC=0
FAKE_CAPTURE_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || FAKE_RC=$?
[ "$FAKE_RC" -ne 0 ] && echo "$FAKE_CAPTURE_OUT" | grep -q "options:widget_regress_fake_type" \
  || fail "capture did not loudly refuse the unknown widget type by name: $FAKE_CAPTURE_OUT"
# team-lead's own requirement: this refusal must read as widgets-aware, not
# a generic "go classify it" -- both real remedies named inline.
echo "$FAKE_CAPTURE_OUT" | grep -q "widget content: either declare it a deliberate exclusion" \
  || fail "refusal did not name the widget-specific remedy (deliberate exclusion): $FAKE_CAPTURE_OUT"
echo "$FAKE_CAPTURE_OUT" | grep -q "add \"regress_fake_type\" to a pinned manifest's widgets{} grammar" \
  || fail "refusal did not name the second widget-specific remedy (extend widgets{} grammar), or misidentified the type: $FAKE_CAPTURE_OUT"

cp "$CONF_REPO1/site.duo.json" "$CONF_REPO1/.tmp-site-backup.json"
jq '.policy.options.widget_regress_fake_type = {"class": "runtime"}' "$CONF_REPO1/site.duo.json" > "$CONF_REPO1/.tmp-site-new.json"
mv "$CONF_REPO1/.tmp-site-new.json" "$CONF_REPO1/site.duo.json"
wp_conf1 duo capture --repo=/siterepo >/dev/null || fail "capture still refused widget_regress_fake_type after a site-policy override classified it runtime"
mv "$CONF_REPO1/.tmp-site-backup.json" "$CONF_REPO1/site.duo.json"
wp_conf1 option delete widget_regress_fake_type >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
pass "unknown widget type: pending surfaces it by name, capture refuses naming BOTH remedies (deliberate exclusion or extend widgets{}), clean once declared, and clean again once removed -- live-verified, not assumed"

# DUO-3278: the core fixture's three declared widget kinds round-trip through
# the sidebar wire format with ledger-only identity and target-local counters.
SIDEBAR_FILE="$CONF_REPO1/state/sidebars/sidebar-1.json"
[ -f "$SIDEBAR_FILE" ] || fail "canonical sidebar-1 file is missing"
jq -e '
  ([.widgets[].type] == ["block","text","nav_menu"])
  and (.widgets | length == 3)
  and ([.widgets[].settings | has("_duo_uuid")] | any | not)
  and (.widgets[0].settings.content | contains("{{post:"))
  and (.widgets[0].settings.content | contains("{{uploads}}"))
  and (.widgets[1].settings.text | contains("{{home}}"))
  and (.widgets[2].settings.nav_menu | startswith("{{term:"))
' "$SIDEBAR_FILE" >/dev/null || fail "canonical block/text/nav-menu widget wire format is wrong"

for TYPE in block text nav_menu; do
  UUID=$(jq -r --arg type "$TYPE" '.widgets[] | select(.type == $type) | .uuid' "$SIDEBAR_FILE")
  SOURCE_LOCAL=$(wp_conf1 eval "echo \\Duo\\Ledger::id_for('$UUID', 'widget_$TYPE');")
  TARGET_LOCAL=$(wp_conf2 eval "echo \\Duo\\Ledger::id_for('$UUID', 'widget_$TYPE');")
  [ -n "$SOURCE_LOCAL" ] && [ -n "$TARGET_LOCAL" ] \
    || fail "widget_$TYPE identity is absent from one environment's ledger"
  [ "$SOURCE_LOCAL" != "$TARGET_LOCAL" ] \
    || fail "widget_$TYPE copied source counter $SOURCE_LOCAL instead of allocating target-locally"
done
TARGET_KEYS=$(wp_conf2 eval '$sidebars=get_option("sidebars_widgets"); echo implode(",", $sidebars["sidebar-1"]);')
[[ "$TARGET_KEYS" != *-21* ]] || fail "colliding target widget defaults survived apply: $TARGET_KEYS"
wp_conf2 eval '
foreach (["block","text","nav_menu"] as $type) {
  $stored=get_option("widget_".$type);
  foreach ($stored as $settings) {
    if (is_array($settings) && array_key_exists("_duo_uuid", $settings)) {
      throw new RuntimeException("settings UUID leaked into widget_".$type);
    }
  }
}
' >/dev/null
pass "block/text/nav-menu widgets use portable refs, ledger-only identity, free target counters, and replace target defaults"

A=$(wp_conf1 post list --post_type=page --name=branch-a --field=ID | tr -d '[:space:]')
B=$(wp_conf1 post list --post_type=page --name=branch-b --field=ID | tr -d '[:space:]')
UA=$(wp_conf1 post meta get "$A" _duo_uuid | tr -d '[:space:]')
UB=$(wp_conf1 post meta get "$B" _duo_uuid | tr -d '[:space:]')

wp_conf1 post meta update "$B" _duo_uuid "$UA" >/dev/null
RC=0
OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || RC=$?
[ "$RC" -ne 0 ] && grep -q "duplicate _duo_uuid $UA.*post:$A, post:$B" <<<"$OUT" \
  || fail "copied page identity did not fail with both owners: $OUT"
[ -z "$(git -C "$CONF_REPO1" status --porcelain -- state)" ] \
  || fail "failed duplicate-identity capture changed the published state tree"

wp_conf1 post meta update "$B" _duo_uuid 'NOT-A-UUID' >/dev/null
RC=0
OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || RC=$?
[ "$RC" -ne 0 ] && grep -q "invalid _duo_uuid 'NOT-A-UUID'.*post:$B" <<<"$OUT" \
  || fail "invalid embedded identity was not rejected: $OUT"
[ -z "$(git -C "$CONF_REPO1" status --porcelain -- state)" ] \
  || fail "failed invalid-identity capture changed the published state tree"

wp_conf1 post meta update "$B" _duo_uuid "$UB" >/dev/null
wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-identity-recovered >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO1/.tmp-identity-recovered" \
  || fail "restoring the page's original UUID did not restore deterministic capture"

pass "copied and invalid _duo_uuid metadata block before atomic state publication; original identities recover deterministically"

# DUO-3210: absence alone is not authority; capture replaces the prior Home
# page with a versioned tombstone. A target-only comment blocks deletion,
# the explicit force path stays loud, comments are preserved, and the
# tombstone receipt makes retry a no-op.
HOME_FILE=$(find "$CONF_REPO1/state/posts/page" -name '*--home.md' -print -quit)
HOME_UUID=$(basename "$HOME_FILE" | sed -E 's/--home\.md$//')
HOME1=$(wp_conf1 post list --post_type=page --name=home --field=ID | tr -d '[:space:]')
HOME2=$(wp_conf2 post list --post_type=page --name=home --field=ID | tr -d '[:space:]')
COMMENT2=$(wp_conf2 comment create --comment_post_ID="$HOME2" --comment_content='runtime deletion guard' --comment_author='Runtime Visitor' --porcelain)
wp_conf1 post delete "$HOME1" --force >/dev/null
DELETE_CAPTURE=$(wp_conf1 duo capture --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
[ "$(jq -r '.counts.deletion' <<<"$DELETE_CAPTURE")" -ge 1 ] \
  || fail "page deletion did not emit a tombstone: $DELETE_CAPTURE"
[ -f "$CONF_REPO1/state/deletions/$HOME_UUID.json" ] || fail "Home tombstone was not published"
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: explicit page deletion'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main

DELETE_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e --arg uuid "$HOME_UUID" '.delete | any(.uuid == $uuid and (.blocked | contains("comments reference")))' \
  <<<"$DELETE_PLAN" >/dev/null || fail "target-only comment did not block the explicit page deletion: $DELETE_PLAN"
DELETE_RC=0
DELETE_OUT=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin 2>&1) || DELETE_RC=$?
[ "$DELETE_RC" -ne 0 ] && grep -qi 'referential guard' <<<"$DELETE_OUT" \
  || fail "guarded page delete was not refused: $DELETE_OUT"
DELETE_OUT=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --force-delete-referenced --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
jq -e '.canary == "clean" and (.warnings | any(contains("FORCED delete")))' <<<"$DELETE_OUT" >/dev/null \
  || fail "forced page deletion was not loud and clean: $DELETE_OUT"
[ -z "$(wp_conf2 post list --post_type=page --name=home --field=ID)" ] || fail "Home page survived exact deletion"
[ "$(wp_conf2 comment get "$COMMENT2" --field=comment_ID)" = "$COMMENT2" ] || fail "runtime comment was cascaded or lost"
RETRY_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e --arg uuid "$HOME_UUID" '(.delete | length) == 0 and (.delete_conflict | length) == 0 and (.deleted | any(.uuid == $uuid))' \
  <<<"$RETRY_PLAN" >/dev/null || fail "page tombstone retry did not settle as deleted: $RETRY_PLAN"
pass "explicit page tombstone guards and preserves comments, verifies exact deletion, and retries idempotently"

# Local edit: the tombstone expected base matches duo_state, but the live
# hash does not. Editing creates a derived revision child; the adapter
# explicitly cascades and verifies revisions while --force-theirs reports
# the overridden delete conflict.
HELLO_FILE=$(find "$CONF_REPO1/state/posts/post" -name '*--hello-conformance.md' -print -quit)
HELLO_UUID=$(basename "$HELLO_FILE" | sed -E 's/--hello-conformance\.md$//')
HELLO1=$(wp_conf1 post list --post_type=post --name=hello-conformance --field=ID | tr -d '[:space:]')
HELLO2=$(wp_conf2 post list --post_type=post --name=hello-conformance --field=ID | tr -d '[:space:]')
wp_conf2 post update "$HELLO2" --post_content='target-only deletion conflict' >/dev/null
wp_conf1 post delete "$HELLO1" --force >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: delete against target local edit'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
LOCAL_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e --arg uuid "$HELLO_UUID" '.delete_conflict | any(.uuid == $uuid and (.reason | contains("changed locally")) and (has("blocked") | not))' \
  <<<"$LOCAL_PLAN" >/dev/null || fail "local edit did not become a deletion conflict: $LOCAL_PLAN"
LOCAL_RC=0
LOCAL_OUT=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin 2>&1) || LOCAL_RC=$?
[ "$LOCAL_RC" -ne 0 ] && grep -qi 'deletion conflicts' <<<"$LOCAL_OUT" \
  || fail "unforced delete conflict was not refused: $LOCAL_OUT"
LOCAL_OUT=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
jq -e '.canary == "clean" and (.warnings | any(contains("FORCED deletion conflict")))' <<<"$LOCAL_OUT" >/dev/null \
  || fail "forced local-edit deletion did not report its override: $LOCAL_OUT"
[ -z "$(wp_conf2 post list --post_type=post --name=hello-conformance --field=ID)" ] || fail "locally edited post survived forced deletion"
pass "delete-vs-local-edit conflicts; force-theirs is loud and revision children cascade exactly"

# Branch edit: capture the changed entity into git without applying it to
# conf2, then delete it on conf1. The tombstone therefore expects the new
# branch hash while conf2's base is still the old hash.
ATT_FILE=$(find "$CONF_REPO1/state/posts/attachment" -name '*--conformance-logo.md' -print -quit)
ATT_UUID=$(basename "$ATT_FILE" | sed -E 's/--conformance-logo\.md$//')
ATT1=$(wp_conf1 post list --post_type=attachment --name=conformance-logo --field=ID | tr -d '[:space:]')
wp_conf1 post update "$ATT1" --post_title='Conformance Logo Branch Edit' >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: branch edits attachment'
git -C "$CONF_REPO1" push -q origin main
wp_conf1 post delete "$ATT1" --force >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: delete branch-edited attachment'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
BRANCH_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e --arg uuid "$ATT_UUID" '.delete_conflict | any(.uuid == $uuid and (.reason | contains("expected hash")))' \
  <<<"$BRANCH_PLAN" >/dev/null || fail "delete-vs-branch-edit did not conflict on its expected base: $BRANCH_PLAN"
wp_conf2 duo apply --repo=/siterepo --with-deletes --force-theirs --default-author=admin --format=json >/dev/null
[ -z "$(wp_conf2 post list --post_type=attachment --name=conformance-logo --field=ID)" ] || fail "branch-conflicted attachment survived forced deletion"
pass "delete-vs-branch-edit conflicts on the tombstone expected base"

# Missing guard infrastructure is a refusal, never a skipped warning.
CHILD_FILE=$(find "$CONF_REPO1/state/posts/page" -name '*--shared-child.md' -print | sort | head -1)
CHILD_UUID=$(basename "$CHILD_FILE" | sed -E 's/--shared-child\.md$//')
CHILD1=$(wp_conf1 eval "echo \\Duo\\Ledger::id_for('$CHILD_UUID', \\Duo\\Ledger::KIND_POST);")
wp_conf1 post delete "$CHILD1" --force >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: missing deletion guard table'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
wp_conf2 db query 'RENAME TABLE wp_comments TO wp_comments_duo_hold' >/dev/null
MISSING_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e --arg uuid "$CHILD_UUID" '.delete | any(.uuid == $uuid and (.blocked | contains("required guard table")))' \
  <<<"$MISSING_PLAN" >/dev/null || fail "missing guard table did not fail closed: $MISSING_PLAN"
wp_conf2 db query 'RENAME TABLE wp_comments_duo_hold TO wp_comments' >/dev/null
wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin --format=json >/dev/null
pass "missing reverse-reference guard infrastructure fails closed"

# Transaction rollback: two safe deletions, but an external FK refuses the
# lexically second UUID. The first row is deleted before the failure and
# must reappear after rollback; removing the test FK lets both complete.
ROLL_A1=$(wp_conf1 post create --post_type=page --post_title='Rollback Alpha' --post_name=rollback-alpha --post_status=publish --porcelain)
ROLL_B1=$(wp_conf1 post create --post_type=page --post_title='Rollback Beta' --post_name=rollback-beta --post_status=publish --porcelain)
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: seed transactional deletion pair'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin --format=json >/dev/null
ROLL_A_FILE=$(find "$CONF_REPO1/state/posts/page" -name '*--rollback-alpha.md' -print -quit)
ROLL_B_FILE=$(find "$CONF_REPO1/state/posts/page" -name '*--rollback-beta.md' -print -quit)
ROLL_A_UUID=$(basename "$ROLL_A_FILE" | sed -E 's/--rollback-alpha\.md$//')
ROLL_B_UUID=$(basename "$ROLL_B_FILE" | sed -E 's/--rollback-beta\.md$//')
ROLL_A2=$(wp_conf2 post list --post_type=page --name=rollback-alpha --field=ID | tr -d '[:space:]')
ROLL_B2=$(wp_conf2 post list --post_type=page --name=rollback-beta --field=ID | tr -d '[:space:]')
wp_conf1 post delete "$ROLL_A1" "$ROLL_B1" --force >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: transactional deletion pair'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
if [[ "$ROLL_A_UUID" < "$ROLL_B_UUID" ]]; then BLOCK_ID=$ROLL_B2; else BLOCK_ID=$ROLL_A2; fi
wp_conf2 db query 'DROP TABLE IF EXISTS wp_duo_delete_block' >/dev/null
wp_conf2 db query 'CREATE TABLE wp_duo_delete_block (post_id bigint(20) unsigned NOT NULL PRIMARY KEY, CONSTRAINT duo_delete_block_fk FOREIGN KEY (post_id) REFERENCES wp_posts(ID)) ENGINE=InnoDB' >/dev/null
wp_conf2 db query "INSERT INTO wp_duo_delete_block (post_id) VALUES ($BLOCK_ID)" >/dev/null
ROLL_RC=0
ROLL_OUT=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin 2>&1) || ROLL_RC=$?
[ "$ROLL_RC" -ne 0 ] || fail "injected second-row deletion failure unexpectedly applied"
[ "$(wp_conf2 post list --post_type=page --name=rollback-alpha --field=ID | tr -d '[:space:]')" = "$ROLL_A2" ] \
  || fail "partial deletion failure did not roll back the first page"
[ "$(wp_conf2 post list --post_type=page --name=rollback-beta --field=ID | tr -d '[:space:]')" = "$ROLL_B2" ] \
  || fail "partial deletion failure lost the blocked page"
wp_conf2 db query 'DROP TABLE wp_duo_delete_block' >/dev/null
wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin --format=json >/dev/null
[ -z "$(wp_conf2 post list --post_type=page --name=rollback-alpha --field=ID)" ] \
  && [ -z "$(wp_conf2 post list --post_type=page --name=rollback-beta --field=ID)" ] \
  || fail "transactional deletion pair did not complete after removing injected failure"
pass "partial delete failure rolls the transaction back; retry completes exactly"

# Clear environment-bound history to simulate a fresh target. Tombstones
# remain `deleted`, never reinterpret absence through ledger history.
TOMBSTONES=$(find "$CONF_REPO1/state/deletions" -type f -name '*.json' | wc -l | tr -d '[:space:]')
wp_conf2 db query 'TRUNCATE TABLE wp_duo_map; TRUNCATE TABLE wp_duo_state' >/dev/null
FRESH_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e --argjson count "$TOMBSTONES" '(.deleted | length) == $count and (.delete | length) == 0 and (.delete_conflict | length) == 0' \
  <<<"$FRESH_PLAN" >/dev/null || fail "fresh target interpreted repository deletion intent differently: $FRESH_PLAN"
pass "fresh and previously mapped targets make the same repository-level deletion decision"
