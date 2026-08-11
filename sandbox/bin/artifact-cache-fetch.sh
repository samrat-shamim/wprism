#!/bin/sh
# Container-side, typed artifact-cache resolver. Invoked only through
# fetch-artifact.sh with already validated lock data.
set -eu

url="${1:?url required}"
sha256="${2:?sha256 required}"
cache_path="${3:?cache path required}"
offline="${4:?offline flag required}"
slug="${5:?slug required}"
version="${6:?version required}"
kind="${7:?kind required}"
force_php="${DUO_ARTIFACT_FORCE_PHP_LOCK:-0}"
test_mode="${DUO_ARTIFACT_TEST_MODE:-0}"
case "$test_mode" in 0|1) ;; *) echo "FAIL: DUO_ARTIFACT_TEST_MODE must be 0 or 1" >&2; exit 1 ;; esac
if [ "$test_mode" = 1 ]; then
  cache_root="${DUO_ARTIFACT_TEST_CACHE_ROOT:?test cache root required}"
else
  cache_root=/artifacts-cache
fi

case "$offline" in 0|1) ;; *) echo "FAIL: artifact cache runner received an invalid offline flag" >&2; exit 1 ;; esac
case "$force_php" in 0|1) ;; *) echo "FAIL: DUO_ARTIFACT_FORCE_PHP_LOCK must be 0 or 1" >&2; exit 1 ;; esac
case "$kind" in plugin|theme) ;; *) echo "FAIL: artifact cache runner received an invalid kind" >&2; exit 1 ;; esac
case "$slug" in ''|*[!a-z0-9._-]*|[!a-z0-9]*|*[!a-z0-9]) echo "FAIL: artifact cache runner received an invalid slug" >&2; exit 1 ;; esac
case "$version" in ''|*[!0-9A-Za-z._-]*|[!0-9A-Za-z]*) echo "FAIL: artifact cache runner received an invalid version" >&2; exit 1 ;; esac
case "$sha256" in *[!0-9a-f]*|'') echo "FAIL: artifact cache runner received an invalid digest" >&2; exit 1 ;; esac
[ "${#sha256}" -eq 64 ] || { echo "FAIL: artifact cache runner received an invalid digest" >&2; exit 1; }
case "$cache_path" in /artifacts-cache/"$kind-$slug-$version-$sha256.zip") ;; *) echo "FAIL: artifact cache runner received an invalid cache path" >&2; exit 1 ;; esac
case "$cache_root" in /*) ;; *) echo "FAIL: artifact cache runner received a non-absolute cache root" >&2; exit 1 ;; esac
physical_path="$cache_root/${cache_path#/artifacts-cache/}"

lock_root="$cache_root/.locks"
lock_path="$lock_root/$kind-$slug-$version-$sha256.lock"
helper_dir=
helper_pid=
control_fd=8
tmp=
mkdir -p "$lock_root"

cleanup() {
  [ -z "$tmp" ] || rm -f -- "$tmp"
  if [ -n "$helper_pid" ]; then
    printf x >&8 2>/dev/null || true
    exec 8>&- 2>/dev/null || true
    wait "$helper_pid" 2>/dev/null || true
  fi
  if [ -n "$helper_dir" ]; then
    rm -f -- "$helper_dir/ready" "$helper_dir/control" 2>/dev/null || true
    rmdir -- "$helper_dir" 2>/dev/null || true
  fi
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

if [ "$force_php" = 0 ] && command -v flock >/dev/null 2>&1; then
  exec 9>"$lock_path"
  flock 9
elif command -v php >/dev/null 2>&1; then
  helper_dir=$(mktemp -d "$lock_root/.php-lock.XXXXXX")
  mkfifo "$helper_dir/control"
  exec 8<>"$helper_dir/control"
  php -r '
    [$script, $lockPath, $controlPath, $readyPath] = $argv;
    $lock = fopen($lockPath, "c");
    if ($lock === false || !flock($lock, LOCK_EX)) { exit(70); }
    $control = fopen($controlPath, "r");
    if ($control === false || file_put_contents($readyPath, "ready\n") === false) { exit(71); }
    fread($control, 1);
    fclose($control);
    flock($lock, LOCK_UN);
    fclose($lock);
  ' "$lock_path" "$helper_dir/control" "$helper_dir/ready" 8>&- &
  helper_pid=$!
  while [ ! -f "$helper_dir/ready" ]; do
    kill -0 "$helper_pid" 2>/dev/null || {
      wait "$helper_pid" 2>/dev/null || true
      echo "FAIL: artifact cache runner could not acquire the PHP flock" >&2
      exit 1
    }
    sleep 0.01
  done
else
  echo "FAIL: artifact cache runner requires flock or PHP flock(); refusing an unlocked shared-cache write" >&2
  exit 1
fi

if [ -f "$physical_path" ]; then
  actual=$(sha256sum "$physical_path" | cut -d' ' -f1)
  if [ "$actual" != "$sha256" ]; then
    echo "FAIL: fetch_artifact: cached $cache_path does not match its pinned digest (expected $sha256, got $actual) — refusing, not silently re-fetching; delete the cache file first if a re-fetch is actually intended" >&2
    exit 1
  fi
  printf 'cache-hit\n'
  exit 0
fi
if [ "$offline" = 1 ]; then
  echo "FAIL: fetch_artifact: cache miss for $slug $version while DUO_ARTIFACT_OFFLINE=1 — refusing every network fetch" >&2
  exit 1
fi

tmp=$(mktemp "$physical_path.tmp.XXXXXX")
chmod 0644 "$tmp"
attempt=1
while [ "$attempt" -le 3 ]; do
  : > "$tmp"
  if curl --connect-timeout 10 --max-time 120 -fsSL -o "$tmp" "$url"; then
    break
  fi
  if [ "$attempt" -eq 3 ]; then
    echo "FAIL: fetch_artifact: download of $slug $version failed after 3 attempts — refusing and deleting only the partial file" >&2
    exit 1
  fi
  echo "Warning: fetch_artifact: download of $slug $version failed on attempt $attempt/3; retrying the pinned URL" >&2
  sleep "$attempt"
  attempt=$((attempt + 1))
done
actual=$(sha256sum "$tmp" | cut -d' ' -f1)
if [ "$actual" != "$sha256" ]; then
  echo "FAIL: fetch_artifact: downloaded $slug $version does not match its pinned digest (expected $sha256, got $actual) — refusing, deleting the partial file, not installing it" >&2
  exit 1
fi
mv "$tmp" "$physical_path"
tmp=
printf 'network-fetch\n'
