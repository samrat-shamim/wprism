#!/usr/bin/env bash
set -euo pipefail
MAP_TARGET=$(wp_conf2 eval-file /siterepo/.tmp-map-probe.php target --use-include)
require_observed_nonempty "Map Block target native observation" "$MAP_TARGET"
jq -e '.post > 0 and .version == "1.35" and .option_matches and .editor_uses_local_key and .body_has_map and .target_key_locations == 2 and .source_key_absent' <<<"$MAP_TARGET" >/dev/null || fail 'native target key binding or editor defaults disagree'
MAP_FRONT=$(curl --fail --silent --show-error "http://localhost:$CONF2_PORT/?page_id=$(jq -r .post <<<"$MAP_TARGET")")
require_observed_nonempty "Map Block target frontend observation" "$MAP_FRONT"
[[ "$MAP_FRONT" == *'www.google.com/maps/embed/v1/place?'* && "$MAP_FRONT" == *'key=map-fixture-target-key'* ]] || fail 'native frontend map missing'
[[ "$MAP_FRONT" != *'map-fixture-source-key'* && "$MAP_FRONT" != *'@env'* ]] || fail 'frontend leaked source or canonical binding'
pass 'native editor default and rendered target iframe use the target key only'
