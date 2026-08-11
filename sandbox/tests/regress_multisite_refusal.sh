#!/usr/bin/env bash
# DUO-3223 live scope-boundary proof: v1 is single-site. A real WordPress
# multisite conversion must make the ordinary `wp duo capture` product path
# fail loudly before repository publication or authored-state mutation.
# Own disposable pair; green runs destroy it, failures leave it for inspection.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

PAIR="${MULTISITE_PAIR:-msrefusal}"
PORT1="${MULTISITE_PORT1:-8882}"
PORT2="${MULTISITE_PORT2:-8883}"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "invalid MULTISITE_PAIR '$PAIR'"
WORDPRESS_OFFLINE="${DUO_WORDPRESS_ORG_OFFLINE:-0}"
case "$WORDPRESS_OFFLINE" in
  0|1) ;;
  *) fail "DUO_WORDPRESS_ORG_OFFLINE must be 0 or 1" ;;
esac
export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2"
COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml -f pair.artifacts.yml)
PAIR_UP_FLAGS=(--artifacts)
if [ "$WORDPRESS_OFFLINE" = 1 ]; then
  COMPOSE+=(-f pair.wordpress-offline.yml)
  PAIR_UP_FLAGS+=(--wordpress-offline)
fi
export DUO_ARTIFACT_OFFLINE="$WORDPRESS_OFFLINE"
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
REPO="siterepo/${PAIR}1"

say "pair-budget preflight"
bash bin/pair.sh list

say "fresh pair $PAIR"
bash bin/pair.sh reset "$PAIR"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" "${PAIR_UP_FLAGS[@]}"

say "convert side 1 to a real WordPress multisite"
wp1 core multisite-convert --title='Duo Multisite Refusal' >/dev/null
[ "$(wp1 eval 'echo is_multisite() ? "yes" : "no";')" = yes ] \
  || fail "WordPress did not report is_multisite() after multisite-convert"
pass "WordPress reports multisite through its own runtime API"

say "prepare an otherwise-valid core site repository and mutation canary"
jq -n '{manifests:["core"],policy:{options:{},post_meta:{},post_types:["post","page","attachment"],taxonomies:["category","post_tag"]},spec_version:2}' > "$REPO/site.duo.json"
cp site-repo.gitignore.template "$REPO/.gitignore"
wp1 option update duo_multisite_refusal_canary untouched >/dev/null
SITE_BEFORE=$(shasum -a 256 "$REPO/site.duo.json" | awk '{print $1}')

say "ordinary product path must refuse loudly and publish nothing"
set +e
CAPTURE_OUT=$(wp1 duo capture --repo=/siterepo 2>&1)
CAPTURE_RC=$?
set -e
printf '%s\n' "$CAPTURE_OUT"
[ "$CAPTURE_RC" -ne 0 ] || fail "multisite capture returned success"
grep -qF 'multisite is unsupported by the certified v1 contract' <<<"$CAPTURE_OUT" \
  || fail "refusal did not name the unsupported multisite contract"
grep -qF 'single-site only' <<<"$CAPTURE_OUT" \
  || fail "refusal did not name the supported single-site boundary"
[ ! -e "$REPO/state" ] || fail "multisite refusal published a state directory"
[ ! -e "$REPO/state.capture-staging" ] || fail "multisite refusal leaked a capture staging directory"
[ ! -e "$REPO/state.capture-backup" ] || fail "multisite refusal leaked a capture backup directory"
[ "$(shasum -a 256 "$REPO/site.duo.json" | awk '{print $1}')" = "$SITE_BEFORE" ] \
  || fail "multisite refusal mutated site.duo.json"
[ "$(wp1 option get duo_multisite_refusal_canary)" = untouched ] \
  || fail "multisite refusal mutated authored WordPress state"
pass "multisite is an actionable non-zero refusal with zero repository/authored-state mutation"

printf '\n\033[1;32m✔ REGRESS_MULTISITE_REFUSAL PASSED\033[0m\n'

say "cleanup: destroy own disposable pair"
bash bin/pair.sh destroy "$PAIR"
pass "$PAIR destroyed"
