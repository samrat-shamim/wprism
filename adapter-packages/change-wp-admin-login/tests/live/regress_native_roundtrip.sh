#!/usr/bin/env bash
set -euo pipefail
AIO_CAPSULE=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
# Keep BASH_SOURCE absolute before changing cwd so capsule-relative sourced
# hooks resolve identically under the package runner and a direct invocation.
[[ ${BASH_SOURCE[0]} = /* ]] || exec bash "$AIO_CAPSULE/tests/live/regress_native_roundtrip.sh" "$@"
cd "$AIO_CAPSULE/../../sandbox"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok: %s\n' "$*"; }
. tests/lib/pair_live_ownership.sh
. conformance/asserts.sh
export WPRISM_SOURCE_ROOT="$(cd .. && pwd -P)"
: "${WPRISM_EXPECTED_SOURCE_SHA:?Set the exact committed source SHA for native qualification}"
export CONF_PAIR="${AIO_PAIR:-aiort}" CONF1_PORT="${AIO_PORT1:-9160}" CONF2_PORT="${AIO_PORT2:-9161}"
export WPRISM_PAIR="$CONF_PAIR" WPRISM_PORT1="$CONF1_PORT" WPRISM_PORT2="$CONF2_PORT"
export WPRISM_CLI_IMAGE="${WPRISM_CLI_IMAGE:-wprism-aio-native-cli:latest}"
pair_live_ownership_prepare "$CONF_PAIR" "$CONF1_PORT" "$CONF2_PORT" 'AIO native target qualification' aio-target
pair_live_ownership_acquire mariadb
pair_live_ownership_up --http --artifacts --git-cli
COMPOSE=(docker compose -p "wprism-$CONF_PAIR" -f pair.yml -f pair.http.yml -f pair.artifacts.yml)
wp_conf1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp_conf2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
export CONF_REPO1="siterepo/${CONF_PAIR}1" CONF_REPO2="siterepo/${CONF_PAIR}2"
PAIR_COMPOSE=("${COMPOSE[@]}")
export WPRISM_ARTIFACT_PACKAGE=change-wp-admin-login
. bin/fetch-artifact.sh
for side in 1 2; do
  AIO_ARTIFACT=$(fetch_artifact change-wp-admin-login 2.4.1 "cli$side")
  "wp_conf$side" site empty --yes
  "wp_conf$side" plugin install "$AIO_ARTIFACT"
done
wp_conf1 plugin activate change-wp-admin-login
git init --bare -q -b main "siterepo/origin-${CONF_PAIR}.git"
printf '%s\n' '{"manifests":["core","change-wp-admin-login"],"policy":{"options":{},"post_meta":{},"post_types":["post","page","attachment"],"taxonomies":["category","post_tag"]},"spec_version":3}' > "$CONF_REPO1/site.wprism.json"
cp site-repo.gitignore.template "$CONF_REPO1/.gitignore"
git -C "$CONF_REPO1" init -q -b main
git -C "$CONF_REPO1" remote add origin "../origin-${CONF_PAIR}.git"
. "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
establish_core_environment_bindings wp_conf1 /siterepo admin@example.test "http://localhost:$CONF1_PORT" "http://localhost:$CONF1_PORT"
wp_conf1 wprism capture --repo=/siterepo
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'AIO native source qualification'
git -C "$CONF_REPO1" push -qu origin main
# Native target qualification follows the same freshly captured source.
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
R1="siterepo/${CONF_PAIR}1"
R2="siterepo/${CONF_PAIR}2"
AIO_TARGET_EVIDENCE="tmp/plugin-adapters/change-wp-admin-login/target-${CONF_PAIR}"
mkdir -p "$AIO_TARGET_EVIDENCE"
git clone -q "siterepo/origin-${CONF_PAIR}.git" "$R2"
establish_core_environment_bindings wp2 /siterepo admin@example.test "http://localhost:$CONF2_PORT" "http://localhost:$CONF2_PORT"
wp2 db query 'ALTER TABLE wp_posts AUTO_INCREMENT=801'
wp2 wprism deploy --repo=/siterepo
wp2 option update aio_login_google_recaptcha_v2_secret_key aio-target-secret
REV=$(git -C "$R2" rev-parse HEAD)
capture_wprism_json_checked APPLY_JSON 'AIO target apply' assert_wprism_apply_ready wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" --json
printf '%s\n' "$APPLY_JSON" > "$AIO_TARGET_EVIDENCE/apply.json"
cp ../adapter-packages/change-wp-admin-login/fixtures/native/verify-canonical.php "$R2/.tmp-aio-verify-canonical.php"
capture_wprism_json_success AIO_NATIVE 'AIO target complete canonical values' wp2 eval-file /siterepo/.tmp-aio-verify-canonical.php --use-include
printf '%s\n' "$AIO_NATIVE" > "$AIO_TARGET_EVIDENCE/canonical.json"
cp "$AIO_CAPSULE/fixtures/native/target-native.php" "$R2/.tmp-aio-target-native.php"
capture_wprism_json_success AIO_NATIVE 'AIO target native API behavior' wp2 eval-file /siterepo/.tmp-aio-target-native.php --use-include
printf '%s\n' "$AIO_NATIVE" > "$AIO_TARGET_EVIDENCE/native.json"
python3 "$AIO_CAPSULE/fixtures/native/target-http.py" "http://localhost:$CONF2_PORT" "http://localhost:$CONF1_PORT" "$AIO_TARGET_EVIDENCE/http" > "$AIO_TARGET_EVIDENCE/http.json"
capture_wprism_json_checked APPLY_JSON 'AIO repeated target apply' assert_wprism_apply_ready wp2 wprism apply --repo=/siterepo --default-author=admin --revision="$REV" --json
printf '%s\n' "$APPLY_JSON" > "$AIO_TARGET_EVIDENCE/repeated-apply.json"
wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-aio-recapture
diff -r "$R1/state" "$R2/.tmp-aio-recapture" > "$AIO_TARGET_EVIDENCE/recapture.diff"
pass 'AIO target native APIs, HTTP login, different IDs, preserved environment, repeat and complete recapture qualify'
pair_live_ownership_complete 'PASS: AIO native source-to-target qualification complete; pair destroyed'
