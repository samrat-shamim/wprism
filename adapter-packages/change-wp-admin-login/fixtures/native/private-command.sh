# Compose transport and private-store collection remain shared. This owner
# contributes only exact expected native refusal profiles.
. tests/lib/conformance_private_command.sh
. tests/lib/private_command_capture.sh
aio_private_validate() {
  local case="$1" service="$2" command="$3" stem="$4"
  php "$WPRISM_SOURCE_ROOT/sandbox/tests/lib/conformance_private_command.php" validate "$command" "$CONF_PAIR" "$service" "$stem" || return 1
  if [ "${stem##*/}" = private ]; then
    (umask 077; php "$WPRISM_SOURCE_ROOT/adapter-packages/change-wp-admin-login/fixtures/native/private-profile.php" "$WPRISM_SOURCE_ROOT" "$CONF_PAIR" "$case" "${stem%/*}" > "$stem.expected-cause.json") || return 1
  fi
}
aio_private_command() {
  local service="$1" command="$2" case="$3"
  shift 3
  local -a aio_private_snapshot=(conformance_private_command_native "$service" "$command" snapshot)
  local -a aio_private_collect=(conformance_private_command_native "$service" "$command" collect)
  local -a aio_private_validator=(aio_private_validate "$case" "$service" "$command")
  wprism_private_command_capture "$WPRISM_SOURCE_ROOT/sandbox/tmp/wprism-aio-$case.$CONF_PAIR" aio_private_snapshot aio_private_collect aio_private_validator -- "$@"
}
