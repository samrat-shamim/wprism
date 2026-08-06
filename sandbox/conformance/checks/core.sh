#!/usr/bin/env bash
# DUO-3209: copied/invalid embedded identity blocks before state publication.
set -euo pipefail

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
