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
ACTIVE_SHELL_HELPER="$REPO_ROOT/tools/active-shell-source.php"
[ -f "$ACTIVE_SHELL_HELPER" ] && [ ! -L "$ACTIVE_SHELL_HELPER" ] \
  || fail "active-shell source helper is missing or unsafe: $ACTIVE_SHELL_HELPER"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/wprism-fetch-artifact.XXXXXX")"
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
printf '{"plugins":{},"themes":{"fixture":{"1.0":{"url":"https://fixture.invalid/theme.zip","sha256":"%s","role":"exercise-fixture","archive_root":"fixture-theme-source"}}}}\n' \
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
  [ "${command[1]}" = /wprism-harness/artifact-cache-fetch.sh ] || return 98
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
export WPRISM_ARTIFACT_LIBRARY_ROOT="$TMP"
# shellcheck source=/dev/null
source "$REPO_ROOT/sandbox/bin/fetch-artifact.sh"
PAIR_COMPOSE=(fake_compose)
export WPRISM_ARTIFACT_TEST_MODE=1 WPRISM_ARTIFACT_TEST_CACHE_ROOT="$FAKE_CACHE"

say "package-owned artifact resolution does not enumerate unrelated capsules"
mkdir -p adapter-packages/unrelated/evidence
printf '{"plugins":{"broken":true},"themes":{}}\n' \
  > adapter-packages/unrelated/evidence/artifacts.lock.json
validate_artifact_platform_library \
  || fail "platform artifact lookup was coupled to a malformed adapter capsule"
artifact_library_platform_jq -e '
  (.themes | keys) == ["fixture"] and (.plugins == []) and
  .themes.fixture["1.0"].archive_root == "fixture-theme-source"
' >/dev/null || fail "platform artifact lookup did not use only the platform fragment"
export WPRISM_ARTIFACT_PLATFORM_ONLY=1
validate_artifact_library \
  || fail "explicit platform-only artifact authority was coupled to a malformed adapter capsule"
artifact_library_jq -e '
  (.themes | keys) == ["fixture"] and (.plugins == [])
' >/dev/null || fail "platform-only generic lookup widened into adapter fragments"
CHILD_PLATFORM=$(bash -c '
set -euo pipefail
. "$1/sandbox/bin/artifact-library.sh"
validate_artifact_library
artifact_library_jq -r "(.plugins | length | tostring) + \":\" + (.themes | keys | join(\",\"))"
' _ "$REPO_ROOT") || fail "an exported platform-only context was lost across a pair child process"
[ "$CHILD_PLATFORM" = '0:fixture' ] \
  || fail "a platform-only child process widened artifact authority into adapter capsules: $CHILD_PLATFORM"
unset WPRISM_ARTIFACT_PLATFORM_ONLY
grep -Fq 'elif [ "$MANIFEST" = core ] || [ "$MANIFEST" = fse ]; then' \
  "$REPO_ROOT/sandbox/conformance/run.sh" \
  || fail "core/FSE conformance no longer selects the platform-only artifact lane"
grep -Fq 'export WPRISM_ARTIFACT_PLATFORM_ONLY=1' "$REPO_ROOT/sandbox/conformance/run.sh" \
  || fail "core/FSE conformance no longer exports platform-only artifact authority to pair.sh"

say "artifact library roots canonicalize before package inference"
export PACKAGE_ROOT="$TMP/adapter-packages/fixture"
export WPRISM_ARTIFACT_LIBRARY_ROOT="$TMP/adapter-packages/../."
artifact_library_jq -e '
  (.plugins | keys) == ["fixture"] and (.plugins | has("unrelated") | not)
' >/dev/null \
  || fail "a noncanonical-but-equivalent artifact root widened PACKAGE_ROOT inference into sibling capsules"
ROOT_CHILD_PACKAGE=$(bash -c '
set -euo pipefail
. "$1/sandbox/bin/artifact-library.sh"
artifact_library_jq -r ".plugins | keys | join(\",\")"
' _ "$REPO_ROOT") || fail "canonical artifact root/package inference was lost across a child process"
[ "$ROOT_CHILD_PACKAGE" = fixture ] \
  || fail "a child widened noncanonical artifact root authority beyond its package: $ROOT_CHILD_PACKAGE"
export WPRISM_ARTIFACT_LIBRARY_ROOT=relative/artifact/root
if artifact_library_repo_root >/dev/null 2>&1; then
  fail "a relative WPRISM_ARTIFACT_LIBRARY_ROOT was accepted"
fi
export WPRISM_ARTIFACT_LIBRARY_ROOT="$TMP/does-not-exist"
if artifact_library_repo_root >/dev/null 2>&1; then
  fail "a missing WPRISM_ARTIFACT_LIBRARY_ROOT was accepted"
fi
export WPRISM_ARTIFACT_LIBRARY_ROOT="$TMP"
export PACKAGE_ROOT="$TMP/platform"
if artifact_library_package_context >/dev/null 2>&1; then
  fail "a PACKAGE_ROOT outside adapter-packages was accepted as unscoped aggregate authority"
fi
unset PACKAGE_ROOT
pass "explicit artifact roots are physical repository roots before package containment is classified"

export WPRISM_ARTIFACT_PACKAGE=fixture
validate_artifact_library \
  || fail "one package's valid artifact fragment was coupled to a malformed sibling"
artifact_library_jq -e '.plugins.fixture["1.0"] and (.plugins | has("broken") | not)' >/dev/null \
  || fail "package-owned artifact lookup did not use the isolated package loader"
CHILD_PACKAGE=$(bash -c '
set -euo pipefail
. "$1/sandbox/bin/artifact-library.sh"
validate_artifact_library
artifact_library_jq -r ".plugins | keys | join(\",\")"
' _ "$REPO_ROOT") || fail "an exported package context was lost across a child process"
[ "$CHILD_PACKAGE" = fixture ] \
  || fail "a package child process widened artifact authority beyond its selected capsule: $CHILD_PACKAGE"
unset WPRISM_ARTIFACT_PACKAGE

# A package live shell always belongs to exactly one capsule, even before it
# opts into cached artifacts. Requiring one canonical first-three-statement
# preamble makes arbitrary variable/wrapper flag construction irrelevant;
# ActiveShellSource removes comments and heredoc bodies before this order check.
validate_package_artifact_caller() { # <package tests/live shell suite>
  local package_live="$1" active diagnostic status
  if ! active="$(php "$ACTIVE_SHELL_HELPER" "$package_live")"; then
    printf 'package live caller has shell source that cannot be classified safely: %s\n' "$package_live" >&2
    return 1
  fi

  if diagnostic="$(printf '%s\n' "$active" | awk \
    -v root_stmt='PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"' \
    -v export_stmt='export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"' '
    {
      statement = $0
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", statement)
      normalized = statement
      # Adjacent quoted and escaped shell fragments form one word at runtime.
      # Normalize those bytes before looking for later authority mutations.
      gsub(/[\047"\\]/, "", normalized)
      if (statement != "") {
        statements++
        if (statements <= 3) {
          preamble[statements] = statement
        } else if (index(normalized, "WPRISM_ARTIFACT_PACKAGE") != 0) {
          authority_mutation = 1
        }
      }
    }
    END {
      if (preamble[1] != "set -euo pipefail" || preamble[2] != root_stmt || preamble[3] != export_stmt) {
        printf "caller lacks the canonical first-three-active-statement package authority preamble"
        exit 1
      }
      if (authority_mutation) {
        printf "caller mutates package authority after the canonical preamble"
        exit 1
      }
    }
  ')"; then
    return 0
  else
    status=$?
  fi
  printf 'package live caller does not establish unconditional artifact scope: %s (%s; exit %s)\n' \
    "$package_live" "${diagnostic:-active-shell classification refused}" "$status" >&2
  return 1
}

validate_package_artifact_callers() { # <adapter-packages root>
  local packages="$1" package_live
  while IFS= read -r package_live; do
    validate_package_artifact_caller "$package_live" || return 1
  done < <(find "$packages" -type f -path '*/tests/live/*.sh' -print | LC_ALL=C sort)
}

CALLER_PROBE="$TMP/artifact-caller-probe/adapter-packages/fourth/tests/live"
mkdir -p "$CALLER_PROBE"
cat > "$CALLER_PROBE/regress_fourth_artifact_pair.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
PAIR_BIN=bin/pair.sh
UP_FLAGS=(--arti""facts)
run_pair() { bash "$PAIR_BIN" "$@"; }
run_pair up fourth 9901 9902 "${UP_FLAGS[@]}"
EOF
if validate_package_artifact_callers "$TMP/artifact-caller-probe/adapter-packages" >/dev/null 2>&1; then
  fail 'package live discovery accepted a split-token variable/array/wrapper caller without its canonical scope preamble'
fi
cat > "$CALLER_PROBE/regress_fourth_artifact_pair.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
PAIR_BIN=bin/pair.sh
UP_FLAGS=(--arti""facts)
run_pair() { bash "$PAIR_BIN" "$@"; }
run_pair up fourth 9901 9902 "${UP_FLAGS[@]}"
EOF
validate_package_artifact_callers "$TMP/artifact-caller-probe/adapter-packages" \
  || fail 'package live discovery refused a split-token caller with the canonical scope preamble'
cat > "$CALLER_PROBE/regress_comment_only.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
# export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
exit 0
EOF
if validate_package_artifact_callers "$TMP/artifact-caller-probe/adapter-packages" >/dev/null 2>&1; then
  fail 'an export present only in a shell comment satisfied package authority'
fi
cat > "$CALLER_PROBE/regress_comment_only.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
# Documentation example only: pair.sh up demo 9901 9902 --artifacts
exit 0
EOF
validate_package_artifact_callers "$TMP/artifact-caller-probe/adapter-packages" \
  || fail 'a comment-only live script with unconditional package authority was refused'

cat > "$CALLER_PROBE/regress_late_scope.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
PAIR_BIN=bin/pair.sh
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
PREFIX=--arti
SUFFIX=facts
bash "$PAIR_BIN" up fourth 9901 9902 "${PREFIX}${SUFFIX}"
EOF
if validate_package_artifact_callers "$TMP/artifact-caller-probe/adapter-packages" >/dev/null 2>&1; then
  fail 'package authority established after another active statement was accepted'
fi
cat > "$CALLER_PROBE/regress_late_scope.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
PAIR_BIN=bin/pair.sh
PREFIX=--arti
SUFFIX=facts
bash "$PAIR_BIN" up fourth 9901 9902 "${PREFIX}${SUFFIX}"
EOF
validate_package_artifact_callers "$TMP/artifact-caller-probe/adapter-packages" \
  || fail 'a fully dynamic artifact flag bypassed unconditional package authority'

cat > "$CALLER_PROBE/regress_scope_unset.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
unset WPRISM_ARTIFACT_PACKAGE
bash bin/pair.sh up fourth 9901 9902 --artifacts
EOF
if validate_package_artifact_caller "$CALLER_PROBE/regress_scope_unset.sh" >/dev/null 2>&1; then
  fail 'a later unset of package artifact authority passed the live caller guard'
fi
cat > "$CALLER_PROBE/regress_scope_function_redirect.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
redirect_scope() { export WPRISM_ARTIFACT_PACKAGE=other; }
redirect_scope
bash bin/pair.sh up fourth 9901 9902 --artifacts
EOF
if validate_package_artifact_caller "$CALLER_PROBE/regress_scope_function_redirect.sh" >/dev/null 2>&1; then
  fail 'a function-body package authority redirection passed the live caller guard'
fi
cat > "$CALLER_PROBE/regress_scope_compound_redirect.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
if true; then WPRISM_ARTIFACT_PACKAGE=other; export WPRISM_ARTIFACT_PACKAGE; fi
bash bin/pair.sh up fourth 9901 9902 --artifacts
EOF
if validate_package_artifact_caller "$CALLER_PROBE/regress_scope_compound_redirect.sh" >/dev/null 2>&1; then
  fail 'a compound-statement package authority redirection passed the live caller guard'
fi
cat > "$CALLER_PROBE/regress_scope_spliced_redirect.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
export WPRISM_ARTIFACT_""PACKAGE=other
bash bin/pair.sh up fourth 9901 9902 --artifacts
EOF
if validate_package_artifact_caller "$CALLER_PROBE/regress_scope_spliced_redirect.sh" >/dev/null 2>&1; then
  fail 'a token-spliced package authority redirection passed the live caller guard'
fi
cat > "$CALLER_PROBE/regress_scope_spliced_unset.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
unset WPRISM_ARTIFACT_''PACKAGE
bash bin/pair.sh up fourth 9901 9902 --artifacts
EOF
if validate_package_artifact_caller "$CALLER_PROBE/regress_scope_spliced_unset.sh" >/dev/null 2>&1; then
  fail 'a token-spliced package authority unset passed the live caller guard'
fi

cat > "$CALLER_PROBE/regress_malformed_scope.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
exit 0
EOF
if validate_package_artifact_callers "$TMP/artifact-caller-probe/adapter-packages" >/dev/null 2>&1; then
  fail 'a malformed package-root preamble was accepted'
fi
rm -f "$CALLER_PROBE/regress_malformed_scope.sh"
validate_package_artifact_callers "$REPO_ROOT/adapter-packages" \
  || fail 'a discovered package live caller does not establish unconditional package scope'
pass 'every package live caller establishes active, ordered scope before arbitrary shell flag construction'

say "package environment and physical roots must agree"
export PACKAGE_ROOT="$TMP/adapter-packages/fixture"
export WPRISM_ARTIFACT_PACKAGE=other
if artifact_library_package_context >/dev/null 2>&1; then
  fail 'explicit package authority silently overrode a conflicting physical PACKAGE_ROOT'
fi
export WPRISM_ARTIFACT_PACKAGE=fixture
[ "$(artifact_library_package_context)" = fixture ] \
  || fail 'matching explicit package authority and physical PACKAGE_ROOT were refused'
unset WPRISM_ARTIFACT_PACKAGE
export -n PACKAGE_ROOT
UNSCOPED_CHILD=$(bash -c '
set -euo pipefail
. "$1/sandbox/bin/artifact-library.sh"
scope="$(artifact_library_package_context)"
[ -z "$scope" ]
printf unscoped
' _ "$REPO_ROOT") || fail 'unset child artifact scope did not become unscoped'
[ "$UNSCOPED_CHILD" = unscoped ] \
  || fail "unset package authority did not demonstrate an unscoped child: $UNSCOPED_CHILD"
unset PACKAGE_ROOT
pass 'runtime artifact scope refuses conflicts and exposes an unset child as unscoped'

say "scenario artifact resolution reads only declared participant fragments"
export WPRISM_ARTIFACT_PARTICIPANTS=fixture,other
validate_artifact_library \
  || fail "scenario participants were coupled to a malformed nonparticipant"
artifact_library_jq -e '
  (.plugins | keys) == ["fixture", "other"] and (.plugins | has("broken") | not)
' >/dev/null || fail "scenario artifact lookup did not use the participant-scoped loader"
THEME_CACHE_FILE="$FAKE_CACHE/theme-fixture-1.0-$DIGEST.zip"
cp "$PAYLOAD" "$THEME_CACHE_FILE"
export WPRISM_ARTIFACT_OFFLINE=1
theme_path="$(fetch_artifact fixture 1.0 cli1 theme)" \
  || fail "participant-scoped pair bootstrap could not resolve its platform theme"
[ "$theme_path" = "/artifacts-cache/theme-fixture-1.0-$DIGEST.zip" ] \
  || fail "platform theme lookup returned an unexpected cache path: $theme_path"
rm -f "$THEME_CACHE_FILE"
unset WPRISM_ARTIFACT_OFFLINE
unset WPRISM_ARTIFACT_PARTICIPANTS
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
WPRISM_ARTIFACT_USAGE_LOG="$TMP/usage.ndjson"
export WPRISM_ARTIFACT_USAGE_LOG
path="$(fetch_artifact fixture 1.0 cli1)"
[ "$(cat "$FAKE_CURL_COUNT")" -eq 3 ] \
  || fail "verified cache hit unexpectedly invoked curl"
USAGE=$(jq -s . "$WPRISM_ARTIFACT_USAGE_LOG")
jq -e --arg digest "$DIGEST" '
  length == 1 and .[0] == {
    kind:"plugin",path:("/artifacts-cache/plugin-fixture-1.0-" + $digest + ".zip"),
    sha256:$digest,slug:"fixture",source:"cache-hit",version:"1.0"
  }
' <<<"$USAGE" >/dev/null || fail "cache usage record was not closed, exact, and digest-addressed"
unset WPRISM_ARTIFACT_USAGE_LOG
pass "cached artifact digest is reverified without refetching"

say "plugin and theme namespaces cannot satisfy each other's pins or cache keys"
export WPRISM_ARTIFACT_OFFLINE=1
if fetch_artifact fixture 1.0 cli1 theme >/dev/null; then
  fail "theme lookup reused the same-slug plugin cache entry"
fi
[ ! -e "$FAKE_CACHE/theme-fixture-1.0-$DIGEST.zip" ] \
  || fail "typed theme refusal created a cache artifact"
unset WPRISM_ARTIFACT_OFFLINE
pass "kind is part of both lock authority and digest-addressed cache identity"

say "offline mode refuses a cache miss before curl"
rm -f "$CACHE_FILE"
export WPRISM_ARTIFACT_OFFLINE=1
printf '0\n' > "$FAKE_CURL_COUNT"
if fetch_artifact fixture 1.0 cli1 >/dev/null; then
  fail "offline cache miss unexpectedly succeeded"
fi
[ "$(cat "$FAKE_CURL_COUNT")" -eq 0 ] \
  || fail "offline cache miss reached curl"
[ ! -e "$CACHE_FILE" ] || fail "offline cache miss created a final cache file"
assert_no_temp_files "offline cache miss created a temporary cache file"
unset WPRISM_ARTIFACT_OFFLINE
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
export FAKE_CURL_MODE=success WPRISM_ARTIFACT_FORCE_PHP_LOCK=1
printf '0\n' > "$FAKE_CURL_COUNT"
path="$(fetch_artifact fixture 1.0 cli1)"
[ "$path" = "/artifacts-cache/plugin-fixture-1.0-$DIGEST.zip" ] \
  || fail "PHP flock fallback returned the wrong cache path"
[ "$(cat "$FAKE_CURL_COUNT")" -eq 1 ] \
  || fail "PHP flock fallback did not perform exactly one cold download"
cmp -s "$PAYLOAD" "$CACHE_FILE" \
  || fail "PHP flock fallback did not publish the pinned bytes"
unset WPRISM_ARTIFACT_FORCE_PHP_LOCK
pass "PHP flock fallback is a real tested lock backend, not an unlocked compatibility path"

say "two cold callers also serialize through the PHP flock fallback"
rm -f "$CACHE_FILE"
clear_temp_files
rm -rf "$FAKE_CURL_BARRIER"
mkdir -p "$FAKE_CURL_BARRIER"
export FAKE_CURL_MODE=concurrent WPRISM_ARTIFACT_FORCE_PHP_LOCK=1
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
unset WPRISM_ARTIFACT_FORCE_PHP_LOCK
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
