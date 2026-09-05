#!/usr/bin/env bash
# Offline behavioral contract for tests/lib/pair_live_ownership.sh. A private
# fake checkout and pair launcher exercise state transitions without Docker.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd -P)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/wprism-live-pair-ownership.XXXXXX")"
trap 'find "$TMP" -depth -delete >/dev/null 2>&1 || true' EXIT

say() { printf '\n== %s ==\n' "$*"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

assert_before() {
  local file="$1" first="$2" second="$3" first_line second_line
  first_line="$(grep -nF -- "$first" "$file" | head -1 | cut -d: -f1 || true)"
  second_line="$(grep -nF -- "$second" "$file" | head -1 | cut -d: -f1 || true)"
  [ -n "$first_line" ] && [ -n "$second_line" ] && [ "$first_line" -lt "$second_line" ] \
    || fail "expected '$first' before '$second' in $file"
}

FIXTURE="$TMP/repo"
mkdir -p "$FIXTURE/sandbox/bin" "$FIXTURE/sandbox/lib" "$FIXTURE/sandbox/tests/lib" \
  "$FIXTURE/sandbox/siterepo" "$FIXTURE/agent" "$FIXTURE/adapter-packages" "$FIXTURE/platform"
cp "$ROOT/sandbox/lib/pair_identity.sh" "$FIXTURE/sandbox/lib/pair_identity.sh"
cp "$ROOT/sandbox/lib/pair_db.sh" "$FIXTURE/sandbox/lib/pair_db.sh"
cp "$ROOT/sandbox/lib/pair_lease.sh" "$FIXTURE/sandbox/lib/pair_lease.sh"
cp "$ROOT/sandbox/tests/lib/pair_live_ownership.sh" "$FIXTURE/sandbox/tests/lib/pair_live_ownership.sh"
: > "$FIXTURE/agent/.fixture"
: > "$FIXTURE/adapter-packages/.fixture"
: > "$FIXTURE/platform/.fixture"
git -C "$FIXTURE" init -q
git -C "$FIXTURE" -c user.name=wprism -c user.email=wprism@example.test add -A
git -C "$FIXTURE" -c user.name=wprism -c user.email=wprism@example.test commit -qm fixture
FIXTURE="$(cd "$FIXTURE" && pwd -P)"
FIXTURE_SHA="$(git -C "$FIXTURE" rev-parse HEAD)"
EVENTS="$TMP/events.log"
ACTIVE="$TMP/active-token"
RESOURCE="$TMP/project-resource"
SCRATCH_RECORD="$TMP/scratch-path"

cat > "$FIXTURE/sandbox/bin/pair.sh" <<'FAKE_PAIR'
#!/usr/bin/env bash
set -euo pipefail
command_name="${1:?}"
shift
printf '%s engine=%s host=%s token=%s args=%s\n' \
  "$command_name" "${WPRISM_DB_ENGINE:-}" "${WPRISM_DB_HOST:-}" \
  "${WPRISM_PAIR_LEASE_TOKEN:-}" "$*" >> "${TEST_EVENTS:?}"
case "$command_name" in
  lease-batch-acquire)
    [ "${TEST_FAIL_ACQUIRE:-0}" -eq 0 ] || exit 71
    [ ! -e "${TEST_ACTIVE:?}" ] || exit 72
    printf '%s\n' "$1" > "$TEST_ACTIVE"
    ;;
  up)
    [ -s "${TEST_ACTIVE:?}" ] || exit 73
    : > "${TEST_RESOURCE:?}"
    mkdir -p "siterepo/${TEST_PAIR:?}1" "siterepo/${TEST_PAIR}2"
    [ "${TEST_PARTIAL_UP_FAILURE:-0}" -eq 0 ] || exit 74
    ;;
  reset|repo-host)
    [ -s "${TEST_ACTIVE:?}" ] || exit 75
    ;;
  destroy)
    [ -s "${TEST_ACTIVE:?}" ] || exit 76
    rm -f -- "${TEST_RESOURCE:?}"
    ;;
  lease-batch-release)
    [ -s "${TEST_ACTIVE:?}" ] || exit 77
    [ ! -e "${TEST_RESOURCE:?}" ] || exit 78
    [ ! -e "siterepo/${TEST_PAIR:?}1" ] && [ ! -e "siterepo/${TEST_PAIR}2" ] \
      && [ ! -e "siterepo/origin-${TEST_PAIR}.git" ] || exit 79
    if [ "${TEST_REQUIRE_SCRATCH_ABSENT:-0}" -eq 1 ]; then
      scratch="$(cat "${TEST_SCRATCH_RECORD:?}")"
      [ ! -e "$scratch" ] && [ ! -L "$scratch" ] || exit 80
    fi
    rm -f -- "$TEST_ACTIVE"
    printf 'release-visible:%s\n' "${WPRISM_DB_ENGINE:-}"
    ;;
  *) exit 64 ;;
esac
FAKE_PAIR
chmod +x "$FIXTURE/sandbox/bin/pair.sh"

export TEST_EVENTS="$EVENTS" TEST_ACTIVE="$ACTIVE" TEST_RESOURCE="$RESOURCE" \
  TEST_SCRATCH_RECORD="$SCRATCH_RECORD" TEST_PAIR='ownershipprobe'

say 'failed acquisition never arms destructive cleanup'
: > "$EVENTS"
if (
  set -euo pipefail
  cd "$FIXTURE/sandbox"
  fail() { printf 'fixture failure: %s\n' "$*" >&2; exit 1; }
  export WPRISM_SOURCE_ROOT="$FIXTURE" WPRISM_EXPECTED_SOURCE_SHA="$FIXTURE_SHA" \
    WPRISM_DB_ENGINE=mariadb WPRISM_DB_HOST=wprism-shared-db TEST_FAIL_ACQUIRE=1
  . tests/lib/pair_live_ownership.sh
  pair_live_ownership_prepare "$TEST_PAIR" 9520 9521 'acquire-refusal fixture' 'wprism-owner-test'
  printf '%s\n' "$PAIR_LIVE_OWNERSHIP_TMP_ROOT" > "$TEST_SCRATCH_RECORD"
  pair_live_ownership_acquire mariadb
  pair_live_ownership_complete 'UNREACHABLE PASS'
) >"$TMP/acquire-refusal.out" 2>&1; then
  fail 'injected lease acquisition failure returned success'
fi
grep -Fq 'lease-batch-acquire engine=mariadb host=wprism-shared-db' "$EVENTS" \
  || fail 'acquisition did not pin the selected MariaDB lane'
! grep -Eq '^(destroy|lease-batch-release) ' "$EVENTS" \
  || fail 'failed acquisition armed destructive cleanup'
! grep -Fq 'UNREACHABLE PASS' "$TMP/acquire-refusal.out" \
  || fail 'failed acquisition published PASS'
[ ! -e "$(cat "$SCRATCH_RECORD")" ] || fail 'failed acquisition leaked owner scratch'
pass 'failed acquisition cleans only scratch and never destroys or releases a pair'

say 'partial up stays owned through destroy, roots, scratch and release'
rm -f -- "$ACTIVE" "$RESOURCE"
: > "$EVENTS"
set +e
(
  set -euo pipefail
  cd "$FIXTURE/sandbox"
  fail() { printf 'fixture failure: %s\n' "$*" >&2; exit 1; }
  export WPRISM_SOURCE_ROOT="$FIXTURE" WPRISM_EXPECTED_SOURCE_SHA="$FIXTURE_SHA" \
    WPRISM_DB_ENGINE=mariadb WPRISM_DB_HOST=wprism-shared-db \
    TEST_FAIL_ACQUIRE=0 TEST_PARTIAL_UP_FAILURE=1 TEST_REQUIRE_SCRATCH_ABSENT=1
  . tests/lib/pair_live_ownership.sh
  pair_live_ownership_prepare "$TEST_PAIR" 9520 9521 'partial-up fixture' 'wprism-owner-test'
  printf '%s\n' "$PAIR_LIVE_OWNERSHIP_TMP_ROOT" > "$TEST_SCRATCH_RECORD"
  pair_live_ownership_acquire mariadb
  pair_live_ownership_up --headless
  pair_live_ownership_complete 'UNREACHABLE PARTIAL PASS'
) >"$TMP/partial-up.out" 2>&1
PARTIAL_STATUS=$?
set -e
if [ "$PARTIAL_STATUS" -eq 0 ]; then
  fail 'injected partial up failure returned success'
fi
assert_before "$EVENTS" 'lease-batch-acquire ' 'up '
assert_before "$EVENTS" 'up ' 'destroy '
assert_before "$EVENTS" 'destroy ' 'lease-batch-release '
! grep -Fq 'UNREACHABLE PARTIAL PASS' "$TMP/partial-up.out" \
  || fail 'partial up failure published PASS'
[ ! -e "$ACTIVE" ] && [ ! -e "$RESOURCE" ] \
  && [ ! -e "$FIXTURE/sandbox/siterepo/${TEST_PAIR}1" ] \
  && [ ! -e "$FIXTURE/sandbox/siterepo/${TEST_PAIR}2" ] \
  && [ ! -e "$(cat "$SCRATCH_RECORD")" ] \
  || fail 'partial-up cleanup left lease, project, roots or scratch'
pass 'partial up is torn down under the lease and cannot publish a false PASS'

say 'sequential engine legs use distinct leases and release before reuse/PASS'
rm -f -- "$ACTIVE" "$RESOURCE"
: > "$EVENTS"
(
  set -euo pipefail
  cd "$FIXTURE/sandbox"
  fail() { printf 'fixture failure: %s\n' "$*" >&2; exit 1; }
  export WPRISM_SOURCE_ROOT="$FIXTURE" WPRISM_EXPECTED_SOURCE_SHA="$FIXTURE_SHA" \
    TEST_FAIL_ACQUIRE=0 TEST_PARTIAL_UP_FAILURE=0 TEST_REQUIRE_SCRATCH_ABSENT=0
  . tests/lib/pair_live_ownership.sh
  pair_live_ownership_prepare "$TEST_PAIR" 9520 9521 'two-engine fixture' 'wprism-owner-test'
  printf '%s\n' "$PAIR_LIVE_OWNERSHIP_TMP_ROOT" > "$TEST_SCRATCH_RECORD"
  pair_live_ownership_acquire mariadb
  pair_live_ownership_up --headless
  pair_live_ownership_finish_leg
  pair_live_ownership_acquire mysql
  pair_live_ownership_up --headless
  pair_live_ownership_repo_host both
  export TEST_REQUIRE_SCRATCH_ABSENT=1
  pair_live_ownership_complete 'PAIR LIVE OWNERSHIP PASSED'
) >"$TMP/two-engine.out" 2>&1 \
  || { cat "$TMP/two-engine.out" >&2; fail 'two-engine helper lifecycle failed'; }
grep -Fq 'lease-batch-acquire engine=mariadb host=wprism-shared-db' "$EVENTS" \
  && grep -Fq 'lease-batch-acquire engine=mysql host=wprism-shared-mysql' "$EVENTS" \
  || fail 'sequential acquisition did not bind both exact engine/container lanes'
TOKEN_COUNT="$(awk '$1 == "lease-batch-acquire" { count++ } END { print count + 0 }' "$EVENTS")"
TOKEN1="$(awk '$1 == "lease-batch-acquire" { sub(/^token=/, "", $4); print $4; exit }' "$EVENTS")"
TOKEN2="$(awk '$1 == "lease-batch-acquire" { count++; if (count == 2) { sub(/^token=/, "", $4); print $4; exit } }' "$EVENTS")"
[ "$TOKEN_COUNT" -eq 2 ] && [ -n "$TOKEN1" ] && [ -n "$TOKEN2" ] && [ "$TOKEN1" != "$TOKEN2" ] \
  || fail 'sequential acquisitions reused one lease token'
assert_before "$TMP/two-engine.out" 'release-visible:mariadb' 'release-visible:mysql'
assert_before "$TMP/two-engine.out" 'release-visible:mysql' 'PAIR LIVE OWNERSHIP PASSED'
[ "$(grep -Fc 'PAIR LIVE OWNERSHIP PASSED' "$TMP/two-engine.out")" -eq 1 ] \
  || fail 'helper did not own one final PASS'
[ ! -e "$ACTIVE" ] && [ ! -e "$RESOURCE" ] && [ ! -e "$(cat "$SCRATCH_RECORD")" ] \
  || fail 'successful sequential cleanup left owned state'
pass 'each engine leg gets a fresh token and verified release precedes reuse and sole PASS'

say 'omitting per-leg settlement refuses before the engine or cleanup authority can change'
: > "$EVENTS"
if (
  set -euo pipefail
  cd "$FIXTURE/sandbox"
  fail() { printf 'fixture failure: %s\n' "$*" >&2; exit 1; }
  export WPRISM_SOURCE_ROOT="$FIXTURE" WPRISM_EXPECTED_SOURCE_SHA="$FIXTURE_SHA" \
    TEST_FAIL_ACQUIRE=0 TEST_PARTIAL_UP_FAILURE=0 TEST_REQUIRE_SCRATCH_ABSENT=0
  . tests/lib/pair_live_ownership.sh
  pair_live_ownership_prepare "$TEST_PAIR" 9520 9521 'unsettled-engine fixture' 'wprism-owner-test'
  printf '%s\n' "$PAIR_LIVE_OWNERSHIP_TMP_ROOT" > "$TEST_SCRATCH_RECORD"
  pair_live_ownership_acquire mariadb
  pair_live_ownership_up --headless
  # Mutation of the matrix's required finish_leg: a second acquire must not
  # export MySQL over MariaDB's still-active lease, even on this refusal path.
  pair_live_ownership_acquire mysql
  pair_live_ownership_complete 'UNREACHABLE UNSETTLED PASS'
) >"$TMP/unsettled-engine.out" 2>&1; then
  fail 'a matrix missing per-leg settlement changed engines successfully'
fi
grep -Fq 'attempted to acquire a second lease before releasing the first' "$TMP/unsettled-engine.out" \
  || fail 'missing per-leg settlement did not produce the ownership refusal'
grep -Fq 'destroy engine=mariadb host=wprism-shared-db' "$EVENTS" \
  && grep -Fq 'lease-batch-release engine=mariadb host=wprism-shared-db' "$EVENTS" \
  || fail 'unsettled-engine refusal lost the exact MariaDB cleanup context'
! grep -Fq 'engine=mysql' "$EVENTS" \
  || fail 'unsettled-engine refusal changed engine before cleanup'
! grep -Fq 'UNREACHABLE UNSETTLED PASS' "$TMP/unsettled-engine.out" \
  || fail 'unsettled-engine refusal published PASS'
[ ! -e "$ACTIVE" ] && [ ! -e "$RESOURCE" ] && [ ! -e "$(cat "$SCRATCH_RECORD")" ] \
  || fail 'unsettled-engine refusal leaked owned state'
pass 'a missing finish_leg fails closed and still destroys/releases only the original engine'

printf '\nPASS: regress-live-pair-ownership\n'
