#!/usr/bin/env bash
# Live DUO-3216 regression: activation/deactivation/order are code_mismatch
# gates for apply; deploy reconciles them through real WP lifecycle APIs;
# deploy-only mail/HTTP observations are report-only; the host `duo promote`
# product path retains a DB checkpoint and runs deploy before apply.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$REPO_ROOT"

PAIR=codexmaca3216
PORT1=8920
PORT2=8921
export DUO_PAIR="$PAIR"
COMPOSE=(docker compose -p "duo-${PAIR}" -f sandbox/pair.yml)
SITE1="$REPO_ROOT/sandbox/siterepo/${PAIR}1"
SITE2="$REPO_ROOT/sandbox/siterepo/${PAIR}2"
ENVS="$REPO_ROOT/.duo-envs.json"
DUO="$REPO_ROOT/cli/duo"
PROBE=duo-promotion-probe/duo-promotion-probe.php

wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
cleanup() {
  rm -f "$ENVS"
  bash sandbox/bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  rm -rf "$SITE1" "$SITE2"
}
trap cleanup EXIT

bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless

for side in 1 2; do
  "${COMPOSE[@]}" exec -T "wp${side}" mkdir -p /var/www/html/wp-content/plugins/duo-promotion-probe
  "${COMPOSE[@]}" cp sandbox/tests/fixtures/duo-promotion-probe.php \
    "wp${side}:/var/www/html/wp-content/plugins/duo-promotion-probe/duo-promotion-probe.php"
done
pass "probe plugin installed on both environments"

rm -rf "$SITE1" "$SITE2"
mkdir -p "$SITE1"
cat > "$SITE1/site.duo.json" <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "options": {"duo_promotion_probe_activated": {"class": "runtime"}},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 1
}
EOF
cp sandbox/site-repo.gitignore.template "$SITE1/.gitignore"
git -C "$SITE1" init -q
git -C "$SITE1" config user.name duo
git -C "$SITE1" config user.email duo@example.test

wp1 plugin activate hello >/dev/null
wp1 plugin activate "$PROBE" >/dev/null
wp1 eval "update_option('active_plugins', ['hello.php', '$PROBE']);" >/dev/null
wp1 duo capture --repo=/siterepo --format=json >/dev/null
git -C "$SITE1" add -A
git -C "$SITE1" commit -qm "promotion fixture"
cp -R "$SITE1" "$SITE2"
chmod -R a+rwX "$SITE2"
pass "canonical state declares exact plugin activation order"

cat > "$ENVS" <<EOF
{"envs":{"target":{"transport":"docker","compose_file":"sandbox/pair.yml","service":"cli2","repo_path":"/siterepo"}}}
EOF

wp2 plugin activate akismet >/dev/null
PLAN="$(wp2 duo plan --repo=/siterepo --format=json 2>/dev/null | tail -1)"
printf '%s\n' "$PLAN" | jq -e --arg probe "$PROBE" '
  any(.code_mismatch[]; .issue == "inactive_in_environment" and .plugin == "hello.php")
  and any(.code_mismatch[]; .issue == "inactive_in_environment" and .plugin == $probe)
  and any(.code_mismatch[]; .issue == "unexpected_active_plugin" and .plugin == "akismet/akismet.php")
' >/dev/null || fail "plan did not block on installed-but-inactive desired plugins"
if wp2 duo apply --repo=/siterepo --adopt-by-slug=posts,terms,menus >/dev/null 2>&1; then
  fail "apply succeeded before activation lifecycle"
fi
[ "$(wp2 option get duo_promotion_probe_activated 2>/dev/null || true)" = "" ] \
  || fail "apply ran the activation hook"
pass "apply refuses before deploy and leaves lifecycle state untouched"

DEPLOY="$(wp2 duo deploy --repo=/siterepo --format=json 2>/dev/null | tail -1)"
printf '%s\n' "$DEPLOY" | jq -e --arg probe "$PROBE" '
  (.code_mismatch == [])
  and any(.reconciled_code_mismatch[]; .issue == "inactive_in_environment" and .plugin == $probe)
  and any(.reconciled_code_mismatch[]; .issue == "unexpected_active_plugin" and .plugin == "akismet/akismet.php")
  and any(.deactivated[]; . == "akismet/akismet.php")
  and any(.external_side_effects[]; contains("wp_mail attempted: DUO promotion activation probe"))
  and any(.external_side_effects[]; contains("http request attempted: https://duo-promotion-probe.invalid/activation"))
' >/dev/null || fail "deploy did not reconcile activation and report both external side-effect attempts"
[ "$(wp2 option get duo_promotion_probe_activated)" = yes ] \
  || fail "real activation hook did not complete"
[ "$(wp2 option get active_plugins --format=json | jq -c .)" = '["hello.php","duo-promotion-probe/duo-promotion-probe.php"]' ] \
  || fail "deploy did not establish canonical plugin order"
pass "deploy activates for real and reports mail/HTTP without failing"

wp2 eval "update_option('active_plugins', ['$PROBE', 'hello.php']);" >/dev/null
PLAN="$(wp2 duo plan --repo=/siterepo --format=json 2>/dev/null | tail -1)"
printf '%s\n' "$PLAN" | jq -e '
  any(.code_mismatch[]; .issue == "active_plugin_order_mismatch")
' >/dev/null || fail "plan did not block same-set, wrong-order activation state"
pass "same plugin set in the wrong load order is a blocking mismatch"

PROMOTE="$($DUO --envs-file="$ENVS" promote target --adopt-by-slug=posts,terms,menus --default-author=admin 2>&1)" \
  || fail "host promote failed: $PROMOTE"
printf '%s\n' "$PROMOTE" | grep -q 'promote complete: deploy -> apply' \
  || fail "host promote did not report its complete phase trace"
CHECKPOINT="$(printf '%s\n' "$PROMOTE" | sed -n 's/^database checkpoint: //p' | head -1)"
[ -f "$SITE2/${CHECKPOINT#/siterepo/}" ] || fail "host-visible retained checkpoint is missing"
[ "$(wp2 option get active_plugins --format=json | jq -c .)" = '["hello.php","duo-promotion-probe/duo-promotion-probe.php"]' ] \
  || fail "promote did not correct exact plugin order before apply"
STATUS="$($DUO --envs-file="$ENVS" status target 2>&1)" \
  || fail "target was not clean after promote: $STATUS"
pass "promote retained its DB checkpoint, corrected order, applied, and converged cleanly"

printf '\n✔ REGRESS_PROMOTION PASSED\n'
