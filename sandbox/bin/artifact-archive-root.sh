#!/bin/sh
# Normalize one lock-declared local ZIP archive root to its canonical
# WordPress plugin/theme slug. Both names are validated safe path components;
# no archive scan, basename guess, or out-of-root fallback is permitted.
set -eu

base=${1:?install root required}
archive_root=${2:?archive root required}
slug=${3:?canonical slug required}

safe_component() {
  case "$1" in
    ''|*[!a-z0-9._-]*|[!a-z0-9]*|*[!a-z0-9]) return 1 ;;
    *) return 0 ;;
  esac
}

case "$base" in /*) ;; *) echo "artifact install root must be absolute" >&2; exit 1 ;; esac
safe_component "$archive_root" || { echo "pinned archive root is malformed" >&2; exit 1; }
safe_component "$slug" || { echo "pinned destination slug is malformed" >&2; exit 1; }
[ -d "$base" ] && [ ! -L "$base" ] || {
  echo "artifact install root has an unsafe type" >&2
  exit 1
}
[ "$archive_root" != "$slug" ] || exit 0

source_path="$base/$archive_root"
destination_path="$base/$slug"
[ -d "$source_path" ] && [ ! -L "$source_path" ] || {
  echo "expected pinned archive root is absent" >&2
  exit 1
}
if [ -e "$destination_path" ] || [ -L "$destination_path" ]; then
  [ -d "$destination_path" ] && [ ! -L "$destination_path" ] || {
    echo "pinned destination slug has an unsafe type" >&2
    exit 1
  }
  rm -rf -- "$destination_path"
fi
mv "$source_path" "$destination_path"
