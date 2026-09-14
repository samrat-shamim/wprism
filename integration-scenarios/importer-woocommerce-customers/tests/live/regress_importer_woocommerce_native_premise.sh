#!/usr/bin/env bash
# Native fixture premises only: no Capture/Plan/Apply qualification is claimed.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd -P)"
cd "$ROOT/sandbox"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok: %s\n' "$*"; }
PAIR="${IMPORTER_WOO_PAIR:?unique owned pair required}"
PORT1="${IMPORTER_WOO_PORT1:?even port required}" PORT2="${IMPORTER_WOO_PORT2:?successor port required}"
export WPRISM_SOURCE_ROOT="$ROOT" WPRISM_EXPECTED_SOURCE_SHA="${WPRISM_EXPECTED_SOURCE_SHA:?}"
[ "$(git rev-parse HEAD)" = "$WPRISM_EXPECTED_SOURCE_SHA" ] && [ -z "$(git status --porcelain)" ] || fail 'exact clean candidate required'
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" WPRISM_CODEBIND_PLUGIN=''
export WPRISM_ARTIFACT_LIBRARY_ROOT="$ROOT" CONF_PAIR="$PAIR" COMPOSE_PROJECT_NAME="wprism-$PAIR"
SCENARIO_ROOT="$ROOT/integration-scenarios/importer-woocommerce-customers"
. tests/lib/pair_live_ownership.sh
. tests/lib/private_command_capture.sh
. conformance/asserts.sh
. bin/fetch-artifact.sh
WPRISM_ARTIFACT_PARTICIPANTS=$(artifact_library_scenario_participants "$SCENARIO_ROOT/scenario.json")
export WPRISM_ARTIFACT_PARTICIPANTS
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" 'Importer Woo native premise' importer-woo-premise
PAIR_COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.artifacts.yml)
wp_side() { local side="$1"; shift; "${PAIR_COMPOSE[@]}" run --rm -T -v "$SCENARIO_ROOT/fixtures:/wprism-scenario:ro" "cli$side" wp "$@"; }
sink=$(umask 077; mktemp -d "$ROOT/sandbox/tmp/importer-woo-native-premise.XXXXXX")
printf 'Native premise evidence: %s\n' "$sink"
combo_capture() {
  local label="$1" result=0 suffix; shift
  [[ "$label" =~ ^[a-z][a-z0-9_-]*$ ]] || fail 'unsafe premise stage'
  for suffix in stdout stderr exit; do (umask 077; set -C; : > "$sink/$label.$suffix"); done
  wprism_private_capture_stage "$sink" "$label" "$@" || result=$?
  [ "$result" = 0 ] || fail "$label failed; inspect retained private streams"
  php -r 'require $argv[1]."/sandbox/tests/lib/PrivateCommandOutput.php"; WPrismTest\PrivateCommandOutput::readBytes($argv[2], "/^ ?Container wprism-".$argv[3]."-cli[12]-run-[a-f0-9]{12} (Creating|Created) *$/D", WPrismTest\EvidenceSizeProfile::NATIVE_DATABASE);' "$ROOT" "$sink/$label" "$PAIR"
  pass "$label"
}
CAPSULE=/var/www/html/wp-content/mu-plugins/adapter-packages/users-customers-import-export-for-wp-woocommerce/fixtures
SCENARIO=/wprism-scenario
native() { local side="$1" fixture="$2"; shift 2; wp_side "$side" --require="$CAPSULE/admin-context.php" --user=admin eval-file "$fixture" "$@" --use-include; }
bash bin/pair.sh list
pair_live_ownership_acquire mariadb
pair_live_ownership_up --artifacts --headless
for side in 1 2; do
  combo_capture "cron$side" wp_side "$side" config set DISABLE_WP_CRON true --raw
  combo_capture "empty$side" wp_side "$side" site empty --yes
  for spec in users-customers-import-export-for-wp-woocommerce:2.7.5 woocommerce:11.0.1; do
    slug=${spec%:*}; version=${spec#*:}
    artifact=$(fetch_artifact "$slug" "$version" "cli$side")
    combo_capture "install$side-$slug" wp_side "$side" plugin install "$artifact" --activate
  done
  combo_capture "hpos$side" establish_woocommerce_hpos wp_side "$side" --require="$CAPSULE/admin-context.php" --user=admin
  role=source; [ "$side" = 1 ] || role=target
  combo_capture "settings$side" native "$side" "$CAPSULE/settings-native.php" "setup-$role"
  combo_capture "exports$side" native "$side" "$CAPSULE/templates-native.php" "setup-$role"
  combo_capture "imports$side" native "$side" "$CAPSULE/import-templates-native.php" "setup-$role"
  combo_capture "customers$side" native "$side" "$SCENARIO/customers-native.php" "seed-$role"
  combo_capture "inputs$side" native "$side" "$SCENARIO/customers-native.php" "inputs-$role"
  combo_capture "save-export$side" native "$side" "$SCENARIO/templates-native.php" save export 'Selected users'
  combo_capture "save-import$side" native "$side" "$SCENARIO/templates-native.php" save import 'Reusable input mapping'
done
. "$SCENARIO_ROOT/fixtures/observe.sh"
combo_database_observe 2 target-before
combo_capture consume-export native 2 "$SCENARIO/templates-native.php" consume export 'Selected users'
combo_capture consume-import native 2 "$SCENARIO/templates-native.php" consume import 'Reusable input mapping'
combo_capture customers-after native 2 "$SCENARIO/customers-native.php" observe
combo_database_observe 2 target-after
php "$SCENARIO_ROOT/fixtures/native-evidence.php" "$sink" "$PAIR"
pair_live_ownership_complete 'PASS: native customer premise and complete observations; Apply not exercised'
