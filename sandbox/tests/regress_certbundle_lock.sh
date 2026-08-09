#!/usr/bin/env bash
# Regression — DUO-3382: certification-bundle launchers must serialize via the
# script's own per-host lock, never by pattern-killing whatever looks like a
# bundle. On 2026-08-09 an agent-side `pkill -f certify_reference_bundle`
# preamble killed three unrelated runs' parent+wrapper, orphaning their
# conformance children and losing their work roots (~40 min each). The fix
# lives in certify_reference_bundle.sh: an advisory /tmp rendezvous acquired
# with mkdir(2), a refusal that NAMES the holder, opt-in bounded waiting, and
# staleness decided by PID LIVENESS rather than age.
#
# Offline, no docker, no pair, no bundle: every case below drives the SHIPPED
# functions by sourcing certify_reference_bundle.sh with
# CERT_BUNDLE_LOCK_LIB_ONLY=1 (the source-only hook that returns before the
# preflight), redirected onto a private rendezvous via CERT_BUNDLE_LOCK_DIR.
# Concurrency is real — racers are separate processes released by a barrier —
# because the property under test is a race, and a single-process simulation
# of mkdir(2) would prove nothing about it.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

SHIPPED="$PWD/certify_reference_bundle.sh"
[ -f "$SHIPPED" ] || fail "cannot find the script under test: $SHIPPED"

SCRATCH=$(mktemp -d "${TMPDIR:-/tmp}/duo-3382.XXXXXX")
LOCK_DIR="$SCRATCH/rendezvous.lock"
HOLDER_FILE="$LOCK_DIR/holder.json"
SPAWNED=()
# Cleanup kills only PIDs this suite recorded when it spawned them — the very
# discipline the issue mandates. A `pkill -f driver.sh` here would be the
# defect under test, committed by its own regression.
cleanup() {
  local pid
  for pid in ${SPAWNED+"${SPAWNED[@]}"}; do kill -9 "$pid" 2>/dev/null || true; done
  rm -rf -- "$SCRATCH"
}
trap cleanup EXIT
export CERT_BUNDLE_LOCK_DIR="$LOCK_DIR"
export CERTBUNDLE_SCRIPT="$SHIPPED"

show() { printf -- '--- %s ---\n' "$1" >&2; cat "$1" >&2; }
assert_in() { # assert_in <file> <literal> <what>
  # Note: grep against a FILE, never a piped variable — see linear-loop.md's
  # pipefail/SIGPIPE field note.
  grep -qF -- "$2" "$1" || { show "$1"; fail "$3 (missing literal: $2)"; }
}
assert_not_in() { # assert_not_in <file> <literal> <what>
  grep -qF -- "$2" "$1" && { show "$1"; fail "$3 (unexpected literal: $2)"; }
  return 0
}
wait_until() { # wait_until <budget-seconds> <what> <command...>
  local budget="$1" what="$2" deadline
  shift 2
  deadline=$(( $(date +%s) + budget ))
  while ! "$@"; do
    [ "$(date +%s)" -lt "$deadline" ] || fail "timed out after ${budget}s waiting for: $what"
    sleep 0.05
  done
}
holder_pid() { jq -r '.pid' "$HOLDER_FILE"; }
snapshot_holder() { cp "$HOLDER_FILE" "$SCRATCH/holder.snapshot"; }
assert_holder_unchanged() { # byte-identical, not merely "still a lock"
  cmp -s "$HOLDER_FILE" "$SCRATCH/holder.snapshot" || { show "$HOLDER_FILE"; fail "$1"; }
}
iso_of() { # iso_of <epoch> — BSD date, then GNU date; started_at is cosmetic
  date -u -r "$1" '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null \
    || date -u -d "@$1" '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null \
    || date -u '+%Y-%m-%dT%H:%M:%SZ'
}
forge_lock() { # forge_lock <pid> <pair> <started-epoch>
  mkdir -p "$LOCK_DIR"
  jq -n --argjson pid "$1" --arg pair "$2" --argjson started_epoch "$3" \
    --arg started_at "$(iso_of "$3")" \
    '{schema_version:1,pid:$pid,pair:$pair,started_at:$started_at,started_epoch:$started_epoch,
      checkout:"/forged/checkout",argv:["forged-holder"]}' > "$HOLDER_FILE"
}

# The driver is the only new code that runs the lock: it sources the shipped
# script and calls its functions, so a drifted or deleted implementation fails
# these cases instead of leaving a transcription behind to rot.
cat > "$SCRATCH/driver.sh" <<'DRIVER'
#!/usr/bin/env bash
set -euo pipefail
MODE="$1"; READY="$2"; BARRIER="$3"
export CERT_BUNDLE_LOCK_LIB_ONLY=1
# shellcheck source=/dev/null
. "$CERTBUNDLE_SCRIPT"
case "$MODE" in
  race)          while [ ! -f "$BARRIER" ]; do sleep 0.02; done ;;
  hold|acquire)  : ;;
  *) printf 'unknown driver mode: %s\n' "$MODE" >&2; exit 64 ;;
esac
certbundle_lock_acquire
printf '%s\n' "$$" > "$READY"
case "$MODE" in
  race|hold) while [ -f "$BARRIER" ]; do sleep 0.05; done ;;   # EXIT trap releases
esac
DRIVER

say "four concurrent invocations, one barrier: exactly one acquires and the losers refuse by name"
BARRIER="$SCRATCH/barrier"
RACERS=()
for i in 1 2 3 4; do
  CERT_BUNDLE_PAIR="racer$i" bash "$SCRATCH/driver.sh" race "$SCRATCH/ready.$i" "$BARRIER" \
    > "$SCRATCH/racer.$i.log" 2>&1 &
  RACERS+=("$!"); SPAWNED+=("$!")
done
: > "$BARRIER"
count_ready() { ls "$SCRATCH"/ready.* >/dev/null 2>&1; }
wait_until 20 "a racer to win the lock" count_ready
three_losers_exited() {
  local live=0 p
  for p in "${RACERS[@]}"; do kill -0 "$p" 2>/dev/null && live=$((live + 1)); done
  [ "$live" -eq 1 ]
}
wait_until 20 "the three losing racers to exit" three_losers_exited
WINNER_INDEX=$(ls "$SCRATCH" | sed -n 's/^ready\.\([0-9]*\)$/\1/p')
[ "$(printf '%s\n' "$WINNER_INDEX" | grep -c .)" = "1" ] \
  || fail "expected exactly one winner, got ready files for: $(printf '%s ' $WINNER_INDEX)"
WINNER_PID=$(cat "$SCRATCH/ready.$WINNER_INDEX")
[ "$(holder_pid)" = "$WINNER_PID" ] || fail "holder.json names $(holder_pid), winner is $WINNER_PID"
assert_in "$SCRATCH/racer.$WINNER_INDEX.log" "host certification lock acquired" "the winner did not announce acquisition"
assert_in "$SCRATCH/racer.$WINNER_INDEX.log" "NOT serialized" "a redirected rendezvous must say so instead of pretending to serialize the host"
snapshot_holder
for i in 1 2 3 4; do
  [ "$i" = "$WINNER_INDEX" ] && continue
  rc=0; wait "${RACERS[$((i - 1))]}" || rc=$?
  [ "$rc" = "1" ] || fail "loser racer $i exited $rc, expected 1 (a refusal is a failure, not a silent skip)"
  assert_in "$SCRATCH/racer.$i.log" "refusing to start a second certification bundle" "loser $i did not refuse loudly"
  assert_in "$SCRATCH/racer.$i.log" "holder pid     : $WINNER_PID (alive)" "loser $i did not name the holder's pid"
  assert_in "$SCRATCH/racer.$i.log" "holder pair    : racer$WINNER_INDEX" "loser $i did not name the holder's pair"
  assert_in "$SCRATCH/racer.$i.log" "holder started :" "loser $i did not report when the holder started, or its age"
  assert_in "$SCRATCH/racer.$i.log" "CERT_BUNDLE_WAIT=1" "loser $i did not print the exact wait command"
  assert_in "$SCRATCH/racer.$i.log" "pkill -f certify_reference_bundle" "loser $i did not name the pattern-kill remediation this issue exists for"
  assert_in "$SCRATCH/racer.$i.log" "Kill only a PID your own launcher recorded" "loser $i did not state the launcher rule"
done
pass "one of four acquired; three refused with exit 1 naming pid $WINNER_PID, its pair, its age, and the wait command"

say "a live holder's lock is never removed by a contender"
[ -d "$LOCK_DIR" ] || fail "the lock disappeared while its holder was still alive"
assert_holder_unchanged "three refusals mutated the live holder's holder.json"
[ "$(holder_pid)" = "$WINNER_PID" ] || fail "a refusing contender rewrote the live holder's record"
pass "lock and holder.json survived three refusals byte-identical"

say "the EXIT trap armed by the acquire itself releases the lock on normal exit"
rm -f "$BARRIER"
rc=0; wait "${RACERS[$((WINNER_INDEX - 1))]}" || rc=$?
[ "$rc" = "0" ] || fail "the winning racer exited $rc"
wait_until 10 "the winner's EXIT trap to release the lock" test '!' -d "$LOCK_DIR"
pass "lock released on the holder's normal exit, with no explicit release call"

say "CERT_BUNDLE_WAIT=1 waits for the holder and acquires at release instead of displacing it"
HOLD="$SCRATCH/hold"
: > "$HOLD"
CERT_BUNDLE_PAIR=holder bash "$SCRATCH/driver.sh" hold "$SCRATCH/ready.holder" "$HOLD" \
  > "$SCRATCH/holder.log" 2>&1 &
HOLDER_JOB=$!; SPAWNED+=("$HOLDER_JOB")
wait_until 20 "the holder to acquire" test -f "$SCRATCH/ready.holder"
HOLDER_PID=$(cat "$SCRATCH/ready.holder")
CERT_BUNDLE_WAIT=1 CERT_BUNDLE_WAIT_POLL=1 CERT_BUNDLE_WAIT_TIMEOUT=60 CERT_BUNDLE_PAIR=waiter \
  bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.waiter" "$SCRATCH/unused" \
  > "$SCRATCH/waiter.log" 2>&1 &
WAITER_JOB=$!; SPAWNED+=("$WAITER_JOB")
wait_until 20 "the waiter's first progress line" grep -qF "waiting for the host certification lock" "$SCRATCH/waiter.log"
assert_in "$SCRATCH/waiter.log" "holder pid $HOLDER_PID pair holder" "the wait progress line does not name the holder"
sleep 1.5
[ ! -f "$SCRATCH/ready.waiter" ] || fail "the waiter acquired while the holder was still alive"
[ "$(holder_pid)" = "$HOLDER_PID" ] || fail "the waiter displaced the live holder"
rm -f "$HOLD"
rc=0; wait "$HOLDER_JOB" || rc=$?
[ "$rc" = "0" ] || fail "the holder exited $rc"
rc=0; wait "$WAITER_JOB" || rc=$?
[ "$rc" = "0" ] || fail "the waiter exited $rc; it should have acquired once the holder released"
assert_in "$SCRATCH/waiter.log" "host certification lock acquired" "the waiter never announced its acquisition"
wait_until 10 "the waiter's own release" test '!' -d "$LOCK_DIR"
pass "the waiter polled with progress lines, left the holder alone, and took the lock at release"

say "waiting is bounded: the budget expires into the same named refusal"
forge_lock "$$" "regression" "$(date +%s)"
rc=0
CERT_BUNDLE_WAIT=1 CERT_BUNDLE_WAIT_POLL=1 CERT_BUNDLE_WAIT_TIMEOUT=1 CERT_BUNDLE_PAIR=impatient \
  bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.impatient" "$SCRATCH/unused" \
  > "$SCRATCH/impatient.log" 2>&1 || rc=$?
[ "$rc" = "1" ] || fail "a waiter over budget exited $rc, expected 1"
assert_in "$SCRATCH/impatient.log" "CERT_BUNDLE_WAIT_TIMEOUT=1" "the timeout refusal does not name the budget it exceeded"
assert_in "$SCRATCH/impatient.log" "holder pid     : $$ (alive)" "the timeout refusal does not name the holder"
[ -d "$LOCK_DIR" ] || fail "an expired waiter removed the live holder's lock"
pass "wait mode is bounded and its timeout refusal names the holder and the budget"

say "staleness is PID liveness, not age: a six-hour-old LIVE holder is still the holder"
forge_lock "$$" "regression" "$(( $(date +%s) - 21600 ))"
rc=0
CERT_BUNDLE_PAIR=patient bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.patient" "$SCRATCH/unused" \
  > "$SCRATCH/ancient.log" 2>&1 || rc=$?
[ "$rc" = "1" ] || fail "an ancient but live holder was displaced (driver exited $rc); a bundle legitimately runs for over an hour"
assert_in "$SCRATCH/ancient.log" "running 6h00m" "the refusal does not report the holder's true age"
assert_not_in "$SCRATCH/ancient.log" "taking it over" "age alone must never trigger a takeover"
[ -d "$LOCK_DIR" ] || fail "an age-based expiry removed a live holder's lock"
pass "a 6h00m-old live holder keeps its lock; only liveness decides"
rm -rf "$LOCK_DIR"

say "a dead holder's lock is taken over, with an announcement"
( exit 0 ) &
DEAD_PID=$!
wait "$DEAD_PID" 2>/dev/null || true
kill -0 "$DEAD_PID" 2>/dev/null && fail "pid $DEAD_PID was reused before the case could run; re-run"
forge_lock "$DEAD_PID" "corpse" "$(( $(date +%s) - 60 ))"
CERT_BUNDLE_PAIR=heir bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.heir" "$SCRATCH/unused" \
  > "$SCRATCH/heir.log" 2>&1
assert_in "$SCRATCH/heir.log" "stale host certification lock" "the takeover was silent"
assert_in "$SCRATCH/heir.log" "holder pid $DEAD_PID (pair corpse" "the takeover did not name the corpse it replaced"
assert_in "$SCRATCH/heir.log" "taking it over" "the takeover did not say what it was doing"
[ ! -d "$LOCK_DIR" ] || fail "the heir did not release its own lock on exit"
pass "a dead holder is announced and taken over; no operator cleanup, no kill"

say "the incident's own shape: a SIGKILLed holder runs no trap, and the next run recovers"
: > "$HOLD"
CERT_BUNDLE_PAIR=victim bash "$SCRATCH/driver.sh" hold "$SCRATCH/ready.victim" "$HOLD" \
  > "$SCRATCH/victim.log" 2>&1 &
VICTIM_JOB=$!; SPAWNED+=("$VICTIM_JOB")
wait_until 20 "the victim to acquire" test -f "$SCRATCH/ready.victim"
VICTIM_PID=$(cat "$SCRATCH/ready.victim")
kill -9 "$VICTIM_PID"
rc=0; wait "$VICTIM_JOB" || rc=$?
[ "$rc" = "137" ] || fail "the victim exited $rc, not 137; the fixture must prove SIGKILL ran no trap"
[ -d "$LOCK_DIR" ] || fail "fixture lost its point: SIGKILL must leave the lock behind (no trap can run)"
[ "$(holder_pid)" = "$VICTIM_PID" ] || fail "the killed holder's record is not what remained"
CERT_BUNDLE_PAIR=successor bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.successor" "$SCRATCH/unused" \
  > "$SCRATCH/successor.log" 2>&1
assert_in "$SCRATCH/successor.log" "stale host certification lock" "the successor did not detect the killed holder"
assert_in "$SCRATCH/successor.log" "host certification lock acquired" "the successor did not acquire after the takeover"
pass "a killed holder leaves a lock the next invocation takes over by liveness — no wedged host"

say "release is ownership-checked: a run that lost its lock never deletes the new holder's"
: > "$HOLD"
CERT_BUNDLE_PAIR=confused bash "$SCRATCH/driver.sh" hold "$SCRATCH/ready.confused" "$HOLD" \
  > "$SCRATCH/confused.log" 2>&1 &
CONFUSED_JOB=$!; SPAWNED+=("$CONFUSED_JOB")
wait_until 20 "the confused holder to acquire" test -f "$SCRATCH/ready.confused"
forge_lock "$$" "usurper" "$(date +%s)"   # the lock now names a different, live run
snapshot_holder
rm -f "$HOLD"
rc=0; wait "$CONFUSED_JOB" || rc=$?
[ "$rc" = "0" ] || fail "the confused holder exited $rc"
assert_in "$SCRATCH/confused.log" "refusing to release" "the release did not report that the lock had changed hands"
[ -d "$LOCK_DIR" ] || fail "a run released a lock it no longer held — the deletion this issue exists to prevent"
assert_holder_unchanged "the usurper's holder.json was mutated by another run's release"
pass "the release refused, loudly, to delete a lock naming someone else"
rm -rf "$LOCK_DIR"

say "a lock nobody can identify is refused with manual remediation, never removed"
mkdir "$LOCK_DIR"   # a run SIGKILLed between mkdir(2) and holder.json
rc=0
CERT_BUNDLE_PAIR=nameless bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.nameless" "$SCRATCH/unused" \
  > "$SCRATCH/nameless.log" 2>&1 || rc=$?
[ "$rc" = "1" ] || fail "an unidentifiable lock exited $rc, expected a refusal (1)"
assert_in "$SCRATCH/nameless.log" "names no holder" "the refusal does not say why it cannot act"
assert_in "$SCRATCH/nameless.log" "rm -rf $LOCK_DIR" "the refusal does not print the exact manual remediation"
[ -d "$LOCK_DIR" ] || fail "an unidentified lock was removed on a guess — the pattern-kill mistake in another hat"
pass "an unnamed lock is refused with instructions, and left in place"
rm -rf "$LOCK_DIR"

say "a rendezvous that cannot be created at all refuses with the mkdir error, and does not spin"
rc=0
SECONDS=0
CERT_BUNDLE_LOCK_DIR=/nonexistent-duo3382-parent/rendezvous.lock CERT_BUNDLE_PAIR=unwritable \
  bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.unwritable" "$SCRATCH/unused" \
  > "$SCRATCH/unwritable.log" 2>&1 || rc=$?
[ "$rc" = "1" ] || fail "an uncreatable rendezvous exited $rc, expected a refusal (1)"
[ "$SECONDS" -lt 30 ] || fail "an uncreatable rendezvous took ${SECONDS}s; a failing mkdir must not be retried forever"
assert_in "$SCRATCH/unwritable.log" "cannot create the host certification rendezvous" "the refusal does not say the rendezvous could not be created"
assert_in "$SCRATCH/unwritable.log" "/nonexistent-duo3382-parent/rendezvous.lock" "the refusal does not name the path it could not create"
pass "an uncreatable rendezvous refuses in ${SECONDS}s with the underlying mkdir error"

# The ordering requirements below cannot be reached without docker and a full
# bundle, so they are pinned against the shipped source instead of simulated:
# a suite that quietly stopped covering them would be worse than no coverage.
say "shipped ordering: the lock is acquired before any preflight or mutation"
line_of() { grep -n -x -- "$2" "$1" | head -1 | cut -d: -f1; }
ACQUIRE_LINE=$(line_of "$SHIPPED" "certbundle_lock_acquire")
PREFLIGHT_LINE=$(line_of "$SHIPPED" "assert_exact_certification_checkout")
WORKROOT_LINE=$(grep -n 'WORK_ROOT=$(mktemp -d ' "$SHIPPED" | head -1 | cut -d: -f1)
[ -n "$ACQUIRE_LINE" ] || fail "certify_reference_bundle.sh no longer calls certbundle_lock_acquire at top level"
[ -n "$PREFLIGHT_LINE" ] && [ -n "$WORKROOT_LINE" ] || fail "cannot locate the preflight call or the work-root allocation"
[ "$ACQUIRE_LINE" -lt "$PREFLIGHT_LINE" ] \
  || fail "the lock is acquired at line $ACQUIRE_LINE, after the preflight at $PREFLIGHT_LINE"
[ "$ACQUIRE_LINE" -lt "$WORKROOT_LINE" ] \
  || fail "the lock is acquired at line $ACQUIRE_LINE, after the work root is allocated at $WORKROOT_LINE"
pass "acquire (line $ACQUIRE_LINE) precedes the preflight (line $PREFLIGHT_LINE) and the work root (line $WORKROOT_LINE)"

say "shipped ordering: one EXIT trap clears the work root and releases the lock LAST"
CLEANUP_BODY="$SCRATCH/cleanup_run.body"
awk '/^cleanup_run\(\) \{$/{inside=1} inside{print} inside && /^\}$/{exit}' "$SHIPPED" > "$CLEANUP_BODY"
[ -s "$CLEANUP_BODY" ] || fail "cannot extract cleanup_run() from certify_reference_bundle.sh"
RM_LINE=$(grep -n 'rm -rf -- "\$WORK_ROOT"' "$CLEANUP_BODY" | head -1 | cut -d: -f1)
REL_LINE=$(grep -n '^  certbundle_lock_release$' "$CLEANUP_BODY" | head -1 | cut -d: -f1)
[ -n "$RM_LINE" ] && [ -n "$REL_LINE" ] \
  || fail "cleanup_run() must both clear the work root and release the lock (rm:'$RM_LINE' release:'$REL_LINE')"
[ "$RM_LINE" -lt "$REL_LINE" ] \
  || fail "cleanup_run() releases the lock before clearing the work root; the next run must never start while this one's work root is still on disk"
grep -q '^trap cleanup_run EXIT' "$SHIPPED" || fail "cleanup_run is defined but never installed as the EXIT trap"
grep -q '^      trap certbundle_lock_release EXIT$' "$SHIPPED" \
  || fail "certbundle_lock_acquire must arm its own release trap, so a signal between acquisition and the work-root trap cannot leak the lock"
pass "cleanup_run clears the work root (body line $RM_LINE) then releases the lock (body line $REL_LINE), and acquire arms its own trap"

say "the source-only hook cannot be used to run a certification"
rc=0
CERT_BUNDLE_LOCK_LIB_ONLY=1 bash "$SHIPPED" > "$SCRATCH/hook.log" 2>&1 || rc=$?
[ "$rc" = "1" ] || fail "executing the shipped script with CERT_BUNDLE_LOCK_LIB_ONLY exited $rc, expected a refusal (1)"
assert_in "$SCRATCH/hook.log" "source-only hook" "the hook refusal does not explain itself"
[ ! -d "$LOCK_DIR" ] || fail "the refused hook invocation left a lock behind"
pass "the test seam refuses to run a bundle instead of silently skipping the lock"

printf '\n\033[1;32m✔ REGRESS_CERTBUNDLE_LOCK PASSED\033[0m\n'
