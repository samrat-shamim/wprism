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
MAP_CAPSULE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MAP_ROOT="$(cd "$MAP_CAPSULE/../.." && pwd)"
mkdir -p "$CONF_REPO2/.tmp-map-lifecycle"
chmod a+rwx "$CONF_REPO2/.tmp-map-lifecycle"
cp "$MAP_CAPSULE/fixtures/lifecycle-native.php" "$MAP_CAPSULE/fixtures/lifecycle-evidence.php" "$CONF_REPO2/.tmp-map-lifecycle/"
# run.sh supplies this literal argv without shell quoting or path operands;
# splitting it into an array preserves its optional offline transport overlay.
read -r -a PAIR_COMPOSE <<<"${COMPOSE:?}"
# check.sh inherits run.sh's sandbox cwd, including these reviewed shared
# transports; literal sources keep package dependency discovery closed.
. tests/lib/conformance_private_command.sh
. tests/lib/private_command_capture.sh
MAP_EXACT_SHA=$(artifact_library_jq -er '.plugins["map-block-gutenberg"]["1.35"].sha256')
[[ "$MAP_EXACT_SHA" =~ ^[a-f0-9]{64}$ ]] || fail 'exact Map Block artifact digest missing'
MAP_EXACT="/artifacts-cache/plugin-map-block-gutenberg-1.35-${MAP_EXACT_SHA}.zip"
capture_wprism_json_success MAP_CACHE 'Map Block exact reinstall artifact' \
  wp_conf2 eval 'echo json_encode(["sha256" => is_file($args[0]) ? hash_file("sha256", $args[0]) : null], JSON_THROW_ON_ERROR);' "$MAP_EXACT"
jq -e --arg sha256 "$MAP_EXACT_SHA" '.sha256 == $sha256' <<<"$MAP_CACHE" >/dev/null \
  || fail 'exact Map Block reinstall archive is missing or has changed'

map_native() {
  capture_wprism_json_success MAP_LIFECYCLE_OBSERVATION 'Map Block native lifecycle probe' \
    wp_conf2 eval-file /siterepo/.tmp-map-lifecycle/lifecycle-native.php "$1" --use-include
  require_observed_nonempty "Map Block lifecycle native observation" "$MAP_LIFECYCLE_OBSERVATION"
  php "$MAP_CAPSULE/fixtures/lifecycle-evidence.php" observation <<<"$MAP_LIFECYCLE_OBSERVATION" \
    || fail 'Map Block lifecycle native premise invalid'
}
map_preserved() { # <active|inactive> <version|missing>
  map_native observe
  jq -e --arg active "$1" --arg version "$2" \
    '.active == ($active == "active") and .installed == (if $version == "missing" then null else $version end)' \
    <<<"$MAP_LIFECYCLE_OBSERVATION" >/dev/null || fail 'Map Block lifecycle installed/active premise differs'
  [ "$(jq -Sc .state <<<"$MAP_LIFECYCLE_OBSERVATION")" = "$MAP_LIFECYCLE_BASELINE" ] \
    || fail 'Map Block lifecycle changed native posts, metadata, identities, state or target-owned options'
}
map_capability() { # <active|inactive> <version|missing>
  local stream='' report='' rc=0
  stream=$(wp_conf2 wprism capabilities --repo=/siterepo --operation=apply --format=json 2>&1) || rc=$?
  require_wprism_answered 'Map Block native dependency capability' json "$stream"
  assert_no_php_runtime_diagnostics 'Map Block native dependency capability' "$stream"
  report=$(awk 'NF { line=$0 } END { print line }' <<<"$stream")
  awk 'NF { last=NR } { lines[NR]=$0 } END { for (i=1; i<last; i++) print lines[i] }' <<<"$stream" >&2
  [ "$rc" = 3 ] || fail 'Map Block capability answer lost its experimental refusal exit'
  php "$MAP_CAPSULE/fixtures/lifecycle-evidence.php" capability "$1" "$2" <<<"$report" \
    || fail 'Map Block native dependency gate disagrees with manufactured target facts'
  map_preserved "$1" "$2"
}
map_private_validate() { # <case> <owned stem>
  php "$MAP_ROOT/sandbox/tests/lib/conformance_private_command.php" validate apply "$CONF_PAIR" cli2 "$2" || return 1
  if [ "${2##*/}" = private ]; then
    php "$MAP_CAPSULE/fixtures/lifecycle-evidence.php" private "$1" "$CONF_PAIR" "$2" || return 1
  fi
}
map_refused() { # <case> <active|inactive> <version|missing>
  local -a map_snapshot=(conformance_private_command_native cli2 apply snapshot)
  local -a map_collect=(conformance_private_command_native cli2 apply collect)
  local -a map_validate=(map_private_validate "$1")
  capture_wprism_json_refusal MAP_LIFECYCLE_REFUSAL 'Map Block native lifecycle apply refusal' \
    wprism_private_command_capture "$MAP_ROOT/sandbox/tmp/map-lifecycle-$CONF_PAIR" \
      map_snapshot map_collect map_validate -- \
      wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json
  # The private validator admits the exact exit, public envelope and complete
  # one-new-record cause graph before the capture helper can publish success.
  jq -e '.command == "apply" and .reason_code == "apply_failed" and .details_redacted == true' \
    <<<"$MAP_LIFECYCLE_REFUSAL" >/dev/null || fail 'Map Block lifecycle public refusal differs'
  map_preserved "$2" "$3"
}

map_native backup
MAP_LIFECYCLE_BASELINE=$(jq -Sc .state <<<"$MAP_LIFECYCLE_OBSERVATION")
map_capability active 1.35
wp_conf2 plugin deactivate map-block-gutenberg
map_capability inactive 1.35
map_refused inactive inactive 1.35
wp_conf2 plugin activate map-block-gutenberg
map_capability active 1.35

# The following are explicit filesystem fault controls, not new upstream
# artifacts or grants to widen the exact 1.35 range.
map_native maximum-header
map_capability active 1.35.1
map_refused 1.35.1 active 1.35.1
map_native restore-header
map_native unreadable-header
map_capability active missing
map_refused missing active missing
map_native restore-header
map_native wrong-basename
map_native activate-wrong
map_native observe
jq -e '.wrong_active and .native_loaded' <<<"$MAP_LIFECYCLE_OBSERVATION" >/dev/null \
  || fail 'wrong-basename control did not really activate native plugin code'
map_capability inactive missing
map_refused wrong-basename inactive missing
map_native restore-basename
wp_conf2 plugin activate map-block-gutenberg
map_capability active 1.35

map_native minimum-header
map_capability active 1.34
map_refused 1.34 active 1.34
map_native restore-header
# Only one release is claimed. Replacing it with the same digest exercises
# supported replacement without inventing a second in-range release.
wp_conf2 plugin install "$MAP_EXACT" --force --activate
map_capability active 1.35
wp_conf2 plugin uninstall map-block-gutenberg --deactivate
map_capability inactive missing
map_refused absent inactive missing
wp_conf2 plugin install "$MAP_EXACT" --activate
map_capability active 1.35

capture_wprism_json_checked MAP_LIFECYCLE_APPLY 'Map Block reinstalled no-op apply' assert_wprism_apply_ready \
  wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json
jq -e '.applied == 0 and .warnings == [] and .plan.update == 0 and .plan.create == 0 and .plan.drift == 0' \
  <<<"$MAP_LIFECYCLE_APPLY" >/dev/null || fail 'Map Block reinstall is not an idempotent authored no-op'
map_preserved active 1.35
wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-map-lifecycle-state >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-map-lifecycle-state" || fail 'Map Block lifecycle recapture changed canonical bytes'
pass 'Map Block native dependency/lifecycle controls preserve target data and exact recapture'
