#!/usr/bin/env bash
# Live public-path regression for DUO-3336. Owns and always destroys one pair.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$REPO_ROOT"

PAIR="${DUO_INIT_PAIR:-codexmaca3336}"
PORT1="${DUO_INIT_PORT1:-9300}"
PORT2="${DUO_INIT_PORT2:-9301}"
HOST_REPO="$REPO_ROOT/sandbox/siterepo/${PAIR}1"
ENVS_FILE="$REPO_ROOT/sandbox/siterepo/${PAIR}-envs.json"
COMPOSE_FILE="$REPO_ROOT/sandbox/pair.yml"
STARTED_AT=$SECONDS

export DUO_PAIR="$PAIR"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

cleanup() {
  rm -f "$ENVS_FILE"
  bash sandbox/bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  rm -rf "$HOST_REPO" "$REPO_ROOT/sandbox/siterepo/${PAIR}2"
}
trap cleanup EXIT

assert_exit() {
  local expected="$1" description="$2"; shift 2
  set +e
  OUT=$("$@" 2>&1)
  CODE=$?
  set -e
  printf '%s\n' "$OUT"
  [ "$CODE" -eq "$expected" ] || fail "$description: expected exit $expected, got $CODE"
  pass "$description (exit $CODE)"
}

say "boot disposable authenticated Docker target on owned ports $PORT1/$PORT2"
bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless

# pair.sh deliberately binds durable pairs to the primary checkout. This pair
# is disposable evidence for the current issue worktree, so every subsequent
# ephemeral CLI invocation explicitly mounts the bytes under test.
export DUO_AGENT_SRC="$REPO_ROOT/agent"
export DUO_MANIFESTS_SRC="$REPO_ROOT/manifests"
COMPOSE=(docker compose -p "duo-$PAIR" -f "$COMPOSE_FILE")
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }

say "install the exact certified WooCommerce boundary and representative authored entities"
wp1 plugin install woocommerce --version=11.0.0 --activate >/dev/null
[ "$(wp1 plugin get woocommerce --field=version | tr -d '\r')" = "11.0.0" ] \
  || fail "WooCommerce 11.0.0 was not installed"
ATTR_ID=$(wp1 wc product_attribute create --name='Duo Init Material' --slug=duoinit --type=select --order_by=menu_order --has_archives=false --user=admin --porcelain)
wp1 wc product_attribute_term create "$ATTR_ID" --name=Cotton --slug=cotton --user=admin >/dev/null
PRODUCT_ID=$(wp1 wc product create --name='Duo Init Shirt' --type=variable \
  --attributes="[{\"id\":$ATTR_ID,\"variation\":true,\"visible\":true,\"options\":[\"Cotton\"]}]" \
  --status=publish --user=admin --porcelain)
wp1 wc product_variation create "$PRODUCT_ID" \
  --attributes="[{\"id\":$ATTR_ID,\"option\":\"Cotton\"}]" \
  --regular_price=24.00 --sku=DUO-INIT-COTTON --user=admin >/dev/null
pass "WooCommerce product, variation, and pa_duoinit taxonomy exist"

mkdir -p "$(dirname "$ENVS_FILE")"
cat > "$ENVS_FILE" <<EOF
{
  "envs": {
    "${PAIR}1": {
      "transport": "docker",
      "compose_file": "$COMPOSE_FILE",
      "service": "cli1",
      "repo_path": "/siterepo"
    }
  }
}
EOF
DUO=("$REPO_ROOT/cli/duo" "--envs-file=$ENVS_FILE")

say "unknown active plugin is an explicit blocker and the proposal is read-only"
wp1 eval '
$dir = WP_PLUGIN_DIR . "/duo-init-unknown";
wp_mkdir_p($dir);
file_put_contents($dir . "/duo-init-unknown.php", "<?php\n/* Plugin Name: Duo Init Unknown */\n");
' >/dev/null
wp1 plugin activate duo-init-unknown >/dev/null
assert_exit 2 "unsupported active plugin blocks init" "${DUO[@]}" init "${PAIR}1" --yes
grep -q 'active_plugin_without_adapter' <<<"$OUT" || fail "blocker did not expose a stable reason code"
grep -q 'no configuration, state, identity, or ledger mutation was made' <<<"$OUT" \
  || fail "blocked output did not state its no-write contract"
[ ! -e "$HOST_REPO/site.duo.json" ] && [ ! -d "$HOST_REPO/state" ] \
  || fail "blocked proposal mutated the repository"
[ "$(wp1 db query "SHOW TABLES LIKE 'wp_duo_%'" --skip-column-names | wc -l | tr -d ' ')" = "0" ] \
  || fail "blocked proposal created ledger tables"
wp1 plugin deactivate duo-init-unknown >/dev/null
wp1 plugin delete duo-init-unknown >/dev/null
pass "unsupported extension stayed outside configuration and state"

say "operator cancellation leaves the reviewed proposal completely uncommitted"
set +e
OUT=$(printf 'n\n' | "${DUO[@]}" init "${PAIR}1" 2>&1)
CODE=$?
set -e
printf '%s\n' "$OUT"
[ "$CODE" -eq 1 ] || fail "cancelled init expected exit 1, got $CODE"
grep -q 'Initialization cancelled' <<<"$OUT" || fail "cancelled init did not say it was cancelled"
[ ! -e "$HOST_REPO/site.duo.json" ] && [ ! -d "$HOST_REPO/state" ] \
  || fail "cancelled proposal mutated the repository"
[ "$(wp1 db query "SHOW TABLES LIKE 'wp_duo_%'" --skip-column-names | wc -l | tr -d ' ')" = "0" ] \
  || fail "cancelled proposal created ledger tables"
pass "confirmation boundary is real"

say "confirm the content-addressed proposal through the public host CLI"
assert_exit 0 "duo init Woo golden path" "${DUO[@]}" init "${PAIR}1" --yes
grep -q 'code: managed-baseline-proposed' <<<"$OUT" || fail "init did not propose a separate code baseline"
grep -q 'active plugin: woocommerce/woocommerce.php 11.0.0' <<<"$OUT" || fail "init did not inventory the active plugin version"
grep -q 'Initialized canonical state baseline' <<<"$OUT" || fail "init did not name the state baseline"
grep -q 'Initialized separate code baseline' <<<"$OUT" || fail "init did not name the independent code baseline"
grep -q 'not a code-and-database rollback checkpoint' <<<"$OUT" || fail "init overstated rollback readiness"
grep -q 'Managed state scope is clean' <<<"$OUT" || fail "init did not state the bounded clean result"
grep -q 'Coverage outside the selected adapters remains advisory' <<<"$OUT" || fail "init claimed whole-site completeness"
for needle in branch 'duo capture' 'duo plan' 'duo promote' rollback; do
  grep -q "$needle" <<<"$OUT" || fail "workflow guide omitted $needle"
done

jq -e '
  ([.manifests[].name] | sort) == ["core", "woocommerce"] and
  ([.manifests[] | select((.digest | type) != "string" or (.digest | length) != 64)] | length) == 0 and
  .code == {"format":1,"layout":"wp-content","source":"code/wp-content"} and
  (.policy.post_types | index("product")) != null and
  (.policy.post_types | index("product_variation")) != null and
  (.policy.post_types | index("shop_coupon")) != null and
  (.policy.post_types | index("shop_order")) == null
' "$HOST_REPO/site.duo.json" >/dev/null || fail "generated site.duo.json violates adapter pins or authored/runtime scope"
find "$HOST_REPO/state/posts/product" -type f -name '*.md' -print -quit | grep -q . \
  || fail "authored Woo product was not captured"
find "$HOST_REPO/state/terms/pa_duoinit" -type f -name '*.json' -print -quit | grep -q . \
  || fail "manifest taxonomy_patterns did not expand the authored attribute taxonomy"
[ ! -d "$HOST_REPO/state/posts/shop_order" ] || fail "runtime Woo orders entered canonical state"
[ -f "$HOST_REPO/code/wp-content/plugins/woocommerce/woocommerce.php" ] \
  || fail "active WooCommerce code was not captured into the separate payload"
[ -f "$HOST_REPO/code/wp-content/themes/twentytwentyone/style.css" ] \
  || fail "active theme code was not captured into the separate payload"
[ ! -e "$HOST_REPO/code/wp-content/mu-plugins/duo-loader.php" ] \
  || fail "Duo's control-plane loader leaked into the managed code payload"
[ "$(wp1 db query "SHOW TABLES LIKE 'wp_duo_%'" --skip-column-names | wc -l | tr -d ' ')" -ge 3 ] \
  || fail "confirmed capture did not establish the environment ledger"
pass "state/media identity exists independently from executable code"

say "ordinary public status remains the truth source for the managed scope"
assert_exit 0 "duo status after init" "${DUO[@]}" status "${PAIR}1"
grep -q '0 conflict' <<<"$OUT" || fail "clean status did not report zero conflicts"
grep -q '0 drift' <<<"$OUT" || fail "clean status did not report zero drift"

ELAPSED=$((SECONDS - STARTED_AT))
[ "$ELAPSED" -le 900 ] || fail "golden path exceeded 15 minutes (${ELAPSED}s)"
pass "golden path completed in ${ELAPSED}s and the pair will be destroyed"

printf '\n\033[1;32m✔ REGRESS_DUO_INIT PASSED\033[0m\n'
