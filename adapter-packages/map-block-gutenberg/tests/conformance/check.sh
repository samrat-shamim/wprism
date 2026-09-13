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
  wp_conf2 eval-file /siterepo/.tmp-map-lifecycle/lifecycle-native.php archive "$MAP_EXACT" --use-include
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
  # The case-specific validator already admitted every public field and the
  # complete private graph, including active_plugins drift before lifecycle.
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

mkdir "$CONF_REPO2/.tmp-map-deletion"
chmod a+rwx "$CONF_REPO2/.tmp-map-deletion"
cp "$MAP_CAPSULE/fixtures/deletion-native.php" "$MAP_CAPSULE/fixtures/deletion-evidence.php" "$CONF_REPO2/.tmp-map-deletion/"
capture_wprism_json_success MAP_DELETE_ARTIFACT 'Map Block retained pre-deletion artifact' \
  wp_conf2 wprism compile --repo=/siterepo --out=/siterepo/.tmp-map-deletion/artifact.json --format=json
jq -e '.format == "wprism-compiled-repository/v1" and (.artifact_hash | type == "string" and test("^[a-f0-9]{64}$"))' \
  <<<"$MAP_DELETE_ARTIFACT" >/dev/null || fail 'Map Block pre-deletion artifact premise missing'
map_delete_observe() {
  capture_wprism_json_success MAP_DELETE_OBSERVATION 'Map Block credential deletion native observation' \
    wp_conf2 eval-file /siterepo/.tmp-map-deletion/deletion-native.php "$1" --use-include
  require_observed_nonempty 'Map Block credential deletion observation' "$MAP_DELETE_OBSERVATION"
  php "$MAP_CAPSULE/fixtures/deletion-evidence.php" observation <<<"$MAP_DELETE_OBSERVATION" \
    || fail 'Map Block credential deletion native premise failed'
}
map_delete_refused() { # <compile|apply> <command-specific args...>
  local command="$1" sink suffix result=0
  shift
  sink=$(umask 077; mktemp -d "$MAP_ROOT/sandbox/tmp/map-delete-$CONF_PAIR.XXXXXX")
  for suffix in stdout stderr exit; do (umask 077; set -C; : >"$sink/command.$suffix"); done
  wprism_private_capture_stage "$sink" command wp_conf2 wprism "$command" --repo=/siterepo --format=json "$@" || result=$?
  # Admit the complete stdout/stderr and exact exit before printing anything;
  # typed authorization findings, unlike a generic apply_failed, name the gate.
  php "$MAP_CAPSULE/fixtures/deletion-evidence.php" transport "$command" "$CONF_PAIR" "$sink/command" \
    || fail "Map Block credential deletion refusal differs; private transport: $sink"
  [ "$result" = 1 ] || fail 'Map Block unsupported credential deletion lost its refusal exit'
  map_delete_observe observe
  [ "$(jq -Sc '[.artifact_sha256,.intent_sha256]' <<<"$MAP_DELETE_OBSERVATION")" = "$MAP_DELETE_PRESERVED" ] \
    || fail 'Map Block credential deletion refusal changed the retained artifact or environment intent'
  [ "$(jq -r .options_sha256 <<<"$MAP_DELETE_OBSERVATION")" = "$MAP_DELETE_INPUT" ] \
    || fail 'Map Block credential deletion refusal changed the rejected source intent'
  diff -r "$CONF_REPO2/.tmp-map-deletion/state-rejected" "$CONF_REPO2/state" \
    || fail 'Map Block credential deletion partially published canonical state'
  map_preserved active 1.35
}
map_delete_observe observe
MAP_DELETE_ORIGINAL=$(jq -r .options_sha256 <<<"$MAP_DELETE_OBSERVATION")
MAP_DELETE_PRESERVED=$(jq -Sc '[.artifact_sha256,.intent_sha256]' <<<"$MAP_DELETE_OBSERVATION")
cp -R "$CONF_REPO2/state" "$CONF_REPO2/.tmp-map-deletion/state-original"
map_delete_observe prepare
jq -e '.deleted' <<<"$MAP_DELETE_OBSERVATION" >/dev/null || fail 'Map Block credential tombstone was not manufactured'
MAP_DELETE_INPUT=$(jq -r .options_sha256 <<<"$MAP_DELETE_OBSERVATION")
[ "$MAP_DELETE_INPUT" != "$MAP_DELETE_ORIGINAL" ] || fail 'Map Block credential deletion did not change source intent'
cp -R "$CONF_REPO2/state" "$CONF_REPO2/.tmp-map-deletion/state-rejected"
map_delete_refused compile --out=/siterepo/.tmp-map-deletion/artifact.json
map_delete_refused apply --default-author=admin --with-deletes
map_delete_refused apply --default-author=admin --with-deletes --force-theirs --force-delete-referenced
map_delete_observe restore
jq -e '.deleted == false' <<<"$MAP_DELETE_OBSERVATION" >/dev/null || fail 'Map Block unsupported credential deletion intent survived restoration'
diff -r "$CONF_REPO2/.tmp-map-deletion/state-original" "$CONF_REPO2/state" || fail 'Map Block deletion restoration changed canonical bytes'
capture_wprism_json_checked MAP_DELETE_RETRY 'Map Block restored deletion no-op apply' assert_wprism_apply_ready \
  wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json
jq -e '.applied == 0 and .warnings == [] and .plan.update == 0 and .plan.create == 0 and .plan.drift == 0' \
  <<<"$MAP_DELETE_RETRY" >/dev/null || fail 'Map Block deletion refusal recovery is not a clean no-op'
map_preserved active 1.35
pass 'Map Block unsupported credential deletion preserves native data, target intent and prior publication'

# Every command below owns this disposable conformance pair. The holder uses
# CapturePublicationWorkflow's existing release barrier, not a guessed race.
mkdir "$CONF_REPO2/.tmp-map-concurrency"
chmod a+rwx "$CONF_REPO2/.tmp-map-concurrency"
cp "$MAP_CAPSULE/fixtures/concurrency-native.php" "$CONF_REPO2/.tmp-map-concurrency/"
MAP_CONCURRENT_SINK=$(umask 077; mktemp -d "$MAP_ROOT/sandbox/tmp/map-concurrent-$CONF_PAIR.XXXXXX")
for MAP_STAGE in holder same other apply retry cleanup; do
  for MAP_SUFFIX in stdout stderr exit; do (umask 077; set -C; : >"$MAP_CONCURRENT_SINK/$MAP_STAGE.$MAP_SUFFIX"); done
done
map_concurrent_phase() { # <expected phase> [release]
  capture_wprism_json_success MAP_CONCURRENT_PHASE 'Map Block capture barrier observation' \
    wp_conf2 eval-file /siterepo/.tmp-map-concurrency/concurrency-native.php "${2:-phase}" --use-include
  require_observed_nonempty 'Map Block capture barrier observation' "$MAP_CONCURRENT_PHASE"
  php "$MAP_CAPSULE/fixtures/concurrency-evidence.php" phase "$1" <<<"$MAP_CONCURRENT_PHASE" \
    || fail 'Map Block capture barrier did not prove its expected phase'
}
map_concurrent_preserved() {
  map_preserved active 1.35
  map_delete_observe observe
  [ "$(jq -Sc '[.artifact_sha256,.intent_sha256]' <<<"$MAP_DELETE_OBSERVATION")" = "$MAP_DELETE_PRESERVED" ] \
    || fail 'Map Block contention changed target credential intent or prior artifact'
  diff -r "$CONF_REPO2/.tmp-map-deletion/state-original" "$CONF_REPO2/state" \
    || fail 'Map Block contention changed canonical repository bytes'
}
map_concurrent_refused() { # <same|other|apply> <wp args...>
  local case_name="$1" result=0
  shift
  wprism_private_capture_stage "$MAP_CONCURRENT_SINK" "$case_name" wp_conf2 "$@" || result=$?
  php "$MAP_CAPSULE/fixtures/concurrency-evidence.php" transport "$case_name" "$CONF_PAIR" "$MAP_CONCURRENT_SINK/$case_name" \
    || fail "Map Block competing command did not return its exact refusal; private transport: $MAP_CONCURRENT_SINK"
  [ "$result" = 1 ] || fail 'Map Block competing command lost its refusal exit'
  map_concurrent_phase locked
  map_concurrent_preserved
}
map_concurrent_clean() { # <host output directory>
  local suffix
  for suffix in .capture-staging .capture-backup .capture-intent .capture-intent.next .capture-intent.previous .capture-receipt.next .capture-receipt.previous; do
    [ ! -e "$1$suffix" ] && [ ! -L "$1$suffix" ] || fail 'Map Block capture left publication recovery residue'
  done
}
MAP_CONCURRENT_PID=''
map_concurrent_cleanup() {
  local result=$?
  trap - EXIT
  if [ -n "$MAP_CONCURRENT_PID" ]; then
    # Retain all transport, even during cleanup. Releasing only this pair's
    # barrier lets its bounded holder exit; no foreign process is killed.
    wprism_private_capture_stage "$MAP_CONCURRENT_SINK" cleanup \
      wp_conf2 eval-file /siterepo/.tmp-map-concurrency/concurrency-native.php release --use-include || true
    wait "$MAP_CONCURRENT_PID" || true
  fi
  exit "$result"
}
trap map_concurrent_cleanup EXIT
map_concurrent_phase ''
wprism_private_capture_stage "$MAP_CONCURRENT_SINK" holder \
  "${PAIR_COMPOSE[@]}" run --rm -T -e WPRISM_TEST_MODE=1 -e WPRISM_TEST_CAPTURE_WAIT_FOR_RELEASE=1 \
  cli2 sh -c 'umask 000; exec wp "$@"' sh wprism capture --repo=/siterepo \
  --out=/siterepo/.tmp-map-concurrency/holder --format=json &
MAP_CONCURRENT_PID=$!
# Poll a typed native answer; malformed/failed reads never count as waiting.
for MAP_ATTEMPT in {1..30}; do
  capture_wprism_json_success MAP_CONCURRENT_PHASE 'Map Block capture holder startup' \
    wp_conf2 eval-file /siterepo/.tmp-map-concurrency/concurrency-native.php phase --use-include
  if php "$MAP_CAPSULE/fixtures/concurrency-evidence.php" phase locked <<<"$MAP_CONCURRENT_PHASE" 2>/dev/null; then break; fi
  php "$MAP_CAPSULE/fixtures/concurrency-evidence.php" phase '' <<<"$MAP_CONCURRENT_PHASE" \
    || fail 'Map Block holder startup reported an invalid phase'
  kill -0 "$MAP_CONCURRENT_PID" 2>/dev/null || fail 'Map Block capture holder exited before overlap'
  sleep 0.1
done
map_concurrent_phase locked
map_concurrent_refused same wprism capture --repo=/siterepo --out=/siterepo/.tmp-map-concurrency/holder --format=json
map_concurrent_refused other wprism capture --repo=/siterepo --out=/siterepo/.tmp-map-concurrency/other --format=json
map_concurrent_refused apply wprism apply --repo=/siterepo --default-author=admin --format=json
for MAP_OUTPUT in holder other; do
  [ ! -e "$CONF_REPO2/.tmp-map-concurrency/$MAP_OUTPUT" ] && [ ! -L "$CONF_REPO2/.tmp-map-concurrency/$MAP_OUTPUT" ] \
    || fail 'Map Block contending capture published before holder release'
  map_concurrent_clean "$CONF_REPO2/.tmp-map-concurrency/$MAP_OUTPUT"
done
map_concurrent_phase release release
MAP_CONCURRENT_RESULT=0
wait "$MAP_CONCURRENT_PID" || MAP_CONCURRENT_RESULT=$?
MAP_CONCURRENT_PID=''
[ "$MAP_CONCURRENT_RESULT" = 0 ] || fail "Map Block capture holder failed; private transport: $MAP_CONCURRENT_SINK"
php "$MAP_CAPSULE/fixtures/concurrency-evidence.php" transport holder "$CONF_PAIR" "$MAP_CONCURRENT_SINK/holder" \
  || fail 'Map Block holder did not return a complete clean capture'
map_concurrent_phase ''
map_concurrent_preserved
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-map-concurrency/holder" || fail 'Map Block winning capture differs from the exact source'
map_concurrent_clean "$CONF_REPO2/.tmp-map-concurrency/holder"
wprism_private_capture_stage "$MAP_CONCURRENT_SINK" retry \
  wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-map-concurrency/other --format=json \
  || fail 'Map Block refused destination did not become retryable'
php "$MAP_CAPSULE/fixtures/concurrency-evidence.php" transport retry "$CONF_PAIR" "$MAP_CONCURRENT_SINK/retry" \
  || fail 'Map Block retry did not return a complete clean capture'
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-map-concurrency/other" || fail 'Map Block capture retry differs from the exact source'
map_concurrent_clean "$CONF_REPO2/.tmp-map-concurrency/other"
capture_wprism_json_checked MAP_CONCURRENT_APPLY 'Map Block post-contention no-op apply' assert_wprism_apply_ready \
  wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json
jq -e '.applied == 0 and .warnings == [] and .plan.update == 0 and .plan.create == 0 and .plan.drift == 0' \
  <<<"$MAP_CONCURRENT_APPLY" >/dev/null || fail 'Map Block contention retry is not an idempotent no-op'
map_concurrent_preserved
trap - EXIT
pass 'Map Block overlapping capture/capture/apply commands serialize and preserve target-owned data'
