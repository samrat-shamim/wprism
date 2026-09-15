#!/usr/bin/env bash
set -euo pipefail
. tests/lib/conformance_private_command.sh
SCENARIO_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
REPOSITORY_ROOT=$(cd "$SCENARIO_ROOT/../.." && pwd -P)
SCENARIO_TARGET="$CONF_REPO2/.tmp-qi-vp-roundtrip"
bash "$REPOSITORY_ROOT/adapter-packages/visual-portfolio/tests/conformance/check.sh"
cp "$CONF_REPO2/.tmp-vp-capture/roundtrip-target.json" "$SCENARIO_TARGET/vp-before-repeat.json"
QI_VP_REV=$(git -C "$CONF_REPO2" rev-parse HEAD)
capture_wprism_json_success QI_VP_REPEAT 'combined zero-write repeated Apply' \
  conformance_private_command cli2 apply wp_conf2 wprism apply --repo=/siterepo \
  --adopt-by-slug=terms,posts --default-author=admin --revision="$QI_VP_REV" --json
printf '%s\n' "$QI_VP_REPEAT" > "$SCENARIO_TARGET/repeat.json"
bash "$REPOSITORY_ROOT/adapter-packages/visual-portfolio/tests/conformance/check.sh"
cp "$CONF_REPO2/.tmp-vp-capture/roundtrip-target.json" "$SCENARIO_TARGET/vp-stable.json"
curl --fail-with-body --silent --show-error --max-time 60 \
  "http://localhost:$CONF1_PORT/qi-native-corpus/" > "$SCENARIO_TARGET/qi-source.html"
curl --fail-with-body --silent --show-error --max-time 60 \
  "http://localhost:$CONF2_PORT/qi-native-corpus/" > "$SCENARIO_TARGET/qi-target.html"
capture_wprism_json_success QI_VP_STABLE 'combined native state after repeat and all HTTP consumers' \
  wp_conf2 eval-file /siterepo/.tmp-qi-roundtrip/native-apply/native.php observe --use-include --user=admin
printf '%s\n' "$QI_VP_STABLE" > "$SCENARIO_TARGET/stable.json"
php "$SCENARIO_ROOT/fixtures/evidence.php" --fixed-point \
  "$SCENARIO_TARGET/apply.json" "$SCENARIO_TARGET/repeat.json" \
  "$SCENARIO_TARGET/qi-source.html" "$SCENARIO_TARGET/qi-target.html" \
  "$CONF_REPO1/.tmp-qi-roundtrip/source.json" "$SCENARIO_TARGET/target.json" "$SCENARIO_TARGET/stable.json" \
  "$SCENARIO_TARGET/vp-before-repeat.json" "$SCENARIO_TARGET/vp-stable.json"
pass 'both native frontends survive the shared fixed point and Qi retains all 204 emitted CSS owner frames'
