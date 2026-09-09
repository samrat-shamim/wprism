#!/usr/bin/env bash
# Native full baseline followed by public host scope/plan/Apply and terminal replay.
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
REPO_ROOT="$(cd "$PACKAGE_ROOT/../.." && pwd -P)"
cd "$REPO_ROOT/sandbox"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
PAIR="${VP_SETTINGS_PAIR:?unique owned pair required}"
PORT1="${VP_SETTINGS_PORT1:?even port required}"
PORT2="${VP_SETTINGS_PORT2:?successor port required}"
EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:?exact candidate SHA required}"
[ "$(git rev-parse HEAD)" = "$EXPECTED_SHA" ] && [ -z "$(git status --porcelain=v1 --untracked-files=all)" ] || fail 'native evidence requires the clean exact candidate'
export WPRISM_SOURCE_ROOT="$REPO_ROOT" WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" WPRISM_CODEBIND_PLUGIN=''
export WPRISM_ARTIFACT_PACKAGE=visual-portfolio WPRISM_ARTIFACT_LIBRARY_ROOT="$REPO_ROOT" CONF_PAIR="$PAIR"
export COMPOSE_PROJECT_NAME="wprism-$PAIR"
. tests/lib/pair_live_ownership.sh
. tests/lib/private_command_capture.sh
. tests/lib/conformance_private_command.sh
. conformance/asserts.sh
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" 'Visual Portfolio native settings Apply' wprism-vp-settings
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml)
PAIR_COMPOSE=("${COMPOSE[@]}")
wp_side() { local side="$1"; shift; "${COMPOSE[@]}" run --rm -T "cli$side" wp "$@"; }
candidate() { local side="$1" verb="$2"; shift 2; conformance_private_command "cli$side" "$verb" wp_side "$side" wprism "$verb" "$@"; }
sink=$(umask 077; mktemp -d "$REPO_ROOT/sandbox/tmp/vp-settings-native.XXXXXX")
printf 'Retained native settings streams for %s: %s\n' "$EXPECTED_SHA" "$sink"
capture() {
  local name="$1" result=0 suffix; shift
  for suffix in stdout stderr exit; do (umask 077; set -C; : > "$sink/$name.$suffix"); done
  wprism_private_capture_stage "$sink" "$name" "$@" || result=$?
  [ "$result" -eq 0 ] || fail "$name exited $result; retained $sink/$name"
  php -r 'require $argv[1]; WPrismTest\PrivateCommandOutput::readBytes($argv[2], "/^ ?Container wprism-[a-z0-9]+-cli[12]-run-[a-z0-9]+ (Creating|Created) *$/D");' \
    "$REPO_ROOT/sandbox/tests/lib/PrivateCommandOutput.php" "$sink/$name"
  printf 'ok: %s\n' "$name"
}
zip="${VP_SETTINGS_ZIP:?local locked Visual Portfolio 3.8.1 zip required}"
[[ "$zip" = /* ]] && [ -f "$zip" ] || fail 'absolute native artifact path required'
[ "$(shasum -a 256 "$zip" | cut -d ' ' -f 1)" = 5b4eb1dd8f1f9ec35e60db58239e48534a82e8957ebe89a0f889399cb7bdabe4 ] || fail 'wrong locked Visual Portfolio artifact'
WPRISM_DB_ENGINE=mariadb bash bin/pair.sh list
pair_live_ownership_acquire mariadb
pair_live_ownership_up
fixture=/var/www/html/wp-content/mu-plugins/adapter-packages/visual-portfolio/fixtures/settings-native.php
for side in 1 2; do
  capture "cron$side" wp_side "$side" config set DISABLE_WP_CRON true --raw
  capture "empty$side" wp_side "$side" site empty --yes
  if [ "$side" = 2 ]; then capture padding wp_side 2 eval-file "$fixture" pad --use-include --user=admin; fi
  capture "install$side" "${COMPOSE[@]}" run --rm -T -v "$zip:/visual-portfolio.zip:ro" "cli$side" wp plugin install /visual-portfolio.zip --activate
  role=source; [ "$side" = 1 ] || role=target
  capture "setup$side" wp_side "$side" eval-file "$fixture" "setup-$role" --use-include --user=admin
done
pair_live_ownership_repo_host
R1="$PAIR_LIVE_OWNERSHIP_SITE1" R2="$PAIR_LIVE_OWNERSHIP_SITE2"
cp site-repo.gitignore.template "$R1/.gitignore"
git -C "$R1" init -q -b evidence
git -C "$R2" init -q -b evidence
capture binding1 establish_core_environment_bindings wp_side /siterepo admin@example.test "http://localhost:$PORT1" "http://localhost:$PORT1" 1
capture capture1 candidate 1 capture --repo=/siterepo --format=json
git -C "$R1" add site.wprism.json .gitignore state
git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'Visual Portfolio native baseline'
git -C "$R2" fetch -q "$R1" evidence
git -C "$R2" merge -q --ff-only FETCH_HEAD
capture binding2 establish_core_environment_bindings wp_side /siterepo admin@example.test "http://localhost:$PORT2" "http://localhost:$PORT2" 2
capture baseline-apply candidate 2 apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --format=json
capture baseline-target wp_side 2 eval-file "$fixture" observe --use-include --user=admin
php -r '$c=["envs"=>[]]; foreach(["source"=>"cli1","target"=>"cli2"] as $name=>$service) $c["envs"][$name]=["transport"=>"docker","compose_file"=>$argv[2],"service"=>$service,"repo_path"=>"/siterepo"]; file_put_contents($argv[1],json_encode($c,JSON_THROW_ON_ERROR));' "$sink/envs.json" "$REPO_ROOT/sandbox/pair.yml"
for phase in move clear disable enable; do
  capture "$phase-save" wp_side 1 eval-file "$fixture" "$phase" --use-include --user=admin
  capture "$phase-capture" candidate 1 capture --repo=/siterepo --format=json
  capture "$phase-source" wp_side 1 eval-file "$fixture" observe --use-include --user=admin
  pair_live_ownership_repo_host
  git -C "$R1" add state
  git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm "Visual Portfolio native $phase"
  git -C "$R2" fetch -q "$R1" evidence
  git -C "$R2" merge -q --ff-only FETCH_HEAD
  capture "$phase-scope" "$REPO_ROOT/cli/wprism" --envs-file="$sink/envs.json" scope source --roots=option:vp_general --contract --format=json
  capture "$phase-plan" "$REPO_ROOT/cli/wprism" --envs-file="$sink/envs.json" plan target --scope-contract="$sink/$phase-scope.stdout" --format=json
  capture "$phase-apply" "$REPO_ROOT/cli/wprism" --envs-file="$sink/envs.json" apply target --scope-contract="$sink/$phase-scope.stdout" --format=json
  capture "$phase-target" wp_side 2 eval-file "$fixture" observe --use-include --user=admin
  capture "$phase-repeat" "$REPO_ROOT/cli/wprism" --envs-file="$sink/envs.json" apply target --scope-contract="$sink/$phase-scope.stdout" --format=json
  capture "$phase-stable" wp_side 2 eval-file "$fixture" observe --use-include --user=admin
done
php "$PACKAGE_ROOT/fixtures/settings-evidence.php" "$sink"
pair_live_ownership_complete 'REGRESS_VISUAL_PORTFOLIO_SETTINGS_APPLY PASSED'
