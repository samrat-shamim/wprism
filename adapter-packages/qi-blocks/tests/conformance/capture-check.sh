#!/usr/bin/env bash
set -euo pipefail
QI_ROUNDTRIP="$CONF_REPO1/.tmp-qi-roundtrip"
capture_wprism_json_success QI_CAPTURE 'Qi complete native canonical source proof' wp_conf1 eval-file /siterepo/.tmp-qi-native/native.php capture --use-include --user=admin
printf '%s\n' "$QI_CAPTURE"
capture_wprism_json_success QI_ROUNDTRIP_SOURCE 'Qi complete native source state' \
  wp_conf1 eval-file /siterepo/.tmp-qi-roundtrip/native-apply/native.php observe --use-include --user=admin
printf '%s\n' "$QI_ROUNDTRIP_SOURCE" > "$QI_ROUNDTRIP/source.json"
pass 'Qi complete standalone native body and PHP style values agree with canonical source state'
