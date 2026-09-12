#!/usr/bin/env bash
set -euo pipefail
MAP_TARGET=$(wp_conf2 eval-file /siterepo/.tmp-map-probe.php target --use-include)
require_observed_nonempty "Map Block target native observation" "$MAP_TARGET"
jq -e '.post == 7001 and .version == "1.35" and .option_matches and .editor_uses_local_key and .body_has_map and .target_key_locations == 2 and .source_key_absent and .runtime_preserved' <<<"$MAP_TARGET" >/dev/null || fail 'native target key binding, identity adoption, runtime preservation or editor defaults disagree'
jq -e '.created > 7001 and .created_target_bound' <<<"$MAP_TARGET" >/dev/null || fail 'explicit-key source map was not created with a target-local key'
capture_wprism_json_checked MAP_REPEAT 'repeated map apply' assert_wprism_apply_ready \
  wp_conf2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --format=json
jq -e '.applied == 0 and .warnings == [] and .plan.update == 0 and .plan.create == 0 and .plan.drift == 0' <<<"$MAP_REPEAT" >/dev/null || fail 'repeated map apply is not a clean no-op'
MAP_FRONT=$(curl --fail --silent --show-error --location --max-redirs 3 --max-time 30 "http://localhost:$CONF2_PORT/?page_id=$(jq -r .post <<<"$MAP_TARGET")")
require_observed_nonempty "Map Block target frontend observation" "$MAP_FRONT"
[[ "$MAP_FRONT" == *'www.google.com/maps/embed/v1/place?'* && "$MAP_FRONT" == *'key=map-fixture-target-key'* ]] || fail 'native frontend map missing'
[[ "$MAP_FRONT" != *'map-fixture-source-key'* && "$MAP_FRONT" != *'@env'* ]] || fail 'frontend leaked source or canonical binding'
wp_conf2 eval-file /siterepo/.tmp-map-probe.php canonical-update --use-include >/dev/null
capture_wprism_json_checked MAP_UPDATE 'authored map update' assert_wprism_apply_ready \
  wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json
jq -e '.plan.update == 1 and .applied == 1' <<<"$MAP_UPDATE" >/dev/null || fail 'map update did not materialize exactly one authored entity'
MAP_UPDATED=$(wp_conf2 eval-file /siterepo/.tmp-map-probe.php target --use-include)
require_observed_nonempty "Map Block updated native observation" "$MAP_UPDATED"
jq -e '.updated_height and .option_matches and .runtime_preserved and .target_key_locations == 2' <<<"$MAP_UPDATED" >/dev/null || fail 'updated native saver or runtime state disagrees'
wp_conf2 eval-file /siterepo/.tmp-map-probe.php canonical-restore --use-include >/dev/null
capture_wprism_json_checked MAP_RESTORE 'authored map restore' assert_wprism_apply_ready \
  wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json
wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-map-final-state >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-map-final-state" || fail 'update/restore recapture is not byte-identical'
pass 'native editor default and rendered target iframe use the target key only'
