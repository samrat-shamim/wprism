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
