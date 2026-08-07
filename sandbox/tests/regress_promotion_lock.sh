#!/usr/bin/env bash
# Live regression — DUO-3217: target-authoritative promotion lease plus the
# final optimistic precondition/guard rechecks which make that lease useful.
set -euo pipefail
cd "$(dirname "$0")/../.."

PAIR=codexmac3217
PORT1=8900
PORT2=8901
export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2"
COMPOSE=(docker compose -p "duo-$PAIR" -f sandbox/pair.yml)
R1="sandbox/siterepo/${PAIR}1"
R2="sandbox/siterepo/${PAIR}2"

wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
cleanup() { bash sandbox/bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true; }
sync_repo() {
  rm -rf "$R2/state" "$R2/media"
  cp -R "$R1/state" "$R2/state"
  [ ! -d "$R1/media" ] || cp -R "$R1/media" "$R2/media"
  cp "$R1/site.duo.json" "$R2/site.duo.json"
}
trap cleanup EXIT

bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless
rm -rf "$R1/.git" "$R1/state" "$R1/site.duo.json" "$R2"
cat > "$R1/site.duo.json" <<'JSON'
{
  "manifests": ["core"],
  "policy": {
    "options": {}, "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 1
}
JSON
cp sandbox/site-repo.gitignore.template "$R1/.gitignore"
git -C "$R1" init -q
git -C "$R1" config user.name duo-lock-test
git -C "$R1" config user.email duo@example.test

wp1 option update blogname 'Lock Base' >/dev/null
PAGE_ID="$(wp1 post create --post_type=page --post_status=publish --post_title='Guarded Page' --porcelain)"
wp1 duo capture --repo=/siterepo --format=json >/dev/null
PAGE_UUID="$(wp1 post meta get "$PAGE_ID" _duo_uuid)"
git -C "$R1" add -A
git -C "$R1" commit -qm 'baseline promotion state'
cp -R "$R1" "$R2"
chmod -R a+rwX "$R2"
wp2 duo apply --repo=/siterepo --adopt-by-slug=posts,terms,menus --default-author=admin >/dev/null
pass 'baseline target materialized'

wp2 duo compile --repo=/siterepo --out=/siterepo/.tmp-promotion-artifact.json --format=json >/dev/null
wp2 duo deploy --repo=/siterepo --compiled=/siterepo/.tmp-promotion-artifact.json \
  --promotion-owner=handoff-owner --promotion-hold >/dev/null
[ "$(wp2 eval 'echo (string) ((\Duo\PromotionLock::current()["phase"] ?? ""));')" = deployed ] \
  || fail 'deploy did not retain its lease for the apply process'
wp2 duo apply --repo=/siterepo --compiled=/siterepo/.tmp-promotion-artifact.json \
  --promotion-owner=handoff-owner --default-author=admin >/dev/null
[ "$(wp2 eval 'echo \Duo\PromotionLock::current() === null ? "none" : "held";')" = none ] \
  || fail 'apply did not release the shared deploy/apply lease'
rm -f "$R2/.tmp-promotion-artifact.json"
pass 'deploy hands one target lease to apply across wp-cli processes'

HASH_A="$(printf 'a%.0s' {1..64})"
wp2 eval "\\Duo\\PromotionLock::acquire('actor-alpha', '$HASH_A', 'test-hold');" >/dev/null
if wp2 eval "\\Duo\\PromotionLock::acquire('actor-bravo', '$HASH_A', 'test-race');" >/tmp/duo3217-lock-contender.log 2>&1; then
  fail 'second owner acquired a live target lease'
fi
grep -q 'promotion lock held' /tmp/duo3217-lock-contender.log \
  || fail 'contender refusal did not name the held promotion lock'
wp2 eval "\\Duo\\PromotionLock::release('actor-alpha', '$HASH_A');" >/dev/null

wp2 eval "
\\Duo\\Ledger::kv_set('promotion_lock', wp_json_encode([
 'owner'=>'dead-owner', 'artifact_hash'=>'$HASH_A', 'phase'=>'crashed',
 'acquired_at'=>time()-20, 'expires_at'=>time()-10
]));
\$lock=\\Duo\\PromotionLock::acquire('actor-bravo', '$HASH_A', 'recovered');
if (empty(\$lock['recovered'])) { throw new RuntimeException('stale owner was not reported recovered'); }
\\Duo\\PromotionLock::release('actor-bravo', '$HASH_A');
" >/dev/null
pass 'concurrent owner is refused and an expired owner is recovered atomically'

wp2 eval "\Duo\PromotionLock::acquire('actor-renew', '$HASH_A', 'renew-start', 4);" >/dev/null
wp2 eval "\Duo\PromotionLock::heartbeat('actor-renew', '$HASH_A', 'renewed', 10);" >/dev/null
sleep 5
if wp2 eval "\Duo\PromotionLock::acquire('actor-other', '$HASH_A', 'too-early');" \
  >/tmp/duo3217-renew-contender.log 2>&1; then
  fail 'renewed lease expired before its extended deadline'
fi
wp2 eval "\Duo\PromotionLock::release('actor-renew', '$HASH_A');" >/dev/null

wp2 eval "\Duo\PromotionLock::acquire('actor-loss', '$HASH_A', 'loss-start', 1);" >/dev/null
sleep 2
if wp2 eval "\Duo\PromotionLock::heartbeat('actor-loss', '$HASH_A', 'must-fail', 1);" \
  >/tmp/duo3217-lock-loss.log 2>&1; then
  fail 'expired owner renewed a lock it had already lost'
fi
grep -q 'lock lost or expired' /tmp/duo3217-lock-loss.log \
  || fail 'lock-loss refusal did not state the expired/lost condition'
wp2 eval "
\$lock=\Duo\PromotionLock::acquire('actor-after-loss', '$HASH_A', 'loss-recovered');
if (empty(\$lock['recovered'])) { throw new RuntimeException('expired loss was not recovered'); }
\Duo\PromotionLock::release('actor-after-loss', '$HASH_A');
" >/dev/null
pass 'lease renewal extends ownership; expiry loses ownership and permits bounded recovery'

wp1 option update blogname 'Lock Desired' >/dev/null
wp1 duo capture --repo=/siterepo --format=json >/dev/null
git -C "$R1" add -A
git -C "$R1" commit -qm 'authored option update'
sync_repo

"${COMPOSE[@]}" run --rm -T \
  -e DUO_TEST_MODE=1 -e DUO_TEST_PROMOTION_PAUSE_MS=4000 \
  cli2 wp duo apply --repo=/siterepo --default-author=admin \
  >/tmp/duo3217-stale-plan.log 2>&1 &
STALE_PID=$!
for _ in {1..80}; do
  PHASE="$(wp2 eval 'echo (string) ((\Duo\PromotionLock::current()["phase"] ?? ""));' 2>/dev/null || true)"
  [ "$PHASE" = precondition-recheck ] && break
  sleep 0.1
done
[ "${PHASE:-}" = precondition-recheck ] || fail 'apply never reached deterministic precondition pause'
wp2 option update blogname 'Live Edit Wins' >/dev/null
if wait "$STALE_PID"; then
  fail 'apply overwrote an authored edit made after planning'
fi
grep -q 'promotion preconditions changed after planning' /tmp/duo3217-stale-plan.log \
  || fail 'stale-plan refusal was not explicit'
[ "$(wp2 option get blogname)" = 'Live Edit Wins' ] || fail 'stale apply changed the live authored edit'
[ "$(wp2 eval 'echo \Duo\PromotionLock::current() === null ? "none" : "held";')" = none ] \
  || fail 'failed apply leaked its target lease'
pass 'authored edit after planning is refused before target mutation'

wp2 option update blogname 'Lock Desired' >/dev/null
wp2 duo apply --repo=/siterepo --default-author=admin >/dev/null
NEW_POST_ID="$(wp1 post create --post_type=post --post_status=publish --post_title='Concurrent Create' --porcelain)"
wp1 duo capture --repo=/siterepo --format=json >/dev/null
NEW_UUID="$(wp1 post meta get "$NEW_POST_ID" _duo_uuid)"
git -C "$R1" add -A
git -C "$R1" commit -qm 'concurrent create fixture'
sync_repo

"${COMPOSE[@]}" run --rm -T \
  -e DUO_TEST_MODE=1 -e DUO_TEST_PROMOTION_PAUSE_MS=4000 \
  cli2 wp duo apply --repo=/siterepo --default-author=admin --promotion-owner=actor-first \
  >/tmp/duo3217-first-apply.log 2>&1 &
FIRST_PID=$!
for _ in {1..80}; do
  PHASE="$(wp2 eval 'echo (string) ((\Duo\PromotionLock::current()["phase"] ?? ""));' 2>/dev/null || true)"
  [ "$PHASE" = precondition-recheck ] && break
  sleep 0.1
done
[ "${PHASE:-}" = precondition-recheck ] || fail 'first concurrent actor never acquired the target lease'
if wp2 duo apply --repo=/siterepo --default-author=admin --promotion-owner=actor-second \
  >/tmp/duo3217-second-apply.log 2>&1; then
  fail 'second concurrent apply entered while the first held the lease'
fi
grep -q 'promotion lock held' /tmp/duo3217-second-apply.log \
  || fail 'second concurrent apply did not fail at the target lock'
wait "$FIRST_PID" || { tail -80 /tmp/duo3217-first-apply.log; fail 'first concurrent apply failed'; }
ROW_COUNT="$(wp2 db query "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key='_duo_uuid' AND meta_value='$NEW_UUID'" --skip-column-names)"
[ "$ROW_COUNT" = 1 ] || fail "concurrent create produced $ROW_COUNT identity rows"
pass 'concurrent applies serialize and create exactly one mapped row'

wp1 post delete "$PAGE_ID" --force >/dev/null
wp1 duo capture --repo=/siterepo --format=json >/dev/null
git -C "$R1" add -A
git -C "$R1" commit -qm 'page deletion intent'
sync_repo
TARGET_PAGE_ID="$(wp2 eval "echo (int) \\Duo\\Ledger::id_for('$PAGE_UUID', \\Duo\\Ledger::KIND_POST);")"

"${COMPOSE[@]}" run --rm -T \
  -e DUO_TEST_MODE=1 -e DUO_TEST_PROMOTION_PAUSE_MS=4000 \
  cli2 wp duo apply --repo=/siterepo --default-author=admin --with-deletes \
  >/tmp/duo3217-delete-race.log 2>&1 &
DELETE_PID=$!
for _ in {1..80}; do
  PHASE="$(wp2 eval 'echo (string) ((\Duo\PromotionLock::current()["phase"] ?? ""));' 2>/dev/null || true)"
  [ "$PHASE" = precondition-recheck ] && break
  sleep 0.1
done
[ "${PHASE:-}" = precondition-recheck ] || fail 'delete actor never reached precondition pause'
wp2 comment create --comment_post_ID="$TARGET_PAGE_ID" --comment_content='runtime race' --comment_author='visitor' >/dev/null
if wait "$DELETE_PID"; then
  fail 'delete proceeded after a runtime reverse reference appeared'
fi
grep -q 'promotion preconditions changed after planning' /tmp/duo3217-delete-race.log \
  || fail 'late reverse reference did not invalidate the plan'
[ "$(wp2 post get "$TARGET_PAGE_ID" --field=ID)" = "$TARGET_PAGE_ID" ] \
  || fail 'guarded page was deleted despite the late runtime reference'
pass 'runtime reverse reference created after planning blocks deletion'

printf '\n✔ REGRESS_PROMOTION_LOCK PASSED\n'
