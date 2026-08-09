#!/usr/bin/env bash
# Regression — bounded retries for pinned artifact downloads.
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

say "permanent download exhaustion removes only the partial temp file"
rm -f "$FAKE_CACHE/fixture-1.0.zip" "$FAKE_CACHE/fixture-1.0.zip.tmp"
export FAKE_CURL_MODE=permanent
printf '0\n' > "$FAKE_CURL_COUNT"
if fetch_artifact fixture 1.0 cli1 >/dev/null; then
  fail "permanent download failure unexpectedly succeeded"
fi
[ "$(cat "$FAKE_CURL_COUNT")" -eq 3 ] \
  || fail "permanent download failure did not stop at three attempts"
[ ! -e "$FAKE_CACHE/fixture-1.0.zip" ] \
  || fail "permanent failure created a cache file"
[ ! -e "$FAKE_CACHE/fixture-1.0.zip.tmp" ] \
  || fail "permanent failure left its partial temp file behind"
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
[ ! -e "$FAKE_CACHE/fixture-1.0.zip.tmp" ] \
  || fail "digest mismatch left its partial temp file behind"
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
