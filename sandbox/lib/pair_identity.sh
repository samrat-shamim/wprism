#!/usr/bin/env bash
# Pair identity primitives shared by the sandbox launcher. This library keeps
# a pair's safe namespace and canonical checkout in one place; lifecycle code
# consumes those facts rather than recreating them with divergent shell rules.

pair_identity_canonical_root() {
  local common_dir
  common_dir=$(git rev-parse --path-format=absolute --git-common-dir 2>/dev/null) \
    || return 1
  dirname "$common_dir"
}

# Evidence runs may explicitly mount the invoking linked worktree. The
# override must be the physical top-level path of a worktree from this same
# repository; ordinary persistent-pair callers keep the canonical root.
pair_identity_source_root() {
  local requested="${WPRISM_SOURCE_ROOT:-}" canonical requested_root requested_common canonical_common
  canonical="$(pair_identity_canonical_root)" || return 1
  if [ -z "$requested" ]; then
    printf '%s\n' "$canonical"
    return 0
  fi
  [[ "$requested" = /* ]] || return 1
  requested_root="$(cd "$requested" 2>/dev/null && pwd -P)" || return 1
  [ "$requested_root" = "$requested" ] || return 1
  [ "$(git -C "$requested_root" rev-parse --show-toplevel 2>/dev/null)" = "$requested_root" ] \
    || return 1
  requested_common="$(git -C "$requested_root" rev-parse --path-format=absolute --git-common-dir 2>/dev/null)" \
    || return 1
  canonical_common="$(git -C "$canonical" rev-parse --path-format=absolute --git-common-dir 2>/dev/null)" \
    || return 1
  [ "$requested_common" = "$canonical_common" ] || return 1
  printf '%s\n' "$requested_root"
}

# Direct Compose callers must retain the selected mounts in their own shell.
# sandbox/.env is only a subprocess fallback: another pair lifecycle command
# can rewrite that shared file while a parallel evidence lane is still active.
pair_identity_export_source_mounts() {
  local root requested="${WPRISM_SOURCE_ROOT:-}"
  if [ -n "$requested" ]; then
    # PAIR_SOURCE_ROOT is a process-local cache for direct Compose callers,
    # not authority over a newly explicit selection. A long-lived harness can
    # legitimately select a second worktree between legs; re-resolve the
    # explicit path so stale exported mount variables cannot win that change.
    root="$(pair_identity_source_root)" || return 1
  elif [ -n "${PAIR_SOURCE_ROOT:-}" ]; then
    # A cached selection still crosses the same physical-worktree boundary as
    # a fresh WPRISM_SOURCE_ROOT. Revalidate it rather than trusting a path an
    # inherited shell could have poisoned before this library was sourced.
    root="$(WPRISM_SOURCE_ROOT="$PAIR_SOURCE_ROOT" pair_identity_source_root)" || return 1
  else
    root="$(pair_identity_source_root)" || return 1
  fi
  PAIR_SOURCE_ROOT="$root"
  export PAIR_SOURCE_ROOT
  export WPRISM_AGENT_SRC="$root/agent"
  export WPRISM_ADAPTER_PACKAGES_SRC="$root/adapter-packages"
  export WPRISM_PLATFORM_SRC="$root/platform"
}

pair_identity_validate_name() { # pair_identity_validate_name <name>
  local name="$1"
  # Used bare both as a MySQL identifier fragment (wp_<name>1/2) and as a
  # docker compose project suffix (wprism-<name>) — lowercase letters/digits
  # only, starting with a letter, keeps it unambiguously safe in both
  # without needing identifier-quoting gymnastics anywhere in the launcher.
  [[ "$name" =~ ^[a-z][a-z0-9]*$ ]] \
    || fail "pair name '$name' invalid — lowercase letters/digits only, starting with a letter"
  # Reserved names identify other compose projects and are never real pairs.
  case "$name" in
    db|sandbox) fail "pair name '$name' is reserved (wprism-$name is already a different project — see pair.sh's validate_name)" ;;
  esac
}
