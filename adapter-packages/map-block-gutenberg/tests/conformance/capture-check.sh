#!/usr/bin/env bash
set -euo pipefail
[ -d "$CONF_REPO1/state/posts/page" ] || fail 'captured map page directory missing'
if rg -q 'map-fixture-source-key|private-source-value|gmw-map-block-key' "$CONF_REPO1/state"; then
  fail 'captured state contains source credential material'
fi
rg -q '@env' "$CONF_REPO1/state/posts/page" || fail 'canonical binding missing'
MAP_SAVED=$(wp_conf1 eval-file /siterepo/.tmp-map-probe.php malformed --use-include)
require_observed_nonempty "Map Block saved-content premise" "$MAP_SAVED"
jq -e '.post > 0 and .body_has_map' <<<"$MAP_SAVED" >/dev/null || fail 'malformed map fixture absent'
MAP_EXIT=0
wp_conf1 wprism capture --repo=/siterepo >"$CONF_REPO1/.tmp-map-refusal.log" 2>&1 || MAP_EXIT=$?
[ "$MAP_EXIT" -ne 0 ] || fail 'unknown map schema was captured'
rg -q 'environment-bound static map schema' "$CONF_REPO1/.tmp-map-refusal.log" || fail 'map schema refusal missing'
if rg -q 'private-source-value|map-fixture-source-key' "$CONF_REPO1/.tmp-map-refusal.log"; then fail 'refusal exposed source data'; fi
git -C "$CONF_REPO1" diff --exit-code -- state >/dev/null || fail 'refused capture changed published state'
[ -z "$(git -C "$CONF_REPO1" ls-files --others --exclude-standard -- state)" ] || fail 'refused capture published new state files'
MAP_RESTORED=$(wp_conf1 eval-file /siterepo/.tmp-map-probe.php restore --use-include)
require_observed_nonempty "Map Block restored-source premise" "$MAP_RESTORED"
jq -e '.post > 0 and .body_has_map and .option_matches' <<<"$MAP_RESTORED" >/dev/null || fail 'map fixture restore failed'
wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-map-recapture >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO1/.tmp-map-recapture" || fail 'capture recovery changed canonical bytes'
pass 'source key omitted; hostile capture refuses atomically; restored recapture is exact'
