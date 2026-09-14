#!/usr/bin/env bash
# Shared capsule phases run as separate child shells; the unique pair name
# binds one private host evidence directory without putting it in site state.
IMPORTER_PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
IMPORTER_ROOT="$(cd "$IMPORTER_PACKAGE_ROOT/../.." && pwd -P)"
: "${CONF_PAIR:?Importer roundtrip requires its owned conformance pair}"
[[ "$CONF_PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail 'unsafe Importer roundtrip pair'
IMPORTER_EVIDENCE="$IMPORTER_ROOT/sandbox/tmp/importer-roundtrip-$CONF_PAIR"
IMPORTER_FIXTURES=/var/www/html/wp-content/mu-plugins/adapter-packages/users-customers-import-export-for-wp-woocommerce/fixtures
. tests/lib/private_command_capture.sh

importer_roundtrip_begin() {
  mkdir -p "$IMPORTER_ROOT/sandbox/tmp"
  (umask 077; mkdir "$IMPORTER_EVIDENCE") || fail 'Importer roundtrip evidence already exists'
}

importer_roundtrip_capture() { # <unique label> <argv...>
  local label="$1" suffix
  shift
  [[ "$label" =~ ^[a-z][a-z0-9-]*$ ]] || fail 'unsafe Importer roundtrip stage'
  for suffix in stdout stderr exit; do
    (umask 077; set -C; : >"$IMPORTER_EVIDENCE/$label.$suffix") || fail 'Importer roundtrip capture collision'
  done
  wprism_private_capture_stage "$IMPORTER_EVIDENCE" "$label" "$@" || fail "Importer roundtrip $label failed; inspect private evidence"
  php "$IMPORTER_PACKAGE_ROOT/fixtures/roundtrip-evidence.php" admit "$IMPORTER_EVIDENCE/$label" "$CONF_PAIR" \
    || fail "Importer roundtrip $label returned unexpected streams"
  pass "Importer roundtrip $label"
}

importer_roundtrip_native() { # <1|2> <fixture> <phase> [args...]
  local side="$1" fixture="$2"
  shift 2
  "wp_conf$side" --require="$IMPORTER_FIXTURES/admin-context.php" --user=admin \
    eval-file "$IMPORTER_FIXTURES/$fixture.php" "$@" --use-include
}

importer_roundtrip_observe() { # <1|2>
  "wp_conf$1" --skip-plugins --user=admin eval-file "$IMPORTER_FIXTURES/settings-native.php" raw-observe --use-include
}
