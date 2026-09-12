#!/usr/bin/env bash
set -euo pipefail
STATE="$CONF_REPO1/state"
[ -d "$STATE/posts/page" ] || fail 'Map Block ordinary page was not captured'
if grep -rFq 'map-fixture-source-key' "$STATE"; then
  fail 'Map Block environment key entered canonical state'
fi
if grep -rFq 'gmw-map-block-key' "$STATE"; then
  fail 'Map Block environment option entered canonical state'
fi
pass 'Map Block environment key and option are excluded from canonical state'

for MAP_MODE in default explicit nested; do
  MAP_PREMISE=$(wp_conf1 eval-file /siterepo/.tmp-map-probe.php "$MAP_MODE" --use-include)
  require_observed_nonempty "Map Block saved-content premise" "$MAP_PREMISE"
  jq -e --arg mode "$MAP_MODE" '.mode == $mode and .post > 0 and .body_has_map and .option_matches and .editor_uses_local_key' <<<"$MAP_PREMISE" >/dev/null \
    || fail 'Map Block saved-content premise failed'
  MAP_RC=0
  MAP_OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || MAP_RC=$?
  [ "$MAP_RC" -ne 0 ] || fail 'Map Block credential-bearing content was captured'
  grep -Fq "block 'webfactory/map' is unsupported" <<<"$MAP_OUT" \
    || fail "Map Block capture did not answer with its reviewed boundary (exit $MAP_RC)"
  if grep -Fq 'map-fixture-source-key' <<<"$MAP_OUT"; then
    fail 'Map Block refusal disclosed the fixture key'
  fi
  # The committed baseline is independently established by run.sh before
  # this hook. A refusal must leave every published state byte unchanged.
  git -C "$CONF_REPO1" diff --exit-code -- state >/dev/null \
    || fail 'Map Block refusal changed published state'
  [ -z "$(git -C "$CONF_REPO1" ls-files --others --exclude-standard state)" ] \
    || fail 'Map Block refusal left an untracked published state file'
  pass "Map Block $MAP_MODE capture refuses without publishing state or disclosing its key"
done

MAP_RESTORED=$(wp_conf1 eval-file /siterepo/.tmp-map-probe.php restore --use-include)
require_observed_nonempty "Map Block restored-source premise" "$MAP_RESTORED"
jq -e '.mode == "restore" and .post > 0 and (.body_has_map | not) and .option_matches and .editor_uses_local_key' <<<"$MAP_RESTORED" >/dev/null \
  || fail 'Map Block source restoration premise failed'
wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-map-recapture >/dev/null
diff -r "$STATE" "$CONF_REPO1/.tmp-map-recapture" \
  || fail 'Map Block source did not recapture byte-identically after refusal recovery'
pass 'Map Block refusal recovery restores exact canonical bytes and retains the source-local editor key'
