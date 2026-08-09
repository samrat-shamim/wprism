#!/usr/bin/env bash
# Live public-path regression for DUO-3336. Owns and always destroys one pair.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$REPO_ROOT"

PAIR="${DUO_INIT_PAIR:-codexmaca3336}"
PORT1="${DUO_INIT_PORT1:-9300}"
PORT2="${DUO_INIT_PORT2:-9301}"
HOST_REPO="$REPO_ROOT/sandbox/siterepo/${PAIR}1"
HOST_REPO2="$REPO_ROOT/sandbox/siterepo/${PAIR}2"
ENVS_FILE="$REPO_ROOT/sandbox/siterepo/${PAIR}-envs.json"
COMPOSE_FILE="$REPO_ROOT/sandbox/pair.yml"
STARTED_AT=$SECONDS

export DUO_PAIR="$PAIR"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

cleanup() {
  rm -f "$ENVS_FILE"
  rm -f "/tmp/${PAIR}-init-concurrent-1.log" "/tmp/${PAIR}-init-concurrent-2.log"
  bash sandbox/bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  rm -rf "$HOST_REPO" \
    "$REPO_ROOT/sandbox/siterepo/${PAIR}2" \
    "$REPO_ROOT/sandbox/siterepo/origin-${PAIR}.git"
}
trap cleanup EXIT

# The evidence pair is deliberately reusable. Start from the same verified
# clean-room boundary that the EXIT trap establishes so a prior interrupted
# or completed run cannot leak repository state into the read-only checks.
cleanup

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
unset DUO_CLI_IMAGE || true
bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless

# pair.sh deliberately binds durable pairs to the primary checkout. This pair
# is disposable evidence for the current issue worktree, so every subsequent
# ephemeral CLI invocation explicitly mounts the bytes under test.
export DUO_AGENT_SRC="$REPO_ROOT/agent"
export DUO_MANIFESTS_SRC="$REPO_ROOT/manifests"
COMPOSE=(docker compose -p "duo-$PAIR" -f "$COMPOSE_FILE")
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
git1() { "${COMPOSE[@]}" run --rm -T --entrypoint git cli1 -C /siterepo "$@"; }

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
    },
    "${PAIR}2": {
      "transport": "docker",
      "compose_file": "$COMPOSE_FILE",
      "service": "cli2",
      "repo_path": "/siterepo"
    }
  }
}
EOF
DUO=("$REPO_ROOT/cli/duo" "--envs-file=$ENVS_FILE")

say "missing target Git is an explicit pre-confirmation blocker"
assert_exit 2 "Git-unavailable target blocks init" "${DUO[@]}" init "${PAIR}1" --yes
grep -q 'git_unavailable' <<<"$OUT" || fail "missing Git blocker did not expose a stable reason code"
[ ! -e "$HOST_REPO/site.duo.json" ] && [ ! -d "$HOST_REPO/state" ] \
  || fail "Git-unavailable proposal mutated the repository"
[ "$(wp1 db query "SHOW TABLES LIKE 'wp_duo_%'" --skip-column-names | wc -l | tr -d ' ')" = "0" ] \
  || fail "Git-unavailable proposal created ledger tables"
pass "Git readiness is proven before confirmation"

say "build the evidence-only target image with Git installed"
DUO_CLI_IMAGE="duo-init-cli-php83-git:${PAIR}"
docker build -q -f sandbox/init-cli.Dockerfile -t "$DUO_CLI_IMAGE" sandbox >/dev/null
export DUO_CLI_IMAGE
[ "$(wp1 eval 'echo trim((string) shell_exec("git --version"));')" != "" ] \
  || fail "Git-enabled evidence target did not expose Git to the agent"
pass "Git-enabled target fixture is ready"

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

say "large risk discovery is deterministic and serialized objects never execute"
wp1 eval '
$dir = WP_PLUGIN_DIR . "/duo-init-risk-canary";
wp_mkdir_p($dir);
$php = <<<'"'"'PHP'"'"'
<?php
/* Plugin Name: Duo Init Risk Canary */
class Duo_Init_Risk_Canary {
    public string $email = "sensitive-person@example.test";
    public function __wakeup(): void { update_option("duo_init_wakeup_ran", "yes"); }
}
PHP;
file_put_contents($dir . "/duo-init-risk-canary.php", $php);
' >/dev/null
wp1 plugin activate duo-init-risk-canary >/dev/null
wp1 eval '
global $wpdb;
$object = new Duo_Init_Risk_Canary();
add_user_meta(1, "duo_init_email_surface", serialize($object));
for ($start = 0; $start < 5001; $start += 250) {
    $optionRows = [];
    $metaRows = [];
    for ($i = $start; $i < min(5001, $start + 250); $i++) {
        $optionRows[] = $wpdb->prepare("(%s,%s,%s)", "duo_init_risk_$i", "plain-$i", "no");
        $metaRows[] = $wpdb->prepare("(%d,%s,%s)", 1, "duo_init_risk_$i", "plain-$i");
    }
    $wpdb->query("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES " . implode(",", $optionRows));
    $wpdb->query("INSERT INTO {$wpdb->usermeta} (user_id,meta_key,meta_value) VALUES " . implode(",", $metaRows));
}
' >/dev/null
RISK_A=$(wp1 duo init --repo=/siterepo --format=json)
RISK_B=$(wp1 duo init --repo=/siterepo --format=json)
[ "$(jq -r .digest <<<"$RISK_A")" = "$(jq -r .digest <<<"$RISK_B")" ] \
  || fail "unchanged >5000-row risk surfaces produced different proposal digests"
jq -e '.state.risk_surfaces.truncated == true and (.state.risk_surfaces.user_meta["email address"] // 0) >= 1' \
  <<<"$RISK_A" >/dev/null || fail "bounded deterministic risk report omitted redacted PII surface"
! grep -q 'sensitive-person@example.test' <<<"$RISK_A" \
  || fail "risk report exposed a raw PII value"
if wp1 option get duo_init_wakeup_ran >/dev/null 2>&1; then
  fail "read-only proposal executed a serialized-object wakeup hook"
fi
[ "$(wp1 db query "SHOW TABLES LIKE 'wp_duo_%'" --skip-column-names | wc -l | tr -d ' ')" = "0" ] \
  || fail "risk proposal created ledger tables"
wp1 eval '
global $wpdb;
delete_user_meta(1, "duo_init_email_surface");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\''duo\\_init\\_risk\\_%'\''");
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\''duo\\_init\\_risk\\_%'\''");
' >/dev/null
wp1 plugin deactivate duo-init-risk-canary >/dev/null
wp1 plugin delete duo-init-risk-canary >/dev/null
pass "risk digest and no-object-execution boundary are deterministic"

say "high-confidence credentials in captured code block with redacted output"
wp1 eval '
$token = "sk_" . "live_" . str_repeat("A", 24);
$payload = str_repeat("x", 32764) . $token;
file_put_contents(WP_PLUGIN_DIR . "/woocommerce/duo-init-secret.php", $payload);
' >/dev/null
assert_exit 2 "credential-bearing active code blocks init" "${DUO[@]}" init "${PAIR}1" --yes
grep -q 'credential_bearing_code_file' <<<"$OUT" || fail "code credential blocker omitted its reason code"
grep -q 'stripe key' <<<"$OUT" || fail "code credential blocker omitted its redacted label"
! grep -q 'sk_live_' <<<"$OUT" || fail "code credential blocker exposed the credential value"
[ ! -e "$HOST_REPO/site.duo.json" ] && [ ! -d "$HOST_REPO/state" ] \
  || fail "credential-bearing proposal mutated the repository"
wp1 eval 'unlink(WP_PLUGIN_DIR . "/woocommerce/duo-init-secret.php");' >/dev/null
pass "captured code secret guard is value-redacted and fail-closed"

say "foreign state, media, and non-pristine ledger ownership refuse before writes"
mkdir -p "$HOST_REPO/state" "$HOST_REPO/media"
assert_exit 2 "foreign state/media block init" "${DUO[@]}" init "${PAIR}1" --yes
grep -q 'existing_state_payload' <<<"$OUT" || fail "stale state blocker was missing"
grep -q 'existing_media_payload' <<<"$OUT" || fail "stale media blocker was missing"
rmdir "$HOST_REPO/state" "$HOST_REPO/media"
wp1 eval '\Duo\Ledger::ensure(); \Duo\Ledger::kv_set("duo_init_stale", "1");' >/dev/null
assert_exit 2 "non-pristine ledger blocks init" "${DUO[@]}" init "${PAIR}1" --yes
grep -q 'existing_duo_ledger' <<<"$OUT" || fail "stale ledger blocker was missing"
[ "$(wp1 db query 'SELECT COUNT(*) FROM wp_duo_kv' --skip-column-names | tr -d '[:space:]')" = "1" ] \
  || fail "ledger refusal mutated the pre-existing row set"
wp1 db query 'DROP TABLE IF EXISTS wp_duo_journal,wp_duo_kv,wp_duo_map,wp_duo_state' >/dev/null
pass "init never adopts or overwrites foreign canonical ownership"

say "post-swap injected failure rolls back repo, media, identity, and ledger rows"
wp1 eval '
$upload = wp_upload_dir();
wp_mkdir_p($upload["path"]);
$path = $upload["path"] . "/duo-init-atomic.txt";
file_put_contents($path, "duo init atomic media\n");
$id = wp_insert_attachment([
    "post_title" => "Duo Init Atomic Media", "post_status" => "inherit",
    "post_mime_type" => "text/plain",
], $path);
update_attached_file($id, $path);
' >/dev/null
INTENT_PLAN=$(wp1 duo init --repo=/siterepo --format=json)
INTENT_DIGEST=$(jq -r .digest <<<"$INTENT_PLAN")
set +e
OUT=$("${COMPOSE[@]}" run --rm -T \
  -e DUO_TEST_MODE=1 \
  -e DUO_TEST_PUBLISH_FAIL_PHASE=intent-written \
  cli1 wp duo init --repo=/siterepo --confirm="$INTENT_DIGEST" --format=json 2>&1)
CODE=$?
set -e
printf '%s\n' "$OUT"
[ "$CODE" -ne 0 ] || fail "intent-written injected failure unexpectedly succeeded"
[ -z "$(find "$HOST_REPO" -mindepth 1 -maxdepth 1 -print -quit)" ] \
  || fail "intent-written failure left repository artifacts"
ROW_TOTAL=$(wp1 db query '
SELECT
  (SELECT COUNT(*) FROM wp_duo_journal) +
  (SELECT COUNT(*) FROM wp_duo_kv) +
  (SELECT COUNT(*) FROM wp_duo_map) +
  (SELECT COUNT(*) FROM wp_duo_state)
' --skip-column-names | tr -d '[:space:]')
[ "$ROW_TOTAL" = "0" ] || fail "intent-written failure left Duo ledger rows"
[ "$(wp1 db query "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '_duo_uuid'" --skip-column-names | tr -d '[:space:]')" = "0" ] \
  || fail "intent-written failure left minted identities"
wp1 db query 'DROP TABLE IF EXISTS wp_duo_journal,wp_duo_kv,wp_duo_map,wp_duo_state' >/dev/null
pass "intent-write failure compensation is complete"

ATOMIC_PLAN=$(wp1 duo init --repo=/siterepo --format=json)
ATOMIC_DIGEST=$(jq -r .digest <<<"$ATOMIC_PLAN")
set +e
OUT=$("${COMPOSE[@]}" run --rm -T \
  -e DUO_TEST_MODE=1 \
  -e DUO_TEST_FAIL_DB_CONTEXT='init capture after filesystem swap' \
  cli1 wp duo init --repo=/siterepo --confirm="$ATOMIC_DIGEST" --format=json 2>&1)
CODE=$?
set -e
printf '%s\n' "$OUT"
[ "$CODE" -ne 0 ] || fail "post-swap injected failure unexpectedly succeeded"
[ -z "$(find "$HOST_REPO" -mindepth 1 -maxdepth 1 -print -quit)" ] \
  || fail "failed init did not restore byte-empty repository ownership"
ROW_TOTAL=$(wp1 db query '
SELECT
  (SELECT COUNT(*) FROM wp_duo_journal) +
  (SELECT COUNT(*) FROM wp_duo_kv) +
  (SELECT COUNT(*) FROM wp_duo_map) +
  (SELECT COUNT(*) FROM wp_duo_state)
' --skip-column-names | tr -d '[:space:]')
[ "$ROW_TOTAL" = "0" ] || fail "failed init left Duo ledger rows"
[ "$(wp1 db query "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '_duo_uuid'" --skip-column-names | tr -d '[:space:]')" = "0" ] \
  || fail "failed init left minted identities"
wp1 db query 'DROP TABLE IF EXISTS wp_duo_journal,wp_duo_kv,wp_duo_map,wp_duo_state' >/dev/null
pass "post-swap failure compensation is complete"

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

say "two concurrent confirmations produce exactly one complete winner"
CONCURRENT_PLAN=$(wp2 duo init --repo=/siterepo --format=json)
CONCURRENT_DIGEST=$(jq -r .digest <<<"$CONCURRENT_PLAN")
set +e
"${COMPOSE[@]}" run --rm -T \
  -e DUO_TEST_MODE=1 -e DUO_TEST_INIT_PAUSE_MS=3000 -e DUO_TEST_INIT_PUBLICATION_PAUSE_MS=5000 \
  cli2 wp duo init --repo=/siterepo --confirm="$CONCURRENT_DIGEST" --format=json \
  >"/tmp/${PAIR}-init-concurrent-1.log" 2>&1 &
PID1=$!
sleep 0.5
"${COMPOSE[@]}" run --rm -T \
  cli2 wp duo init --repo=/siterepo --confirm="$CONCURRENT_DIGEST" --format=json \
  >"/tmp/${PAIR}-init-concurrent-2.log" 2>&1 &
PID2=$!
wait "$PID2"; CODE2=$?
for _ in $(seq 1 100); do
  [ -f "$HOST_REPO2/site.duo.json" ] && break
  sleep 0.1
done
[ -f "$HOST_REPO2/site.duo.json" ] || fail "concurrent winner never reached publication-lock phase"
set +e
CAPTURE_OUT=$(wp2 duo capture --repo=/siterepo --format=json 2>&1)
CAPTURE_CODE=$?
set -e
[ "$CAPTURE_CODE" -ne 0 ] || fail "ordinary capture entered while init held its publication lock"
grep -q 'another capture is already publishing' <<<"$CAPTURE_OUT" \
  || fail "ordinary capture refusal did not name the held publication lock"
set +e
wait "$PID1"; CODE1=$?
set -e
if { [ "$CODE1" -eq 0 ] && [ "$CODE2" -eq 0 ]; } \
  || { [ "$CODE1" -ne 0 ] && [ "$CODE2" -ne 0 ]; }; then
  printf '%s\n' "first=$CODE1 second=$CODE2"
  sed -n '1,120p' "/tmp/${PAIR}-init-concurrent-1.log"
  sed -n '1,120p' "/tmp/${PAIR}-init-concurrent-2.log"
  fail "concurrent init expected exactly one successful confirmation"
fi
[ -f "$HOST_REPO2/site.duo.json" ] && [ -d "$HOST_REPO2/state" ] \
  && [ -d "$HOST_REPO2/code/wp-content" ] && [ -d "$HOST_REPO2/.git" ] \
  || fail "concurrent winner did not leave one complete baseline tuple"
[ "$(wp2 db query "SELECT COUNT(*) FROM wp_duo_kv WHERE k IN ('code_revision','code_descriptor')" --skip-column-names | tr -d '[:space:]')" = "2" ] \
  || fail "concurrent winner did not publish exactly one completed lifecycle pair"
assert_exit 0 "concurrent winner status" "${DUO[@]}" status "${PAIR}2"
pass "init advisory lease prevents loser cleanup from touching the winner"

say "confirm the content-addressed proposal through the public host CLI"
assert_exit 0 "duo init Woo golden path" "${DUO[@]}" init "${PAIR}1" --yes
grep -q 'code: managed-baseline-proposed' <<<"$OUT" || fail "init did not propose a separate code baseline"
grep -q 'active plugin: woocommerce/woocommerce.php 11.0.0' <<<"$OUT" || fail "init did not inventory the active plugin version"
grep -q 'Initialized canonical state baseline' <<<"$OUT" || fail "init did not name the state baseline"
grep -q 'Initialized separate code baseline' <<<"$OUT" || fail "init did not name the independent code baseline"
grep -q 'not a code-and-database rollback checkpoint' <<<"$OUT" || fail "init overstated rollback readiness"
grep -q 'Managed state scope is clean' <<<"$OUT" || fail "init did not state the bounded clean result"
grep -q 'Coverage outside the selected adapters remains advisory' <<<"$OUT" || fail "init claimed whole-site completeness"
grep -q 'active_theme_code_only' <<<"$OUT" || fail "init hid the active theme code-only state advisory"
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

say "the advertised Git baseline, commit, and branch path is executable"
git1 rev-parse --show-toplevel | grep -qx /siterepo \
  || fail "confirmed init did not create a target-owned Git worktree"
git1 config user.name 'Duo Init Regression'
git1 config user.email 'duo-init@example.invalid'
git1 add .gitignore site.duo.json code state media
git1 commit -m 'duo: initial code and state baselines' >/dev/null
git1 switch -c duo-init-regression >/dev/null
[ "$(git1 branch --show-current | tr -d '\r')" = "duo-init-regression" ] \
  || fail "Git-ready baseline could not create the first branch"
[ -z "$(git1 status --porcelain)" ] || fail "initial Git baseline left unstaged canonical files"
pass "first commit and branch work without hand-authored repository setup"

say "ordinary public status remains the truth source for the managed scope"
assert_exit 0 "duo status after init" "${DUO[@]}" status "${PAIR}1"
grep -q '0 conflict' <<<"$OUT" || fail "clean status did not report zero conflicts"
grep -q '0 drift' <<<"$OUT" || fail "clean status did not report zero drift"

ELAPSED=$((SECONDS - STARTED_AT))
[ "$ELAPSED" -le 900 ] || fail "golden path exceeded 15 minutes (${ELAPSED}s)"
pass "golden path completed in ${ELAPSED}s and the pair will be destroyed"

printf '\n\033[1;32m✔ REGRESS_DUO_INIT PASSED\033[0m\n'
