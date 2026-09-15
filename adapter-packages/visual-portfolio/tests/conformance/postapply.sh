#!/usr/bin/env bash
set -euo pipefail
capture_wprism_json_success VP_EDITOR_ROUNDTRIP 'Visual Portfolio target native editor Save and reopen' \
  wp_conf2 eval-file /siterepo/.tmp-vp-capture/roundtrip-native.php editor-roundtrip --use-include --user=admin
jq -e '.format == "wprism-vp-native-editor-roundtrip/v1" and .cursor == "3.8.1" and
  .lazy_loading == "full" and .pages["vp-author-gallery"].blocks == 12 and
  .pages["vp-alternate-archive"].blocks == 1' <<<"$VP_EDITOR_ROUNDTRIP" >/dev/null \
  || fail 'Visual Portfolio target editor did not retain the current authored setting and complete block roster'
pass 'Visual Portfolio current authored settings survive native editor Save and reopen before final recapture'
