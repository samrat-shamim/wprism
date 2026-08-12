#!/usr/bin/env bash
# Offline regression — DUO-3355's extracted pair-budget resource lock.
#
# This exercises the shipped lock library directly, without Docker or a
# pair lifecycle: one holder blocks a second process, release wakes exactly
# that waiter, the persistent lock inode survives, and the EXIT cleanup keeps
# the caller's status while removing only its helper state.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/duo-pair-budget-lock.XXXXXX")"
trap 'rm -rf -- "$TMP"' EXIT

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

LOCK_LIB="$ROOT/sandbox/lib/pair_budget_lock.sh"
[ -r "$LOCK_LIB" ] || fail "pair-budget lock library is missing"
bash -n "$LOCK_LIB" || fail "pair-budget lock library does not parse"

say "standalone load and one holder"
source "$LOCK_LIB"
root="$TMP/holder"
mkdir -p "$root"
budget_lock_acquire "$root"
lock_path="$root/sandbox/siterepo/.pair-budget.lock"
[ -f "$lock_path" ] || fail "acquire did not create the persistent lock file"
holder_mode="$PAIR_BUDGET_LOCK_MODE"
case "$holder_mode" in flock|python) ;; *) fail "acquire selected an unknown backend: $holder_mode" ;; esac
helper_dir="$PAIR_BUDGET_LOCK_HELPER_DIR"
[ -d "$helper_dir" ] || fail "acquire did not expose its private helper directory"
pass "standalone library acquires through the $holder_mode backend"

cat > "$TMP/waiter.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
fail() { printf '%s\n' "$*" >&2; exit 1; }
source "${LOCK_LIB:?}"
: > "${STARTED:?}"
budget_lock_acquire "${LOCK_ROOT:?}"
: > "${ACQUIRED:?}"  # this is created only after the lock is acquired
while [ ! -e "${RELEASE:?}" ]; do sleep 0.02; done
budget_lock_release
: > "${RELEASED:?}"
EOF
chmod +x "$TMP/waiter.sh"

export LOCK_LIB LOCK_ROOT="$root" STARTED="$TMP/started" \
  ACQUIRED="$TMP/acquired" RELEASE="$TMP/release" RELEASED="$TMP/released"
env bash "$TMP/waiter.sh" &
waiter_pid=$!
for _ in $(seq 1 100); do
  [ -e "$STARTED" ] && break
  sleep 0.02
done
[ -e "$STARTED" ] || fail "the second process did not start"
[ ! -e "$ACQUIRED" ] || fail "a second process acquired while the holder still owned the lock"
: > "$RELEASE"
budget_lock_release
wait "$waiter_pid"
[ -e "$ACQUIRED" ] || fail "the blocked waiter did not acquire after release"
[ -e "$RELEASED" ] || fail "the waiter did not release after acquisition"
[ -f "$lock_path" ] || fail "release removed the persistent lock file"
pass "a second process waits for release, then acquires; the lock inode persists"

say "EXIT cleanup preserves status and removes only helper state"
cleanup_root="$TMP/cleanup"
mkdir -p "$cleanup_root"
set +e
LOCK_LIB="$LOCK_LIB" LOCK_ROOT="$cleanup_root" CLEANUP_HELPER_FILE="$TMP/helper-path" bash -c '
  set -euo pipefail
  fail() { printf "%s\n" "$*" >&2; exit 1; }
  source "$LOCK_LIB"
  budget_lock_acquire "$LOCK_ROOT"
  helper="$PAIR_BUDGET_LOCK_HELPER_DIR"
  printf "%s\n" "$helper" > "$CLEANUP_HELPER_FILE"
  arm_budget_up_cleanup
  exit 23
'
cleanup_status=$?
set -e
[ "$cleanup_status" -eq 23 ] || fail "EXIT cleanup changed the caller status (got $cleanup_status)"
cleanup_helper="$(cat "$TMP/helper-path")"
[ ! -e "$cleanup_helper" ] || fail "EXIT cleanup left the private helper directory"
[ -f "$cleanup_root/sandbox/siterepo/.pair-budget.lock" ] \
  || fail "EXIT cleanup removed the persistent lock file"
pass "EXIT cleanup releases helper state without masking the caller status"

printf '\n\033[1;32m✔ REGRESS_PAIR_BUDGET_LOCK PASSED\033[0m\n'
