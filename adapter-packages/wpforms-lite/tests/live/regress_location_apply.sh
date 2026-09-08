#!/usr/bin/env bash
# Content-only Apply evidence. No init, code descriptor, deploy or certification.
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
REPO_ROOT="$(cd "$PACKAGE_ROOT/../.." && pwd -P)"
cd "$REPO_ROOT/sandbox"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
PAIR="${WPFORMS_APPLY_PAIR:?unique owned pair required}"
PORT1="${WPFORMS_APPLY_PORT1:?even port required}"
PORT2="${WPFORMS_APPLY_PORT2:?successor port required}"
EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:?exact candidate SHA required}"
TARGET_KIND="${WPFORMS_APPLY_TARGET_KIND:-seeded}"
case "$TARGET_KIND" in seeded|empty) ;; *) fail 'target kind must be seeded or empty' ;; esac
SETTINGS="${WPFORMS_APPLY_SETTINGS:-0}"
case "$SETTINGS:$TARGET_KIND" in 0:*|1:seeded) ;; *) fail 'settings profile requires an explicitly seeded target' ;; esac
[[ "$EXPECTED_SHA" =~ ^[a-f0-9]{40}$ ]] && [ "$(git rev-parse HEAD)" = "$EXPECTED_SHA" ] || fail 'wrong native evidence source'
[ -z "$(git status --porcelain=v1 --untracked-files=all)" ] || fail 'dirty native evidence source'
export WPRISM_SOURCE_ROOT="$REPO_ROOT" WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" WPRISM_CODEBIND_PLUGIN=''
. tests/lib/pair_live_ownership.sh
. tests/lib/private_command_capture.sh
. tests/lib/conformance_private_command.sh
. conformance/asserts.sh
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" 'WPForms native authored-state Apply evidence' 'wprism-wpf-apply'
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml)
PAIR_COMPOSE=("${COMPOSE[@]}")
WPRISM_ARTIFACT_LIBRARY_ROOT="$REPO_ROOT" CONF_PAIR="$PAIR"
capture() { capture_status 0 "$@"; }
capture_status() {
  local expected="$1" name="$2" status=0 suffix
  shift 2
  for suffix in stdout stderr exit; do (umask 077; set -C; : >"$sink/$name.$suffix"); done
  wprism_private_capture_stage "$sink" "$name" "$@" || status=$?
  [ "$status" -eq "$expected" ] || fail "native $name exited $status (expected $expected); retained $sink/$name"
  php -r 'require $argv[1]; require $argv[2]; WPrismTest\PrivateCommandOutput::readBytes($argv[3], WPFormsApplyEvidence::stderrPattern($argv[4], $argv[5], $argv[6]), profile: preg_match("/^settings-(?:general|validation)[12]$/D", $argv[6]) === 1 ? WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE : WPrismTest\EvidenceSizeProfile::COMPACT, expectedExit: (int) $argv[7]);' \
    "$REPO_ROOT/sandbox/tests/lib/PrivateCommandOutput.php" "$PACKAGE_ROOT/fixtures/location-provider/apply-evidence.php" \
    "$sink/$name" "$PAIR" "$REPO_ROOT" "$name" "$expected"
}
wp_side() { local side="$1"; shift; "${COMPOSE[@]}" run --rm -T "cli$side" wp "$@"; }
candidate() {
  local side="$1" command="$2"; shift 2
  conformance_private_command "cli$side" "$command" wp_side "$side" wprism "$command" "$@"
}
fixture=/var/www/html/wp-content/mu-plugins/adapter-packages/wpforms-lite/fixtures/location-provider/native-apply.php
native() { local side="$1"; shift; wp_side "$side" eval-file "$fixture" "$@" "$TARGET_KIND" "$PAIR" "$SETTINGS" --use-include --user=admin; }
zip="${WPFORMS_APPLY_ZIP:?locked local WPForms 2.0.1.1 zip required}"
[[ "$zip" = /* ]] && [ -f "$zip" ] || fail 'absolute native artifact path required'
[ "$(shasum -a 256 "$zip" | cut -d ' ' -f 1)" = 6245074790df01a6e24a42587e024132b4a28fac499d1a8fa12ebf5580e4852b ] || fail 'wrong locked native plugin artifact'
sink=$(umask 077; mktemp -d "$REPO_ROOT/sandbox/tmp/wpforms-apply-native.XXXXXX")
printf 'Retained source-bound %s native streams: %s\n' "$EXPECTED_SHA" "$sink"
WPRISM_DB_ENGINE=mariadb bash bin/pair.sh list
pair_live_ownership_acquire mariadb
pair_live_ownership_up --headless
for side in 1 2; do
  capture "cron$side" wp_side "$side" config set DISABLE_WP_CRON true --raw
  capture "install$side" "${COMPOSE[@]}" run --rm -T -v "$zip:/wpforms-lite.zip:ro" "cli$side" wp plugin install /wpforms-lite.zip --activate
  if [ "$SETTINGS" = 1 ]; then
    capture "debug$side" wp_side "$side" config set WP_DEBUG true --raw
    capture "debug-log$side" wp_side "$side" config set WP_DEBUG_LOG true --raw
    capture "debug-display$side" wp_side "$side" config set WP_DEBUG_DISPLAY false --raw
  fi
  role=source; [ "$side" = 1 ] || role=target
  capture "setup$side" native "$side" setup "$role"
  capture "seed$side" native "$side" seed "$role"
  if [ "$SETTINGS" = 1 ]; then
    capture "settings-general$side" native "$side" settings-author "$role-general"
    capture "settings-validation$side" native "$side" settings-author "$role-validation"
    [ "$side" = 1 ] || capture settings-local2 native 2 settings-local target
  fi
done
capture before2 native 2 observe before
pair_live_ownership_repo_host
R1="$PAIR_LIVE_OWNERSHIP_SITE1" R2="$PAIR_LIVE_OWNERSHIP_SITE2"
cp site-repo.gitignore.template "$R1/.gitignore"
git -C "$R1" init -q -b evidence
git -C "$R2" init -q -b evidence
for case_name in baseline embeds widgets routing; do
  [ "$case_name" = baseline ] || capture "$case_name-mutate" native 1 mutate "$case_name"
  if [ "$case_name" = baseline ]; then
    capture binding1 establish_core_environment_bindings wp_side /siterepo admin@example.test \
      "http://${PAIR}1.invalid" "http://${PAIR}1.invalid" 1
  fi
  capture "$case_name-capture" candidate 1 capture --repo=/siterepo --format=json
  capture "$case_name-source" native 1 observe "$case_name"
  capture "$case_name-contract" native 1 contract "$case_name"
  pair_live_ownership_repo_host
  git -C "$R1" add site.wprism.json .gitignore state
  git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm "Native WPForms $case_name"
  git -C "$R2" fetch -q "$R1" evidence
  git -C "$R2" merge -q --ff-only FETCH_HEAD
  if [ "$case_name" = baseline ]; then
    capture binding2 establish_core_environment_bindings wp_side /siterepo admin@example.test \
      "http://${PAIR}2.invalid" "http://${PAIR}2.invalid" 2
    if [ "$TARGET_KIND" = empty ]; then
      capture refusal-before native 2 observe before
      capture refusal-prepare native 2 prepare-refusal baseline
      pair_live_ownership_repo_host
      (umask 077; mkdir "$sink/refusal.source")
      cp "$R2/site.wprism.json" "$sink/refusal.source/site.wprism.json"
      cp -R "$R2/state" "$sink/refusal.source/state"
      capture_status 1 refusal-apply candidate 2 apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --format=json
      capture refusal-after native 2 observe before
      capture refusal-restore native 2 restore-refusal baseline
    fi
  fi
  capture "$case_name-plan" candidate 2 plan --repo=/siterepo --adopt-by-slug=posts,terms --format=json
  capture "$case_name-apply" candidate 2 apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --format=json
  capture "$case_name-target" native 2 observe "$case_name"
  capture "$case_name-repeat" candidate 2 apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --format=json
  capture "$case_name-stable" native 2 observe "$case_name"
  capture "$case_name-recapture" candidate 2 capture --repo=/siterepo --out="/siterepo/.tmp-$case_name-recapture" --format=json
  capture "$case_name-source-repeat" candidate 1 capture --repo=/siterepo --out="/siterepo/.tmp-$case_name-repeat" --format=json
  pair_live_ownership_repo_host
  for side in source target source-repeat; do
    mkdir "$sink/$case_name.$side"
    cp "$R1/site.wprism.json" "$sink/$case_name.$side/site.wprism.json"
    state="$R1/state"
    [ "$side" != target ] || state="$R2/.tmp-$case_name-recapture"
    [ "$side" != source-repeat ] || state="$R1/.tmp-$case_name-repeat"
    cp -R "$state" "$sink/$case_name.$side/state"
  done
done
php "$PACKAGE_ROOT/fixtures/location-provider/apply-evidence.php" --admit "$sink" "$PAIR" "$TARGET_KIND" "$SETTINGS"
pair_live_ownership_complete 'REGRESS_WPFORMS_LOCATION_APPLY PASSED'
