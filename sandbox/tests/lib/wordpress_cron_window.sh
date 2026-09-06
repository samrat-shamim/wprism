#!/usr/bin/env bash
# Test-only WP-Cron window. The caller owns a subshell and installs exit/signal
# traps before begin and sources conformance/asserts.sh. Its transport runs sh
# in the selected site's MU directory, outside WordPress; its WP runner proves
# the guard loaded in that same site.
# This prevents new spawn_cron() writes, not pre-existing workers or other
# asynchronous writers. It neither filters database rows nor deletes transients.

wordpress_cron_window_dispatch() { # <prepare|release>
  # Bash 3.2 misparses case arms inside a heredoc nested in $(...). Keep the
  # remote source outside the response capture so macOS executes these bytes.
  "$WPRISM_CRON_WINDOW_TRANSPORT" -s -- "$1" "$WPRISM_CRON_WINDOW_TOKEN" <<'REMOTE'
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

wordpress_cron_window_control() { # <prepare|release>
  local operation="$1" observed
  observed=$(wordpress_cron_window_dispatch "$operation") || return 1
  [ "$observed" = "$operation:$WPRISM_CRON_WINDOW_TOKEN" ]
}

wordpress_cron_window_assert_quiet() { # <label> <complete native capture>
  ! grep -Eq '(^|[[:space:]])Warning:' <<<"$2"
}

wordpress_cron_window_begin() { # <WP runner> <MU-directory shell transport>
  local wp_runner="$1" observed
  [ -z "${WPRISM_CRON_WINDOW_TOKEN:-}" ] || return 1
  WPRISM_CRON_WINDOW_TOKEN=$(php -r 'echo bin2hex(random_bytes(16));') || return 1
  WPRISM_CRON_WINDOW_TRANSPORT="$2"
  wordpress_cron_window_control prepare || return 1
  capture_wprism_json_checked observed 'WordPress cron-window native premise' wordpress_cron_window_assert_quiet "$wp_runner" eval \
    'echo json_encode(["disabled"=>defined("DISABLE_WP_CRON") && DISABLE_WP_CRON === true,"owner"=>defined("WPRISM_TEST_CRON_WINDOW_TOKEN") ? WPRISM_TEST_CRON_WINDOW_TOKEN : null]);' || return 1
  jq -e --arg owner "$WPRISM_CRON_WINDOW_TOKEN" '. == {disabled:true,owner:$owner}' <<<"$observed" >/dev/null
}

wordpress_cron_window_exit() { # <incoming status>; cleanup must not turn failure green
  local status="$1"
  trap - EXIT INT TERM
  if [ -n "${WPRISM_CRON_WINDOW_TOKEN:-}" ]; then
    if ! wordpress_cron_window_control release; then
      printf 'FAIL: WordPress cron-window cleanup could not prove removal of its owned guard\n' >&2
      [ "$status" -ne 0 ] || status=1
    fi
  fi
  exit "$status"
}
