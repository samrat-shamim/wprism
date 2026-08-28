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
cd "$(dirname "$0")/../../.."

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

command -v jq >/dev/null || fail "jq required on PATH"
command -v sha256sum >/dev/null || fail "sha256sum required on PATH"

REPO_ROOT="$(cd .. && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/duo-fetch-artifact.XXXXXX")"
trap 'rm -rf -- "$TMP"' EXIT INT TERM
mkdir -p "$TMP/adapter-packages/fixture/evidence" "$TMP/platform/artifact-library" \
  "$TMP/tools/src" "$TMP/fake-bin" "$TMP/cache"
cp "$REPO_ROOT/tools/artifact-library.php" "$TMP/tools/artifact-library.php"
cp "$REPO_ROOT/tools/src/ArtifactLibrary.php" "$TMP/tools/src/ArtifactLibrary.php"

PAYLOAD="$TMP/pinned-artifact.zip"
printf 'pinned artifact bytes\n' > "$PAYLOAD"
DIGEST="$(sha256sum "$PAYLOAD" | awk '{print $1}')"
printf '{"plugins":{"fixture":{"1.0":{"url":"https://fixture.invalid/pinned.zip","sha256":"%s","role":"exercise-fixture"}}},"themes":{}}\n' \
  "$DIGEST" > "$TMP/adapter-packages/fixture/evidence/artifacts.lock.json"
mkdir -p "$TMP/adapter-packages/other/evidence"
printf '{"plugins":{"other":{"1.0":{"url":"https://fixture.invalid/other.zip","sha256":"%s","role":"exercise-fixture"}}},"themes":{}}\n' \
  "$DIGEST" > "$TMP/adapter-packages/other/evidence/artifacts.lock.json"
printf '{"plugins":{},"themes":{"fixture":{"1.0":{"url":"https://fixture.invalid/theme.zip","sha256":"%s","role":"exercise-fixture"}}}}\n' \
  "$DIGEST" > "$TMP/platform/artifact-library/artifacts.lock.json"

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
    https://*)
      url="$1"
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
printf x >> "$FAKE_CURL_LOG"

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
    : > "$FAKE_CURL_BARRIER/started"
    for wait_round in $(seq 1 500); do
      [ ! -e "$FAKE_CURL_BARRIER/release" ] || break
      /bin/sleep 0.01
    done
    [ -e "$FAKE_CURL_BARRIER/release" ] || {
      printf 'concurrent curl fixture timed out waiting for release\n' >&2
      exit 97
    }
    ;;
esac

test "$url" = 'https://fixture.invalid/pinned.zip'
cp "$FAKE_CURL_PAYLOAD" "$output"
EOF
chmod +x "$TMP/fake-bin/curl"

cat > "$TMP/fake-bin/sleep" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
chmod +x "$TMP/fake-bin/sleep"

FAKE_CACHE="$TMP/cache"
CACHE_FILE="$FAKE_CACHE/plugin-fixture-1.0-$DIGEST.zip"
clear_temp_files() {
  local temp
  for temp in "$CACHE_FILE".tmp.*; do
    [ -e "$temp" ] || continue
    rm -f -- "$temp"
  done
}
assert_no_temp_files() {
  if compgen -G "$CACHE_FILE.tmp.*" >/dev/null; then
    fail "$1"
  fi
}
fake_compose() {
  local -a argv=("$@") command=()
  local index found=0
  for index in "${!argv[@]}"; do
    if [ "${argv[$index]}" = sh ]; then
      command=("${argv[@]:$index}")
      found=1
      break
    fi
  done
  [ "$found" = 1 ] || return 97
  [ "${command[1]}" = /duo-harness/artifact-cache-fetch.sh ] || return 98
  command[1]="$REPO_ROOT/sandbox/bin/artifact-cache-fetch.sh"
  "${command[@]}"
}

export PATH="$TMP/fake-bin:$PATH"
export FAKE_CURL_COUNT="$TMP/curl-count"
export FAKE_CURL_LOG="$TMP/curl-log"
export FAKE_CURL_MODE=transient
export FAKE_CURL_PAYLOAD="$PAYLOAD"
printf '0\n' > "$FAKE_CURL_COUNT"
: > "$FAKE_CURL_LOG"

cd "$TMP"
export DUO_ARTIFACT_LIBRARY_ROOT="$TMP"
# shellcheck source=/dev/null
source "$REPO_ROOT/sandbox/bin/fetch-artifact.sh"
PAIR_COMPOSE=(fake_compose)
export DUO_ARTIFACT_TEST_MODE=1 DUO_ARTIFACT_TEST_CACHE_ROOT="$FAKE_CACHE"

say "package-owned artifact resolution does not enumerate unrelated capsules"
mkdir -p adapter-packages/unrelated/evidence
printf '{"plugins":{"broken":true},"themes":{}}\n' \
  > adapter-packages/unrelated/evidence/artifacts.lock.json
validate_artifact_platform_library \
  || fail "platform artifact lookup was coupled to a malformed adapter capsule"
artifact_library_platform_jq -e '
  (.themes | keys) == ["fixture"] and (.plugins == [])
' >/dev/null || fail "platform artifact lookup did not use only the platform fragment"
export DUO_ARTIFACT_PACKAGE=fixture
validate_artifact_library \
  || fail "one package's valid artifact fragment was coupled to a malformed sibling"
artifact_library_jq -e '.plugins.fixture["1.0"] and (.plugins | has("broken") | not)' >/dev/null \
  || fail "package-owned artifact lookup did not use the isolated package loader"
unset DUO_ARTIFACT_PACKAGE

say "scenario artifact resolution reads only declared participant fragments"
export DUO_ARTIFACT_PARTICIPANTS=fixture,other
validate_artifact_library \
  || fail "scenario participants were coupled to a malformed nonparticipant"
artifact_library_jq -e '
  (.plugins | keys) == ["fixture", "other"] and (.plugins | has("broken") | not)
' >/dev/null || fail "scenario artifact lookup did not use the participant-scoped loader"
THEME_CACHE_FILE="$FAKE_CACHE/theme-fixture-1.0-$DIGEST.zip"
cp "$PAYLOAD" "$THEME_CACHE_FILE"
export DUO_ARTIFACT_OFFLINE=1
theme_path="$(fetch_artifact fixture 1.0 cli1 theme)" \
  || fail "participant-scoped pair bootstrap could not resolve its platform theme"
[ "$theme_path" = "/artifacts-cache/theme-fixture-1.0-$DIGEST.zip" ] \
  || fail "platform theme lookup returned an unexpected cache path: $theme_path"
rm -f "$THEME_CACHE_FILE"
unset DUO_ARTIFACT_OFFLINE
unset DUO_ARTIFACT_PARTICIPANTS
if validate_artifact_library; then
  fail "aggregate artifact validation ignored the deliberately malformed sibling fixture"
fi
rm -rf adapter-packages/unrelated
pass "package-owned artifact resolution uses only its capsule while aggregate validation still sees every owner"

WOO_SCENARIO="$REPO_ROOT/integration-scenarios/woocommerce-rewrite-coinstall/scenario.json"
[ "$(artifact_library_scenario_participants "$WOO_SCENARIO")" \
    = 'polylang,the-events-calendar,woocommerce,yoast' ] \
  || fail "live scenario participant context did not come from its closed scenario record"
pass "live scenario artifact authority is derived from its declared participant list"

say "the typed lock schema refuses unknown roles before artifact resolution"
cp adapter-packages/fixture/evidence/artifacts.lock.json "$TMP/valid-artifacts.lock.json"
jq '.plugins.fixture["1.0"].role = "unreviewed-role"' \
  adapter-packages/fixture/evidence/artifacts.lock.json > "$TMP/invalid-artifacts.lock.json"
mv "$TMP/invalid-artifacts.lock.json" adapter-packages/fixture/evidence/artifacts.lock.json
if validate_artifact_library; then
  fail "typed artifact library accepted an unknown role"
fi
if fetch_artifact fixture 1.0 cli1 >/dev/null; then
  fail "artifact resolver executed an entry with an unknown role"
fi
[ ! -s "$FAKE_CURL_LOG" ] || fail "invalid lock role reached curl"
mv "$TMP/valid-artifacts.lock.json" adapter-packages/fixture/evidence/artifacts.lock.json
validate_artifact_library || fail "valid typed artifact library was refused"
pass "unknown roles fail closed before download or bundle execution"

say "theme entries cannot claim plugin-only certification or refusal roles"
cp platform/artifact-library/artifacts.lock.json "$TMP/valid-artifacts.lock.json"
jq '.themes.fixture["1.0"].role = "certified-boundary"' \
  platform/artifact-library/artifacts.lock.json > "$TMP/invalid-artifacts.lock.json"
mv "$TMP/invalid-artifacts.lock.json" platform/artifact-library/artifacts.lock.json
if validate_artifact_library; then
  fail "typed artifact library accepted a certified-boundary theme that the bundle cannot inventory"
fi
[ ! -s "$FAKE_CURL_LOG" ] || fail "invalid theme role reached curl"
mv "$TMP/valid-artifacts.lock.json" platform/artifact-library/artifacts.lock.json
pass "themes remain execution fixtures and cannot disappear from plugin-only boundary evidence"

say "two transient download failures recover on the bounded third attempt"
path="$(fetch_artifact fixture 1.0 cli1)"
[ "$path" = "/artifacts-cache/plugin-fixture-1.0-$DIGEST.zip" ] \
  || fail "fetch_artifact returned an unexpected container path: $path"
[ "$(cat "$FAKE_CURL_COUNT")" -eq 3 ] \
  || fail "transient fixture used an unexpected number of curl attempts"
cmp -s "$PAYLOAD" "$CACHE_FILE" \
  || fail "successful retry did not publish the exact pinned bytes"
pass "transient failures retry at most three times and then publish verified bytes"

say "a verified cache hit is rechecked locally without another download"
DUO_ARTIFACT_USAGE_LOG="$TMP/usage.ndjson"
export DUO_ARTIFACT_USAGE_LOG
path="$(fetch_artifact fixture 1.0 cli1)"
[ "$(cat "$FAKE_CURL_COUNT")" -eq 3 ] \
  || fail "verified cache hit unexpectedly invoked curl"
USAGE=$(jq -s . "$DUO_ARTIFACT_USAGE_LOG")
jq -e --arg digest "$DIGEST" '
  length == 1 and .[0] == {
    kind:"plugin",path:("/artifacts-cache/plugin-fixture-1.0-" + $digest + ".zip"),
    sha256:$digest,slug:"fixture",source:"cache-hit",version:"1.0"
  }
' <<<"$USAGE" >/dev/null || fail "cache usage record was not closed, exact, and digest-addressed"
unset DUO_ARTIFACT_USAGE_LOG
pass "cached artifact digest is reverified without refetching"

say "plugin and theme namespaces cannot satisfy each other's pins or cache keys"
export DUO_ARTIFACT_OFFLINE=1
if fetch_artifact fixture 1.0 cli1 theme >/dev/null; then
  fail "theme lookup reused the same-slug plugin cache entry"
fi
[ ! -e "$FAKE_CACHE/theme-fixture-1.0-$DIGEST.zip" ] \
  || fail "typed theme refusal created a cache artifact"
unset DUO_ARTIFACT_OFFLINE
pass "kind is part of both lock authority and digest-addressed cache identity"

say "offline mode refuses a cache miss before curl"
rm -f "$CACHE_FILE"
export DUO_ARTIFACT_OFFLINE=1
printf '0\n' > "$FAKE_CURL_COUNT"
if fetch_artifact fixture 1.0 cli1 >/dev/null; then
  fail "offline cache miss unexpectedly succeeded"
fi
[ "$(cat "$FAKE_CURL_COUNT")" -eq 0 ] \
  || fail "offline cache miss reached curl"
[ ! -e "$CACHE_FILE" ] || fail "offline cache miss created a final cache file"
assert_no_temp_files "offline cache miss created a temporary cache file"
unset DUO_ARTIFACT_OFFLINE
pass "offline cache miss refuses before any network fetch"

say "concurrent cold callers serialize one download and share its verified publication"
clear_temp_files
FAKE_CURL_BARRIER="$TMP/concurrent-barrier"
mkdir -p "$FAKE_CURL_BARRIER"
export FAKE_CURL_BARRIER FAKE_CURL_MODE=concurrent
printf '0\n' > "$FAKE_CURL_COUNT"
: > "$FAKE_CURL_LOG"
FETCH_A_OUT="$TMP/concurrent-a.out"
FETCH_B_OUT="$TMP/concurrent-b.out"
FETCH_A_ERR="$TMP/concurrent-a.err"
FETCH_B_ERR="$TMP/concurrent-b.err"
fetch_artifact fixture 1.0 cli1 >"$FETCH_A_OUT" 2>"$FETCH_A_ERR" &
FETCH_A_PID=$!
for wait_round in $(seq 1 500); do
  [ ! -e "$FAKE_CURL_BARRIER/started" ] || break
  /bin/sleep 0.01
done
[ -e "$FAKE_CURL_BARRIER/started" ] || fail "first concurrent fetch never reached curl"
fetch_artifact fixture 1.0 cli1 >"$FETCH_B_OUT" 2>"$FETCH_B_ERR" &
FETCH_B_PID=$!
: > "$FAKE_CURL_BARRIER/release"
if ! wait "$FETCH_A_PID"; then
  cat "$FETCH_A_ERR" >&2
  fail "first concurrent fetch failed"
fi
if ! wait "$FETCH_B_PID"; then
  cat "$FETCH_B_ERR" >&2
  fail "second concurrent fetch failed"
fi
[ "$(cat "$FETCH_A_OUT")" = "/artifacts-cache/plugin-fixture-1.0-$DIGEST.zip" ] \
  || fail "first concurrent fetch returned the wrong cache path"
[ "$(cat "$FETCH_B_OUT")" = "/artifacts-cache/plugin-fixture-1.0-$DIGEST.zip" ] \
  || fail "second concurrent fetch returned the wrong cache path"
[ "$(wc -c < "$FAKE_CURL_LOG" | tr -d ' ')" -eq 1 ] \
  || fail "concurrent cold callers downloaded the same pinned artifact more than once"
grep -Fq 'source=network-fetch' "$FETCH_A_ERR" \
  || fail "first concurrent caller did not record the sole network fetch"
grep -Fq 'source=cache-hit' "$FETCH_B_ERR" \
  || fail "second concurrent caller did not record the locked cache hit"
[ "$(sha256sum "$CACHE_FILE" | awk '{print $1}')" = "$DIGEST" ] \
  || fail "concurrent fetches did not leave the pinned digest in the final cache"
assert_no_temp_files "concurrent fetches left temp files behind"
pass "concurrent cold callers perform one download and both consume the same verified bytes"

say "PHP flock fallback publishes the same verified cache contract"
rm -f "$CACHE_FILE"
clear_temp_files
export FAKE_CURL_MODE=success DUO_ARTIFACT_FORCE_PHP_LOCK=1
printf '0\n' > "$FAKE_CURL_COUNT"
path="$(fetch_artifact fixture 1.0 cli1)"
[ "$path" = "/artifacts-cache/plugin-fixture-1.0-$DIGEST.zip" ] \
  || fail "PHP flock fallback returned the wrong cache path"
[ "$(cat "$FAKE_CURL_COUNT")" -eq 1 ] \
  || fail "PHP flock fallback did not perform exactly one cold download"
cmp -s "$PAYLOAD" "$CACHE_FILE" \
  || fail "PHP flock fallback did not publish the pinned bytes"
unset DUO_ARTIFACT_FORCE_PHP_LOCK
pass "PHP flock fallback is a real tested lock backend, not an unlocked compatibility path"

say "two cold callers also serialize through the PHP flock fallback"
rm -f "$CACHE_FILE"
clear_temp_files
rm -rf "$FAKE_CURL_BARRIER"
mkdir -p "$FAKE_CURL_BARRIER"
export FAKE_CURL_MODE=concurrent DUO_ARTIFACT_FORCE_PHP_LOCK=1
printf '0\n' > "$FAKE_CURL_COUNT"
: > "$FAKE_CURL_LOG"
fetch_artifact fixture 1.0 cli1 >"$FETCH_A_OUT" 2>"$FETCH_A_ERR" &
FETCH_A_PID=$!
for wait_round in $(seq 1 500); do
  [ ! -e "$FAKE_CURL_BARRIER/started" ] || break
  /bin/sleep 0.01
done
[ -e "$FAKE_CURL_BARRIER/started" ] || fail "PHP-lock fetch never reached curl"
fetch_artifact fixture 1.0 cli1 >"$FETCH_B_OUT" 2>"$FETCH_B_ERR" &
FETCH_B_PID=$!
: > "$FAKE_CURL_BARRIER/release"
wait "$FETCH_A_PID" || { cat "$FETCH_A_ERR" >&2; fail "first PHP-lock fetch failed"; }
wait "$FETCH_B_PID" || { cat "$FETCH_B_ERR" >&2; fail "second PHP-lock fetch failed"; }
[ "$(wc -c < "$FAKE_CURL_LOG" | tr -d ' ')" -eq 1 ] \
  || fail "PHP flock fallback allowed more than one cold download"
grep -Fq 'source=network-fetch' "$FETCH_A_ERR" \
  || fail "first PHP-lock caller did not record the sole network fetch"
grep -Fq 'source=cache-hit' "$FETCH_B_ERR" \
  || fail "second PHP-lock caller did not record the cache hit"
cmp -s "$PAYLOAD" "$CACHE_FILE" \
  || fail "PHP-lock concurrent callers did not publish the pinned bytes"
unset DUO_ARTIFACT_FORCE_PHP_LOCK
pass "PHP flock fallback serializes concurrent cold callers to one download"

say "declared archive roots replace only a safe stale canonical destination"
ARCHIVE_ROOT="$TMP/archive-root"
mkdir -p "$ARCHIVE_ROOT/paid-memberships-pro-3.8.3" "$ARCHIVE_ROOT/paid-memberships-pro"
printf 'pinned bytes\n' > "$ARCHIVE_ROOT/paid-memberships-pro-3.8.3/plugin.php"
printf 'stale bytes\n' > "$ARCHIVE_ROOT/paid-memberships-pro/stale.php"
sh "$REPO_ROOT/sandbox/bin/artifact-archive-root.sh" "$ARCHIVE_ROOT" \
  paid-memberships-pro-3.8.3 paid-memberships-pro
[ -f "$ARCHIVE_ROOT/paid-memberships-pro/plugin.php" ] \
  || fail "declared archive root was not normalized to the canonical slug"
[ ! -e "$ARCHIVE_ROOT/paid-memberships-pro/stale.php" ] \
  || fail "stale canonical destination was not replaced"
mkdir -p "$ARCHIVE_ROOT/paid-memberships-pro-3.8.3"
rm -rf "$ARCHIVE_ROOT/paid-memberships-pro"
ln -s "$TMP/outside" "$ARCHIVE_ROOT/paid-memberships-pro"
if sh "$REPO_ROOT/sandbox/bin/artifact-archive-root.sh" "$ARCHIVE_ROOT" \
  paid-memberships-pro-3.8.3 paid-memberships-pro; then
  fail "archive-root normalization accepted a symlink destination"
fi
[ -L "$ARCHIVE_ROOT/paid-memberships-pro" ] \
  || fail "archive-root refusal changed the unsafe destination"
rm -f "$ARCHIVE_ROOT/paid-memberships-pro"
rm -rf "$ARCHIVE_ROOT/paid-memberships-pro-3.8.3"
if sh "$REPO_ROOT/sandbox/bin/artifact-archive-root.sh" "$ARCHIVE_ROOT" \
  paid-memberships-pro-3.8.3 paid-memberships-pro; then
  fail "archive-root normalization accepted an absent declared source"
fi
pass "archive-root normalization handles repeat installs and refuses unsafe or absent roots"

say "permanent download exhaustion removes only the partial temp file"
rm -f "$CACHE_FILE"
clear_temp_files
export FAKE_CURL_MODE=permanent
printf '0\n' > "$FAKE_CURL_COUNT"
if fetch_artifact fixture 1.0 cli1 >/dev/null; then
  fail "permanent download failure unexpectedly succeeded"
fi
[ "$(cat "$FAKE_CURL_COUNT")" -eq 3 ] \
  || fail "permanent download failure did not stop at three attempts"
[ ! -e "$CACHE_FILE" ] \
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
[ ! -e "$CACHE_FILE" ] \
  || fail "digest mismatch promoted unverified bytes"
assert_no_temp_files "digest mismatch left its partial temp file behind"
pass "digest mismatch remains a non-retryable fail-closed boundary"

say "a cached digest mismatch is surfaced without silent refetch"
printf 'tampered cache bytes\n' > "$CACHE_FILE"
export FAKE_CURL_MODE=transient
printf '0\n' > "$FAKE_CURL_COUNT"
if fetch_artifact fixture 1.0 cli1 >/dev/null; then
  fail "cached digest mismatch unexpectedly succeeded"
fi
[ "$(cat "$FAKE_CURL_COUNT")" -eq 0 ] \
  || fail "cached digest mismatch silently refetched the artifact"
cmp -s <(printf 'tampered cache bytes\n') "$CACHE_FILE" \
  || fail "cached digest mismatch altered the operator-visible cache bytes"
pass "tampered cached bytes remain visible and are never silently replaced"

printf '\n\033[1;32m✔ REGRESS_FETCH_ARTIFACT PASSED\033[0m\n'
