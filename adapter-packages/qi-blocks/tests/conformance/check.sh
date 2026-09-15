#!/usr/bin/env bash
set -euo pipefail
. tests/lib/conformance_private_command.sh
QI_CAPSULE=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
QI_SOURCE="$CONF_REPO1/.tmp-qi-roundtrip"
QI_TARGET="$CONF_REPO2/.tmp-qi-roundtrip"
QI_REV=$(git -C "$CONF_REPO2" rev-parse HEAD)
capture_wprism_json_success QI_REPEAT 'Qi zero-write repeated Apply' \
  conformance_private_command cli2 apply wp_conf2 wprism apply --repo=/siterepo \
  --adopt-by-slug=terms,posts --default-author=admin --revision="$QI_REV" --json
printf '%s\n' "$QI_REPEAT" > "$QI_TARGET/repeat.json"
curl --fail-with-body --silent --show-error --max-time 60 "http://localhost:$CONF1_PORT/qi-native-corpus/" > "$QI_SOURCE/frontend.html"
curl --fail-with-body --silent --show-error --max-time 60 "http://localhost:$CONF2_PORT/qi-native-corpus/" > "$QI_TARGET/frontend.html"
capture_wprism_json_success QI_STABLE 'Qi complete target state after repeat and HTTP consumption' \
  wp_conf2 eval-file /siterepo/.tmp-qi-roundtrip/native-apply/native.php observe --use-include --user=admin
printf '%s\n' "$QI_STABLE" > "$QI_TARGET/stable.json"
php "$QI_CAPSULE/fixtures/native-apply/evidence.php" --admit-roundtrip-fixed-point \
  "$QI_TARGET/apply.json" "$QI_TARGET/repeat.json" "$QI_SOURCE/frontend.html" "$QI_TARGET/frontend.html" \
  "$QI_SOURCE/source.json" "$QI_TARGET/target.json" "$QI_TARGET/stable.json"
pass 'Qi Apply repeats without writes; complete native state and all 204 emitted CSS owner frames remain exact'
