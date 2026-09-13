#!/usr/bin/env bash
# Sourced by the shared matrix driver. Its pair stays owned by that driver;
# the single positive conformance child finishes before the old artifact runs.
VMATRIX_PLUGIN_SLUG=map-block-gutenberg
MAP_MATRIX_CAPSULE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
MAP_MATRIX_ROOT="$(cd "$MAP_MATRIX_CAPSULE/../.." && pwd -P)"
# The driver sources capsules from sandbox/; these literal reviewed imports
# preserve the package validator's closed dependency discovery.
. tests/lib/conformance_private_command.sh
. tests/lib/private_command_capture.sh

version_matrix_preflight() {
  [[ "${WPRISM_EXPECTED_SOURCE_SHA:-}" =~ ^[a-f0-9]{7,40}$ ]] \
    || fail 'Map version matrix requires an exact committed candidate SHA'
  export WPRISM_SOURCE_ROOT="$MAP_MATRIX_ROOT" WPRISM_ARTIFACT_LIBRARY_ROOT="$MAP_MATRIX_ROOT"
  export WPRISM_ARTIFACT_PACKAGE=map-block-gutenberg
  jq -e '.status == "certified"' "$MAP_MATRIX_CAPSULE/package/disposition.json" >/dev/null \
    || fail 'Map version matrix requires the reviewed certified disposition'
  jq -e '.entry.mode == "roundtrip" and .entry.plugins == [{"slug":"map-block-gutenberg","version":"1.35"}]' \
    "$MAP_MATRIX_CAPSULE/tests/conformance/entry.json" >/dev/null \
    || fail 'Map version matrix requires the exact 1.35 host roundtrip entry'
}

map_matrix_evidence() { # <kind> <version> <private stem>
  php "$MAP_MATRIX_CAPSULE/fixtures/version-matrix-evidence.php" "$1" "$PAIR" "$2" "$3"
}

map_matrix_observe() { # <stage> <version>
  wprism_private_capture_stage "$MAP_MATRIX_SINK" "$1-native" \
    wp2 eval-file /siterepo/.tmp-map-lifecycle/lifecycle-native.php observe --use-include \
    || fail 'Map version matrix native observation failed'
  MAP_MATRIX_NATIVE=$(map_matrix_evidence native "$2" "$MAP_MATRIX_SINK/$1-native") \
    || fail 'Map version matrix native premise differs'
  require_observed_nonempty 'Map version matrix native rows' "$MAP_MATRIX_NATIVE"
  wprism_private_capture_stage "$MAP_MATRIX_SINK" "$1-credentials" \
    wp2 eval-file /siterepo/.tmp-map-deletion/deletion-native.php observe --use-include \
    || fail 'Map version matrix credential observation failed'
  MAP_MATRIX_CREDENTIALS=$(map_matrix_evidence credentials "$2" "$MAP_MATRIX_SINK/$1-credentials") \
    || fail 'Map version matrix credential premise differs'
  require_observed_nonempty 'Map version matrix credential intent and artifact' "$MAP_MATRIX_CREDENTIALS"
}

map_matrix_preserved() { # <stage> <version>
  map_matrix_observe "$1" "$2"
  [ "$MAP_MATRIX_NATIVE" = "$MAP_MATRIX_BASE_NATIVE" ] \
    && [ "$MAP_MATRIX_CREDENTIALS" = "$MAP_MATRIX_BASE_CREDENTIALS" ] \
    || fail 'Map version matrix changed native rows, credential intent or retained artifact'
  diff -r "$MAP_MATRIX_SINK/state" "$CONF_REPO2/state" \
    || fail 'Map version matrix changed the canonical tree'
}

map_matrix_capability() { # <stage> <version>
  local result=0 expected=3
  [ "$2" != 1.35 ] || expected=0
  wprism_private_capture_stage "$MAP_MATRIX_SINK" "$1-capability" \
    wp2 wprism capabilities --repo=/siterepo --operation=apply --format=json || result=$?
  [ "$result" = "$expected" ] \
    && map_matrix_evidence capability "$2" "$MAP_MATRIX_SINK/$1-capability" \
    || fail 'Map version matrix capability did not reach the exact version gate'
}

map_matrix_private_validate() { # <baseline|private stem>
  php "$MAP_MATRIX_ROOT/sandbox/tests/lib/conformance_private_command.php" validate apply "$PAIR" cli2 "$1" || return 1
  if [ "${1##*/}" = private ]; then
    php "$MAP_MATRIX_CAPSULE/fixtures/lifecycle-evidence.php" private 1.34 "$PAIR" "$1" || return 1
  fi
}

version_matrix_workflow() {
  export CONF_PAIR="$PAIR" CONF1_PORT="$PORT1" CONF2_PORT="$PORT2"
  export CONF_REPO1="siterepo/${PAIR}1" CONF_REPO2="siterepo/${PAIR}2"
  export CONF_EXPECTED_SOURCE_SHA="${WPRISM_EXPECTED_SOURCE_SHA:?}"
  MAP_MATRIX_SINK=$(umask 077; mktemp -d "$MAP_MATRIX_ROOT/sandbox/tmp/map-version-matrix-$PAIR.XXXXXX")
  # Every retained stream is private before a command starts, including the
  # complete positive run. The final report cannot conceal a nonzero child.
  local stage suffix result=0
  for stage in positive baseline-native baseline-credentials baseline-capability install \
    installed-native installed-credentials old-capability refused-native refused-credentials; do
    for suffix in stdout stderr exit; do (umask 077; set -C; : >"$MAP_MATRIX_SINK/$stage.$suffix"); done
  done
  say 'Map exact 1.35: one complete certified host roundtrip on the matrix pair'
  bash "$MAP_MATRIX_ROOT/sandbox/conformance/run.sh" map-block-gutenberg 2>&1 \
    | tee "$MAP_MATRIX_SINK/positive.stdout" || result=$?
  printf '%s\n' "$result" >"$MAP_MATRIX_SINK/positive.exit"
  map_matrix_evidence positive 1.35 "$MAP_MATRIX_SINK/positive" \
    || fail "Map certified host roundtrip failed; retained evidence: $MAP_MATRIX_SINK"
  VMATRIX_CASES=$((VMATRIX_CASES + 1))

  map_matrix_observe baseline 1.35
  MAP_MATRIX_BASE_NATIVE="$MAP_MATRIX_NATIVE"
  MAP_MATRIX_BASE_CREDENTIALS="$MAP_MATRIX_CREDENTIALS"
  cp -R "$CONF_REPO2/state" "$MAP_MATRIX_SINK/state"
  map_matrix_capability baseline 1.35

  say 'Map official 1.34: exact-artifact replacement, refusal and preservation'
  local archive digest
  digest=$(artifact_library_jq -er '.plugins["map-block-gutenberg"]["1.34"].sha256')
  [[ "$digest" =~ ^[a-f0-9]{64}$ ]] || fail 'Map 1.34 digest pin is missing'
  archive=$(fetch_artifact map-block-gutenberg 1.34 cli2) \
    || fail 'Map official 1.34 artifact resolution failed'
  [ "$archive" = "/artifacts-cache/plugin-map-block-gutenberg-1.34-$digest.zip" ] \
    || fail 'Map official 1.34 artifact path disagrees with its pin'
  # Preserve activation while replacing real plugin bytes. Deactivation would
  # reach authored active_plugins drift before the version-mismatch guard.
  wprism_private_capture_stage "$MAP_MATRIX_SINK" install wp2 plugin install "$archive" --force \
    || fail 'Map official 1.34 replacement failed'
  map_matrix_evidence install 1.34 "$MAP_MATRIX_SINK/install" \
    || fail 'Map official 1.34 replacement lacks clean success evidence'
  map_matrix_preserved installed 1.34
  map_matrix_capability old 1.34
  local -a map_matrix_snapshot=(conformance_private_command_native cli2 apply snapshot)
  local -a map_matrix_collect=(conformance_private_command_native cli2 apply collect)
  local -a map_matrix_validate=(map_matrix_private_validate)
  capture_wprism_json_refusal MAP_MATRIX_REFUSAL 'Map official 1.34 exact private apply refusal' \
    wprism_private_command_capture "$MAP_MATRIX_SINK/refusal" \
      map_matrix_snapshot map_matrix_collect map_matrix_validate -- \
      wp2 wprism apply --repo=/siterepo --default-author=admin --format=json
  map_matrix_preserved refused 1.34
  VMATRIX_CASES=$((VMATRIX_CASES + 1))
  pass "Map 1.35 host roundtrip and official 1.34 refusal preserve target data; evidence: $MAP_MATRIX_SINK"
}
