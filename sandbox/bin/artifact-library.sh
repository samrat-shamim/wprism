#!/usr/bin/env bash
# Convention-discovered artifact authority. Adapter pins live in the owning
# capsule; platform fixtures live under platform/. The aggregate exists only
# on stdout for consumers that need a cross-adapter view.

artifact_library_repo_root() {
  if [ -n "${DUO_ARTIFACT_LIBRARY_ROOT:-}" ]; then
    local configured="$DUO_ARTIFACT_LIBRARY_ROOT" canonical
    case "$configured" in
      /*) ;;
      *)
        echo "FAIL: DUO_ARTIFACT_LIBRARY_ROOT must be an absolute repository path" >&2
        return 1
        ;;
    esac
    canonical="$(cd "$configured" 2>/dev/null && pwd -P)" || {
      echo "FAIL: DUO_ARTIFACT_LIBRARY_ROOT is not a resolvable directory: $configured" >&2
      return 1
    }
    [ -f "$canonical/tools/artifact-library.php" ] \
      && [ -f "$canonical/tools/src/ArtifactLibrary.php" ] \
      && [ -d "$canonical/adapter-packages" ] || {
      echo "FAIL: DUO_ARTIFACT_LIBRARY_ROOT is not an artifact-library repository root: $configured" >&2
      return 1
    }
    printf '%s\n' "$canonical"
    return
  fi
  local source_dir
  source_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)" || return 1
  (cd "$source_dir/../.." && pwd)
}

# One package-owned evidence run must not enumerate sibling capsules. Explicit
# DUO_ARTIFACT_PACKAGE is authoritative only when it agrees with a concurrently
# visible PACKAGE_ROOT; the generic conformance/version-matrix drivers already
# carry the owning manifest name, and package-local live suites carry their
# canonical PACKAGE_ROOT.
artifact_library_package_context() {
  local repo package_root package_candidate="" candidate="${DUO_ARTIFACT_PACKAGE:-}"
  repo="$(artifact_library_repo_root)" || return 1
  if [ -n "${PACKAGE_ROOT:-}" ]; then
    case "$PACKAGE_ROOT" in
      /*) ;;
      *)
        echo "FAIL: PACKAGE_ROOT must be an absolute adapter package path" >&2
        return 1
        ;;
    esac
    package_root="$(cd "$PACKAGE_ROOT" 2>/dev/null && pwd -P)" || {
      echo "FAIL: PACKAGE_ROOT is not a resolvable adapter package directory: $PACKAGE_ROOT" >&2
      return 1
    }
    case "$package_root" in
      "$repo"/adapter-packages/*)
        package_candidate="${package_root##*/}"
        [ "$package_root" = "$repo/adapter-packages/$package_candidate" ] || {
          echo "FAIL: PACKAGE_ROOT must name one direct adapter package directory: $PACKAGE_ROOT" >&2
          return 1
        }
        ;;
      *)
        echo "FAIL: PACKAGE_ROOT is outside the selected artifact-library repository: $PACKAGE_ROOT" >&2
        return 1
        ;;
    esac
  fi
  if [ -z "$candidate" ] && [ -n "${VMATRIX_MANIFEST:-}" ]; then
    candidate="$VMATRIX_MANIFEST"
  fi
  if [ -z "$candidate" ] && [ -n "${MANIFEST:-}" ] \
    && [ -d "$repo/adapter-packages/$MANIFEST" ]; then
    candidate="$MANIFEST"
  fi
  if [ -z "$candidate" ]; then
    candidate="$package_candidate"
  elif [ -n "$package_candidate" ] && [ "$candidate" != "$package_candidate" ]; then
    echo "FAIL: artifact package context '$candidate' disagrees with PACKAGE_ROOT '$package_candidate'" >&2
    return 1
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

artifact_library_platform_context() {
  local platform_only="${DUO_ARTIFACT_PLATFORM_ONLY:-0}"
  case "$platform_only" in
    0) return 0 ;;
    1) printf '1\n' ;;
    *)
      echo "FAIL: DUO_ARTIFACT_PLATFORM_ONLY must be 0 or 1" >&2
      return 1
      ;;
  esac
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
  local repo package participants platform_only contexts=0
  repo="$(artifact_library_repo_root)" || return 1
  package="$(artifact_library_package_context)" || return 1
  participants="$(artifact_library_participant_context)" || return 1
  platform_only="$(artifact_library_platform_context)" || return 1
  [ -z "$package" ] || contexts=$((contexts + 1))
  [ -z "$participants" ] || contexts=$((contexts + 1))
  [ -z "$platform_only" ] || contexts=$((contexts + 1))
  if [ "$contexts" -gt 1 ]; then
    echo "FAIL: artifact package, participant, and platform contexts are mutually exclusive" >&2
    return 1
  fi
  if [ -n "$package" ]; then
    php "$repo/tools/artifact-library.php" --root="$repo" --adapter="$package"
  elif [ -n "$participants" ]; then
    php "$repo/tools/artifact-library.php" --root="$repo" --participants="$participants"
  elif [ -n "$platform_only" ]; then
    artifact_library_platform_emit
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
