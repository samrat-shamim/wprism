#!/usr/bin/env bash
# Test-only WP-Cron window. The caller owns a subshell and installs exit/signal
# traps before begin and sources conformance/asserts.sh. Its transport runs sh
# in the selected site's MU directory, outside WordPress; its WP runner proves
# the guard loaded in that same site.
# This prevents new spawn_cron() writes, not pre-existing workers or other
# asynchronous writers. It neither filters database rows nor deletes transients.

# A conformance parent observes both sites through child hooks. Keep each
# guard's authority independently so a failed target cleanup still releases
# the source guard. Indexed arrays retain macOS Bash 3.2 support.
if ! declare -p WPRISM_CRON_WINDOW_NAMES >/dev/null 2>&1; then
  WPRISM_CRON_WINDOW_NAMES=()
  WPRISM_CRON_WINDOW_TOKENS=()
  WPRISM_CRON_WINDOW_TRANSPORTS=()
fi

wordpress_cron_window_compose_transport() { # <cli1|cli2> <remote shell argv...>
  local service="$1"
  local -a compose
  shift
  case "$service" in cli1|cli2) ;; *) return 1 ;; esac
  read -r -a compose <<<"${COMPOSE:?}"
  # Pair bind mounts leave the MU parent root-owned. Only guard installation
  # and exact-byte removal use root; the native WP premise keeps the site uid.
  "${compose[@]}" run --rm -T --no-deps --user root \
    --workdir /var/www/html/wp-content/mu-plugins --entrypoint sh "$service" "$@"
}

wordpress_cron_window_dispatch() { # <prepare|release> <transport> <token>
  # Bash 3.2 misparses case arms inside a heredoc nested in $(...). Keep the
  # remote source outside the response capture so macOS executes these bytes.
  "$2" -s -- "$1" "$3" <<'REMOTE'
set -eu
operation="$1" token="$2"
case "$token" in ''|*[!a-f0-9]*) exit 64 ;; esac
[ "${#token}" -eq 32 ] || exit 64
target=wprism-native-read-window.php
payload() {
  printf '%s\n' '<?php' "// wprism test owner: $token" \
    "define('WPRISM_TEST_CRON_WINDOW_TOKEN', '$token');" \
    "if (!defined('DISABLE_WP_CRON')) { define('DISABLE_WP_CRON', true); }"
}
case "$operation" in
  prepare)
    [ ! -e "$target" ] && [ ! -L "$target" ] || exit 65
    temporary=$(umask 077; mktemp ./wprism-native-read-window.XXXXXX)
    trap 'rm -f -- "$temporary"' EXIT
    payload > "$temporary"
    chmod 0644 "$temporary"
    # Publish complete readable bytes without replacing an occupied name.
    ln "$temporary" "$target"
    ;;
  release)
    if [ -e "$target" ] || [ -L "$target" ]; then
      [ -f "$target" ] && [ ! -L "$target" ] || exit 66
      payload | cmp - "$target" >/dev/null || exit 67
      rm -- "$target"
    fi
    [ ! -e "$target" ] && [ ! -L "$target" ] || exit 68
    ;;
  *) exit 64 ;;
esac
printf '%s:%s\n' "$operation" "$token"
REMOTE
}

wordpress_cron_window_control() { # <prepare|release> <owned window index>
  local operation="$1" index="$2" observed
  observed=$(wordpress_cron_window_dispatch "$operation" \
    "${WPRISM_CRON_WINDOW_TRANSPORTS[$index]}" "${WPRISM_CRON_WINDOW_TOKENS[$index]}") || return 1
  [ "$observed" = "$operation:${WPRISM_CRON_WINDOW_TOKENS[$index]}" ]
}

wordpress_cron_window_assert_quiet() { # <label> <complete native capture>
  ! grep -Eq '(^|[[:space:]])Warning:' <<<"$2"
}

wordpress_cron_window_begin() { # <WP runner> <MU-directory shell transport> [distinct window name]
  local wp_runner="$1" name="${3-default}" observed token index prior
  [[ "$name" =~ ^[a-z][a-z0-9-]{0,63}$ ]] || return 1
  index=${#WPRISM_CRON_WINDOW_NAMES[@]}
  [ "$index" -lt 16 ] || return 1
  if [ "$index" -gt 0 ]; then
    for prior in "${WPRISM_CRON_WINDOW_NAMES[@]}"; do [ "$prior" != "$name" ] || return 1; done
  fi
  token=$(php -r 'echo bin2hex(random_bytes(16));') || return 1
  WPRISM_CRON_WINDOW_NAMES[$index]="$name"
  WPRISM_CRON_WINDOW_TOKENS[$index]="$token"
  WPRISM_CRON_WINDOW_TRANSPORTS[$index]="$2"
  wordpress_cron_window_control prepare "$index" || return 1
  capture_wprism_json_checked observed 'WordPress cron-window native premise' wordpress_cron_window_assert_quiet "$wp_runner" eval \
    'echo json_encode(["disabled"=>defined("DISABLE_WP_CRON") && DISABLE_WP_CRON === true,"owner"=>defined("WPRISM_TEST_CRON_WINDOW_TOKEN") ? WPRISM_TEST_CRON_WINDOW_TOKEN : null]);' || return 1
  jq -e --arg owner "$token" '. == {disabled:true,owner:$owner}' <<<"$observed" >/dev/null
}

wordpress_cron_window_release() {
  local index failed=0
  local -a names=() tokens=() transports=()
  for ((index=${#WPRISM_CRON_WINDOW_NAMES[@]}-1; index>=0; index--)); do
    if ! wordpress_cron_window_control release "$index"; then
      printf 'FAIL: WordPress cron-window cleanup could not prove removal of its owned guard\n' >&2
      failed=1
      names+=("${WPRISM_CRON_WINDOW_NAMES[$index]}")
      tokens+=("${WPRISM_CRON_WINDOW_TOKENS[$index]}")
      transports+=("${WPRISM_CRON_WINDOW_TRANSPORTS[$index]}")
    fi
  done
  WPRISM_CRON_WINDOW_NAMES=() WPRISM_CRON_WINDOW_TOKENS=() WPRISM_CRON_WINDOW_TRANSPORTS=()
  if [ "$failed" -ne 0 ]; then
    WPRISM_CRON_WINDOW_NAMES=("${names[@]}")
    WPRISM_CRON_WINDOW_TOKENS=("${tokens[@]}")
    WPRISM_CRON_WINDOW_TRANSPORTS=("${transports[@]}")
  fi
  [ "$failed" -eq 0 ]
}

wordpress_cron_window_exit() { # <incoming status>; cleanup must not turn failure green
  local status="$1"
  trap - EXIT INT TERM
  wordpress_cron_window_release || { [ "$status" -ne 0 ] || status=1; }
  exit "$status"
}
