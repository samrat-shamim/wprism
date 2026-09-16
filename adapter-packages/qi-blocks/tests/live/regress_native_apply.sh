#!/usr/bin/env bash
# One disposable exact-source pair. Native REST writers, full divergent-ID
# Apply, repeat, recapture and HTTP CSS; browser/editor evidence is separate.
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
. "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/native-apply/setup.sh"
# Replay all seven complete native global-control saves; attributes alone
# would not prove the retained HTML, CSS, media and target-local state.
dest="$PAIR_LIVE_OWNERSHIP_SITE1/.tmp-qi-native"
mkdir "$dest/native-global-controls" "$dest/conformance"
cp "$PACKAGE_ROOT/fixtures/native-global-controls/"*.php "$PACKAGE_ROOT/fixtures/native-global-controls/observations.json" "$dest/native-global-controls/"
cp "$PACKAGE_ROOT/fixtures/conformance/corpus.php" "$dest/conformance/"
global_cases=$(php -r 'require $argv[1]; foreach (array_keys(QiNativeGlobalControlsCorpus::cases(file_get_contents($argv[2]))) as $name) echo $name, "\n";' \
  "$PACKAGE_ROOT/fixtures/native-global-controls/corpus.php" "$PACKAGE_ROOT/fixtures/native-global-controls/observations.json") || fail 'native global case inventory failed'
global_names=()
while IFS= read -r name; do global_names+=("$name"); done <<<"$global_cases"
[ "${#global_names[@]}" -eq 7 ] || fail 'native global case inventory is incomplete'
before_name=target2
for name in "${global_names[@]}"; do
  capture "$name-seed" '' wp_side 1 eval-file /siterepo/.tmp-qi-native/native-global-controls/native.php "$name" --use-include --user=admin
  capture "$name-capture" capture candidate 1 capture --repo=/siterepo --format=json
  capture "$name-source" '' wp_side 1 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
  snapshot "$name-source" "$R1" "$R1/state"
  git -C "$R1" add state media
  git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm "Qi retained native $name writer"
  git -C "$R2" fetch -q "$R1" evidence
  git -C "$R2" merge -q --ff-only FETCH_HEAD
  snapshot "$name-input" "$R2" "$R2/state"
  capture "$name-apply" apply candidate 2 apply --repo=/siterepo --default-author=admin --format=json
  capture "$name-target" '' wp_side 2 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
  capture "$name-repeat" apply candidate 2 apply --repo=/siterepo --default-author=admin --format=json
  for side in 1 2; do
    port="$PORT1"; [ "$side" = 1 ] || port="$PORT2"
    capture "$name-http$side" '' curl --fail-with-body --silent --show-error --max-time 60 "http://localhost:$port/qi-native-corpus/"
  done
  capture "$name-stable" '' wp_side 2 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
  capture "$name-source-stable" '' wp_side 1 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
  capture "$name-recapture" capture candidate 2 capture --repo=/siterepo --out="/siterepo/.tmp-qi-$name-recapture" --format=json
  snapshot "$name-target" "$R2" "$R2/.tmp-qi-$name-recapture"
  capture "$name-source-repeat" capture candidate 1 capture --repo=/siterepo --out="/siterepo/.tmp-qi-$name-source-repeat" --format=json
  snapshot "$name-source-repeat" "$R1" "$R1/.tmp-qi-$name-source-repeat"
  php "$PACKAGE_ROOT/fixtures/native-global-controls/evidence.php" --admit-global "$sink" "$PAIR" "$name" "$before_name"
  before_name="$name-stable"
done
# The picker saves response metadata absent from the registry's empty defaults.
# Replay all six retained picker shapes, including signature and both patterns,
# before custom crop work. Empty defaults cannot qualify selected controls.
dest="$PAIR_LIVE_OWNERSHIP_SITE1/.tmp-qi-native"
mkdir "$dest/native-controls"
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
