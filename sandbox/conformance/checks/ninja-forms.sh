#!/usr/bin/env bash
# Ninja Forms render-level acceptance (task #75): byte-identical canonical
# state alone doesn't prove the form actually resolves on the target — the
# nf3_forms row is a completely NEW row on conf2 (typed-snapshot create, not
# a copy), so this check confirms conf2's own rendered page carries the real
# 23-field form content (not a crash, not empty) using WHATEVER local id
# conf2 assigned it, and that Ninja Forms' own model API — the exact code
# path the front end and the submission handler both use — resolves it
# correctly server-side too.
#
# Invoked by conformance/run.sh after a clean apply, from the sandbox/
# directory; conf1/conf2's ports are set by run.sh via CONF1_PORT/CONF2_PORT
# (defaults below match the legacy docker-compose.yml conf1/conf2 ports for
# any standalone invocation).
set -euo pipefail
CONF2_PORT="${CONF2_PORT:-8807}"

FRONT=$(curl -fsSL "http://localhost:${CONF2_PORT}/conformance-careers/") || fail "conf2 conformance-careers page did not return 200"
[ "${#FRONT}" -ge 1000 ] \
  || fail "conf2 conformance-careers response was suspiciously short (${#FRONT} bytes)"

if grep -qiE 'fatal error|uncaught' <<<"$FRONT"; then
    fail "conf2's rendered careers page contains a PHP fatal error marker"
fi

grep -qE 'Job Application|nf-form-' <<<"$FRONT" \
  || fail "conf2's rendered careers page shows no Ninja Forms markup at all (empty/broken form block)"

grep -q 'First Name' <<<"$FRONT" \
  || fail "conf2's rendered form is missing its own field content (First Name) — form definition did not round-trip"

# The real proof: Ninja Forms' OWN model API (the exact path the front end
# and submission handler both use) resolves the form server-side on conf2,
# using CONF2's OWN local form id (never conf1's) — read back from conf2's
# own nf3_forms table directly rather than assumed.
CONF2_FORM_ID=$($COMPOSE run --rm -T cli2 wp db query \
  "SELECT id FROM wp_nf3_forms WHERE title='Job Application'" --skip-column-names)
[ -n "$CONF2_FORM_ID" ] || fail "conf2 has no 'Job Application' row in nf3_forms at all"

API_OUT=$($COMPOSE run --rm -T cli2 wp eval "
\$form = Ninja_Forms()->form($CONF2_FORM_ID)->get();
echo \$form->get_setting('title') . \"|\" . count(Ninja_Forms()->form($CONF2_FORM_ID)->get_fields()) . \"|\" . count(Ninja_Forms()->form($CONF2_FORM_ID)->get_actions());
")
[ "$API_OUT" = "Job Application|23|3" ] \
  || fail "Ninja_Forms()->form($CONF2_FORM_ID) on conf2 did not resolve correctly (got: $API_OUT, expected: Job Application|23|3)"
pass "conf2 renders its own 'Job Application' form (id=$CONF2_FORM_ID) with correct content, and Ninja Forms' own model API resolves it server-side (23 fields, 3 actions)"

# DUO-3209: mapped custom-table identity is environment-bound promotion
# metadata. Prove a database restore without it blocks before duplication,
# then prove the exact, hash-verified sidecar restores mappings, 3-way state,
# and applied-revision association. The row witness also has to reject a
# stale backup and an already-conflicting ledger without partial writes.
SIDE=/siterepo/.tmp-identity-ledger.json
wp_conf2 duo identity-export --repo=/siterepo --out="$SIDE" >/dev/null
EXPECTED_MAPS=$(jq '.maps | length' "$CONF_REPO2/.tmp-identity-ledger.json")
EXPECTED_STATES=$(jq '.states | length' "$CONF_REPO2/.tmp-identity-ledger.json")
EXPECTED_REV=$(jq -r '.applied_revision' "$CONF_REPO2/.tmp-identity-ledger.json")
[ "$EXPECTED_MAPS" -gt 0 ] && [ "$EXPECTED_STATES" -gt 0 ] \
  || fail "identity sidecar omitted mappings or sync state"

wp_conf2 db query 'TRUNCATE TABLE wp_duo_map; TRUNCATE TABLE wp_duo_state; DELETE FROM wp_duo_kv;' >/dev/null
PLAN_RC=0
PLAN_OUT=$(wp_conf2 duo plan --repo=/siterepo 2>&1) || PLAN_RC=$?
[ "$PLAN_RC" -ne 0 ] || fail "restored populated Ninja Forms DB planned successfully without identity metadata"
grep -q "mapped identity missing.*nf3_forms" <<<"$PLAN_OUT" \
  || fail "missing mapped identity failed for the wrong reason: $PLAN_OUT"

# plan repaired the post/term subset from embedded metadata before reaching
# nf3_forms; import must accept that verified subset, while still rejecting
# any row that disagrees with the sidecar.
wp_conf2 duo identity-import --repo=/siterepo --in="$SIDE" >/dev/null
MAPS_NOW=$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_duo_map' --skip-column-names | tr -d '[:space:]')
STATES_NOW=$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_duo_state' --skip-column-names | tr -d '[:space:]')
REV_NOW=$(wp_conf2 db query "SELECT v FROM wp_duo_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')
[ "$MAPS_NOW" = "$EXPECTED_MAPS" ] || fail "identity import restored $MAPS_NOW/$EXPECTED_MAPS mappings"
[ "$STATES_NOW" = "$EXPECTED_STATES" ] || fail "identity import restored $STATES_NOW/$EXPECTED_STATES sync states"
[ "$REV_NOW" = "$EXPECTED_REV" ] || fail "identity import lost applied revision ($REV_NOW != $EXPECTED_REV)"
RESTORED_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
[ "$(jq '[.create,.update,.drift,.conflict,.collision] | map(length) | add' <<<"$RESTORED_PLAN")" = 0 ] \
  || fail "verified identity restore did not return target to a clean plan: $RESTORED_PLAN"

wp_conf2 db query "UPDATE wp_nf3_forms SET title='Stale Restore' WHERE id=$CONF2_FORM_ID; TRUNCATE TABLE wp_duo_map; TRUNCATE TABLE wp_duo_state; DELETE FROM wp_duo_kv;" >/dev/null
STALE_RC=0
STALE_OUT=$(wp_conf2 duo identity-import --repo=/siterepo --in="$SIDE" 2>&1) || STALE_RC=$?
[ "$STALE_RC" -ne 0 ] && grep -q 'witness mismatch' <<<"$STALE_OUT" \
  || fail "stale database/sidecar pairing was not rejected: $STALE_OUT"
[ "$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_duo_map' --skip-column-names | tr -d '[:space:]')" = 0 ] \
  || fail "stale sidecar import partially mutated duo_map"
wp_conf2 db query "UPDATE wp_nf3_forms SET title='Job Application' WHERE id=$CONF2_FORM_ID" >/dev/null
wp_conf2 duo identity-import --repo=/siterepo --in="$SIDE" >/dev/null

wp_conf2 db query "UPDATE wp_duo_map SET uuid='00000000-0000-4000-8000-000000000999' WHERE id_kind='nf3_form' AND local_id=$CONF2_FORM_ID" >/dev/null
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 duo identity-import --repo=/siterepo --in="$SIDE" 2>&1) || CONFLICT_RC=$?
[ "$CONFLICT_RC" -ne 0 ] && grep -q 'current identity ledger conflicts' <<<"$CONFLICT_OUT" \
  || fail "conflicting live ledger was not rejected: $CONFLICT_OUT"
[ "$(wp_conf2 db query "SELECT uuid FROM wp_duo_map WHERE id_kind='nf3_form' AND local_id=$CONF2_FORM_ID" --skip-column-names | tr -d '[:space:]')" = '00000000-0000-4000-8000-000000000999' ] \
  || fail "conflicting sidecar import partially rebound the live mapping"
wp_conf2 db query 'TRUNCATE TABLE wp_duo_map; TRUNCATE TABLE wp_duo_state; DELETE FROM wp_duo_kv;' >/dev/null
wp_conf2 duo identity-import --repo=/siterepo --in="$SIDE" >/dev/null

jq '.applied_revision = "tampered"' "$CONF_REPO2/.tmp-identity-ledger.json" > "$CONF_REPO2/.tmp-identity-tampered.json"
TAMPER_RC=0
TAMPER_OUT=$(wp_conf2 duo identity-import --repo=/siterepo --in=/siterepo/.tmp-identity-tampered.json 2>&1) || TAMPER_RC=$?
[ "$TAMPER_RC" -ne 0 ] && grep -q 'integrity hash does not verify' <<<"$TAMPER_OUT" \
  || fail "tampered identity sidecar was not rejected: $TAMPER_OUT"

# Source-side ledger loss is equally dangerous once canonical mapped UUIDs
# exist: capture must not mint replacements for them.
wp_conf1 duo identity-export --repo=/siterepo --out="$SIDE" >/dev/null
wp_conf1 db query "DELETE FROM wp_duo_map WHERE id_kind IN ('nf3_form','nf3_field','nf3_action')" >/dev/null
CAPTURE_RC=0
CAPTURE_OUT=$(wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-lost-ledger-state 2>&1) || CAPTURE_RC=$?
[ "$CAPTURE_RC" -ne 0 ] && grep -q 'mapped identity history is missing' <<<"$CAPTURE_OUT" \
  || fail "source capture minted replacements after mapped identity loss: $CAPTURE_OUT"
wp_conf1 duo identity-import --repo=/siterepo --in="$SIDE" >/dev/null
wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-restored-state >/dev/null

pass "mapped identity loss blocks; verified sidecar restores map/state/revision; stale, conflicting, and tampered restores fail atomically"

# DUO-3210: deleting a mapped custom-table parent is explicit and guarded.
# The 23 fields + 3 actions are authored children deleted in the same
# revision, so they are excluded from the form's reverse-ref count; a target-
# only nf_sub row is runtime data and must block until removed.
FORM_UUID=$(jq -r '.uuid' "$CONF_REPO1"/state/tables/nf3_forms/*.json)
CONF1_FORM_ID=$(wp_conf1 db query "SELECT id FROM wp_nf3_forms WHERE title='Job Application'" --skip-column-names | tr -d '[:space:]')
SUB_ID=$(wp_conf2 post create --post_type=nf_sub --post_title='Conformance Runtime Submission' --post_status=publish --porcelain)
wp_conf2 post meta add "$SUB_ID" _form_id "$CONF2_FORM_ID" >/dev/null
PAGE_ID=$(wp_conf1 post list --post_type=page --name=conformance-careers --field=ID | tr -d '[:space:]')
wp_conf1 post update "$PAGE_ID" --post_content='<!-- wp:paragraph --><p>Applications are closed.</p><!-- /wp:paragraph -->' >/dev/null
wp_conf1 db query "
  DELETE FROM wp_nf3_field_meta WHERE parent_id IN (SELECT id FROM wp_nf3_fields WHERE parent_id=$CONF1_FORM_ID);
  DELETE FROM wp_nf3_action_meta WHERE parent_id IN (SELECT id FROM wp_nf3_actions WHERE parent_id=$CONF1_FORM_ID);
  DELETE FROM wp_nf3_fields WHERE parent_id=$CONF1_FORM_ID;
  DELETE FROM wp_nf3_actions WHERE parent_id=$CONF1_FORM_ID;
  DELETE FROM wp_nf3_form_meta WHERE parent_id=$CONF1_FORM_ID;
  DELETE FROM wp_nf3_forms WHERE id=$CONF1_FORM_ID;
" >/dev/null
CAPTURE_DELETE=$(wp_conf1 duo capture --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
[ "$(jq -r '.counts.deletion' <<<"$CAPTURE_DELETE")" = 27 ] \
  || fail "form removal did not capture 27 explicit tombstones: $CAPTURE_DELETE"
[ -f "$CONF_REPO1/state/deletions/$FORM_UUID.json" ] || fail "form tombstone was not published"
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: delete Ninja Forms graph'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main

DELETE_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e --arg uuid "$FORM_UUID" '
  (.delete | length) == 27 and
  (.delete_conflict | length) == 0 and
  (.delete | any(.uuid == $uuid and (.blocked | contains("form submissions"))))
' <<<"$DELETE_PLAN" >/dev/null || fail "Ninja Forms deletion plan did not isolate authored children and block the runtime submission: $DELETE_PLAN"

DELETE_RC=0
DELETE_OUT=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin 2>&1) || DELETE_RC=$?
[ "$DELETE_RC" -ne 0 ] && grep -qi 'referential guard' <<<"$DELETE_OUT" \
  || fail "runtime form submission did not block apply: $DELETE_OUT"
wp_conf2 post delete "$SUB_ID" --force >/dev/null
DELETE_OUT=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
jq -e '.canary == "clean" and .applied >= 28' <<<"$DELETE_OUT" >/dev/null \
  || fail "guard-cleared form graph deletion failed: $DELETE_OUT"
for table in nf3_forms nf3_fields nf3_actions nf3_form_meta nf3_field_meta nf3_action_meta; do
  [ "$(wp_conf2 db query "SELECT COUNT(*) FROM wp_$table" --skip-column-names | tr -d '[:space:]')" = 0 ] \
    || fail "exact delete verification left rows in wp_$table"
done
RETRY_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e '(.delete | length) == 0 and (.delete_conflict | length) == 0 and (.deleted | length) == 27' \
  <<<"$RETRY_PLAN" >/dev/null || fail "deleted receipts were not idempotent on retry: $RETRY_PLAN"
pass "explicit Ninja Forms tombstones delete children-before-parent, preserve runtime submissions behind a guard, verify exact absence, and retry as deleted"
