#!/usr/bin/env bash
# The shared driver owns one pair. Its conformance child resets that same
# pair, returns with the complete native roundtrip, and leaves it for refusals.
VMATRIX_PLUGIN_SLUG=users-customers-import-export-for-wp-woocommerce
IMPORTER_MATRIX_PACKAGE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
IMPORTER_MATRIX_ROOT="$(cd "$IMPORTER_MATRIX_PACKAGE/../.." && pwd -P)"
. tests/lib/private_command_capture.sh
. tests/lib/conformance_private_command.sh
. tests/lib/wordpress_cron_window.sh

version_matrix_preflight() {
  [[ "${WPRISM_EXPECTED_SOURCE_SHA:-}" =~ ^[a-f0-9]{40}$ ]] \
    && [ "$(git -C "$IMPORTER_MATRIX_ROOT" rev-parse HEAD)" = "$WPRISM_EXPECTED_SOURCE_SHA" ] \
    && [ -z "$(git -C "$IMPORTER_MATRIX_ROOT" status --porcelain=v1 --untracked-files=all)" ] \
    || fail 'Importer version matrix requires the clean exact candidate'
  export WPRISM_SOURCE_ROOT="$IMPORTER_MATRIX_ROOT" WPRISM_ARTIFACT_LIBRARY_ROOT="$IMPORTER_MATRIX_ROOT"
  export WPRISM_ARTIFACT_PACKAGE="$VMATRIX_PLUGIN_SLUG"
  jq -e '.status == "experimental"' "$IMPORTER_MATRIX_PACKAGE/package/disposition.json" >/dev/null \
    || fail 'Importer version matrix requires its reviewed experimental boundary'
  jq -e '.entry.mode == "agent-roundtrip" and .entry.plugins == [{"slug":"users-customers-import-export-for-wp-woocommerce","version":"2.7.5"}]' \
    "$IMPORTER_MATRIX_PACKAGE/tests/conformance/entry.json" >/dev/null \
    || fail 'Importer version matrix requires its exact 2.7.5 agent roundtrip'
}

importer_matrix_capture() { # <unique stage> <expected exit> <command...>
  local stage="$1" expected="$2" result=0 suffix
  shift 2
  for suffix in stdout stderr exit; do
    (umask 077; set -C; : >"$IMPORTER_MATRIX_SINK/$stage.$suffix") || fail 'Importer matrix stream collision'
  done
  wprism_private_capture_stage "$IMPORTER_MATRIX_SINK" "$stage" "$@" || result=$?
  [ "$result" -eq "$expected" ] || fail "Importer matrix $stage exited $result; retained private evidence: $IMPORTER_MATRIX_SINK"
}

importer_matrix_observe() { # <unique stage>
  importer_matrix_capture "$1-tables" 0 wp2 db query 'SHOW FULL TABLES' --batch --raw --skip-column-names --quiet
  importer_matrix_capture "$1-database" 0 wp2 db export - --single-transaction --skip-lock-tables --skip-add-locks \
    --skip-dump-date --order-by-primary --hex-blob --complete-insert --skip-extended-insert --quiet
  importer_matrix_capture "$1-state" 0 php "$IMPORTER_MATRIX_PACKAGE/fixtures/dependency-evidence.php" snapshot \
    "$IMPORTER_MATRIX_ROOT/sandbox/$CONF_REPO2"
  importer_matrix_capture "$1-native" 0 wp2 --skip-plugins --user=admin eval-file \
    "$IMPORTER_MATRIX_FIXTURES/settings-native.php" raw-observe --use-include
}

importer_matrix_cron_transport() {
  "${PAIR_COMPOSE[@]}" run --rm -T --no-deps --user root \
    --workdir /var/www/html/wp-content/mu-plugins --entrypoint sh cli2 "$@"
}

importer_matrix_refusals() (
  local archive digest verb
  # imprvm648a changed only _transient_doing_cron during Apply. Control new
  # test-owned cron launches; keep that row in both complete observations.
  trap 'wordpress_cron_window_exit "$?"' EXIT
  trap 'exit 130' INT TERM
  wordpress_cron_window_begin wp2 importer_matrix_cron_transport \
    || fail 'Importer matrix could not establish its owned cron read window'
  importer_matrix_capture supported 0 wp2 --skip-plugins --user=admin eval-file \
    "$IMPORTER_MATRIX_FIXTURES/dependency-native.php" --use-include

  say 'Importer official 2.7.4: active-code deployment and Apply refuse before mutation'
  digest=$(artifact_library_jq -er '.plugins["users-customers-import-export-for-wp-woocommerce"]["2.7.4"].sha256')
  [[ "$digest" =~ ^[a-f0-9]{64}$ ]] || fail 'Importer refusal artifact digest is absent'
  archive=$(fetch_artifact "$VMATRIX_PLUGIN_SLUG" 2.7.4 cli2) || fail 'Importer refusal artifact resolution failed'
  [ "$archive" = "/artifacts-cache/plugin-$VMATRIX_PLUGIN_SLUG-2.7.4-$digest.zip" ] \
    || fail 'Importer refusal artifact path disagrees with its lock'
  # Keep active_plugins unchanged so Apply reaches the version guard rather
  # than the independently qualified inactive-code lifecycle refusal.
  importer_matrix_capture install 0 wp2 plugin install "$archive" --force
  importer_matrix_capture prior 0 wp2 --skip-plugins --user=admin eval-file \
    "$IMPORTER_MATRIX_FIXTURES/dependency-native.php" --use-include
  php "$IMPORTER_MATRIX_PACKAGE/fixtures/version-matrix-evidence.php" installed "$IMPORTER_MATRIX_SINK" "$PAIR" \
    || fail 'Importer exact active artifact premise failed'
  for verb in deploy apply; do
    importer_matrix_observe "$verb-before"
    importer_matrix_capture "$verb-refusal" 1 conformance_private_command cli2 "$verb" \
      wp2 wprism "$verb" --repo=/siterepo --format=json
    importer_matrix_observe "$verb-after"
    php "$IMPORTER_MATRIX_PACKAGE/fixtures/version-matrix-evidence.php" "$verb" "$IMPORTER_MATRIX_SINK" "$PAIR" \
      || fail "Importer $verb version refusal or complete preservation failed; retained $IMPORTER_MATRIX_SINK"
  done
)

version_matrix_workflow() {
  export CONF_PAIR="$PAIR" CONF1_PORT="$PORT1" CONF2_PORT="$PORT2"
  export CONF_REPO1="siterepo/${PAIR}1" CONF_REPO2="siterepo/${PAIR}2"
  export CONF_EXPECTED_SOURCE_SHA="$WPRISM_EXPECTED_SOURCE_SHA"
  IMPORTER_MATRIX_FIXTURES=/var/www/html/wp-content/mu-plugins/adapter-packages/users-customers-import-export-for-wp-woocommerce/fixtures
  IMPORTER_MATRIX_SINK=$(umask 077; mktemp -d "$IMPORTER_MATRIX_ROOT/sandbox/tmp/importer-version-matrix-$PAIR.XXXXXX")
  local result=0 suffix
  for suffix in stdout stderr exit; do (umask 077; set -C; : >"$IMPORTER_MATRIX_SINK/positive.$suffix"); done
  say 'Importer 2.7.5: complete experimental agent roundtrip and native CSV consumers'
  bash "$IMPORTER_MATRIX_ROOT/sandbox/conformance/run.sh" "$VMATRIX_PLUGIN_SLUG" 2>&1 \
    | tee "$IMPORTER_MATRIX_SINK/positive.stdout" || result=$?
  printf '%s\n' "$result" >"$IMPORTER_MATRIX_SINK/positive.exit"
  php "$IMPORTER_MATRIX_PACKAGE/fixtures/version-matrix-evidence.php" positive "$IMPORTER_MATRIX_SINK" "$PAIR" \
    || fail 'Importer exact supported roundtrip failed'
  VMATRIX_CASES=$((VMATRIX_CASES + 1))
  importer_matrix_refusals
  VMATRIX_CASES=$((VMATRIX_CASES + 2))
  pass "Importer 2.7.5 roundtrip and official active 2.7.4 refusal boundaries; private evidence: $IMPORTER_MATRIX_SINK"
}
