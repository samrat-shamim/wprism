#!/usr/bin/env bash
# Convention-discovered artifact authority. Adapter pins live in the owning
# capsule; platform fixtures live under platform/. The aggregate exists only
# on stdout for consumers that need a cross-adapter view.

artifact_library_repo_root() {
  if [ -n "${DUO_ARTIFACT_LIBRARY_ROOT:-}" ]; then
    printf '%s\n' "$DUO_ARTIFACT_LIBRARY_ROOT"
    return
  fi
  local source_dir
  source_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)" || return 1
  (cd "$source_dir/../.." && pwd)
}

# One package-owned evidence run must not enumerate sibling capsules. Explicit
# DUO_ARTIFACT_PACKAGE wins; the generic conformance/version-matrix drivers
# already carry the owning manifest name, and package-local live suites carry
# their canonical PACKAGE_ROOT.
artifact_library_package_context() {
  local repo candidate="${DUO_ARTIFACT_PACKAGE:-}"
  repo="$(artifact_library_repo_root)" || return 1
  if [ -z "$candidate" ] && [ -n "${VMATRIX_MANIFEST:-}" ]; then
    candidate="$VMATRIX_MANIFEST"
  fi
  if [ -z "$candidate" ] && [ -n "${MANIFEST:-}" ] \
    && [ -d "$repo/adapter-packages/$MANIFEST" ]; then
    candidate="$MANIFEST"
  fi
  if [ -z "$candidate" ] && [ -n "${PACKAGE_ROOT:-}" ]; then
    case "$PACKAGE_ROOT" in
      "$repo"/adapter-packages/*)
        candidate="${PACKAGE_ROOT##*/}"
        ;;
    esac
  fi
  if [ -z "$candidate" ]; then
    return 0
  fi
  [[ "$candidate" =~ ^[a-z][a-z0-9]*(-[a-z0-9]+)*$ ]] \
    || { echo "FAIL: artifact package context is not canonical: $candidate" >&2; return 1; }
  [ -f "$repo/adapter-packages/$candidate/evidence/artifacts.lock.json" ] \
    || { echo "FAIL: artifact package context has no package-owned fragment: $candidate" >&2; return 1; }
  printf '%s\n' "$candidate"
}

artifact_library_participant_context() {
  local participants="${DUO_ARTIFACT_PARTICIPANTS:-}"
  if [ -z "$participants" ]; then
    return 0
  fi
  [[ "$participants" =~ ^[a-z][a-z0-9]*(-[a-z0-9]+)*(,[a-z][a-z0-9]*(-[a-z0-9]+)*)*$ ]] \
    || { echo "FAIL: artifact participant context is not a canonical comma-separated list: $participants" >&2; return 1; }
  printf '%s\n' "$participants"
}

artifact_library_scenario_participants() {
  local record="$1"
  [ -f "$record" ] && [ ! -L "$record" ] \
    || { echo "FAIL: artifact scenario record is not an ordinary file: $record" >&2; return 1; }
  jq -er '
    if .format == "duo-adapter-integration-scenario/v1"
      and (.participants | type) == "array"
      and (.participants | length) >= 2
      and all(.participants[]; type == "string" and test("^[a-z][a-z0-9]*(-[a-z0-9]+)*$"))
      and (.participants == (.participants | sort | unique))
    then .participants | join(",")
    else error("scenario participants are not a sorted, unique canonical list")
    end
  ' "$record"
}

artifact_library_emit() {
  local repo package participants
  repo="$(artifact_library_repo_root)" || return 1
  package="$(artifact_library_package_context)" || return 1
  participants="$(artifact_library_participant_context)" || return 1
  if [ -n "$package" ] && [ -n "$participants" ]; then
    echo "FAIL: artifact package and participant contexts are mutually exclusive" >&2
    return 1
  fi
  if [ -n "$package" ]; then
    php "$repo/tools/artifact-library.php" --root="$repo" --adapter="$package"
  elif [ -n "$participants" ]; then
    php "$repo/tools/artifact-library.php" --root="$repo" --participants="$participants"
  else
    php "$repo/tools/artifact-library.php" --root="$repo"
  fi
}

# Pair bootstrap needs the shared WordPress theme fixture, but a package or
# scenario scope must not be widened to every sibling adapter to obtain it.
artifact_library_platform_emit() {
  local repo
  repo="$(artifact_library_repo_root)" || return 1
  php "$repo/tools/artifact-library.php" --root="$repo" --platform
}

validate_artifact_platform_library() {
  artifact_library_platform_emit >/dev/null 2>&1 || {
    echo "FAIL: platform artifact library is malformed or contains an unsupported namespace, key, role, URL, digest, archive root, or duplicate owner" >&2
    return 1
  }
}

artifact_library_platform_jq() {
  local library
  library="$(artifact_library_platform_emit)" || return 1
  jq "$@" <<<"$library"
}

validate_artifact_library() {
  artifact_library_emit >/dev/null 2>&1 || {
    echo "FAIL: artifact library is malformed or contains an unsupported namespace, key, role, URL, digest, archive root, or duplicate owner" >&2
    return 1
  }
}

artifact_library_jq() {
  local library
  library="$(artifact_library_emit)" || return 1
  jq "$@" <<<"$library"
}
