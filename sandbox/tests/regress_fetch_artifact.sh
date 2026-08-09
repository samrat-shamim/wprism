#!/usr/bin/env bash
# Regression — bounded retries and race-free shared-cache publication for
# pinned artifact downloads.
#
# This is deliberately host-only: it sources fetch-artifact.sh, replaces the
# compose runner with a local sh -c bridge, and replaces curl with a small
# deterministic fixture. No docker, network, WordPress, or shared artifact
# cache is touched. The fixture exercises the same command string that the
# live pair invokes inside its root-owned cli container.
set -euo pipefail
cd "$(dirname "$0")/.."

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

command -v jq >/dev/null || fail "jq required on PATH"
command -v sha256sum >/dev/null || fail "sha256sum required on PATH"

REPO_ROOT="$(cd .. && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/duo-fetch-artifact.XXXXXX")"
trap 'rm -rf -- "$TMP"' EXIT INT TERM
mkdir -p "$TMP/conformance" "$TMP/fake-bin" "$TMP/cache"

PAYLOAD="$TMP/pinned-artifact.zip"
printf 'pinned artifact bytes\n' > "$PAYLOAD"
DIGEST="$(sha256sum "$PAYLOAD" | awk '{print $1}')"
printf '{"fixture":{"1.0":{"url":"file://%s","sha256":"%s"}}}\n' \
  "$PAYLOAD" "$DIGEST" > "$TMP/conformance/artifacts.lock.json"

cat > "$TMP/fake-bin/curl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

output=""
url=""
while [ "$#" -gt 0 ]; do
  case "$1" in
    -o)
      output="$2"
      shift 2
      ;;
    file://*)
      url="${1#file://}"
      shift
      ;;
    *)
      shift
      ;;
  esac
done

count="$(cat "$FAKE_CURL_COUNT" 2>/dev/null || printf '0')"
count=$((count + 1))
printf '%s\n' "$count" > "$FAKE_CURL_COUNT"

case "$FAKE_CURL_MODE" in
  transient)
    if [ "$count" -lt 3 ]; then
      printf 'partial attempt %s\n' "$count" > "$output"
      exit 22
    fi
    ;;
  permanent)
    printf 'partial exhausted attempt %s\n' "$count" > "$output"
    exit 22
    ;;
  wrong)
    printf 'wrong digest bytes\n' > "$output"
    exit 0
    ;;
  concurrent)
    marker="$FAKE_CURL_BARRIER/$$"
    printf '%s\n' "$output" > "$marker"
    ready=0
    for wait_round in $(seq 1 200); do
      if [ "$(find "$FAKE_CURL_BARRIER" -type f | wc -l)" -ge 2 ]; then
        ready=1
        break
      fi
      /bin/sleep 0.01
    done
    if [ "$ready" -ne 1 ]; then
      printf 'concurrent curl fixture timed out waiting for its peer\n' >&2
      exit 97
    fi
    ;;
esac

cp "$url" "$output"
EOF
chmod +x "$TMP/fake-bin/curl"

cat > "$TMP/fake-bin/sleep" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
chmod +x "$TMP/fake-bin/sleep"

FAKE_CACHE="$TMP/cache"
clear_temp_files() {
  local temp
  for temp in "$FAKE_CACHE"/fixture-1.0.zip.tmp.*; do
    [ -e "$temp" ] || continue
    rm -f -- "$temp"
  done
}
assert_no_temp_files() {
  if compgen -G "$FAKE_CACHE/fixture-1.0.zip.tmp.*" >/dev/null; then
    fail "$1"
  fi
}
fake_compose() {
  local command="${!#}"
  command="${command//\/artifacts-cache/$FAKE_CACHE}"
  sh -c "$command"
}

export PATH="$TMP/fake-bin:$PATH"
export FAKE_CURL_COUNT="$TMP/curl-count"
export FAKE_CURL_MODE=transient
printf '0\n' > "$FAKE_CURL_COUNT"

cd "$TMP"
# fetch_artifact intentionally resolves conformance/artifacts.lock.json from
# the caller's working directory, matching sandbox's live pair scripts.
# shellcheck source=/dev/null
source "$REPO_ROOT/sandbox/bin/fetch-artifact.sh"
PAIR_COMPOSE=(fake_compose)

say "two transient download failures recover on the bounded third attempt"
path="$(fetch_artifact fixture 1.0 cli1)"
[ "$path" = "/artifacts-cache/fixture-1.0.zip" ] \
  || fail "fetch_artifact returned an unexpected container path: $path"
[ "$(cat "$FAKE_CURL_COUNT")" -eq 3 ] \
  || fail "transient fixture used an unexpected number of curl attempts"
cmp -s "$PAYLOAD" "$FAKE_CACHE/fixture-1.0.zip" \
  || fail "successful retry did not publish the exact pinned bytes"
pass "transient failures retry at most three times and then publish verified bytes"

say "a verified cache hit is rechecked locally without another download"
path="$(fetch_artifact fixture 1.0 cli1)"
[ "$(cat "$FAKE_CURL_COUNT")" -eq 3 ] \
  || fail "verified cache hit unexpectedly invoked curl"
pass "cached artifact digest is reverified without refetching"

say "concurrent fetches use distinct temp paths and both publish atomically"
rm -f "$FAKE_CACHE/fixture-1.0.zip"
clear_temp_files
FAKE_CURL_BARRIER="$TMP/concurrent-barrier"
mkdir -p "$FAKE_CURL_BARRIER"
export FAKE_CURL_BARRIER FAKE_CURL_MODE=concurrent
FETCH_A_OUT="$TMP/concurrent-a.out"
FETCH_B_OUT="$TMP/concurrent-b.out"
FETCH_A_ERR="$TMP/concurrent-a.err"
FETCH_B_ERR="$TMP/concurrent-b.err"
fetch_artifact fixture 1.0 cli1 >"$FETCH_A_OUT" 2>"$FETCH_A_ERR" &
FETCH_A_PID=$!
fetch_artifact fixture 1.0 cli1 >"$FETCH_B_OUT" 2>"$FETCH_B_ERR" &
FETCH_B_PID=$!
if ! wait "$FETCH_A_PID"; then
  cat "$FETCH_A_ERR" >&2
  fail "first concurrent fetch failed"
fi
if ! wait "$FETCH_B_PID"; then
  cat "$FETCH_B_ERR" >&2
  fail "second concurrent fetch failed"
fi
[ "$(cat "$FETCH_A_OUT")" = "/artifacts-cache/fixture-1.0.zip" ] \
  || fail "first concurrent fetch returned the wrong cache path"
[ "$(cat "$FETCH_B_OUT")" = "/artifacts-cache/fixture-1.0.zip" ] \
  || fail "second concurrent fetch returned the wrong cache path"
TEMP_PATHS="$(for marker in "$FAKE_CURL_BARRIER"/*; do cat "$marker"; done | sort -u)"
[ "$(printf '%s\n' "$TEMP_PATHS" | awk 'NF' | wc -l)" -eq 2 ] \
  || fail "concurrent fetches did not expose two distinct curl temp paths"
TEMP_PATH_A="$(printf '%s\n' "$TEMP_PATHS" | sed -n '1p')"
TEMP_PATH_B="$(printf '%s\n' "$TEMP_PATHS" | sed -n '2p')"
[ "$TEMP_PATH_A" != "$TEMP_PATH_B" ] \
  || fail "concurrent fetches reused the shared fixed temp path"
case "$TEMP_PATH_A" in
  "$FAKE_CACHE/fixture-1.0.zip.tmp."*) ;;
  *) fail "first concurrent temp path was not created on the cache mount" ;;
esac
case "$TEMP_PATH_B" in
  "$FAKE_CACHE/fixture-1.0.zip.tmp."*) ;;
  *) fail "second concurrent temp path was not created on the cache mount" ;;
esac
[ "$(sha256sum "$FAKE_CACHE/fixture-1.0.zip" | awk '{print $1}')" = "$DIGEST" ] \
  || fail "concurrent fetches did not leave the pinned digest in the final cache"
assert_no_temp_files "concurrent fetches left temp files behind"
pass "concurrent fetches have unique temp paths, verified final bytes, and clean mounts"

say "permanent download exhaustion removes only the partial temp file"
rm -f "$FAKE_CACHE/fixture-1.0.zip"
clear_temp_files
export FAKE_CURL_MODE=permanent
printf '0\n' > "$FAKE_CURL_COUNT"
if fetch_artifact fixture 1.0 cli1 >/dev/null; then
  fail "permanent download failure unexpectedly succeeded"
fi
[ "$(cat "$FAKE_CURL_COUNT")" -eq 3 ] \
  || fail "permanent download failure did not stop at three attempts"
[ ! -e "$FAKE_CACHE/fixture-1.0.zip" ] \
  || fail "permanent failure created a cache file"
assert_no_temp_files "permanent failure left its partial temp file behind"
pass "exhausted transient failures fail closed and clean only the temp path"

say "a digest mismatch is rejected without promotion"
export FAKE_CURL_MODE=wrong
printf '0\n' > "$FAKE_CURL_COUNT"
if fetch_artifact fixture 1.0 cli1 >/dev/null; then
  fail "digest mismatch unexpectedly succeeded"
fi
[ "$(cat "$FAKE_CURL_COUNT")" -eq 1 ] \
  || fail "digest mismatch was retried instead of failing closed"
[ ! -e "$FAKE_CACHE/fixture-1.0.zip" ] \
  || fail "digest mismatch promoted unverified bytes"
assert_no_temp_files "digest mismatch left its partial temp file behind"
pass "digest mismatch remains a non-retryable fail-closed boundary"

say "a cached digest mismatch is surfaced without silent refetch"
printf 'tampered cache bytes\n' > "$FAKE_CACHE/fixture-1.0.zip"
export FAKE_CURL_MODE=transient
printf '0\n' > "$FAKE_CURL_COUNT"
if fetch_artifact fixture 1.0 cli1 >/dev/null; then
  fail "cached digest mismatch unexpectedly succeeded"
fi
[ "$(cat "$FAKE_CURL_COUNT")" -eq 0 ] \
  || fail "cached digest mismatch silently refetched the artifact"
cmp -s <(printf 'tampered cache bytes\n') "$FAKE_CACHE/fixture-1.0.zip" \
  || fail "cached digest mismatch altered the operator-visible cache bytes"
pass "tampered cached bytes remain visible and are never silently replaced"

printf '\n\033[1;32m✔ REGRESS_FETCH_ARTIFACT PASSED\033[0m\n'
