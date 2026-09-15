#!/usr/bin/env bash
# Inherits run.sh's exact host root, pair and Compose argv. Hook subprocesses
# cannot inherit Bash arrays; run.sh exports the reviewed, literal COMPOSE argv
# for that boundary. Reconstruct it here so every capsule uses one protected
# command transport instead of reimplementing private refusal collection.

conformance_private_command_prepare() {
  if declare -p PAIR_COMPOSE 2>/dev/null | grep -q '^declare -a '; then
    [ "${#PAIR_COMPOSE[@]}" -gt 0 ]
    return
  fi
  [ -n "${COMPOSE:-}" ] || return 1
  read -r -a PAIR_COMPOSE <<<"$COMPOSE"
  [ "${#PAIR_COMPOSE[@]}" -gt 0 ]
}

conformance_private_command_native() { # <service> <verb> <snapshot|collect> [owned baseline stdout]
  local service="$1" command="$2" mode="$3" root="${WPRISM_ARTIFACT_LIBRARY_ROOT:?}"
  conformance_private_command_prepare || return 1
  "${PAIR_COMPOSE[@]}" run --rm -T \
    --volume "$root/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro" \
    --volume "$root/sandbox/tests/lib/conformance_private_command.php:/wprism-test/conformance_private_command.php:ro" \
    --entrypoint php "$service" /wprism-test/conformance_private_command.php \
    "$mode" "$command" /siterepo/.wprism/refusals <"${4:-/dev/null}"
}

conformance_private_command() { # <cli1|cli2> <verb> <protected command argv...>
  local service="$1" command="$2" root="${WPRISM_ARTIFACT_LIBRARY_ROOT:?}"
  shift 2
  case "$service" in cli1|cli2) ;; *) return 1 ;; esac
  [[ "$command" =~ ^[a-z][a-z0-9-]{0,63}$ && "${CONF_PAIR:?}" =~ ^[a-z][a-z0-9]*$ ]] || return 1
  [ "$#" -gt 0 ] || return 1
  local -a conformance_snapshot=(conformance_private_command_native "$service" "$command" snapshot)
  local -a conformance_collect=(conformance_private_command_native "$service" "$command" collect)
  local -a conformance_validate=(php "$root/sandbox/tests/lib/conformance_private_command.php" validate "$command" "$CONF_PAIR" "$service")
  . "$root/sandbox/tests/lib/private_command_capture.sh"
  mkdir -p "$root/sandbox/tmp" || return 1
  wprism_private_command_capture "$root/sandbox/tmp/wprism-conformance-$command.$CONF_PAIR" \
    conformance_snapshot conformance_collect conformance_validate -- "$@"
}
