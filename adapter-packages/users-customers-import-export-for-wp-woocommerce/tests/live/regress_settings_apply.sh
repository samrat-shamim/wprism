#!/usr/bin/env bash
# Native full baseline followed by public host scope/plan/Apply and terminal replay.
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
REPO_ROOT="$(cd "$PACKAGE_ROOT/../.." && pwd -P)"
cd "$REPO_ROOT/sandbox"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok: %s\n' "$*"; }
PAIR="${IMPORTER_SETTINGS_PAIR:?unique owned pair required}"
PORT1="${IMPORTER_SETTINGS_PORT1:?even port required}"
PORT2="${IMPORTER_SETTINGS_PORT2:?successor port required}"
EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:?exact candidate SHA required}"
[ "$(git rev-parse HEAD)" = "$EXPECTED_SHA" ] && [ -z "$(git status --porcelain=v1 --untracked-files=all)" ] || fail 'native evidence requires the clean exact candidate'
export WPRISM_SOURCE_ROOT="$REPO_ROOT" WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" WPRISM_CODEBIND_PLUGIN=''
export WPRISM_ARTIFACT_LIBRARY_ROOT="$REPO_ROOT" CONF_PAIR="$PAIR"
export COMPOSE_PROJECT_NAME="wprism-$PAIR"
. tests/lib/pair_live_ownership.sh
. tests/lib/private_command_capture.sh
. tests/lib/conformance_private_command.sh
. conformance/asserts.sh
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" 'Importer native settings Apply' wprism-importer-settings
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml)
PAIR_COMPOSE=("${COMPOSE[@]}")
wp_side() { local side="$1"; shift; "${COMPOSE[@]}" run --rm -T "cli$side" wp "$@"; }
candidate() { local side="$1" verb="$2"; shift 2; conformance_private_command "cli$side" "$verb" wp_side "$side" wprism "$verb" "$@"; }
host_apply() { conformance_private_command cli2 apply "$REPO_ROOT/cli/wprism" --envs-file="$sink/envs.json" apply target "$@"; }
sink=$(umask 077; mktemp -d "$REPO_ROOT/sandbox/tmp/importer-settings-native.XXXXXX")
printf 'Retained native settings streams for %s: %s\n' "$EXPECTED_SHA" "$sink"
capture() { local name="$1"; shift; capture_expected "$name" 0 "$@"; }
capture_expected() {
  local name="$1" expected="$2" result=0 suffix verb=''; shift 2
  if [ "${1:-}" = candidate ]; then verb="$3"; fi
  if [ "${1:-}" = host_apply ]; then verb=apply; fi
  for suffix in stdout stderr exit; do (umask 077; set -C; : > "$sink/$name.$suffix"); done
  wprism_private_capture_stage "$sink" "$name" "$@" || result=$?
  [ "$result" -eq "$expected" ] || fail "$name exited $result (expected $expected); retained $sink/$name"
  assert_no_php_runtime_diagnostics "$name stdout" "$(cat "$sink/$name.stdout")"
  php "$PACKAGE_ROOT/fixtures/settings-evidence.php" admit-command "$sink/$name" "$PAIR" "$verb"
  printf 'ok: %s\n' "$name"
}
zip="${IMPORTER_SETTINGS_ZIP:?local locked Importer 2.7.5 zip required}"
[[ "$zip" = /* ]] && [ -f "$zip" ] || fail 'absolute native artifact path required'
[ "$(shasum -a 256 "$zip" | cut -d ' ' -f 1)" = 6b7bd053960bee782e900688dac0cfed9df2519a2a0cdf72f65cf47d4b77e4a2 ] || fail 'wrong locked Importer artifact'
WPRISM_DB_ENGINE=mariadb bash bin/pair.sh list
pair_live_ownership_acquire mariadb
pair_live_ownership_up
fixture_root=/var/www/html/wp-content/mu-plugins/adapter-packages/users-customers-import-export-for-wp-woocommerce/fixtures
fixture="$fixture_root/settings-native.php"
native_side() { local side="$1"; shift; wp_side "$side" --require="$fixture_root/admin-context.php" eval-file "$fixture" "$@" --use-include --user=admin; }
wp_conf1() { wp_side 1 "$@"; }
for side in 1 2; do
  capture "cron$side" wp_side "$side" config set DISABLE_WP_CRON true --raw
  capture "empty$side" wp_side "$side" site empty --yes
  capture "install$side" "${COMPOSE[@]}" run --rm -T -v "$zip:/importer.zip:ro" "cli$side" wp plugin install /importer.zip --activate
  role=source; [ "$side" = 1 ] || role=target
  capture "setup$side" native_side "$side" "setup-$role"
done
. "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
pair_live_ownership_repo_host
R1="$PAIR_LIVE_OWNERSHIP_SITE1" R2="$PAIR_LIVE_OWNERSHIP_SITE2"
cp site-repo.gitignore.template "$R1/.gitignore"
git -C "$R1" init -q -b evidence
git -C "$R2" init -q -b evidence
capture binding1 establish_core_environment_bindings wp_side /siterepo admin@example.test "http://localhost:$PORT1" "http://localhost:$PORT1" 1
capture baseline-capture candidate 1 capture --repo=/siterepo --format=json
. "$(dirname "${BASH_SOURCE[0]}")/../conformance/capture-check.sh"
git -C "$R1" add site.wprism.json .gitignore state
git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'Importer native settings baseline'
git -C "$R2" fetch -q "$R1" evidence
git -C "$R2" merge -q --ff-only FETCH_HEAD
capture binding2 establish_core_environment_bindings wp_side /siterepo admin@example.test "http://localhost:$PORT2" "http://localhost:$PORT2" 2
capture baseline-apply candidate 2 apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --format=json
capture jobs native_side 2 jobs
capture baseline-target native_side 2 observe
php -r '$c=["envs"=>[]]; foreach(["source"=>"cli1","target"=>"cli2"] as $name=>$service) $c["envs"][$name]=["transport"=>"docker","compose_file"=>$argv[2],"service"=>$service,"repo_path"=>"/siterepo"]; file_put_contents($argv[1],json_encode($c,JSON_THROW_ON_ERROR));' "$sink/envs.json" "$REPO_ROOT/sandbox/pair.yml"
for phase in target source retention; do
  capture "$phase-save" native_side 1 "save-$phase"
  capture "$phase-capture" candidate 1 capture --repo=/siterepo --format=json
  capture "$phase-source" native_side 1 observe
  pair_live_ownership_repo_host
  git -C "$R1" add state
  git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm "Importer native $phase settings"
  git -C "$R2" fetch -q "$R1" evidence
  git -C "$R2" merge -q --ff-only FETCH_HEAD
  capture "$phase-scope" "$REPO_ROOT/cli/wprism" --envs-file="$sink/envs.json" scope source --roots=option:wt_iew_advanced_settings --contract --format=json
  capture "$phase-plan" "$REPO_ROOT/cli/wprism" --envs-file="$sink/envs.json" plan target --scope-contract="$sink/$phase-scope.stdout" --format=json
  capture "$phase-apply" host_apply --scope-contract="$sink/$phase-scope.stdout" --request-id="importer-settings-$phase" --format=json
  capture "$phase-target" native_side 2 observe
  capture "$phase-repeat" host_apply --scope-contract="$sink/$phase-scope.stdout" --request-id="importer-settings-$phase" --format=json
  capture "$phase-stable" native_side 2 observe
  capture "$phase-recapture" candidate 2 capture --repo=/siterepo --format=json
  pair_live_ownership_repo_host
  cmp "$R1/state/options/core.json" "$R2/state/options/core.json" || fail 'source and target recapture differ'
done
capture purge-save native_side 2 save-retention
capture purge-target native_side 2 observe
php "$PACKAGE_ROOT/fixtures/settings-evidence.php" "$sink" "$PAIR"
pair_live_ownership_complete 'REGRESS_IMPORTER_SETTINGS_APPLY PASSED'
