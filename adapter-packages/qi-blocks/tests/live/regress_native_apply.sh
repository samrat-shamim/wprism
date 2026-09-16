#!/usr/bin/env bash
# One disposable exact-source pair. Native REST writers, full divergent-ID
# Apply, repeat, recapture and HTTP CSS; browser/editor evidence is separate.
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
REPO_ROOT="$(cd "$PACKAGE_ROOT/../.." && pwd -P)"
cd "$REPO_ROOT/sandbox"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
PAIR="${QI_APPLY_PAIR:?unique owned pair required}"
PORT1="${QI_APPLY_PORT1:?even port required}"
PORT2="${QI_APPLY_PORT2:?successor port required}"
EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:?exact candidate SHA required}"
[[ "$EXPECTED_SHA" =~ ^[a-f0-9]{40}$ ]] && [ "$(git rev-parse HEAD)" = "$EXPECTED_SHA" ] || fail 'wrong native evidence source'
[ -z "$(git status --porcelain=v1 --untracked-files=all)" ] || fail 'dirty native evidence source'
export WPRISM_SOURCE_ROOT="$REPO_ROOT" WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" WPRISM_CODEBIND_PLUGIN=''
export WPRISM_ARTIFACT_LIBRARY_ROOT="$REPO_ROOT" CONF_PAIR="$PAIR"
. tests/lib/pair_live_ownership.sh
. tests/lib/private_command_capture.sh
. tests/lib/conformance_private_command.sh
. conformance/asserts.sh
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" 'Qi native content-only Apply evidence' wprism-qi-apply
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml)
PAIR_COMPOSE=("${COMPOSE[@]}")
wp_side() { local side="$1"; shift; "${COMPOSE[@]}" run --rm -T "cli$side" wp "$@"; }
candidate() {
  local side="$1" command="$2"; shift 2
  conformance_private_command "cli$side" "$command" wp_side "$side" wprism "$command" "$@"
}
sink=$(umask 077; mktemp -d "$REPO_ROOT/sandbox/tmp/qi-native-apply.XXXXXX")
printf 'Retained source-bound %s native streams: %s\n' "$EXPECTED_SHA" "$sink"
capture() {
  local name="$1" verb="$2" result=0 suffix; shift 2
  for suffix in stdout stderr exit; do (umask 077; set -C; : > "$sink/$name.$suffix"); done
  wprism_private_capture_stage "$sink" "$name" "$@" || result=$?
  [ "$result" -eq 0 ] || fail "native $name exited $result; evidence retained in $sink"
  php "$PACKAGE_ROOT/fixtures/native-apply/evidence.php" --stream "$sink/$name" "$PAIR" "$REPO_ROOT" "$verb"
  printf 'ok: %s\n' "$name"
}
snapshot() {
  local name="$1" site="$2" state="$3"
  pair_live_ownership_repo_host
  mkdir "$sink/$name"
  cp "$site/site.wprism.json" "$sink/$name/site.wprism.json"
  cp -R "$state" "$sink/$name/state"
  # The target's own content-addressed media catalog must survive teardown.
  # Copying source/media here would hide target-only or missing media blobs.
  cp -R "$site/media" "$sink/$name/media"
}
zip="${QI_APPLY_ZIP:?locked local Qi 1.5.2 zip required}"
[[ "$zip" = /* ]] && [ -f "$zip" ] || fail 'absolute native artifact path required'
[ "$(shasum -a 256 "$zip" | cut -d ' ' -f 1)" = 6168357231ad0d39e41bcf71b1a0d4ad0fa08a5cc4263cc708dfe198b1f1f887 ] || fail 'wrong locked Qi artifact'
WPRISM_DB_ENGINE=mariadb bash bin/pair.sh list
pair_live_ownership_acquire mariadb
pair_live_ownership_up
for side in 1 2; do
  capture "cron$side" '' wp_side "$side" config set DISABLE_WP_CRON true --raw
  capture "empty$side" '' wp_side "$side" site empty --yes
  capture "install$side" '' "${COMPOSE[@]}" run --rm -T -v "$zip:/qi-blocks.zip:ro" "cli$side" wp plugin install /qi-blocks.zip --activate
  dest="$PAIR_LIVE_OWNERSHIP_SITE_ROOT/$PAIR$side/.tmp-qi-native"
  mkdir "$dest"
  cp "$PACKAGE_ROOT/fixtures/conformance/"*.php "$dest/"
  cp "$PACKAGE_ROOT/fixtures/native-blocks.html" "$PACKAGE_ROOT/fixtures/native-options.json" "$dest/"
  cp "$PACKAGE_ROOT/fixtures/native-apply/native.php" "$dest/apply-native.php"
  role=source; [ "$side" = 1 ] || role=target
  capture "setup$side" '' wp_side "$side" eval-file /siterepo/.tmp-qi-native/apply-native.php "setup-$role" --use-include --user=admin
done
capture seed1 '' wp_side 1 eval-file /siterepo/.tmp-qi-native/native.php seed --use-include --user=admin
capture styles1 '' wp_side 1 eval-file /siterepo/.tmp-qi-native/native.php styles --use-include --user=admin
capture native1 '' wp_side 1 eval-file /siterepo/.tmp-qi-native/native.php observe --use-include --user=admin
capture before2 '' wp_side 2 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
pair_live_ownership_repo_host
R1="$PAIR_LIVE_OWNERSHIP_SITE1" R2="$PAIR_LIVE_OWNERSHIP_SITE2"
cp site-repo.gitignore.template "$R1/.gitignore"
git -C "$R1" init -q -b evidence
git -C "$R2" init -q -b evidence
capture binding1 '' establish_core_environment_bindings wp_side /siterepo admin@example.test "http://localhost:$PORT1" "http://localhost:$PORT1" 1
capture capture1 capture candidate 1 capture --repo=/siterepo --format=json
capture source1 '' wp_side 1 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
snapshot source "$R1" "$R1/state"
git -C "$R1" add site.wprism.json .gitignore state media
git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'Qi native source corpus'
git -C "$R2" fetch -q "$R1" evidence
git -C "$R2" merge -q --ff-only FETCH_HEAD
capture binding2 '' establish_core_environment_bindings wp_side /siterepo admin@example.test "http://localhost:$PORT2" "http://localhost:$PORT2" 2
snapshot target-input "$R2" "$R2/state"
capture plan2 plan candidate 2 plan --repo=/siterepo --adopt-by-slug=posts,terms --format=json
capture apply2 apply candidate 2 apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --format=json
capture target2 '' wp_side 2 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
capture repeat2 apply candidate 2 apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --format=json
capture stable2 '' wp_side 2 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
capture recapture2 capture candidate 2 capture --repo=/siterepo --out=/siterepo/.tmp-qi-recapture --format=json
snapshot target "$R2" "$R2/.tmp-qi-recapture"
capture source-repeat1 capture candidate 1 capture --repo=/siterepo --out=/siterepo/.tmp-qi-source-repeat --format=json
snapshot source-repeat "$R1" "$R1/.tmp-qi-source-repeat"
capture source-http '' curl --fail-with-body --silent --show-error --max-time 60 "http://localhost:$PORT1/qi-native-corpus/"
capture target-http '' curl --fail-with-body --silent --show-error --max-time 60 "http://localhost:$PORT2/qi-native-corpus/"
capture source-http-after '' wp_side 1 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
capture target-http-after '' wp_side 2 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
capture render-recapture2 capture candidate 2 capture --repo=/siterepo --out=/siterepo/.tmp-qi-render-recapture --format=json
snapshot render-target "$R2" "$R2/.tmp-qi-render-recapture"
capture render-repeat1 capture candidate 1 capture --repo=/siterepo --out=/siterepo/.tmp-qi-render-repeat --format=json
snapshot render-source "$R1" "$R1/.tmp-qi-render-repeat"
php "$PACKAGE_ROOT/fixtures/native-apply/evidence.php" --admit "$sink" "$PAIR"
# The picker saves response metadata absent from the registry's empty defaults.
# Replay all six retained picker shapes, including signature and both patterns,
# before custom crop work. Empty defaults cannot qualify selected controls.
dest="$PAIR_LIVE_OWNERSHIP_SITE1/.tmp-qi-native"
mkdir "$dest/native-controls" "$dest/conformance"
cp "$PACKAGE_ROOT/fixtures/native-controls/"*.php "$PACKAGE_ROOT/fixtures/native-controls/blocks.html" "$dest/native-controls/"
cp "$PACKAGE_ROOT/fixtures/conformance/corpus.php" "$dest/conformance/"
capture controls-seed '' wp_side 1 eval-file /siterepo/.tmp-qi-native/native-controls/native.php --use-include --user=admin
capture controls-capture capture candidate 1 capture --repo=/siterepo --format=json
capture controls-source '' wp_side 1 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
snapshot controls-source "$R1" "$R1/state"
git -C "$R1" add state media
git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'Qi native full-response gallery controls'
git -C "$R2" fetch -q "$R1" evidence
git -C "$R2" merge -q --ff-only FETCH_HEAD
snapshot controls-input "$R2" "$R2/state"
capture controls-apply apply candidate 2 apply --repo=/siterepo --default-author=admin --format=json
capture controls-target '' wp_side 2 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
capture controls-repeat apply candidate 2 apply --repo=/siterepo --default-author=admin --format=json
for side in 1 2; do
  port="$PORT1"; name=source; [ "$side" = 1 ] || { port="$PORT2"; name=target; }
  capture "controls-http$side" '' curl --fail-with-body --silent --show-error --max-time 60 "http://localhost:$port/qi-native-corpus/"
  url=$(jq -er '.attachment.url' "$sink/controls-$name.stdout")
  capture "controls-image$side" '' curl --fail-with-body --silent --show-error --max-time 60 \
    --output "$sink/controls-image$side.png" --write-out '{"status":%{http_code}}\n' "$url"
done
capture controls-stable '' wp_side 2 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
capture controls-source-stable '' wp_side 1 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
capture controls-recapture capture candidate 2 capture --repo=/siterepo --out=/siterepo/.tmp-qi-controls-recapture --format=json
snapshot controls-target "$R2" "$R2/.tmp-qi-controls-recapture"
capture controls-source-repeat capture candidate 1 capture --repo=/siterepo --out=/siterepo/.tmp-qi-controls-source-repeat --format=json
snapshot controls-source-repeat "$R1" "$R1/.tmp-qi-controls-source-repeat"
php "$PACKAGE_ROOT/fixtures/native-controls/evidence.php" --admit-controls "$sink" "$PAIR"
# The baseline above has no selected crop. Exercise content-only file work on
# that already-converged target; native HTTP must detect an omitted recipe.
for side in 1 2; do
  dest="$PAIR_LIVE_OWNERSHIP_SITE1/.tmp-qi-native"; [ "$side" = 1 ] || dest="$PAIR_LIVE_OWNERSHIP_SITE2/.tmp-qi-native"
  mkdir -p "$dest/native-media" "$dest/conformance"
  cp "$PACKAGE_ROOT/fixtures/native-media/"*.php "$PACKAGE_ROOT/fixtures/native-media/blocks.html" "$dest/native-media/"
  cp "$PACKAGE_ROOT/fixtures/conformance/corpus.php" "$dest/conformance/"
done
capture media-seed '' wp_side 1 eval-file /siterepo/.tmp-qi-native/native-media/native.php seed --use-include --user=admin
capture media-capture capture candidate 1 capture --repo=/siterepo --format=json
capture media-source '' wp_side 1 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
snapshot media-source "$R1" "$R1/state"
git -C "$R1" add state media
git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'Qi native selected crop controls'
git -C "$R2" fetch -q "$R1" evidence
git -C "$R2" merge -q --ff-only FETCH_HEAD
snapshot media-input "$R2" "$R2/state"
capture media-apply apply candidate 2 apply --repo=/siterepo --default-author=admin --format=json
capture media-target '' wp_side 2 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
capture media-repeat apply candidate 2 apply --repo=/siterepo --default-author=admin --format=json
for side in 1 2; do
  port="$PORT1"; [ "$side" = 1 ] || port="$PORT2"
  capture "media-pixels$side" '' wp_side "$side" eval-file /siterepo/.tmp-qi-native/native-media/native.php pixels --use-include --user=admin
  capture "media-page-http$side" '' curl --fail-with-body --silent --show-error --max-time 60 "http://localhost:$port/qi-native-corpus/"
  for index in 0 1 2 3; do
    url=$(jq -er --argjson index "$index" '.images[$index].url' "$sink/media-pixels$side.stdout")
    capture "media-image$side-$index" '' curl --fail-with-body --silent --show-error --max-time 60 \
      --output "$sink/media-image$side-$index.png" --write-out '{"status":%{http_code}}\n' "$url"
  done
done
capture media-stable '' wp_side 2 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
capture media-recapture capture candidate 2 capture --repo=/siterepo --out=/siterepo/.tmp-qi-media-recapture --format=json
snapshot media-target "$R2" "$R2/.tmp-qi-media-recapture"
capture media-source-repeat capture candidate 1 capture --repo=/siterepo --out=/siterepo/.tmp-qi-media-source-repeat --format=json
snapshot media-source-repeat "$R1" "$R1/.tmp-qi-media-source-repeat"
php "$PACKAGE_ROOT/fixtures/native-media/evidence.php" --admit-media "$sink" "$PAIR"
pair_live_ownership_complete 'REGRESS_QI_NATIVE_APPLY PASSED'
