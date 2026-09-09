#!/usr/bin/env bash
set -euo pipefail
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
