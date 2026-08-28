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
# their canonical PACKAGE_ROOT. Shared and cross-adapter scenarios have none of
# those contexts and deliberately retain the aggregate view.
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

artifact_library_emit() {
  local repo package
  repo="$(artifact_library_repo_root)" || return 1
  package="$(artifact_library_package_context)" || return 1
  if [ -n "$package" ]; then
    php "$repo/tools/artifact-library.php" --root="$repo" --adapter="$package"
  else
    php "$repo/tools/artifact-library.php" --root="$repo"
  fi
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
