#!/usr/bin/env bash
. "$(dirname "${BASH_SOURCE[0]}")/roundtrip.sh"
# run.sh exports COMPOSE for child hooks; Bash arrays do not cross that ABI.
# Use the same argv as wp_env, including the optional offline artifact overlay.
read -r -a PAIR_COMPOSE <<<"${COMPOSE:?exact conformance transport required}"
. tests/lib/conformance_private_command.sh

importer_dirty_source_cron_transport() { wordpress_cron_window_compose_transport cli1 "$@"; }
importer_dirty_source_window() {
  . tests/lib/wordpress_cron_window.sh
  trap 'wordpress_cron_window_exit "$?"' EXIT
  trap 'exit 130' INT TERM
  wordpress_cron_window_begin wp_conf1 importer_dirty_source_cron_transport \
    || fail 'Importer source observation requires its owned cron window'
}

importer_dirty_capture() { # <unique stage> <expected exit> <argv...>
  local stage="$1" expected="$2" result=0 suffix
  shift 2
  [[ "$stage" =~ ^[a-z][a-z0-9-]*$ ]] || fail 'unsafe dirty-target evidence stage'
  for suffix in stdout stderr exit; do
    (umask 077; set -C; : >"$IMPORTER_EVIDENCE/$stage.$suffix") || fail 'dirty-target stream collision'
  done
  wprism_private_capture_stage "$IMPORTER_EVIDENCE" "$stage" "$@" || result=$?
  [ "$result" -eq "$expected" ] || fail "Importer $stage exited $result; inspect $IMPORTER_EVIDENCE"
}

importer_dirty_snapshot() { # <unique stage>
  importer_dirty_capture "$1-tables" 0 wp_conf2 db query 'SHOW FULL TABLES' --batch --raw --skip-column-names --quiet
  importer_dirty_capture "$1-database" 0 wp_conf2 db export - --single-transaction --skip-lock-tables --skip-add-locks \
    --skip-dump-date --order-by-primary --hex-blob --complete-insert --skip-extended-insert --quiet
  importer_dirty_capture "$1-state" 0 php "$IMPORTER_PACKAGE_ROOT/fixtures/dependency-evidence.php" snapshot "$IMPORTER_ROOT/sandbox/$CONF_REPO2"
  importer_roundtrip_capture "$1-native" importer_roundtrip_observe 2
}

importer_dirty_refusal() { # <collision|drift|conflict>
  local case="$1" revision
  revision=$(git -C "$CONF_REPO2" rev-parse HEAD)
  importer_dirty_snapshot "$case-before"
  importer_roundtrip_capture "$case-plan" wp_conf2 wprism plan --repo=/siterepo --adopt-by-slug=terms,posts --format=json
  importer_dirty_capture "$case-refusal" 1 conformance_private_command cli2 apply \
    wp_conf2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --revision="$revision" --default-author=admin --format=json
  importer_dirty_snapshot "$case-after"
  php "$IMPORTER_PACKAGE_ROOT/fixtures/dirty-target-evidence.php" refusal "$IMPORTER_EVIDENCE" "$CONF_PAIR" "$case"
}
