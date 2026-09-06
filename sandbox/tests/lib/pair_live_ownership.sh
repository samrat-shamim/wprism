#!/usr/bin/env bash
# Shared disposable-pair ownership state machine for direct live evidence.
# The caller validates its product-specific environment and defines fail();
# this helper owns the generic transition from no authority -> exact
# engine/root lease -> partial-or-complete pair -> verified teardown -> PASS.

PAIR_LIVE_OWNERSHIP_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
PAIR_LIVE_OWNERSHIP_SANDBOX="$(cd "$PAIR_LIVE_OWNERSHIP_LIB_DIR/../.." && pwd -P)"
PAIR_LIVE_OWNERSHIP_REPO="$(cd "$PAIR_LIVE_OWNERSHIP_SANDBOX/.." && pwd -P)"

# shellcheck source=../../lib/pair_identity.sh
. "$PAIR_LIVE_OWNERSHIP_SANDBOX/lib/pair_identity.sh"
# shellcheck source=../../lib/pair_db.sh
. "$PAIR_LIVE_OWNERSHIP_SANDBOX/lib/pair_db.sh"
# shellcheck source=../../lib/pair_lease.sh
. "$PAIR_LIVE_OWNERSHIP_SANDBOX/lib/pair_lease.sh"

pair_live_ownership_mode_of() {
  local path="$1" mode
  if mode="$(stat -f '%Lp' "$path" 2>/dev/null)"; then
    : # BSD stat.
  elif mode="$(stat -c '%a' "$path" 2>/dev/null)"; then
    : # GNU stat.
  else
    return 1
  fi
  printf '%s\n' "$mode"
}

pair_live_ownership_prepare() { # <pair> <port1> <port2> <diagnostic-label> <scratch-prefix>
  local pair="$1" port1="$2" port2="$3" label="$4" scratch_prefix="$5"
  [ "$(pwd -P)" = "$PAIR_LIVE_OWNERSHIP_SANDBOX" ] \
    || fail "$label must run from its physical sandbox directory"
  pair_identity_validate_name "$pair"
  [[ "$port1" =~ ^[0-9]+$ && "$port2" =~ ^[0-9]+$ ]] \
    && [ "$port1" -ge 8900 ] && [ "$port2" -eq $((port1 + 1)) ] && [ $((port1 % 2)) -eq 0 ] \
    || fail "$label received an invalid even/successor port pair"
  [[ "$scratch_prefix" =~ ^[a-z0-9][a-z0-9-]*$ ]] \
    || fail "$label scratch prefix is not a safe mktemp name"
  [ "${WPRISM_SOURCE_ROOT:-}" = "$PAIR_LIVE_OWNERSHIP_REPO" ] \
    || fail "$label must select this exact physical worktree through WPRISM_SOURCE_ROOT"
  pair_identity_export_source_mounts \
    || fail "$label could not pin its exact worktree mounts"

  PAIR_LIVE_OWNERSHIP_PAIR="$pair"
  PAIR_LIVE_OWNERSHIP_PORT1="$port1"
  PAIR_LIVE_OWNERSHIP_PORT2="$port2"
  PAIR_LIVE_OWNERSHIP_LABEL="$label"
  PAIR_LIVE_OWNERSHIP_SITE_ROOT="$PAIR_LIVE_OWNERSHIP_SANDBOX/siterepo"
  PAIR_LIVE_OWNERSHIP_SITE1="$PAIR_LIVE_OWNERSHIP_SITE_ROOT/${pair}1"
  PAIR_LIVE_OWNERSHIP_SITE2="$PAIR_LIVE_OWNERSHIP_SITE_ROOT/${pair}2"
  PAIR_LIVE_OWNERSHIP_ORIGIN="$PAIR_LIVE_OWNERSHIP_SITE_ROOT/origin-${pair}.git"
  PAIR_LIVE_OWNERSHIP_TMP_ROOT=''
  PAIR_LIVE_OWNERSHIP_LEASE_ACTIVE=0
  PAIR_LIVE_OWNERSHIP_PAIR_OWNED=0
  PAIR_LIVE_OWNERSHIP_BODY_COMPLETE=0
  PAIR_LIVE_OWNERSHIP_CLEANING=0
  PAIR_LIVE_OWNERSHIP_TOKEN=''
  PAIR_LIVE_OWNERSHIP_OWNER_START=''
  PAIR_LIVE_OWNERSHIP_ENGINE=''
  PAIR_LIVE_OWNERSHIP_CONTAINER=''

  trap 'pair_live_ownership_exit "$?"' EXIT
  trap 'exit 130' INT TERM
  # The host later writes repository fixtures read by the pair's uid 33.
  # A process-wide 077 here silently turned those files into unreadable 0600
  # inputs. Only scratch allocation owns this mask; the caller keeps its own.
  PAIR_LIVE_OWNERSHIP_TMP_ROOT="$(umask 077; mktemp -d "${TMPDIR:-/tmp}/${scratch_prefix}.${pair}.XXXXXX")" \
    || fail "$label could not allocate owner-private scratch"
  chmod 0700 "$PAIR_LIVE_OWNERSHIP_TMP_ROOT" \
    || fail "$label could not protect owner-private scratch"
  [ "$(pair_live_ownership_mode_of "$PAIR_LIVE_OWNERSHIP_TMP_ROOT")" = 700 ] \
    || fail "$label owner-private scratch mode is not 0700"
}

pair_live_ownership_select_engine() { # <mariadb|mysql>
  local engine="$1"
  case "$engine" in
    mariadb|mysql) ;;
    *) fail "$PAIR_LIVE_OWNERSHIP_LABEL requested unsupported database engine '$engine'" ;;
  esac
  WPRISM_DB_ENGINE="$engine"
  pair_db_select_engine
  PAIR_LIVE_OWNERSHIP_ENGINE="$engine"
  PAIR_LIVE_OWNERSHIP_CONTAINER="$DB_CONTAINER"
}

pair_live_ownership_acquire() { # <mariadb|mysql>
  local engine="$1" token
  [ "$PAIR_LIVE_OWNERSHIP_LEASE_ACTIVE" -eq 0 ] \
    || fail "$PAIR_LIVE_OWNERSHIP_LABEL attempted to acquire a second lease before releasing the first"
  pair_live_ownership_select_engine "$engine"
  # Re-export through pair_identity on every leg: WPRISM_SOURCE_ROOT is the
  # authority and must replace any PAIR_SOURCE_ROOT cached by the prior leg.
  pair_identity_export_source_mounts \
    || fail "$PAIR_LIVE_OWNERSHIP_LABEL could not revalidate its exact worktree mounts"
  PAIR_LIVE_OWNERSHIP_OWNER_START="$(pair_lease_owner_start "$$")" \
    || fail "$PAIR_LIVE_OWNERSHIP_LABEL could not identify its lease owner process"
  token="$(pair_lease_token "$$" "$PAIR_LIVE_OWNERSHIP_OWNER_START")" \
    || fail "$PAIR_LIVE_OWNERSHIP_LABEL could not generate its lease token"
  export WPRISM_PAIR_LEASE_TOKEN="$token"
  if ! bash "$PAIR_LIVE_OWNERSHIP_SANDBOX/bin/pair.sh" lease-batch-acquire \
      "$token" "$$" "$PAIR_LIVE_OWNERSHIP_OWNER_START" \
      "$PAIR_LIVE_OWNERSHIP_PAIR" "$PAIR_LIVE_OWNERSHIP_PORT1" "$PAIR_LIVE_OWNERSHIP_PORT2"; then
    unset WPRISM_PAIR_LEASE_TOKEN
    fail "$PAIR_LIVE_OWNERSHIP_LABEL could not acquire its complete pair namespace"
  fi
  # Only successful publication arms destructive cleanup. From here through
  # release, even a half-created `up` belongs to this process.
  PAIR_LIVE_OWNERSHIP_TOKEN="$token"
  PAIR_LIVE_OWNERSHIP_LEASE_ACTIVE=1
  PAIR_LIVE_OWNERSHIP_PAIR_OWNED=1
}

pair_live_ownership_up() { # [pair.sh up flags...]
  [ "$PAIR_LIVE_OWNERSHIP_LEASE_ACTIVE" -eq 1 ] \
    || fail "$PAIR_LIVE_OWNERSHIP_LABEL cannot start a pair without its lease"
  bash "$PAIR_LIVE_OWNERSHIP_SANDBOX/bin/pair.sh" up \
    "$PAIR_LIVE_OWNERSHIP_PAIR" "$PAIR_LIVE_OWNERSHIP_PORT1" "$PAIR_LIVE_OWNERSHIP_PORT2" "$@"
}

pair_live_ownership_reset() {
  [ "$PAIR_LIVE_OWNERSHIP_LEASE_ACTIVE" -eq 1 ] \
    || fail "$PAIR_LIVE_OWNERSHIP_LABEL cannot reset a pair without its lease"
  bash "$PAIR_LIVE_OWNERSHIP_SANDBOX/bin/pair.sh" reset "$PAIR_LIVE_OWNERSHIP_PAIR"
}

pair_live_ownership_repo_host() { # [1|2|both]
  [ "$PAIR_LIVE_OWNERSHIP_LEASE_ACTIVE" -eq 1 ] \
    || fail "$PAIR_LIVE_OWNERSHIP_LABEL cannot hand back repository ownership without its lease"
  bash "$PAIR_LIVE_OWNERSHIP_SANDBOX/bin/pair.sh" repo-host \
    "$PAIR_LIVE_OWNERSHIP_PAIR" "${1:-both}"
}

pair_live_ownership_remove_pair_roots() {
  local path
  for path in \
    "$PAIR_LIVE_OWNERSHIP_SITE1" \
    "$PAIR_LIVE_OWNERSHIP_SITE2" \
    "$PAIR_LIVE_OWNERSHIP_ORIGIN"; do
    case "$path" in
      "$PAIR_LIVE_OWNERSHIP_SITE_ROOT/${PAIR_LIVE_OWNERSHIP_PAIR}1"|\
      "$PAIR_LIVE_OWNERSHIP_SITE_ROOT/${PAIR_LIVE_OWNERSHIP_PAIR}2"|\
      "$PAIR_LIVE_OWNERSHIP_SITE_ROOT/origin-${PAIR_LIVE_OWNERSHIP_PAIR}.git") ;;
      *) printf 'FAIL: %s refused an unowned cleanup path: %s\n' "$PAIR_LIVE_OWNERSHIP_LABEL" "$path" >&2; return 1 ;;
    esac
    if [ -e "$path" ] || [ -L "$path" ]; then
      find "$path" -depth -delete || return 1
    fi
    [ ! -e "$path" ] && [ ! -L "$path" ] || return 1
  done
}

pair_live_ownership_remove_scratch() {
  local path="$PAIR_LIVE_OWNERSHIP_TMP_ROOT"
  [ -n "$path" ] || return 0
  if [ -e "$path" ] || [ -L "$path" ]; then
    find "$path" -depth -delete || return 1
  fi
  [ ! -e "$path" ] && [ ! -L "$path" ] || return 1
  PAIR_LIVE_OWNERSHIP_TMP_ROOT=''
}

pair_live_ownership_teardown() { # <remove-scratch:0|1>
  local remove_scratch="$1" failed=0 destroy_log=''
  if declare -F pair_live_ownership_before_teardown >/dev/null 2>&1; then
    pair_live_ownership_before_teardown || failed=1
  fi

  if [ "$PAIR_LIVE_OWNERSHIP_PAIR_OWNED" -eq 1 ]; then
    if [ -n "$PAIR_LIVE_OWNERSHIP_TMP_ROOT" ]; then
      destroy_log="$PAIR_LIVE_OWNERSHIP_TMP_ROOT/pair-destroy-${PAIR_LIVE_OWNERSHIP_ENGINE}.log"
    else
      destroy_log="${TMPDIR:-/tmp}/wprism-pair-destroy-${PAIR_LIVE_OWNERSHIP_PAIR}.$$"
    fi
    if (umask 077; bash "$PAIR_LIVE_OWNERSHIP_SANDBOX/bin/pair.sh" destroy \
        "$PAIR_LIVE_OWNERSHIP_PAIR" >"$destroy_log" 2>&1); then
      PAIR_LIVE_OWNERSHIP_PAIR_OWNED=0
    else
      failed=1
      printf 'FAIL: %s pair destroy failed; transcript follows (%s):\n' \
        "$PAIR_LIVE_OWNERSHIP_LABEL" "$destroy_log" >&2
      tail -80 "$destroy_log" >&2
    fi
  fi

  if [ "$failed" -eq 0 ] && [ "$PAIR_LIVE_OWNERSHIP_LEASE_ACTIVE" -eq 1 ]; then
    pair_live_ownership_remove_pair_roots || {
      failed=1
      printf 'FAIL: %s exact site-root cleanup was not proved\n' "$PAIR_LIVE_OWNERSHIP_LABEL" >&2
    }
  fi
  if [ "$remove_scratch" -eq 1 ]; then
    pair_live_ownership_remove_scratch || {
      failed=1
      printf 'FAIL: %s owner-private scratch cleanup was not proved\n' "$PAIR_LIVE_OWNERSHIP_LABEL" >&2
    }
  fi

  if [ "$PAIR_LIVE_OWNERSHIP_LEASE_ACTIVE" -eq 1 ]; then
    if [ "$failed" -eq 0 ]; then
      if bash "$PAIR_LIVE_OWNERSHIP_SANDBOX/bin/pair.sh" lease-batch-release \
          "$PAIR_LIVE_OWNERSHIP_TOKEN"; then
        PAIR_LIVE_OWNERSHIP_LEASE_ACTIVE=0
        PAIR_LIVE_OWNERSHIP_TOKEN=''
        unset WPRISM_PAIR_LEASE_TOKEN
      else
        failed=1
        printf 'FAIL: %s lease release or final namespace census failed\n' \
          "$PAIR_LIVE_OWNERSHIP_LABEL" >&2
      fi
    else
      printf 'FAIL: %s retains its lease because teardown was not proved\n' \
        "$PAIR_LIVE_OWNERSHIP_LABEL" >&2
    fi
  fi
  [ "$failed" -eq 0 ]
}

pair_live_ownership_finish_leg() {
  pair_live_ownership_teardown 0 \
    || fail "$PAIR_LIVE_OWNERSHIP_LABEL could not verify and release its current database leg"
}

pair_live_ownership_exit() { # <incoming-status>
  local incoming_status="$1" cleanup_status=0
  [ "$PAIR_LIVE_OWNERSHIP_CLEANING" -eq 0 ] || exit "$incoming_status"
  PAIR_LIVE_OWNERSHIP_CLEANING=1
  trap - EXIT INT TERM
  set +e
  pair_live_ownership_teardown 1
  cleanup_status=$?
  if [ "$incoming_status" -eq 0 ] \
      && [ "$PAIR_LIVE_OWNERSHIP_BODY_COMPLETE" -eq 1 ] \
      && [ "$cleanup_status" -eq 0 ] \
      && [ "$PAIR_LIVE_OWNERSHIP_LEASE_ACTIVE" -eq 0 ] \
      && [ -z "$PAIR_LIVE_OWNERSHIP_TMP_ROOT" ]; then
    printf '\n\033[1;32m%s\033[0m\n' "$PAIR_LIVE_OWNERSHIP_PASS_MESSAGE"
    exit 0
  fi
  [ "$cleanup_status" -eq 0 ] \
    || printf 'FAIL: %s cleanup was incomplete; no PASS was published\n' "$PAIR_LIVE_OWNERSHIP_LABEL" >&2
  [ "$incoming_status" -ne 0 ] || incoming_status=1
  exit "$incoming_status"
}

pair_live_ownership_complete() { # <sole-pass-message>
  PAIR_LIVE_OWNERSHIP_PASS_MESSAGE="$1"
  PAIR_LIVE_OWNERSHIP_BODY_COMPLETE=1
  pair_live_ownership_exit 0
}
