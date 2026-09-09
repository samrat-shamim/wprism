#!/usr/bin/env bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
REPO_ROOT="$(cd "$PACKAGE_ROOT/../.." && pwd -P)"
cd "$REPO_ROOT/sandbox"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
PAIR="${VP_QUERY_PAIR:?unique owned pair required}"
PORT1="${VP_QUERY_PORT1:?even port required}" PORT2="${VP_QUERY_PORT2:?successor port required}"
EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:?exact candidate SHA required}"
[ "$(git rev-parse HEAD)" = "$EXPECTED_SHA" ] && [ -z "$(git status --porcelain=v1 --untracked-files=all)" ] || fail 'native evidence requires the clean exact candidate'
export WPRISM_SOURCE_ROOT="$REPO_ROOT" WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" WPRISM_CODEBIND_PLUGIN=''
export WPRISM_ARTIFACT_LIBRARY_ROOT="$REPO_ROOT" WPRISM_ARTIFACT_PACKAGE=visual-portfolio CONF_PAIR="$PAIR"
. tests/lib/pair_live_ownership.sh
. tests/lib/private_command_capture.sh
. tests/lib/conformance_private_command.sh
. conformance/asserts.sh
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" 'Visual Portfolio native query Apply' wprism-vp-query
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml)
PAIR_COMPOSE=("${COMPOSE[@]}")
wp_side() { local side="$1"; shift; "${COMPOSE[@]}" run --rm -T "cli$side" wp "$@"; }
candidate() { local side="$1" verb="$2"; shift 2; conformance_private_command "cli$side" "$verb" wp_side "$side" wprism "$verb" "$@"; }
sink=$(umask 077; mktemp -d "$REPO_ROOT/sandbox/tmp/vp-query-native.XXXXXX")
printf 'Retained native query streams for %s: %s\n' "$EXPECTED_SHA" "$sink"
capture() { local name="$1"; shift; capture_expected "$name" 0 "$@"; }
capture_expected() {
  local name="$1" expected="$2" result=0 suffix verb=''; shift 2
  if [ "${1:-}" = candidate ]; then verb="$3"; fi
  for suffix in stdout stderr exit; do (umask 077; set -C; : > "$sink/$name.$suffix"); done
  wprism_private_capture_stage "$sink" "$name" "$@" || result=$?
  [ "$result" -eq "$expected" ] || fail "$name exited $result (expected $expected); retained $sink/$name"
  php "$PACKAGE_ROOT/fixtures/settings-evidence.php" admit-command "$sink/$name" "$PAIR" "$verb" "$expected"
  printf 'ok: %s\n' "$name"
}
zip="${VP_QUERY_ZIP:?local locked Visual Portfolio 3.8.1 zip required}"
[[ "$zip" = /* ]] && [ -f "$zip" ] || fail 'absolute native artifact path required'
[ "$(shasum -a 256 "$zip" | cut -d ' ' -f 1)" = 5b4eb1dd8f1f9ec35e60db58239e48534a82e8957ebe89a0f889399cb7bdabe4 ] || fail 'wrong locked Visual Portfolio artifact'
WPRISM_DB_ENGINE=mariadb bash bin/pair.sh list
pair_live_ownership_acquire mariadb
pair_live_ownership_up
fixture=/var/www/html/wp-content/mu-plugins/adapter-packages/visual-portfolio/fixtures/queries/native.php
evidence="$PACKAGE_ROOT/fixtures/queries/evidence.php"
for side in 1 2; do
  capture "cron$side" wp_side "$side" config set DISABLE_WP_CRON true --raw
  capture "empty$side" wp_side "$side" site empty --yes
  capture "install$side" "${COMPOSE[@]}" run --rm -T -v "$zip:/visual-portfolio.zip:ro" "cli$side" wp plugin install /visual-portfolio.zip --activate
  capture "theme$side" wp_side "$side" theme activate twentytwentyone
  role=source; [ "$side" = 1 ] || role=target
  capture "seed$side" wp_side "$side" eval-file "$fixture" "seed-$role" --use-include --user=admin
done
pair_live_ownership_repo_host
R1="$PAIR_LIVE_OWNERSHIP_SITE1" R2="$PAIR_LIVE_OWNERSHIP_SITE2"
cp site-repo.gitignore.template "$R1/.gitignore"
git -C "$R1" init -q -b evidence
git -C "$R2" init -q -b evidence
capture binding1 establish_core_environment_bindings wp_side /siterepo admin@example.test "http://localhost:$PORT1" "http://localhost:$PORT1" 1
capture capture candidate 1 capture --repo=/siterepo --format=json
capture source wp_side 1 eval-file "$fixture" observe --use-include --user=admin
git -C "$R1" add site.wprism.json .gitignore state
git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'Native Visual Portfolio query bodies'
git -C "$R2" fetch -q "$R1" evidence
git -C "$R2" merge -q --ff-only FETCH_HEAD
capture binding2 establish_core_environment_bindings wp_side /siterepo admin@example.test "http://localhost:$PORT2" "http://localhost:$PORT2" 2
capture apply candidate 2 apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --format=json
capture target wp_side 2 eval-file "$fixture" observe --use-include --user=admin
capture repeat candidate 2 apply --repo=/siterepo --format=json
capture stable wp_side 2 eval-file "$fixture" observe --use-include --user=admin
capture recapture candidate 2 capture --repo=/siterepo --format=json
pair_live_ownership_repo_host
git -C "$R2" diff --exit-code -- state || fail 'complete target recapture differs from source canonical state'
for case in default manual post-types filters reset duplicates taxonomy-exclusion; do
  capture "http1-$case" curl --fail --silent --show-error --max-time 30 "http://localhost:$PORT1/vp-query-$case/"
  capture "http2-$case" curl --fail --silent --show-error --max-time 30 "http://localhost:$PORT2/vp-query-$case/"
done
php "$evidence" positive "$sink" "$PAIR"
for case in custom hidden-custom missing; do
  capture "$case-save" wp_side 1 eval-file "$fixture" "$case" --use-include --user=admin
  capture "$case-before" wp_side 1 eval-file "$fixture" observe --use-include --user=admin
  capture "$case-state-before" php "$evidence" snapshot "$R1"
  capture_expected "$case-refusal" 1 candidate 1 capture --repo=/siterepo --format=json
  capture "$case-after" wp_side 1 eval-file "$fixture" observe --use-include --user=admin
  capture "$case-state-after" php "$evidence" snapshot "$R1"
done
php "$evidence" negative "$sink" "$PAIR"
pair_live_ownership_complete 'REGRESS_VISUAL_PORTFOLIO_NATIVE_QUERIES PASSED'
