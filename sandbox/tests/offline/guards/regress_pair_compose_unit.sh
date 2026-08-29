#!/usr/bin/env bash
# Offline regression — issue #3355's extracted pair-compose discovery filter.
#
# pair_compose_configure() (build the docker compose argv, export/persist the
# canonical bind-mount source) is already exercised for real by
# regress_pair_bootstrap_unit.sh/regress_pair_candidate_source.sh, which run
# the shipped pair.sh's own
# up/stop/start/destroy against a fake docker. This suite covers what those
# two don't: the jq filtering logic inside pair_compose_live_pairs()/
# pair_compose_stopped_pairs() — the exact rules deciding which `docker
# compose ls` rows are a WPrism pair at all — fed synthetic `compose ls
# --format json` output directly, no real docker or pair lifecycle involved.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/wprism-pair-compose-unit.XXXXXX")"
trap 'rm -rf -- "$TMP"' EXIT

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

COMPOSE_LIB="$ROOT/sandbox/lib/pair_compose.sh"
[ -r "$COMPOSE_LIB" ] || fail "pair-compose library is missing"
bash -n "$COMPOSE_LIB" || fail "pair-compose library does not parse"
command -v jq >/dev/null 2>&1 || fail "jq is required for this regression"

# A fake `docker` on PATH answering only `compose ls [-a] --format json`,
# with a fixed-shape payload this run controls via WPRISM_PAIR_TEST_COMPOSE_LS.
# Any other invocation is a sentinel failure so a call this suite did not
# expect (a real docker escape) is loud, not silently skipped.
FAKE_BIN="$TMP/bin"
mkdir -p "$FAKE_BIN"
cat > "$FAKE_BIN/docker" <<'FAKE_DOCKER'
#!/usr/bin/env bash
set -euo pipefail
if [ "${1:-}" = compose ] && [ "${2:-}" = ls ]; then
  if [ -n "${WPRISM_PAIR_TEST_COMPOSE_LS_FAIL:-}" ]; then
    exit 1
  fi
  printf '%s' "${WPRISM_PAIR_TEST_COMPOSE_LS?}"
  exit 0
fi
if [ "${1:-}" = inspect ] && [ "${2:-}" = --format ]; then
  case "${4:-}" in
    "wprism-${WPRISM_PAIR_TEST_INSPECT_PAIR:-missing}-wp1-1") printf '%s\n' "${WPRISM_PAIR_TEST_INSPECT_PORT1:-}" ;;
    "wprism-${WPRISM_PAIR_TEST_INSPECT_PAIR:-missing}-wp2-1") printf '%s\n' "${WPRISM_PAIR_TEST_INSPECT_PORT2:-}" ;;
    *) exit 1 ;;
  esac
  exit 0
fi
if [ "${1:-}" = ps ] && [ "${2:-}" = -a ]; then
  if [ -n "${WPRISM_PAIR_TEST_INSPECT_PAIR:-}" ]; then
    printf 'wprism-%s-wp1-1\nwprism-%s-wp2-1\n' "$WPRISM_PAIR_TEST_INSPECT_PAIR" "$WPRISM_PAIR_TEST_INSPECT_PAIR"
  fi
  exit 0
fi
printf 'FAKE-DOCKER-SENTINEL: %s\n' "$*" >&2
exit 42
FAKE_DOCKER
chmod +x "$FAKE_BIN/docker"
export PATH="$FAKE_BIN:$PATH"

source "$COMPOSE_LIB"

say "wprism-prefixed pair.yml projects are matched; the legacy wprism-sandbox project is not"
# wprism-sandbox is a real, lowercase-letters-only project name that would pass
# a naive naming-convention filter -- it is excluded here because its own
# ConfigFiles names sandbox/docker-compose.yml, never pair.yml (the
# documented reason this filter keys on ConfigFiles, not the Name pattern).
export WPRISM_PAIR_TEST_COMPOSE_LS='[
  {"Name":"wprism-abc12","Status":"running(4)","ConfigFiles":"/repo/sandbox/pair.yml"},
  {"Name":"wprism-sandbox","Status":"running(1)","ConfigFiles":"/repo/sandbox/docker-compose.yml"},
  {"Name":"other-project","Status":"running(1)","ConfigFiles":"/repo/sandbox/pair.yml"}
]'
got="$(pair_compose_live_pairs)"
[ "$got" = "abc12" ] || fail "expected only 'abc12', got: $got"
pass "wprism-sandbox and a non-wprism-prefixed project are both excluded; only the real pair matches"

say "a project with multiple ConfigFiles still matches when pair.yml is one of them"
export WPRISM_PAIR_TEST_COMPOSE_LS='[
  {"Name":"wprism-multi","Status":"running(2)","ConfigFiles":"/repo/sandbox/pair.yml,/repo/sandbox/pair.http.yml"}
]'
got="$(pair_compose_live_pairs)"
[ "$got" = "multi" ] || fail "expected 'multi' from a comma-joined ConfigFiles list, got: $got"
pass "pair.yml matches whether it is the only overlay or joined with others"

say "stopped_pairs excludes anything whose Status contains running, live_pairs does not filter by status at all"
export WPRISM_PAIR_TEST_COMPOSE_LS='[
  {"Name":"wprism-up","Status":"running(2)","ConfigFiles":"/repo/sandbox/pair.yml"},
  {"Name":"wprism-down","Status":"exited(2)","ConfigFiles":"/repo/sandbox/pair.yml"}
]'
got="$(pair_compose_live_pairs)"
expected="$(printf 'wprism-up\nwprism-down' | sed 's/^wprism-//' | sort)"
[ "$(printf '%s' "$got" | sort)" = "$expected" ] || fail "live_pairs must not filter on Status at all, got: $got"
got="$(pair_compose_stopped_pairs)"
[ "$got" = "down" ] || fail "expected only 'down' (Status has no 'running'), got: $got"
pass "stopped_pairs' Status filter is independent of live_pairs' own (status-blind) query"

say "stopped container bindings remain visible even though no listener exists"
export WPRISM_PAIR_TEST_COMPOSE_LS='[
  {"Name":"wprism-down","Status":"exited(2)","ConfigFiles":"/repo/sandbox/pair.yml"},
  {"Name":"wprism-headless","Status":"exited(2)","ConfigFiles":"/repo/sandbox/pair.yml"}
]'
export WPRISM_PAIR_TEST_INSPECT_PAIR=down WPRISM_PAIR_TEST_INSPECT_PORT1=9300 WPRISM_PAIR_TEST_INSPECT_PORT2=9301
got="$(pair_compose_all_bound_ports)"
[ "$got" = $'down\t9300\ndown\t9301' ] \
  || fail "expected stopped pair bindings plus a successful no-port headless project, got: $got"
unset WPRISM_PAIR_TEST_INSPECT_PAIR WPRISM_PAIR_TEST_INSPECT_PORT1 WPRISM_PAIR_TEST_INSPECT_PORT2
pass "lease preflight can enumerate persisted ports from stopped web containers"

say "rows with a missing or wrong-typed ConfigFiles/Name are excluded, not fatal"
export WPRISM_PAIR_TEST_COMPOSE_LS='[
  {"Name":"wprism-good","Status":"running(1)","ConfigFiles":"/repo/sandbox/pair.yml"},
  {"Name":"wprism-noconfig","Status":"running(1)"},
  {"Name":123,"Status":"running(1)","ConfigFiles":"/repo/sandbox/pair.yml"},
  {"Status":"running(1)","ConfigFiles":"/repo/sandbox/pair.yml"},
  {"Name":"wprism-arrayconfig","Status":"running(1)","ConfigFiles":["/repo/sandbox/pair.yml"]}
]'
got="$(pair_compose_live_pairs)"
[ "$got" = "good" ] || fail "malformed rows should be silently excluded, not fatal or matched; got: $got"
pass "a missing ConfigFiles, a numeric Name, a missing Name, and an array-typed ConfigFiles are all excluded without failing the whole query"

say "malformed compose ls output is a refusal, never an empty list"
export WPRISM_PAIR_TEST_COMPOSE_LS='{"not":"an array"}'
if pair_compose_live_pairs >/dev/null 2>&1; then
  fail "a non-array compose ls payload must refuse (non-zero), not silently return empty"
fi
pass "a non-array JSON root refuses rather than reporting zero live pairs"

export WPRISM_PAIR_TEST_COMPOSE_LS='not even json'
if pair_compose_live_pairs >/dev/null 2>&1; then
  fail "unparseable compose ls output must refuse, not silently return empty"
fi
pass "unparseable JSON refuses rather than reporting zero live pairs"

unset WPRISM_PAIR_TEST_COMPOSE_LS
export WPRISM_PAIR_TEST_COMPOSE_LS_FAIL=1
if pair_compose_live_pairs >/dev/null 2>&1; then
  fail "a failing 'docker compose ls' must refuse, not silently return empty"
fi
unset WPRISM_PAIR_TEST_COMPOSE_LS_FAIL
pass "a failing docker invocation refuses rather than reporting zero live pairs"

say "empty compose ls output (no projects at all) also refuses, not an empty list"
export WPRISM_PAIR_TEST_COMPOSE_LS=''
if pair_compose_live_pairs >/dev/null 2>&1; then
  fail "an empty compose ls payload must refuse, matching the documented 'never an empty list' contract"
fi
pass "an empty payload refuses, consistent with malformed/failed compose ls"
unset WPRISM_PAIR_TEST_COMPOSE_LS

say "jq missing from PATH is a refusal too, not an empty list"
# A PATH with the fake docker but no jq at all -- the query's own second
# stage (docker compose ls succeeds; the pipe into jq is what's missing).
NO_JQ_BIN="$TMP/no-jq-bin"
mkdir -p "$NO_JQ_BIN"
ln -sf "$FAKE_BIN/docker" "$NO_JQ_BIN/docker"
export WPRISM_PAIR_TEST_COMPOSE_LS='[{"Name":"wprism-abc12","Status":"running(1)","ConfigFiles":"/repo/sandbox/pair.yml"}]'
if (PATH="$NO_JQ_BIN"; pair_compose_live_pairs) >/dev/null 2>&1; then
  fail "a PATH with no jq must refuse (non-zero from the pipeline), not silently return empty"
fi
pass "a missing jq refuses rather than reporting zero live pairs"

printf '\n\033[1;32m✔ REGRESS_PAIR_COMPOSE_UNIT PASSED\033[0m\n'
