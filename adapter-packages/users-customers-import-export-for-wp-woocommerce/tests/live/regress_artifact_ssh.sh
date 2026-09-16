#!/usr/bin/env bash
set -euo pipefail

# The shared adoption suite owns SSH, recovery authority and labeled teardown.
# This capsule establishes native activation only after an inactive installation.
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"

importer_ssh_artifact_capture() { # <unique-stage> <command argv...>
  local stage="importer-artifact-$1" suffix status=0
  shift
  for suffix in stdout stderr exit; do
    (umask 077; set -C; : >"$DIAG_DIR/$stage.$suffix") || fail 'Importer SSH artifact capture collision'
  done
  wprism_private_capture_stage "$DIAG_DIR" "$stage" "$@" || status=$?
  [ "$status" -eq 0 ] || fail "Importer SSH artifact $stage failed; inspect its private capture"
  php "$PACKAGE_ROOT/fixtures/ssh-artifact-evidence.php" admit "$DIAG_DIR/$stage" \
    || fail 'Importer SSH artifact emitted an unexpected diagnostic'
  pass "$stage"
}

wprism_ssh_adopt_extension() {
  local slug=users-customers-import-export-for-wp-woocommerce
  local fixture=/home/wprism/recovery-fixture
  importer_ssh_artifact_capture upload scp -F "$TMP/ssh_config" \
    "$PACKAGE_ROOT/fixtures/admin-context.php" "$PACKAGE_ROOT/fixtures/dependency-native.php" \
    "wprism-adopt-fixture:$fixture/"
  importer_ssh_artifact_capture initial wp_ssh_fixture --skip-plugins --user=admin \
    eval-file "$fixture/dependency-native.php" --use-include
  importer_ssh_artifact_capture install-prior \
    wprism_ssh_install_locked_plugin "$slug" 2.7.4 refusal-fixture inactive
  importer_ssh_artifact_capture prior wp_ssh_fixture --skip-plugins --user=admin \
    eval-file "$fixture/dependency-native.php" --use-include
  importer_ssh_artifact_capture delete-prior wp_ssh_fixture plugin delete "$slug" --quiet
  importer_ssh_artifact_capture install-supported \
    wprism_ssh_install_locked_plugin "$slug" 2.7.5 certified-boundary inactive
  importer_ssh_artifact_capture supported wp_ssh_fixture --skip-plugins --user=admin \
    eval-file "$fixture/dependency-native.php" --use-include
  importer_ssh_artifact_capture activate wp_ssh_fixture --require="$fixture/admin-context.php" --user=admin \
    plugin activate "$slug" --quiet
  importer_ssh_artifact_capture activated wp_ssh_fixture --skip-plugins --user=admin \
    eval-file "$fixture/dependency-native.php" --use-include
  importer_ssh_artifact_capture admin wp_ssh_fixture --require="$fixture/admin-context.php" --user=admin \
    eval-file "$fixture/dependency-native.php" --use-include
  php "$PACKAGE_ROOT/fixtures/ssh-artifact-evidence.php" verify "$DIAG_DIR"
}
