#!/usr/bin/env bash
# Live issue #3216 regression: activation/deactivation/order are code_mismatch
# gates for apply; deploy reconciles them through real WP lifecycle APIs;
# deploy-only mail/HTTP observations are report-only; the host `wprism promote`
# product path retains a DB checkpoint and runs fresh retirement/activation
# processes before apply. `wprism deploy` retains its own checkpoint under its own
# lease, `wprism recover` lists and restores it through the same four ordered
# steps, and `--no-checkpoint` writes nothing.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$REPO_ROOT"

PAIR=codexmaca3216
PORT1=8920
PORT2=8921
export WPRISM_PAIR="$PAIR"
COMPOSE=(docker compose -p "wprism-${PAIR}" -f sandbox/pair.yml)
SITE1="$REPO_ROOT/sandbox/siterepo/${PAIR}1"
SITE2="$REPO_ROOT/sandbox/siterepo/${PAIR}2"
ENVS="$REPO_ROOT/.wprism-envs.json"
WPRISM="$REPO_ROOT/cli/wprism"
PROBE=wprism-promotion-probe/wprism-promotion-probe.php

wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
cleanup() {
  local status=$?
  trap - EXIT INT TERM
  set +e
  rm -f "$ENVS"
  # Capture/artifact descendants are created by container uid 33. Normalize
  # only this disposable pair bind before pair.sh and the host remove it.
  "${COMPOSE[@]}" run --rm -T -u root cli1 sh -c 'chmod -R ugo+rwX /siterepo' >/dev/null 2>&1 || true
  "${COMPOSE[@]}" run --rm -T -u root cli2 sh -c 'chmod -R ugo+rwX /siterepo' >/dev/null 2>&1 || true
  bash sandbox/bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  rm -rf -- "$SITE1" "$SITE2"
  if [ -e "$SITE1" ] || [ -e "$SITE2" ]; then
    printf 'FAIL: promotion cleanup left %s or %s behind\n' "$SITE1" "$SITE2" >&2
    status=1
  fi
  exit "$status"
}
trap cleanup EXIT

bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless

for side in 1 2; do
  "${COMPOSE[@]}" exec -T "wp${side}" mkdir -p /var/www/html/wp-content/plugins/wprism-promotion-probe
  "${COMPOSE[@]}" cp sandbox/tests/fixtures/wprism-promotion-probe.php \
    "wp${side}:/var/www/html/wp-content/plugins/wprism-promotion-probe/wprism-promotion-probe.php"
done
pass "probe plugin installed on both environments"

rm -rf "$SITE1" "$SITE2"
mkdir -p "$SITE1"
# The host recreates this bind-mounted root after pair.sh's writable setup;
# restore the same cross-uid contract before container uid 33 captures into it.
chmod 0777 "$SITE1"
cat > "$SITE1/site.wprism.json" <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "options": {"wprism_promotion_probe_activated": {"class": "runtime"}},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp sandbox/site-repo.gitignore.template "$SITE1/.gitignore"
git -C "$SITE1" init -q
git -C "$SITE1" config user.name wprism
git -C "$SITE1" config user.email wprism@example.test

wp1 plugin activate hello >/dev/null
wp1 plugin activate "$PROBE" >/dev/null
wp1 eval "update_option('active_plugins', ['hello.php', '$PROBE']);" >/dev/null
wp1 wprism capture --repo=/siterepo --format=json >/dev/null
git -C "$SITE1" add -A
git -C "$SITE1" commit -qm "promotion fixture"
cp -R "$SITE1" "$SITE2"
chmod -R a+rwX "$SITE2"
pass "canonical state declares exact plugin activation order"

cat > "$ENVS" <<EOF
{"envs":{"target":{"transport":"docker","compose_file":"sandbox/pair.yml","service":"cli2","repo_path":"/siterepo"}}}
EOF

wp2 plugin activate akismet >/dev/null
PLAN="$(wp2 wprism plan --repo=/siterepo --format=json 2>/dev/null | tail -1)"
printf '%s\n' "$PLAN" | jq -e --arg probe "$PROBE" '
  any(.code_mismatch[]; .issue == "inactive_in_environment" and .plugin == "hello.php")
  and any(.code_mismatch[]; .issue == "inactive_in_environment" and .plugin == $probe)
  and any(.code_mismatch[]; .issue == "unexpected_active_plugin" and .plugin == "akismet/akismet.php")
' >/dev/null || fail "plan did not block on installed-but-inactive desired plugins"
if wp2 wprism apply --repo=/siterepo --adopt-by-slug=posts,terms,menus >/dev/null 2>&1; then
  fail "apply succeeded before activation lifecycle"
fi
[ "$(wp2 option get wprism_promotion_probe_activated 2>/dev/null || true)" = "" ] \
  || fail "apply ran the activation hook"
pass "apply refuses before deploy and leaves lifecycle state untouched"

DEPLOY="$($WPRISM --envs-file="$ENVS" deploy target 2>&1)" \
  || fail "host lifecycle deploy failed: $DEPLOY"
grep -Fq 'deploy phase: lifecycle-retire' <<<"$DEPLOY" \
  || fail "host deploy did not enter its fresh retirement process"
grep -Fq 'deploy phase: lifecycle-activate' <<<"$DEPLOY" \
  || fail "host deploy did not enter its fresh activation process"
grep -Fq 'deactivated: akismet/akismet.php' <<<"$DEPLOY" \
  || fail "host deploy did not retire the unexpected plugin"
grep -Fq "activated: $PROBE" <<<"$DEPLOY" \
  || fail "host deploy did not activate the canonical probe"
grep -Fq 'wp_mail attempted: WPRISM promotion activation probe' <<<"$DEPLOY" \
  || fail "host deploy did not report the activation mail attempt"
grep -Fq 'http request attempted: https://wprism-promotion-probe.invalid/activation' <<<"$DEPLOY" \
  || fail "host deploy did not report the activation HTTP attempt"
[ "$(wp2 option get wprism_promotion_probe_activated)" = yes ] \
  || fail "real activation hook did not complete"
[ "$(wp2 option get active_plugins --format=json | jq -c .)" = '["hello.php","wprism-promotion-probe/wprism-promotion-probe.php"]' ] \
  || fail "deploy did not establish canonical plugin order"
pass "deploy activates for real and reports mail/HTTP without failing"

# Deploy's own database checkpoint, end to end on a real target: it is taken
# under deploy's own lease before staging, retained beside the deploy-<owner>
# artifact, listed by `wprism recover --list` as a retained-release-checkpoint, and
# restorable through the same four ordered steps a promote checkpoint gets.
grep -Fq 'deploy phase: checkpoint' <<<"$DEPLOY" \
  || fail "host deploy did not take a database checkpoint"
DEPLOY_CKPT="$(printf '%s\n' "$DEPLOY" | sed -n 's/^database checkpoint retained: //p' | head -1)"
[ -n "$DEPLOY_CKPT" ] || fail "host deploy printed no retained checkpoint path"
case "$DEPLOY_CKPT" in
  /siterepo/.wprism/checkpoints/deploy-*.sql.enc) : ;;
  *) fail "deploy retained its checkpoint at an unexpected path: $DEPLOY_CKPT" ;;
esac
[ -s "$SITE2/${DEPLOY_CKPT#/siterepo/}" ] \
  || fail "the deploy checkpoint is missing or empty on the target"
DEPLOY_CKPT_ID="$(basename "$DEPLOY_CKPT" .sql)"
# The stem is load-bearing: RetainedCheckpoints reads the lease identity out of
# the SIBLING artifacts/<same-stem>.json, so a stem mismatch lists the row with
# an empty artifact_hash and then refuses checkpoint_identity_unknown.
[ -f "$SITE2/.wprism/artifacts/${DEPLOY_CKPT_ID}.json" ] \
  || fail "the deploy checkpoint has no sibling artifact to read its lease identity from"
RECOVER_LIST="$($WPRISM --envs-file="$ENVS" recover target --list 2>&1)" \
  || fail "wprism recover --list failed after deploy: $RECOVER_LIST"
grep -Fq "$DEPLOY_CKPT_ID  retained  retained-release-checkpoint" <<<"$RECOVER_LIST" \
  || fail "wprism recover --list did not list the deploy checkpoint: $RECOVER_LIST"
RECOVER_OUT="$($WPRISM --envs-file="$ENVS" recover target "--restore=$DEPLOY_CKPT_ID" --writers-excluded 2>&1)" \
  || fail "restoring the deploy checkpoint failed: $RECOVER_OUT"
grep -Fq 'recovery profile: operator-directed' <<<"$RECOVER_OUT" \
  || fail "the deploy checkpoint restore printed no operator-directed claim"
for step in 'abort: ok' 'begin: ok' 'import: ok' 'final-abort: ok'; do
  grep -Fq "$step" <<<"$RECOVER_OUT" || fail "the deploy checkpoint restore did not report '$step': $RECOVER_OUT"
done
pass "deploy retains a recoverable database checkpoint under its own lease"

# --no-checkpoint is the opt-out: no new file under .wprism/checkpoints, and no
# retained line. Re-run against the now-converged target, which is a no-op
# lifecycle move.
BEFORE_COUNT="$(ls -1 "$SITE2/.wprism/checkpoints" | wc -l | tr -d ' ')"
DEPLOY_NC="$($WPRISM --envs-file="$ENVS" deploy target --no-checkpoint 2>&1)" \
  || fail "--no-checkpoint deploy failed: $DEPLOY_NC"
grep -Fq 'deploy phase: checkpoint' <<<"$DEPLOY_NC" \
  && fail "--no-checkpoint still ran the checkpoint phase"
grep -Fq 'database checkpoint retained: ' <<<"$DEPLOY_NC" \
  && fail "--no-checkpoint still reported a retained checkpoint"
AFTER_COUNT="$(ls -1 "$SITE2/.wprism/checkpoints" | wc -l | tr -d ' ')"
[ "$BEFORE_COUNT" = "$AFTER_COUNT" ] \
  || fail "--no-checkpoint wrote a new file under .wprism/checkpoints ($BEFORE_COUNT -> $AFTER_COUNT)"
pass "wprism deploy --no-checkpoint writes nothing under .wprism/checkpoints"

wp2 eval "update_option('active_plugins', ['$PROBE', 'hello.php']);" >/dev/null
PLAN="$(wp2 wprism plan --repo=/siterepo --format=json 2>/dev/null | tail -1)"
printf '%s\n' "$PLAN" | jq -e '
  any(.code_mismatch[]; .issue == "active_plugin_order_mismatch")
' >/dev/null || fail "plan did not block same-set, wrong-order activation state"
pass "same plugin set in the wrong load order is a blocking mismatch"

PROMOTE="$($WPRISM --envs-file="$ENVS" promote target --adopt-by-slug=posts,terms,menus --default-author=admin 2>&1)" \
  || fail "host promote failed: $PROMOTE"
grep -Fq 'promote complete: lifecycle-retire -> lifecycle-activate -> apply' <<<"$PROMOTE" \
  || fail "host promote did not report its complete phase trace"
CHECKPOINT="$(printf '%s\n' "$PROMOTE" | sed -n 's/^database checkpoint: //p' | head -1)"
[ -f "$SITE2/${CHECKPOINT#/siterepo/}" ] || fail "host-visible retained checkpoint is missing"
[ "$(wp2 option get active_plugins --format=json | jq -c .)" = '["hello.php","wprism-promotion-probe/wprism-promotion-probe.php"]' ] \
  || fail "promote did not correct exact plugin order before apply"
STATUS="$($WPRISM --envs-file="$ENVS" status target 2>&1)" \
  || fail "target was not clean after promote: $STATUS"
pass "promote retained its DB checkpoint, corrected order, applied, and converged cleanly"

printf '\n✔ REGRESS_PROMOTION PASSED\n'
