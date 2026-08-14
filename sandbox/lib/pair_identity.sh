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
  local requested="${DUO_SOURCE_ROOT:-}" canonical requested_root requested_common canonical_common
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

pair_identity_validate_name() { # pair_identity_validate_name <name>
  local name="$1"
  # Used bare both as a MySQL identifier fragment (wp_<name>1/2) and as a
  # docker compose project suffix (duo-<name>) — lowercase letters/digits
  # only, starting with a letter, keeps it unambiguously safe in both
  # without needing identifier-quoting gymnastics anywhere in the launcher.
  [[ "$name" =~ ^[a-z][a-z0-9]*$ ]] \
    || fail "pair name '$name' invalid — lowercase letters/digits only, starting with a letter"
  # Reserved names identify other compose projects and are never real pairs.
  case "$name" in
    db|sandbox) fail "pair name '$name' is reserved (duo-$name is already a different project — see pair.sh's validate_name)" ;;
  esac
}
