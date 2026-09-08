#!/usr/bin/env bash
set -euo pipefail
capture_wprism_json_success QI_CAPTURE 'Qi complete native canonical source proof' wp_conf1 eval-file /siterepo/.tmp-qi-native/native.php capture --use-include --user=admin
printf '%s\n' "$QI_CAPTURE"
pass 'Qi complete standalone native body and PHP style values agree with canonical source state'
