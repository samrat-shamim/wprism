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

artifact_library_emit() {
  local repo
  repo="$(artifact_library_repo_root)" || return 1
  php "$repo/tools/artifact-library.php" --root="$repo"
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
