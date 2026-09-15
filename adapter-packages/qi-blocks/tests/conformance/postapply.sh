#!/usr/bin/env bash
set -euo pipefail
QI_CAPSULE=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
QI_SOURCE="$CONF_REPO1/.tmp-qi-roundtrip"
QI_TARGET="$CONF_REPO2/.tmp-qi-roundtrip"
printf '%s\n' "$APPLY_JSON" > "$QI_TARGET/apply.json"
capture_wprism_json_success QI_TARGET_STATE 'Qi complete native target state after Apply' \
  wp_conf2 eval-file /siterepo/.tmp-qi-roundtrip/native-apply/native.php observe --use-include --user=admin
printf '%s\n' "$QI_TARGET_STATE" > "$QI_TARGET/target.json"
php "$QI_CAPSULE/fixtures/native-apply/evidence.php" --admit-roundtrip-native \
  "$QI_SOURCE/source.json" "$QI_TARGET/target.json" "$QI_TARGET/before.json" "$QI_SOURCE/source-seed.json" \
  "$QI_CAPSULE/fixtures/native-blocks.html" "$QI_CAPSULE/fixtures/native-options.json"
pass 'Qi full native body, ordered PHP styles, divergent identities, media and target-local witnesses survive Apply'
