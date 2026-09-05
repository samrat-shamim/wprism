#!/usr/bin/env bash
# A diagnostic lifecycle, not a command-success or expected-cause certificate.
# Owner-supplied argv arrays keep native transport, reader bounds and profiles
# out of this shared shell mechanism. Bash 3.2 indirect array expansion avoids
# eval/command strings and preserves empty, whitespace and quoted arguments.

wprism_private_capture_stage() { # <private directory> <stage> <command argv...>
  local __wprism_private_capture_directory="$1" __wprism_private_capture_stage="$2"
  local __wprism_private_capture_status=0
  shift 2
  ("$@") >"$__wprism_private_capture_directory/$__wprism_private_capture_stage.stdout" \
    2>"$__wprism_private_capture_directory/$__wprism_private_capture_stage.stderr" \
    || __wprism_private_capture_status=$?
  printf '%s\n' "$__wprism_private_capture_status" \
    >"$__wprism_private_capture_directory/$__wprism_private_capture_stage.exit" || return 1
  return "$__wprism_private_capture_status"
}

wprism_private_command_capture() { # <absolute sink prefix> <snapshot argv name> <collect argv name> <validator argv name> -- <command argv...>
  [ "$#" -ge 6 ] && [ "$5" = -- ] || {
    printf 'private command capture requires three explicit callback argv arrays and a command\n' >&2
    return 1
  }
  local __wprism_private_capture_prefix="$1" __wprism_private_capture_name
  local __wprism_private_capture_command_ref
  local __wprism_private_capture_snapshot="$2[@]" __wprism_private_capture_collect="$3[@]"
  local __wprism_private_capture_validator="$4[@]" __wprism_private_capture_directory
  local __wprism_private_capture_stage __wprism_private_capture_suffix __wprism_private_capture_status=0
  [[ "$__wprism_private_capture_prefix" = /* && "${__wprism_private_capture_prefix##*/}" =~ ^[a-z][a-z0-9.-]{0,95}$ ]] \
    && [ -d "${__wprism_private_capture_prefix%/*}" ] || {
    printf 'private command capture requires an existing absolute diagnostic parent and safe sink name\n' >&2
    return 1
  }
  for __wprism_private_capture_name in "$2" "$3" "$4"; do
    [[ "$__wprism_private_capture_name" =~ ^[A-Za-z_][A-Za-z0-9_]*$ \
      && "$__wprism_private_capture_name" != __wprism_private_capture_* ]] \
      && [[ "$(declare -p "$__wprism_private_capture_name" 2>/dev/null)" = 'declare -a '* ]] || {
      printf 'private command capture requires caller-owned indexed callback argv arrays\n' >&2
      return 1
    }
    __wprism_private_capture_command_ref="$__wprism_private_capture_name[0]"
    [ -n "${!__wprism_private_capture_command_ref-}" ] || {
      printf 'private command capture callback argv must start with a nonempty command\n' >&2
      return 1
    }
  done
  shift 5
  __wprism_private_capture_directory=$(umask 077; mktemp -d "$__wprism_private_capture_prefix.XXXXXX") || return 1
  # Scope umask only to allocation: a host command may publish files consumed
  # by a different site uid. regress_private_command_capture.php pins its
  # 0044 readability even when the command exits nonzero after publication.
  for __wprism_private_capture_stage in baseline baseline-check command private private-check; do
    for __wprism_private_capture_suffix in stdout stderr exit; do
      (umask 077; set -C; : >"$__wprism_private_capture_directory/$__wprism_private_capture_stage.$__wprism_private_capture_suffix") || return 1
    done
  done
  wprism_private_capture_stage "$__wprism_private_capture_directory" baseline "${!__wprism_private_capture_snapshot}" \
    && wprism_private_capture_stage "$__wprism_private_capture_directory" baseline-check \
      "${!__wprism_private_capture_validator}" "$__wprism_private_capture_directory/baseline" \
    && [ ! -s "$__wprism_private_capture_directory/baseline-check.stdout" ] \
    && [ ! -s "$__wprism_private_capture_directory/baseline-check.stderr" ] || {
    printf 'private command baseline failed; unverified diagnostics: %s\n' "$__wprism_private_capture_directory" >&2
    return 1
  }
  wprism_private_capture_stage "$__wprism_private_capture_directory" command "$@" \
    || __wprism_private_capture_status=$?
  wprism_private_capture_stage "$__wprism_private_capture_directory" private \
    "${!__wprism_private_capture_collect}" "$__wprism_private_capture_directory/baseline.stdout" \
    && wprism_private_capture_stage "$__wprism_private_capture_directory" private-check \
      "${!__wprism_private_capture_validator}" "$__wprism_private_capture_directory/private" \
    && [ ! -s "$__wprism_private_capture_directory/private-check.stdout" ] \
    && [ ! -s "$__wprism_private_capture_directory/private-check.stderr" ] || {
    printf 'private command collection failed; unverified diagnostics: %s\n' "$__wprism_private_capture_directory" >&2
    return 1
  }
  # Preserve each public stream's bytes; replay stderr first so a native JSON
  # stdout receipt stays the final line of the caller's merged capture. Never
  # replay private reader/validator bytes, even on their nonzero exits.
  printf 'private command diagnostics (unverified): %s\n' "$__wprism_private_capture_directory" >&2
  cat "$__wprism_private_capture_directory/command.stderr" >&2 || return 1
  cat "$__wprism_private_capture_directory/command.stdout" || return 1
  return "$__wprism_private_capture_status"
}
