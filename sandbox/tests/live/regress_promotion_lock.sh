#!/usr/bin/env bash
# Live regression — issue #3217: target-authoritative promotion lease plus the
# final optimistic precondition/guard rechecks which make that lease useful.
set -euo pipefail
cd "$(dirname "$0")/../../.."

PAIR=promotion-lock
PORT1=8900
PORT2=8901
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
COMPOSE=(docker compose -p "wprism-$PAIR" -f sandbox/pair.yml)
R1="sandbox/siterepo/${PAIR}1"
R2="sandbox/siterepo/${PAIR}2"

wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
share_source_repo() {
  "${COMPOSE[@]}" run --rm -T cli1 sh -c '
    [ ! -e /siterepo/state ] || chmod -R a+rwX /siterepo/state
    [ ! -e /siterepo/media ] || chmod -R a+rwX /siterepo/media
    [ ! -e /siterepo/state.capture.lock ] || chmod a+rw /siterepo/state.capture.lock
  ' >/dev/null
}
cleanup() {
  bash sandbox/bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
}
sync_repo() {
  rm -rf "$R2/state" "$R2/media"
  cp -R "$R1/state" "$R2/state"
  [ ! -d "$R1/media" ] || cp -R "$R1/media" "$R2/media"
  cp "$R1/site.wprism.json" "$R2/site.wprism.json"
}
trap cleanup EXIT

# Make reruns deterministic after an interrupted local invocation. Permission
# repair needs the healthy CLI container, so it happens after pair startup;
# cleanup itself never launches another one while tearing the pair down.
bash sandbox/bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless
share_source_repo >/dev/null 2>&1 || true
rm -rf "$R1/.git" "$R1/state" "$R1/site.wprism.json" "$R2"
chmod 0777 "$R1"
cat > "$R1/site.wprism.json" <<'JSON'
{
  "manifests": ["core"],
  "policy": {
    "options": {}, "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
JSON
cp sandbox/site-repo.gitignore.template "$R1/.gitignore"
git -C "$R1" init -q
git -C "$R1" config user.name wprism-lock-test
git -C "$R1" config user.email wprism@example.test

wp1 option update blogname 'Lock Base' >/dev/null
PAGE_ID="$(wp1 post create --post_type=page --post_status=publish --post_title='Guarded Page' --porcelain)"
wp1 wprism capture --repo=/siterepo --format=json >/dev/null
share_source_repo
PAGE_UUID="$(wp1 post meta get "$PAGE_ID" _wprism_uuid)"
git -C "$R1" add -A
git -C "$R1" commit -qm 'baseline promotion state'
cp -R "$R1" "$R2"
chmod -R a+rwX "$R2"
wp2 wprism apply --repo=/siterepo --adopt-by-slug=posts,terms,menus --default-author=admin >/dev/null
pass 'baseline target materialized'

wp2 wprism compile --repo=/siterepo --out=/siterepo/.tmp-promotion-artifact.json --format=json >/dev/null
ARTIFACT_HASH="$(jq -r '.artifact_hash' "$R2/.tmp-promotion-artifact.json")"
wp2 wprism promotion-begin --repo=/siterepo --promotion-owner=handoff-owner --artifact-hash="$ARTIFACT_HASH" >/dev/null
wp2 wprism deploy --repo=/siterepo --compiled=/siterepo/.tmp-promotion-artifact.json \
  --promotion-owner=handoff-owner --artifact-hash="$ARTIFACT_HASH" --promotion-hold >/dev/null
[ "$(wp2 eval 'echo (string) ((\WPrism\PromotionLock::current()["phase"] ?? ""));')" = deployed ] \
  || fail 'deploy did not retain its lease for the apply process'
wp2 wprism apply --repo=/siterepo --compiled=/siterepo/.tmp-promotion-artifact.json \
  --promotion-owner=handoff-owner --artifact-hash="$ARTIFACT_HASH" --default-author=admin >/dev/null
[ "$(wp2 eval 'echo \WPrism\PromotionLock::current() === null ? "none" : "held";')" = none ] \
  || fail 'apply did not release the shared deploy/apply lease'
pass 'deploy hands one target lease to apply across wp-cli processes'

# An old checkpoint owner cannot restart after its lease expired and another
# session recovered/completed, even though that newer session removed the
# live row on success. Post-begin phases are continuations, never fresh locks.
"${COMPOSE[@]}" run --rm -T -e WPRISM_TEST_MODE=1 -e WPRISM_TEST_PROMOTION_TTL=1 \
  cli2 wp wprism promotion-begin --repo=/siterepo --promotion-owner=obsolete-owner --artifact-hash="$ARTIFACT_HASH" >/dev/null
sleep 2
wp2 wprism promotion-begin --repo=/siterepo --promotion-owner=recovery-owner --artifact-hash="$ARTIFACT_HASH" >/dev/null
wp2 wprism promotion-abort --promotion-owner=recovery-owner --artifact-hash="$ARTIFACT_HASH" >/dev/null
if wp2 wprism deploy --repo=/siterepo --compiled=/siterepo/.tmp-promotion-artifact.json \
  --promotion-owner=obsolete-owner --artifact-hash="$ARTIFACT_HASH" \
  >/tmp/promotion-lock-obsolete-checkpoint.log 2>&1; then
  fail 'obsolete checkpoint owner restarted after a newer session completed'
fi
grep -q 'begun owner/artifact session was superseded' /tmp/promotion-lock-obsolete-checkpoint.log \
  || fail 'obsolete checkpoint continuation refusal was not explicit'
if wp2 wprism promotion-abort --promotion-owner=obsolete-owner --artifact-hash="$ARTIFACT_HASH" \
  >/tmp/promotion-lock-obsolete-abort.log 2>&1; then
  fail 'obsolete checkpoint cleanup was reported idempotent after a newer session'
fi
grep -q 'latest begun session belongs to' /tmp/promotion-lock-obsolete-abort.log \
  || fail 'obsolete checkpoint cleanup did not identify the superseding session'
rm -f "$R2/.tmp-promotion-artifact.json"
pass 'post-checkpoint phases and recovery require the exact latest begun session'

HASH_A="$(printf 'a%.0s' {1..64})"

# The host begins its lease before DB export. Abort is deliberately distinct
# from completion release: it is exact-owner/hash, idempotent when a mutating
# phase already cleaned up, and safe to call after a checkpoint import has put
# the old row back into wprism_kv.
wp2 wprism promotion-begin --repo=/siterepo --promotion-owner=checkpoint-owner --artifact-hash="$HASH_A" >/dev/null
[ "$(wp2 eval 'echo (string) ((\WPrism\PromotionLock::current()["phase"] ?? ""));')" = checkpoint ] \
  || fail 'promotion-begin did not acquire the checkpoint lease'
if wp2 wprism promotion-begin --repo=/siterepo --promotion-owner=checkpoint-other --artifact-hash="$HASH_A" \
  >/tmp/promotion-lock-checkpoint-contender.log 2>&1; then
  fail 'second promotion-begin entered a live checkpoint lease'
fi
grep -q 'promotion lock held' /tmp/promotion-lock-checkpoint-contender.log \
  || fail 'checkpoint contender refusal did not name the held lease'
wp2 wprism promotion-abort --promotion-owner=checkpoint-owner --artifact-hash="$HASH_A" >/dev/null
wp2 wprism promotion-abort --promotion-owner=checkpoint-owner --artifact-hash="$HASH_A" >/dev/null
[ "$(wp2 eval 'echo \WPrism\PromotionLock::current() === null ? "none" : "held";')" = none ] \
  || fail 'idempotent promotion-abort left its lease behind'

wp2 wprism promotion-begin --repo=/siterepo --promotion-owner=checkpoint-restore --artifact-hash="$HASH_A" >/dev/null
wp2 wprism promotion-abort --promotion-owner=checkpoint-restore --artifact-hash="$HASH_A" >/dev/null
wp2 eval "\WPrism\Ledger::kv_set('promotion_lock', wp_json_encode([
 'owner'=>'checkpoint-restore', 'artifact_hash'=>'$HASH_A', 'phase'=>'checkpoint',
 'acquired_at'=>time()-5, 'expires_at'=>time()+60
]));" >/dev/null
wp2 wprism promotion-abort --promotion-owner=checkpoint-restore --artifact-hash="$HASH_A" >/dev/null
[ "$(wp2 eval 'echo \WPrism\PromotionLock::current() === null ? "none" : "held";')" = none ] \
  || fail 'promotion-abort did not clear a checkpoint-restored lease row'

wp2 wprism promotion-begin --repo=/siterepo --promotion-owner=checkpoint-guard --artifact-hash="$HASH_A" >/dev/null
if wp2 wprism promotion-abort --promotion-owner=checkpoint-other --artifact-hash="$HASH_A" \
  >/tmp/promotion-lock-abort-owner.log 2>&1; then
  fail 'promotion-abort accepted a different owner'
fi
if wp2 wprism promotion-abort --promotion-owner=checkpoint-guard \
  --artifact-hash="$(printf 'b%.0s' {1..64})" >/tmp/promotion-lock-abort-hash.log 2>&1; then
  fail 'promotion-abort accepted a different artifact hash'
fi
[ "$(wp2 eval 'echo (string) ((\WPrism\PromotionLock::current()["owner"] ?? ""));')" = checkpoint-guard ] \
  || fail 'mismatching promotion-abort changed the live lease'
wp2 wprism promotion-abort --promotion-owner=checkpoint-guard --artifact-hash="$HASH_A" >/dev/null
pass 'promotion begin/abort serializes checkpointing and safely cleans restored rows'

# A same-owner handoff after expiry must fail rather than revive the old
# checkpoint lease. A distinct owner remains able to recover it after expiry.
"${COMPOSE[@]}" run --rm -T -e WPRISM_TEST_MODE=1 -e WPRISM_TEST_PROMOTION_TTL=1 \
  cli2 wp wprism promotion-begin --repo=/siterepo --promotion-owner=checkpoint-expired --artifact-hash="$HASH_A" >/dev/null
sleep 2
if "${COMPOSE[@]}" run --rm -T -e WPRISM_TEST_MODE=1 -e WPRISM_TEST_PROMOTION_TTL=1 \
  cli2 wp wprism promotion-begin --repo=/siterepo --promotion-owner=checkpoint-expired --artifact-hash="$HASH_A" \
  >/tmp/promotion-lock-same-owner-expired.log 2>&1; then
  fail 'expired checkpoint owner revived its own lease'
fi
grep -q 'expired before handoff' /tmp/promotion-lock-same-owner-expired.log \
  || fail 'same-owner expiry refusal was not explicit'
wp2 wprism promotion-abort --promotion-owner=checkpoint-expired --artifact-hash="$HASH_A" >/dev/null
wp2 wprism promotion-begin --repo=/siterepo --promotion-owner=checkpoint-expired --artifact-hash="$HASH_A" >/dev/null
wp2 wprism promotion-abort --promotion-owner=checkpoint-expired --artifact-hash="$HASH_A" >/dev/null
pass 'expired checkpoint owner must abort before beginning a fresh recovery lease'

# The durable row handles process handoff; a connection-scoped advisory fence
# covers one long-running mutation process. Even after TTL=1 expires, another
# owner cannot recover while the first process is still inside opaque work,
# and the continuously fenced owner can renew when that work returns.
"${COMPOSE[@]}" run --rm -T -e WPRISM_TEST_MODE=1 -e WPRISM_TEST_PROMOTION_TTL=1 \
  cli2 wp eval "
\WPrism\PromotionLock::acquire('long-running-owner', '$HASH_A', 'long-running', 1);
sleep(8);
\WPrism\PromotionLock::heartbeat('long-running-owner', '$HASH_A', 'long-returned', 1);
\WPrism\PromotionLock::release('long-running-owner', '$HASH_A');
" >/tmp/promotion-lock-long-running.log 2>&1 &
LONG_PID=$!
for _ in {1..80}; do
  PHASE="$(wp2 eval 'echo (string) ((\WPrism\PromotionLock::current()["phase"] ?? ""));' 2>/dev/null || true)"
  [ "$PHASE" = long-running ] && break
  sleep 0.1
done
[ "${PHASE:-}" = long-running ] || fail 'long-running owner never acquired its process fence'
sleep 2
if wp2 eval "\WPrism\PromotionLock::acquire('long-contender', '$HASH_A', 'must-not-enter');" \
  >/tmp/promotion-lock-long-contender.log 2>&1; then
  fail 'contender entered while an expired-row owner still held the live process fence'
fi
grep -q 'promotion lock held by another live target process' /tmp/promotion-lock-long-contender.log \
  || fail 'process-fence contender refusal was not explicit'
wait "$LONG_PID" || { cat /tmp/promotion-lock-long-running.log; fail 'continuously fenced owner could not renew after opaque work'; }
pass 'live process fence prevents TTL recovery during long hooks or filesystem work'

wp2 eval "\\WPrism\\PromotionLock::acquire('actor-alpha', '$HASH_A', 'test-hold');" >/dev/null
if wp2 eval "\\WPrism\\PromotionLock::acquire('actor-bravo', '$HASH_A', 'test-race');" >/tmp/promotion-lock-lock-contender.log 2>&1; then
  fail 'second owner acquired a live target lease'
fi
grep -q 'promotion lock held' /tmp/promotion-lock-lock-contender.log \
  || fail 'contender refusal did not name the held promotion lock'
wp2 eval "\\WPrism\\PromotionLock::release('actor-alpha', '$HASH_A');" >/dev/null

wp2 eval "
\\WPrism\\Ledger::kv_set('promotion_lock', wp_json_encode([
 'owner'=>'dead-owner', 'artifact_hash'=>'$HASH_A', 'phase'=>'crashed',
 'acquired_at'=>time()-20, 'expires_at'=>time()-10
]));
\$lock=\\WPrism\\PromotionLock::acquire('actor-bravo', '$HASH_A', 'recovered');
if (empty(\$lock['recovered'])) { throw new RuntimeException('stale owner was not reported recovered'); }
\\WPrism\\PromotionLock::release('actor-bravo', '$HASH_A');
" >/dev/null
pass 'concurrent owner is refused and an expired owner is recovered atomically'

wp2 eval "\WPrism\PromotionLock::acquire('actor-renew', '$HASH_A', 'renew-start', 4);" >/dev/null
wp2 eval "\WPrism\PromotionLock::heartbeat('actor-renew', '$HASH_A', 'renewed', 10);" >/dev/null
sleep 5
if wp2 eval "\WPrism\PromotionLock::acquire('actor-other', '$HASH_A', 'too-early');" \
  >/tmp/promotion-lock-renew-contender.log 2>&1; then
  fail 'renewed lease expired before its extended deadline'
fi
wp2 eval "\WPrism\PromotionLock::release('actor-renew', '$HASH_A');" >/dev/null

wp2 eval "\WPrism\PromotionLock::acquire('actor-loss', '$HASH_A', 'loss-start', 1);" >/dev/null
sleep 2
if wp2 eval "\WPrism\PromotionLock::heartbeat('actor-loss', '$HASH_A', 'must-fail', 1);" \
  >/tmp/promotion-lock-lock-loss.log 2>&1; then
  fail 'expired owner renewed a lock it had already lost'
fi
grep -q 'lock lost or expired' /tmp/promotion-lock-lock-loss.log \
  || fail 'lock-loss refusal did not state the expired/lost condition'
wp2 eval "
\$lock=\WPrism\PromotionLock::acquire('actor-after-loss', '$HASH_A', 'loss-recovered');
if (empty(\$lock['recovered'])) { throw new RuntimeException('expired loss was not recovered'); }
\WPrism\PromotionLock::release('actor-after-loss', '$HASH_A');
" >/dev/null
pass 'lease renewal extends ownership; expiry loses ownership and permits bounded recovery'

wp1 option update blogname 'Lock Desired' >/dev/null
wp1 wprism capture --repo=/siterepo --format=json >/dev/null
share_source_repo
git -C "$R1" add -A
git -C "$R1" commit -qm 'authored option update'
sync_repo

"${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 -e WPRISM_TEST_PROMOTION_PAUSE_MS=10000 \
  cli2 wp wprism apply --repo=/siterepo --default-author=admin \
  >/tmp/promotion-lock-stale-plan.log 2>&1 &
STALE_PID=$!
for _ in {1..80}; do
  PHASE="$(wp2 eval 'echo (string) ((\WPrism\PromotionLock::current()["phase"] ?? ""));' 2>/dev/null || true)"
  [ "$PHASE" = precondition-recheck ] && break
  sleep 0.1
done
[ "${PHASE:-}" = precondition-recheck ] || fail 'apply never reached deterministic precondition pause'
wp2 option update blogname 'Live Edit Wins' >/dev/null
if wait "$STALE_PID"; then
  fail 'apply overwrote an authored edit made after planning'
fi
grep -q 'promotion preconditions changed after planning' /tmp/promotion-lock-stale-plan.log \
  || fail 'stale-plan refusal was not explicit'
[ "$(wp2 option get blogname)" = 'Live Edit Wins' ] || fail 'stale apply changed the live authored edit'
[ "$(wp2 eval 'echo \WPrism\PromotionLock::current() === null ? "none" : "held";')" = none ] \
  || fail 'failed apply leaked its target lease'
pass 'authored edit after planning is refused before target mutation'

wp2 option update blogname 'Lock Desired' >/dev/null
wp2 wprism apply --repo=/siterepo --default-author=admin >/dev/null
NEW_POST_ID="$(wp1 post create --post_type=post --post_status=publish --post_title='Concurrent Create' --porcelain)"
wp1 wprism capture --repo=/siterepo --format=json >/dev/null
share_source_repo
NEW_UUID="$(wp1 post meta get "$NEW_POST_ID" _wprism_uuid)"
git -C "$R1" add -A
git -C "$R1" commit -qm 'concurrent create fixture'
sync_repo

"${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 -e WPRISM_TEST_PROMOTION_PAUSE_MS=10000 \
  cli2 wp wprism apply --repo=/siterepo --default-author=admin \
  >/tmp/promotion-lock-first-apply.log 2>&1 &
FIRST_PID=$!
for _ in {1..80}; do
  PHASE="$(wp2 eval 'echo (string) ((\WPrism\PromotionLock::current()["phase"] ?? ""));' 2>/dev/null || true)"
  [ "$PHASE" = precondition-recheck ] && break
  sleep 0.1
done
[ "${PHASE:-}" = precondition-recheck ] || fail 'first concurrent actor never acquired the target lease'
if wp2 wprism apply --repo=/siterepo --default-author=admin \
  >/tmp/promotion-lock-second-apply.log 2>&1; then
  fail 'second concurrent apply entered while the first held the lease'
fi
grep -q 'promotion lock held' /tmp/promotion-lock-second-apply.log \
  || fail 'second concurrent apply did not fail at the target lock'
wait "$FIRST_PID" || { tail -80 /tmp/promotion-lock-first-apply.log; fail 'first concurrent apply failed'; }
ROW_COUNT="$(wp2 db query "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key='_wprism_uuid' AND meta_value='$NEW_UUID'" --skip-column-names)"
[ "$ROW_COUNT" = 1 ] || fail "concurrent create produced $ROW_COUNT identity rows"
pass 'concurrent applies serialize and create exactly one mapped row'

wp1 post delete "$PAGE_ID" --force >/dev/null
wp1 wprism capture --repo=/siterepo --format=json >/dev/null
share_source_repo
git -C "$R1" add -A
git -C "$R1" commit -qm 'page deletion intent'
sync_repo
TARGET_PAGE_ID="$(wp2 eval "echo (int) \\WPrism\\Ledger::id_for('$PAGE_UUID', \\WPrism\\Ledger::KIND_POST);")"

"${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 -e WPRISM_TEST_PROMOTION_PAUSE_MS=10000 \
  cli2 wp wprism apply --repo=/siterepo --default-author=admin --with-deletes \
  >/tmp/promotion-lock-delete-race.log 2>&1 &
DELETE_PID=$!
for _ in {1..80}; do
  PHASE="$(wp2 eval 'echo (string) ((\WPrism\PromotionLock::current()["phase"] ?? ""));' 2>/dev/null || true)"
  [ "$PHASE" = precondition-recheck ] && break
  sleep 0.1
done
[ "${PHASE:-}" = precondition-recheck ] || fail 'delete actor never reached precondition pause'
wp2 comment create --comment_post_ID="$TARGET_PAGE_ID" --comment_content='runtime race' --comment_author='visitor' >/dev/null
if wait "$DELETE_PID"; then
  fail 'delete proceeded after a runtime reverse reference appeared'
fi
grep -q 'promotion preconditions changed after planning' /tmp/promotion-lock-delete-race.log \
  || fail 'late reverse reference did not invalidate the plan'
[ "$(wp2 post get "$TARGET_PAGE_ID" --field=ID)" = "$TARGET_PAGE_ID" ] \
  || fail 'guarded page was deleted despite the late runtime reference'
pass 'runtime reverse reference created after planning blocks deletion'

# The locked artifact and the exact Policy object validated with it are the
# complete state-side input. Mutating the checkout after that validation must
# not make Capture::snapshot reopen site.wprism.json mid-plan.
wp2 wprism compile --repo=/siterepo --out=/siterepo/.tmp-frozen-policy.json --format=json >/dev/null
FROZEN_HASH="$(jq -r '.artifact_hash' "$R2/.tmp-frozen-policy.json")"
wp2 wprism promotion-begin --repo=/siterepo --promotion-owner=frozen-policy-owner --artifact-hash="$FROZEN_HASH" >/dev/null
cp "$R2/site.wprism.json" "$R2/.tmp-site.wprism.valid.json"
"${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 -e WPRISM_TEST_PROMOTION_LOCKED_PAUSE_MS=4000 \
  cli2 wp wprism apply --repo=/siterepo --compiled=/siterepo/.tmp-frozen-policy.json \
  --promotion-owner=frozen-policy-owner --artifact-hash="$FROZEN_HASH" --default-author=admin \
  >/tmp/promotion-lock-frozen-policy.log 2>&1 &
FROZEN_PID=$!
for _ in {1..80}; do
  PHASE="$(wp2 eval 'echo (string) ((\WPrism\PromotionLock::current()["phase"] ?? ""));' 2>/dev/null || true)"
  [ "$PHASE" = artifact-validated ] && break
  sleep 0.1
done
[ "${PHASE:-}" = artifact-validated ] || fail 'apply never reached locked artifact/policy validation'
printf '{invalid after locked validation\n' > "$R2/site.wprism.json"
set +e
wait "$FROZEN_PID"
FROZEN_EXIT=$?
set -e
mv "$R2/.tmp-site.wprism.valid.json" "$R2/site.wprism.json"
[ "$FROZEN_EXIT" -eq 0 ] || { cat /tmp/promotion-lock-frozen-policy.log; fail 'locked apply reopened mutable policy'; }
rm -f "$R2/.tmp-frozen-policy.json"
pass 'locked apply uses one frozen policy/artifact pair through both plan snapshots'

printf '\n✔ REGRESS_PROMOTION_LOCK PASSED\n'
