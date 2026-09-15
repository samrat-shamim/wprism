#!/usr/bin/env bash
set -euo pipefail
VP_CAPSULE=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
capture_wprism_json_success VP_VERIFY1 'Visual Portfolio complete canonical source readback' wp_conf1 eval-file /siterepo/.tmp-vp-capture/verify.php --use-include --user=admin
capture_wprism_json_success VP_OBSERVED1 'Visual Portfolio complete native source observation' wp_conf1 eval-file /siterepo/.tmp-vp-capture/setup.php observe --use-include --user=admin
printf '%s\n' "$VP_VERIFY1" "$VP_OBSERVED1"
capture_wprism_json_success VP_RECAPTURE 'Visual Portfolio public repeated Capture' wp_conf1 wprism capture --repo=/siterepo --format=json
capture_wprism_json_success VP_VERIFY2 'Visual Portfolio complete repeated canonical readback' wp_conf1 eval-file /siterepo/.tmp-vp-capture/verify.php --use-include --user=admin
capture_wprism_json_success VP_OBSERVED2 'Visual Portfolio complete repeated native observation' wp_conf1 eval-file /siterepo/.tmp-vp-capture/setup.php observe --use-include --user=admin
printf '%s\n' "$VP_VERIFY2" "$VP_OBSERVED2"
[ "$VP_VERIFY1" = "$VP_VERIFY2" ] || fail 'Visual Portfolio canonical state or compiled artifact changed on repeated Capture'
[ "$VP_OBSERVED1" = "$VP_OBSERVED2" ] || fail 'Visual Portfolio native state changed on repeated Capture'
pass 'Visual Portfolio source captures and compiles with complete native and canonical fixed points'

capture_wprism_json_success VP_ROUNDTRIP_SOURCE 'Visual Portfolio independent native source roles' wp_conf1 eval-file /siterepo/.tmp-vp-capture/roundtrip-native.php observe --use-include --user=admin
printf '%s\n' "$VP_ROUNDTRIP_SOURCE" > "$CONF_REPO1/.tmp-vp-capture/roundtrip-source.json"

cp "$VP_CAPSULE/../../sandbox/tests/live/apply/native_image_geometry.php" "$CONF_REPO1/.tmp-vp-capture/"
capture_wprism_json_success VP_GEOMETRY 'native Core bounded resize geometry' wp_conf1 eval-file /siterepo/.tmp-vp-capture/native_image_geometry.php --use-include --user=admin
printf '%s\n' "$VP_GEOMETRY"
