#!/usr/bin/env bash
set -euo pipefail
MAP_CAPSULE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cp "$MAP_CAPSULE/fixtures/source-probe.php" "$CONF_REPO2/.tmp-map-probe.php"
MAP_DIRTY=$(wp_conf2 eval-file /siterepo/.tmp-map-probe.php target-seed --use-include)
require_observed_nonempty "Map Block dirty target premise" "$MAP_DIRTY"
jq -e '.post == 7001 and .runtime_preserved and (.native_post_hash | length == 64)' <<<"$MAP_DIRTY" >/dev/null || fail 'dirty target premise failed'
capture_wprism_json_refusal MAP_MISSING 'missing map environment binding' \
  wp_conf2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --format=json
jq -e '.reason_code == "apply_refused" and .message == "block environment binding is missing or differs from target-local intent"' <<<"$MAP_MISSING" >/dev/null || fail 'missing target binding did not reach its exact gate'
MAP_AFTER=$(wp_conf2 eval-file /siterepo/.tmp-map-probe.php target --use-include)
require_observed_nonempty "Map Block refused target observation" "$MAP_AFTER"
[ "$(jq -r .native_post_hash <<<"$MAP_DIRTY")" = "$(jq -r .native_post_hash <<<"$MAP_AFTER")" ] || fail 'missing key refusal changed unmanaged target post'
printf '%s\n' 'map-fixture-target-key' | wp_conf2 wprism env-set --repo=/siterepo --name=gmw-map-block-key --stdin >/dev/null
wp_conf2 eval-file /siterepo/.tmp-map-probe.php target-drift --use-include >/dev/null
capture_wprism_json_refusal MAP_DRIFT 'drifted map environment binding' \
  wp_conf2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --format=json
jq -e '.reason_code == "apply_refused" and .message == "block environment binding is missing or differs from target-local intent"' <<<"$MAP_DRIFT" >/dev/null || fail 'drifted target key did not reach its exact gate'
MAP_AFTER=$(wp_conf2 eval-file /siterepo/.tmp-map-probe.php target --use-include)
require_observed_nonempty "Map Block drift refusal observation" "$MAP_AFTER"
[ "$(jq -r .native_post_hash <<<"$MAP_DIRTY")" = "$(jq -r .native_post_hash <<<"$MAP_AFTER")" ] || fail 'drift refusal changed target post'
printf '%s\n' 'map-fixture-target-key' | wp_conf2 wprism env-set --repo=/siterepo --name=gmw-map-block-key --stdin >/dev/null
pass 'target API key provisioned through the product env-set path'
