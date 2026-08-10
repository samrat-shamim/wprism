#!/usr/bin/env bash
# Regression — DUO-3382: certification-bundle launchers must serialize via the
# script's own per-host lock, never by pattern-killing whatever looks like a
# bundle. On 2026-08-09 an agent-side `pkill -f certify_reference_bundle`
# preamble killed three unrelated runs' parent+wrapper, orphaning their
# conformance children and losing their work roots (~40 min each).
#
# The lock in certify_reference_bundle.sh is flock(2) held by a helper process
# that owns the descriptor, the same crash-safe primitive sandbox/bin/pair.sh
# uses for the pair budget. The kernel releases it when its holder stops
# existing, so there is no corpse to detect, no age or liveness heuristic, and
# no takeover or rename path. Cases 8 and 9 below are the two races review
# REPRODUCED against the earlier mkdir-gated revision of this section; they
# pass here because the mechanisms they raced no longer exist, and case 16
# pins that absence so nobody reintroduces one. Case 18 is a third reproduced
# defect from the following review round: the helper used to read an empty
# `ps` as death and drop the lock while its acquirer was still running.
#
# Offline, no docker, no pair, no bundle: every case drives the SHIPPED
# functions by sourcing certify_reference_bundle.sh with
# CERT_BUNDLE_LOCK_LIB_ONLY=1 (the source-only hook that returns before the
# preflight), redirected onto a private rendezvous via CERT_BUNDLE_LOCK_DIR.
# Concurrency is real — contenders are separate processes released by a
# barrier — because the property under test is a race, and a single-process
# simulation of the lock would prove nothing about it.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

SHIPPED="$PWD/certify_reference_bundle.sh"
[ -f "$SHIPPED" ] || fail "cannot find the script under test: $SHIPPED"

SCRATCH=$(mktemp -d "${TMPDIR:-/tmp}/duo-3382.XXXXXX")
LOCK_DIR="$SCRATCH/rendezvous.lock"
LOCK_FILE="$LOCK_DIR/lock"
HOLDER_FILE="$LOCK_DIR/holder.json"
SPAWNED=()
CASE9_BACKEND_GATE=
CASE9_NAMING_GATE=
CASE9_NAMING_ENTERED=
CASE9_NAMING_SHIM_PID=
CASE9_NAMING_SHIM_PID_FILE=
CASE9_NAMING_EXITED=
# Cleanup kills only PIDs this suite recorded when it spawned them — the very
# discipline the issue mandates. A `pkill -f driver.sh` here would be the
# defect under test, committed by its own regression.
cleanup() {
  local pid candidate shim_ticks=0
  # Case 9's backend and naming gates can leave descendants waiting even after
  # their recorded parent is killed on a failure. Open every gate first so
  # those descendants either finish or reach their bounded timeout; only then
  # signal PIDs this suite itself recorded.
  if [ -n "${CASE9_BACKEND_GATE:-}" ]; then : > "$CASE9_BACKEND_GATE" 2>/dev/null || true; fi
  if [ -n "${CASE9_NAMING_GATE:-}" ]; then : > "$CASE9_NAMING_GATE" 2>/dev/null || true; fi
  # The naming shim is a descendant of the recorded case-9 parent, not a
  # child this shell can wait(2) on. If a failure leaves it in the gated
  # write, drain or kill that exact PID after opening the gate; never use a
  # pattern kill that could touch another run's shim.
  if [ -z "${CASE9_NAMING_SHIM_PID:-}" ] && [ -s "${CASE9_NAMING_SHIM_PID_FILE:-}" ]; then
    candidate=$(cat "$CASE9_NAMING_SHIM_PID_FILE" 2>/dev/null || true)
    case "$candidate" in ''|*[!0-9]*) ;; *) CASE9_NAMING_SHIM_PID="$candidate" ;; esac
  fi
  if [ -n "${CASE9_NAMING_SHIM_PID:-}" ]; then
    while kill -0 "$CASE9_NAMING_SHIM_PID" 2>/dev/null; do
      shim_ticks=$((shim_ticks + 1))
      if [ "$shim_ticks" -gt 200 ]; then
        kill -9 "$CASE9_NAMING_SHIM_PID" 2>/dev/null || true
        break
      fi
      sleep 0.05
    done
  fi
  for pid in ${SPAWNED+"${SPAWNED[@]}"}; do kill -9 "$pid" 2>/dev/null || true; done
  # Plus any background job this shell started that a failing case never got
  # as far as recording. `jobs -p` is still by-PID and still only this run's
  # own children — it is a second reading of the same state, not a pattern.
  for pid in $(jobs -p 2>/dev/null); do kill -9 "$pid" 2>/dev/null || true; done
  # Helper control directories live under this scratch (TMPDIR below), so they
  # go with it; nothing of this run is left outside it.
  rm -rf -- "$SCRATCH"
}
trap cleanup EXIT
forget_spawned() {
  local drop="$1" pid
  local kept=()
  for pid in ${SPAWNED+"${SPAWNED[@]}"}; do
    [ "$pid" = "$drop" ] || kept+=("$pid")
  done
  SPAWNED=("${kept[@]}")
}
wait_spawned() {
  local pid="$1" rc=0
  wait "$pid" || rc=$?
  forget_spawned "$pid"
  return "$rc"
}
export CERT_BUNDLE_LOCK_DIR="$LOCK_DIR"
export CERTBUNDLE_SCRIPT="$SHIPPED"
# The shipped lock puts each helper's control directory under TMPDIR, because
# those files are private to one run — unlike the rendezvous, which must be
# shared and is therefore a fixed literal. Pointing TMPDIR into this suite's
# own scratch is not a test-only seam then; it is what TMPDIR already means,
# and it is what lets case 20 below assert about the helpers THIS run started
# instead of every helper on the host. Scoping matters: the check used to glob
# the host's TMPDIR, so a CONCURRENT suite run failed it (reproduced — one of
# two overlapping runs failed on the other's live control directory), as would
# a helper whose self-removal trails its acquirer's exit by up to one liveness
# tick.
OUTER_TMPDIR="${TMPDIR:-/tmp}"
export TMPDIR="$SCRATCH/helpers"
mkdir -p "$TMPDIR"
REAL_JQ=$(command -v jq) || fail "jq required"

show() { printf -- '--- %s ---\n' "$1" >&2; cat "$1" >&2; }
assert_in() { # assert_in <file> <literal> <what>
  # grep against a FILE, never a piped variable — see linear-loop.md's
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
case9_naming_window_ready() {
  # The lock file is persistent by contract, so its existence is not evidence
  # that THIS invocation owns the flock. The naming marker is written only
  # after the shipped acquire path returns successfully and enters its holder
  # write. The independent product-path contender below is the kernel-backed
  # proof: it must observe the flock as busy while this predicate remains true.
  # The marker is written by the gated jq shim only after the nameless
  # invocation has entered its naming write. It is the causal edge that the
  # persistent lock pathname cannot provide. Keep the parent live check here:
  # a stale naming marker from a just-killed fixture must not satisfy this
  # wait either.
  [ -e "${CASE9_NAMING_ENTERED:-}" ] || return 1
  kill -0 "${NAMELESS_JOB:-0}" 2>/dev/null || return 1
  [ ! -f "$HOLDER_FILE" ]
}
case9_naming_shim_gone() {
  [ -n "${CASE9_NAMING_SHIM_PID:-}" ] || return 0
  ! kill -0 "$CASE9_NAMING_SHIM_PID" 2>/dev/null
}
case9_mutated_wait() {
  # Deliberate mutant of the old readiness test. Case 9 runs this beside the
  # corrected wait and proves that this condition returns while the gated
  # helper has not acquired the flock; changing the real wait back to this
  # shape therefore fails deterministically instead of relying on scheduling.
  [ -e "$LOCK_FILE" ] && [ ! -e "$HOLDER_FILE" ]
}
snapshot_lock() { cp "$HOLDER_FILE" "$SCRATCH/holder.snapshot"; cp "$LOCK_FILE" "$SCRATCH/lock.snapshot"; }
assert_lock_unchanged() {
  cmp -s "$HOLDER_FILE" "$SCRATCH/holder.snapshot" || { show "$HOLDER_FILE"; fail "$1 (naming record changed)"; }
  cmp -s "$LOCK_FILE" "$SCRATCH/lock.snapshot" || fail "$1 (lock file changed)"
}
assert_no_takeover_residue() { # nothing in this design renames or expires a lock
  local strays
  strays=$(find "$SCRATCH" -maxdepth 2 -name '*.stale.*' 2>/dev/null | head -5)
  [ -z "$strays" ] || fail "$1: takeover residue exists ($strays); this design has no rename path"
}
acquire_after_kill() { # acquire_after_kill <pair> <log> <budget-seconds> [backend]
  # A killed acquirer's helper drops the descriptor as soon as it notices the
  # death (one ps tick for the flock backend, one getppid tick for python), so
  # recovery is fast but not instantaneous. Assert the BOUND and report the
  # measured latency rather than pretending to a zero-latency contract this
  # design does not offer. No operator step is involved either way.
  local pair="$1" log="$2" budget="$3" backend="${4:-auto}" start now rc
  start=$(date +%s)
  while :; do
    rc=0
    CERT_BUNDLE_LOCK_BACKEND="$backend" CERT_BUNDLE_PAIR="$pair" \
      bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.$pair" "$SCRATCH/unused" \
      > "$log" 2>&1 || rc=$?
    [ "$rc" = 0 ] && break
    now=$(date +%s)
    [ $((now - start)) -lt "$budget" ] \
      || { show "$log"; fail "the lock was still held ${budget}s after its holder was killed; the kernel must reclaim it without any operator step"; }
    sleep 0.1
  done
  printf '%s\n' "$(( $(date +%s) - start ))"
}

dead_pid() { # a pid that has certainly exited
  local p
  ( exit 0 ) &
  p=$!
  wait "$p" 2>/dev/null || true
  kill -0 "$p" 2>/dev/null && fail "pid $p was reused before it could be used as a corpse; re-run"
  printf '%s\n' "$p"
}
forge_record() { # forge_record <pid> <pair> <started-epoch> — a NAMING record only
  mkdir -p "$LOCK_DIR"
  "$REAL_JQ" -n --argjson pid "$1" --arg pair "$2" --argjson started_epoch "$3" \
    --arg started_at "$(date -u -r "$3" '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null \
      || date -u -d "@$3" '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || date -u '+%Y-%m-%dT%H:%M:%SZ')" \
    '{schema_version:2,pid:$pid,pair:$pair,started_at:$started_at,started_epoch:$started_epoch,
      checkout:"/forged/checkout",backend:"forged",argv:["forged-record"]}' > "$HOLDER_FILE"
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
  race)               while [ ! -f "$BARRIER" ]; do sleep 0.02; done ;;
  hold|acquire|child) : ;;
  *) printf 'unknown driver mode: %s\n' "$MODE" >&2; exit 64 ;;
esac
certbundle_lock_acquire
if [ "$MODE" = child ]; then
  # A descendant that inherits every open descriptor and outlives this shell —
  # the orphaned-conformance-child shape from the incident itself.
  sleep 300 &
  printf '%s\n' "$!" > "$READY.child"
fi
printf '%s\n' "$$" > "$READY"
case "$MODE" in
  race|hold|child) while [ -f "$BARRIER" ]; do sleep 0.05; done ;;
esac
DRIVER

# A jq shim that stalls ONLY the naming-record write, reproducing the window
# review used to race the earlier revision (case 9).
mkdir -p "$SCRATCH/shim"
cat > "$SCRATCH/shim/jq" <<SHIM
#!/usr/bin/env bash
shim_rc=0
is_naming=0
for a in "\$@"; do
  case "\$a" in
    *schema_version*)
      is_naming=1
      if [ -n "\${DUO_SHIM_WAIT_FOR:-}" ]; then
        # Record the causal edge before waiting on the private naming gate.
        # Write the PID first so a reader that sees the marker can always
        # identify this exact shim rather than guessing from a process list.
        if [ -n "\${DUO_SHIM_PID_FILE:-}" ]; then
          printf '%s\n' "\$\$" > "\$DUO_SHIM_PID_FILE"
        fi
        if [ -n "\${DUO_SHIM_ENTERED:-}" ]; then
          : > "\$DUO_SHIM_ENTERED"
        fi
        deadline=\$(( \$(date +%s) + 60 ))
        while [ ! -e "\$DUO_SHIM_WAIT_FOR" ]; do
          [ "\$(date +%s)" -lt "\$deadline" ] || exit 125
          sleep 0.02
        done
      else
        sleep "\${DUO_SHIM_SLEEP:-2}"
      fi
      ;;
  esac
done
"$REAL_JQ" "\$@" || shim_rc=\$?
if [ "\$is_naming" = 1 ] && [ -n "\${DUO_SHIM_EXITED:-}" ]; then
  : > "\$DUO_SHIM_EXITED"
fi
exit "\$shim_rc"
SHIM
chmod +x "$SCRATCH/shim/jq"

say "case 1 — four concurrent invocations, one barrier: exactly one acquires and the losers refuse by name"
BARRIER="$SCRATCH/barrier"
RACERS=()
for i in 1 2 3 4; do
  CERT_BUNDLE_PAIR="racer$i" bash "$SCRATCH/driver.sh" race "$SCRATCH/ready.$i" "$BARRIER" \
    > "$SCRATCH/racer.$i.log" 2>&1 &
  RACERS+=("$!"); SPAWNED+=("$!")
done
: > "$BARRIER"
count_ready() { ls "$SCRATCH"/ready.* >/dev/null 2>&1; }
wait_until 30 "a racer to win the lock" count_ready
three_losers_exited() {
  local live=0 p
  for p in "${RACERS[@]}"; do kill -0 "$p" 2>/dev/null && live=$((live + 1)); done
  [ "$live" -eq 1 ]
}
wait_until 30 "the three losing racers to exit" three_losers_exited
WINNER_INDEX=$(ls "$SCRATCH" | sed -n 's/^ready\.\([0-9]*\)$/\1/p')
[ "$(printf '%s\n' "$WINNER_INDEX" | grep -c .)" = "1" ] \
  || fail "expected exactly one winner, got ready files for: $(printf '%s ' $WINNER_INDEX)"
WINNER_PID=$(cat "$SCRATCH/ready.$WINNER_INDEX")
[ "$(holder_pid)" = "$WINNER_PID" ] || fail "the naming record says $(holder_pid), the winner is $WINNER_PID"
assert_in "$SCRATCH/racer.$WINNER_INDEX.log" "host certification lock acquired" "the winner did not announce acquisition"
assert_in "$SCRATCH/racer.$WINNER_INDEX.log" "NOT serialized" "a redirected rendezvous must say so instead of pretending to serialize the host"
snapshot_lock
for i in 1 2 3 4; do
  [ "$i" = "$WINNER_INDEX" ] && continue
  rc=0; wait_spawned "${RACERS[$((i - 1))]}" || rc=$?
  [ "$rc" = "1" ] || fail "loser racer $i exited $rc, expected 1 (a refusal is a failure, not a silent skip)"
  assert_in "$SCRATCH/racer.$i.log" "refusing to start a second certification bundle" "loser $i did not refuse loudly"
  assert_in "$SCRATCH/racer.$i.log" "holder pid     : $WINNER_PID" "loser $i did not name the holder's pid"
  assert_in "$SCRATCH/racer.$i.log" "holder pair    : racer$WINNER_INDEX" "loser $i did not name the holder's pair"
  assert_in "$SCRATCH/racer.$i.log" "holder started :" "loser $i did not report when the holder started, or how long it has held"
  assert_in "$SCRATCH/racer.$i.log" "CERT_BUNDLE_WAIT=1" "loser $i did not print the exact wait command"
  assert_in "$SCRATCH/racer.$i.log" "pkill -f certify_reference_bundle" "loser $i did not name the pattern-kill remediation this issue exists for"
  assert_in "$SCRATCH/racer.$i.log" "Kill only a PID your own launcher recorded" "loser $i did not state the launcher rule"
  assert_in "$SCRATCH/racer.$i.log" "nothing to clean up by hand" "loser $i did not say the lock needs no manual cleanup"
done
pass "one of four acquired; three refused with exit 1 naming pid $WINNER_PID, its pair, its hold time, and the wait command"

say "case 2 — a live holder's lock is untouched by a contender"
[ -d "$LOCK_DIR" ] || fail "the rendezvous disappeared while its holder was still alive"
assert_lock_unchanged "three refusals mutated the live holder's lock"
assert_no_takeover_residue "case 2"
pass "lock file and naming record survived three refusals byte-identical"

say "case 3 — the EXIT trap armed by the acquire releases on normal exit, and the lock FILE survives"
rm -f "$BARRIER"
rc=0; wait_spawned "${RACERS[$((WINNER_INDEX - 1))]}" || rc=$?
[ "$rc" = "0" ] || fail "the winning racer exited $rc"
wait_until 15 "the winner's EXIT trap to drop the naming record" test '!' -f "$HOLDER_FILE"
[ -f "$LOCK_FILE" ] \
  || fail "release removed the lock file; a later run would flock a fresh inode and two holders could coexist"
pass "naming record gone, lock file kept, with no explicit release call"

say "case 4 — CERT_BUNDLE_WAIT=1 polls for the holder and acquires at release instead of displacing it"
HOLD="$SCRATCH/hold"
: > "$HOLD"
CERT_BUNDLE_PAIR=holder bash "$SCRATCH/driver.sh" hold "$SCRATCH/ready.holder" "$HOLD" \
  > "$SCRATCH/holder.log" 2>&1 &
HOLDER_JOB=$!; SPAWNED+=("$HOLDER_JOB")
wait_until 30 "the holder to acquire" test -f "$SCRATCH/ready.holder"
HOLDER_PID=$(cat "$SCRATCH/ready.holder")
CERT_BUNDLE_WAIT=1 CERT_BUNDLE_WAIT_POLL=1 CERT_BUNDLE_WAIT_TIMEOUT=90 CERT_BUNDLE_PAIR=waiter \
  bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.waiter" "$SCRATCH/unused" \
  > "$SCRATCH/waiter.log" 2>&1 &
WAITER_JOB=$!; SPAWNED+=("$WAITER_JOB")
wait_until 30 "the waiter's first progress line" grep -qF "waiting for the host certification lock" "$SCRATCH/waiter.log"
assert_in "$SCRATCH/waiter.log" "holder pid $HOLDER_PID pair holder" "the wait progress line does not name the holder"
sleep 1.5
[ ! -f "$SCRATCH/ready.waiter" ] || fail "the waiter acquired while the holder was still alive"
[ "$(holder_pid)" = "$HOLDER_PID" ] || fail "the waiter displaced the live holder"
rm -f "$HOLD"
rc=0; wait_spawned "$HOLDER_JOB" || rc=$?
[ "$rc" = "0" ] || fail "the holder exited $rc"
rc=0; wait_spawned "$WAITER_JOB" || rc=$?
[ "$rc" = "0" ] || fail "the waiter exited $rc; it should have acquired once the holder released"
assert_in "$SCRATCH/waiter.log" "host certification lock acquired" "the waiter never announced its acquisition"
wait_until 15 "the waiter's own release" test '!' -f "$HOLDER_FILE"
pass "the waiter polled with progress lines, left the holder alone, and took the lock after release"

say "case 5 — waiting is bounded: the budget expires into the same named refusal"
: > "$HOLD"
CERT_BUNDLE_PAIR=stubborn bash "$SCRATCH/driver.sh" hold "$SCRATCH/ready.stubborn" "$HOLD" \
  > "$SCRATCH/stubborn.log" 2>&1 &
STUBBORN_JOB=$!; SPAWNED+=("$STUBBORN_JOB")
wait_until 30 "the stubborn holder to acquire" test -f "$SCRATCH/ready.stubborn"
STUBBORN_PID=$(cat "$SCRATCH/ready.stubborn")
rc=0
CERT_BUNDLE_WAIT=1 CERT_BUNDLE_WAIT_POLL=1 CERT_BUNDLE_WAIT_TIMEOUT=1 CERT_BUNDLE_PAIR=impatient \
  bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.impatient" "$SCRATCH/unused" \
  > "$SCRATCH/impatient.log" 2>&1 || rc=$?
[ "$rc" = "1" ] || fail "a waiter over budget exited $rc, expected 1"
assert_in "$SCRATCH/impatient.log" "CERT_BUNDLE_WAIT_TIMEOUT=1" "the timeout refusal does not name the budget it exceeded"
assert_in "$SCRATCH/impatient.log" "holder pid     : $STUBBORN_PID" "the timeout refusal does not name the holder"
[ -f "$HOLDER_FILE" ] || fail "an expired waiter removed the live holder's record"
pass "wait mode is bounded, and its timeout refusal names the holder and the budget"

say "case 6 — a lock held for hours is never expired by age"
forge_record "$STUBBORN_PID" "stubborn" "$(( $(date +%s) - 21600 ))"   # same live holder, 6h-old record
rc=0
CERT_BUNDLE_PAIR=patient bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.patient" "$SCRATCH/unused" \
  > "$SCRATCH/ancient.log" 2>&1 || rc=$?
[ "$rc" = "1" ] || fail "a holder holding for 6h was displaced (contender exited $rc); a bundle legitimately runs over an hour"
assert_in "$SCRATCH/ancient.log" "holding 6h00m" "the refusal does not report how long the holder has held"
assert_not_in "$SCRATCH/ancient.log" "taking it over" "age must never trigger a takeover"
assert_not_in "$SCRATCH/ancient.log" "stale" "age must never be described as staleness"
rm -f "$HOLD"; rc=0; wait_spawned "$STUBBORN_JOB" || rc=$?
pass "a 6h00m-old LIVE holder keeps its lock; nothing in this design expires by age"

say "case 7 — a SIGKILLed holder's lock is released by the kernel: no corpse, no takeover, no cleanup"
: > "$HOLD"
CERT_BUNDLE_PAIR=victim bash "$SCRATCH/driver.sh" hold "$SCRATCH/ready.victim" "$HOLD" \
  > "$SCRATCH/victim.log" 2>&1 &
VICTIM_JOB=$!; SPAWNED+=("$VICTIM_JOB")
wait_until 30 "the victim to acquire" test -f "$SCRATCH/ready.victim"
VICTIM_PID=$(cat "$SCRATCH/ready.victim")
kill -9 "$VICTIM_PID"
rc=0; wait_spawned "$VICTIM_JOB" || rc=$?
[ "$rc" = "137" ] || fail "the victim exited $rc, not 137; the fixture must prove SIGKILL ran no trap"
LATENCY=$(acquire_after_kill successor "$SCRATCH/successor.log" 20)
assert_in "$SCRATCH/successor.log" "host certification lock acquired" "the successor did not acquire after its predecessor was killed"
assert_not_in "$SCRATCH/successor.log" "stale" "the successor invented a staleness decision the kernel already made"
assert_not_in "$SCRATCH/successor.log" "taking it over" "the successor announced a takeover; there is no takeover path"
assert_no_takeover_residue "case 7"
pass "a killed holder's lock came back through the kernel in ${LATENCY}s, with no heuristic and no operator step"

say "case 8 — RACE 1 (reviewer's race_b): concurrent claimers of a dead holder's record, plus a late arrival, never produce two holders"
rm -f "$HOLDER_FILE"
CORPSE=$(dead_pid)
forge_record "$CORPSE" "corpse" "$(( $(date +%s) - 60 ))"   # a crashed run's leftover record
: > "$BARRIER"
CLAIMERS=()
for i in 1 2 3; do
  CERT_BUNDLE_PAIR="claimer$i" bash "$SCRATCH/driver.sh" race "$SCRATCH/claim.$i" "$BARRIER" \
    > "$SCRATCH/claim.$i.log" 2>&1 &
  CLAIMERS+=("$!"); SPAWNED+=("$!")
done
: > "$BARRIER"
wait_until 30 "a claimer to win" bash -c 'ls "$1"/claim.[0-9] >/dev/null 2>&1' _ "$SCRATCH"
claimers_settled() {
  local live=0 p
  for p in "${CLAIMERS[@]}"; do kill -0 "$p" 2>/dev/null && live=$((live + 1)); done
  [ "$live" -eq 1 ]
}
wait_until 30 "the two losing claimers to exit" claimers_settled
ANNOUNCED=$(cat "$SCRATCH"/claim.[0-9].log 2>/dev/null | grep -c "host certification lock acquired" || true)
[ "$ANNOUNCED" = "1" ] \
  || fail "RACE 1 REGRESSED: $ANNOUNCED invocations announced the lock (review reproduced 2 against the mkdir revision); exactly 1 is the contract"
WON=$(ls "$SCRATCH" | sed -n 's/^claim\.\([0-9]\)$/\1/p')
[ "$(printf '%s\n' "$WON" | grep -c .)" = "1" ] || fail "RACE 1 REGRESSED: more than one claimer reported success ($WON)"
[ "$(holder_pid)" = "$(cat "$SCRATCH/claim.$WON")" ] \
  || fail "the naming record does not name the single winner; the corpse's record was not replaced"
assert_no_takeover_residue "case 8"
rm -f "$BARRIER"
for p in "${CLAIMERS[@]}"; do wait_spawned "$p" 2>/dev/null || true; done
pass "3 concurrent arrivals over a dead run's leftover record produced exactly 1 holder and 0 takeover artifacts"

say "case 9 — RACE 2 (reviewer's race_a): a holder killed between acquiring and naming itself never wedges the rendezvous"
rm -f "$HOLDER_FILE"
: >> "$LOCK_FILE"   # the inode is persistent even when no run currently holds it
: > "$HOLD"
# Gate the backend before it reaches the real flock(2). The lock file already
# exists from the earlier cases and the naming record is absent, so the old
# predicate would pass before this process can acquire anything. A fresh,
# case-private TMPDIR keeps this holder's helper controls isolated for the
# final leak check; the naming marker and refusing contender carry authority.
CASE9_TMPDIR="$SCRATCH/helpers-case9"
mkdir -p "$CASE9_TMPDIR"
CASE9_BACKEND= CASE9_BACKEND_BIN=
if command -v flock >/dev/null 2>&1; then
  CASE9_BACKEND=flock
  CASE9_BACKEND_BIN=$(command -v flock)
else
  command -v python3 >/dev/null 2>&1 || fail "flock or python3 required for case 9's deterministic backend gate"
  CASE9_BACKEND=python
  CASE9_BACKEND_BIN=$(command -v python3)
fi
CASE9_CONTENDER_BACKEND=auto
if [ "$CASE9_BACKEND" = flock ] && command -v python3 >/dev/null 2>&1; then
  CASE9_CONTENDER_BACKEND=python
elif [ "$CASE9_BACKEND" = python ] && command -v flock >/dev/null 2>&1; then
  CASE9_CONTENDER_BACKEND=flock
fi
CASE9_BACKEND_GATE="$SCRATCH/backend-go"
CASE9_NAMING_GATE="$SCRATCH/naming-go"
CASE9_NAMING_ENTERED="$SCRATCH/naming-entered"
CASE9_NAMING_SHIM_PID_FILE="$SCRATCH/naming-shim.pid"
CASE9_NAMING_EXITED="$SCRATCH/naming-exited"
mkdir -p "$SCRATCH/gated-backend"
cat > "$SCRATCH/gated-backend/$(basename "$CASE9_BACKEND_BIN")" <<GATED_BACKEND
#!/usr/bin/env bash
deadline=\$(( \$(date +%s) + 60 ))
while [ ! -e "$CASE9_BACKEND_GATE" ]; do
  [ "\$(date +%s)" -lt "\$deadline" ] || exit 125
  sleep 0.02
done
exec "$CASE9_BACKEND_BIN" "\$@"
GATED_BACKEND
chmod +x "$SCRATCH/gated-backend/$(basename "$CASE9_BACKEND_BIN")"
PATH="$SCRATCH/gated-backend:$SCRATCH/shim:$PATH" TMPDIR="$CASE9_TMPDIR" \
  DUO_SHIM_WAIT_FOR="$CASE9_NAMING_GATE" DUO_SHIM_ENTERED="$CASE9_NAMING_ENTERED" \
  DUO_SHIM_PID_FILE="$CASE9_NAMING_SHIM_PID_FILE" DUO_SHIM_EXITED="$CASE9_NAMING_EXITED" \
  CERT_BUNDLE_LOCK_BACKEND="$CASE9_BACKEND" CERT_BUNDLE_PAIR=nameless \
  bash "$SCRATCH/driver.sh" hold "$SCRATCH/ready.nameless" "$HOLD" > "$SCRATCH/nameless.log" 2>&1 &
NAMELESS_JOB=$!; SPAWNED+=("$NAMELESS_JOB")
# The shim stalls the record write after acquisition, so this is the window:
# the helper owns the flock, nobody is nameable, and a contender must still
# refuse. Run the old predicate as a deliberate mutant beside the real wait.
(
  : > "$SCRATCH/case9-corrected-started"
  wait_until 30 "the nameless holder to enter its gated naming write" case9_naming_window_ready
  : > "$SCRATCH/case9-corrected-done"
) &
CORRECTED_WAIT_JOB=$!; SPAWNED+=("$CORRECTED_WAIT_JOB")
(
  : > "$SCRATCH/case9-mutated-started"
  wait_until 30 "the mutated persistent-file predicate to return" case9_mutated_wait
  : > "$SCRATCH/case9-mutated-done"
) &
MUTATED_WAIT_JOB=$!; SPAWNED+=("$MUTATED_WAIT_JOB")
wait_until 5 "the corrected and mutated case 9 waits to start" \
  test -f "$SCRATCH/case9-corrected-started"
wait_until 5 "the mutated persistent-file predicate to return early" \
  test -f "$SCRATCH/case9-mutated-done"
[ ! -f "$SCRATCH/case9-corrected-done" ] \
  || fail "the corrected readiness wait returned before its helper acquired the flock; the persistent-file mutant was not killed"
if case9_naming_window_ready; then
  fail "the gated holder unexpectedly entered naming before the backend gate opened"
fi
# This is the mutation tooth's product-path half: with the old pathname/no-
# holder wait, a contender really would launch here and acquire the lock while
# the nameless driver is still blocked before its flock(2). The corrected wait
# must not advance to this probe until the backend gate is opened below.
rc=0
CERT_BUNDLE_LOCK_BACKEND="$CASE9_CONTENDER_BACKEND" CERT_BUNDLE_PAIR=mutantprobe \
  bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.mutant-probe" "$SCRATCH/unused" \
  > "$SCRATCH/mutant-probe.log" 2>&1 || rc=$?
[ "$rc" = "0" ] \
  || { show "$SCRATCH/mutant-probe.log"; fail "the old persistent-file wait did not expose its early product-path acquisition (rc $rc)"; }
assert_in "$SCRATCH/mutant-probe.log" "host certification lock acquired" \
  "the old persistent-file wait mutant did not acquire through the product path"
[ ! -f "$SCRATCH/case9-corrected-done" ] \
  || fail "the corrected readiness wait advanced while the nameless helper was still gated"
: > "$CASE9_BACKEND_GATE"
rc=0; wait_spawned "$CORRECTED_WAIT_JOB" || rc=$?
[ "$rc" = "0" ] \
  || fail "the corrected readiness wait did not observe the causal naming edge, absent record, and live parent (rc $rc)"
rc=0; wait_spawned "$MUTATED_WAIT_JOB" || rc=$?
[ "$rc" = "0" ] || fail "the deliberate persistent-file mutant did not expose its early return (rc $rc)"
[ ! -f "$HOLDER_FILE" ] \
  || fail "the nameless holder wrote its naming record before the corrected wait reached its positive acquisition marker"
wait_until 10 "the naming shim to enter its gated write" test -f "$CASE9_NAMING_ENTERED"
[ -s "$CASE9_NAMING_SHIM_PID_FILE" ] \
  || fail "the naming shim entered without recording its PID"
CASE9_NAMING_SHIM_PID=$(cat "$CASE9_NAMING_SHIM_PID_FILE")
case "$CASE9_NAMING_SHIM_PID" in
  ''|*[!0-9]*) fail "the naming shim recorded an invalid PID: $CASE9_NAMING_SHIM_PID" ;;
esac
kill -0 "$CASE9_NAMING_SHIM_PID" 2>/dev/null \
  || fail "the naming shim exited before the gated mid-naming contender probe"
rc=0
CERT_BUNDLE_LOCK_BACKEND="$CASE9_CONTENDER_BACKEND" CERT_BUNDLE_PAIR=probe \
  bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.probe" "$SCRATCH/unused" \
  > "$SCRATCH/probe.log" 2>&1 || rc=$?
[ "$rc" = "1" ] || fail "a contender arriving in the naming window exited $rc; the lock must still exclude while its holder is unnamed"
assert_in "$SCRATCH/probe.log" "cannot be named right now" "the refusal does not admit that the holder is unnamed"
assert_in "$SCRATCH/probe.log" "it is the kernel that says so" "the refusal does not state what the exclusion actually rests on"
# The nameless parent is still blocked in the naming shim. Kill that recorded
# parent while the naming gate remains closed: the jq shim cannot finish the
# partial holder write until the gate is opened below, preserving the original
# mid-naming/no-record recovery window rather than turning this into a normal
# post-naming kill.
kill -9 "$NAMELESS_JOB"
rc=0; wait_spawned "$NAMELESS_JOB" || rc=$?
[ "$rc" = "137" ] || fail "the nameless holder exited $rc, not 137 while its naming gate was closed"
: > "$CASE9_NAMING_GATE"
wait_until 10 "the recorded naming shim to finish after its gate opened" \
  test -f "$CASE9_NAMING_EXITED"
wait_until 10 "the recorded naming shim to exit" case9_naming_shim_gone
rm -f "$CASE9_NAMING_SHIM_PID_FILE"
CASE9_NAMING_SHIM_PID=
acquire_after_kill after "$SCRATCH/after.log" 20 >/dev/null
assert_in "$SCRATCH/after.log" "host certification lock acquired" \
  "RACE 2 REGRESSED: the rendezvous wedged after a holder was killed mid-naming (review reproduced exactly this against the mkdir revision)"
assert_not_in "$SCRATCH/after.log" "rm -rf" "recovery required a manual removal instruction"
LITTER=$(find "$LOCK_DIR" -maxdepth 1 -name 'holder.json.*' 2>/dev/null | head -3)
[ -z "$LITTER" ] \
  || fail "a holder killed mid-write left its partial record behind ($LITTER); one per killed run would accumulate in the shared rendezvous forever"
rm -f "$HOLD"
pass "an unnamed holder still excluded, and killing it left the rendezvous immediately usable — no wedge, no manual step"

say "case 10 — release removes only the naming record, never the lock file"
[ -f "$LOCK_FILE" ] || fail "the lock file did not survive the previous cases' releases"
[ ! -f "$HOLDER_FILE" ] || fail "a naming record outlived its holder"
pass "lock file intact across every acquire/release above; no record left behind"

say "case 11 — a descendant that outlives a killed holder does not keep the lock held"
: > "$HOLD"
CERT_BUNDLE_PAIR=parent bash "$SCRATCH/driver.sh" child "$SCRATCH/ready.parent" "$HOLD" \
  > "$SCRATCH/parent.log" 2>&1 &
PARENT_JOB=$!; SPAWNED+=("$PARENT_JOB")
wait_until 30 "the holder and its descendant to start" test -f "$SCRATCH/ready.parent.child"
PARENT_PID=$(cat "$SCRATCH/ready.parent"); CHILD_PID=$(cat "$SCRATCH/ready.parent.child")
SPAWNED+=("$CHILD_PID")
kill -9 "$PARENT_PID"
rc=0; wait_spawned "$PARENT_JOB" || rc=$?
kill -0 "$CHILD_PID" 2>/dev/null \
  || fail "fixture lost its point: the descendant must still be alive to prove it does not hold the lock"
acquire_after_kill nextrun "$SCRATCH/nextrun.log" 20 >/dev/null
assert_in "$SCRATCH/nextrun.log" "host certification lock acquired" \
  "an orphaned descendant kept the lock held — the helper must own the descriptor, not the run's process tree"
if ! kill -9 "$CHILD_PID" 2>/dev/null && kill -0 "$CHILD_PID" 2>/dev/null; then
  fail "the recorded orphaned child could not be stopped by its exact PID"
fi
# This orphan is not a child this shell can wait(2) on. Once its exact PID has
# been signalled (or is already gone), remove it from the EXIT-trap registry:
# keeping a dead PID until case 20 would let PID reuse turn cleanup into a
# signal against an unrelated host process.
forget_spawned "$CHILD_PID"
rm -f "$HOLD"
pass "the orphaned child survived and held nothing; the helper owns the descriptor"

say "case 12 — both lock backends serialize, and each is exercised rather than assumed"
for backend in flock python; do
  if ! CERT_BUNDLE_LOCK_BACKEND="$backend" bash -c '
      case "$1" in flock) command -v flock ;; python) command -v python3 ;; esac' _ "$backend" >/dev/null 2>&1; then
    printf 'note: %s backend unavailable on this host; skipped\n' "$backend"
    continue
  fi
  : > "$HOLD"
  CERT_BUNDLE_LOCK_BACKEND="$backend" CERT_BUNDLE_PAIR=bhold \
    bash "$SCRATCH/driver.sh" hold "$SCRATCH/ready.$backend" "$HOLD" > "$SCRATCH/$backend.log" 2>&1 &
  BJOB=$!; SPAWNED+=("$BJOB")
  wait_until 30 "the $backend holder to acquire" test -f "$SCRATCH/ready.$backend"
  assert_in "$SCRATCH/$backend.log" "$backend)" "the $backend holder did not report which backend it used"
  rc=0
  CERT_BUNDLE_LOCK_BACKEND="$backend" CERT_BUNDLE_PAIR=bcontend \
    bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.$backend.c" "$SCRATCH/unused" \
    > "$SCRATCH/$backend.c.log" 2>&1 || rc=$?
  [ "$rc" = "1" ] || fail "the $backend backend admitted a second holder (contender exited $rc)"
  BPID=$(cat "$SCRATCH/ready.$backend")
  kill -9 "$BPID"; rc=0; wait_spawned "$BJOB" || rc=$?
  BLAT=$(acquire_after_kill "bafter$backend" "$SCRATCH/$backend.a.log" 20 "$backend")
  assert_in "$SCRATCH/$backend.a.log" "host certification lock acquired" \
    "the $backend backend did not release on SIGKILL"
  rm -f "$HOLD"
  pass "$backend backend: excluded a second holder, and the kernel reclaimed it ${BLAT}s after SIGKILL"
done

say "case 13 — an unusable rendezvous refuses loudly instead of running unserialized"
rc=0
CERT_BUNDLE_LOCK_DIR=/nonexistent-duo3382-parent/rendezvous.lock CERT_BUNDLE_PAIR=unwritable \
  bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.unwritable" "$SCRATCH/unused" \
  > "$SCRATCH/unwritable.log" 2>&1 || rc=$?
[ "$rc" = "1" ] || fail "an uncreatable rendezvous exited $rc, expected a refusal (1)"
assert_in "$SCRATCH/unwritable.log" "cannot create the host certification rendezvous" "the refusal does not say what it could not create"
assert_in "$SCRATCH/unwritable.log" "/nonexistent-duo3382-parent/rendezvous.lock" "the refusal does not name the path"
rc=0
CERT_BUNDLE_LOCK_BACKEND=nonsense CERT_BUNDLE_PAIR=badbackend \
  bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.badbackend" "$SCRATCH/unused" \
  > "$SCRATCH/badbackend.log" 2>&1 || rc=$?
[ "$rc" = "1" ] || fail "an unknown backend exited $rc, expected a refusal (1)"
assert_in "$SCRATCH/badbackend.log" "must be auto, flock, or python" "the backend refusal does not name the accepted values"
# A helper that writes `busy` and exits can do both between the parent's
# marker tests and its kill -0, which would report an ordinary "the lock is
# taken" as a crashed helper — a refusal misfiled as a hard failure, naming
# the wrong cause. Review could not reproduce it in 200 contended attempts,
# so the re-read that closes it is pinned structurally rather than raced: an
# unreproducible window is exactly the kind a behavioural test stops covering
# without anyone noticing.
TRY_BODY="$SCRATCH/lock_try.body"
awk '/^certbundle_lock_try\(\) \{/{inside=1} inside{print} inside && /^\}$/{exit}' "$SHIPPED" > "$TRY_BODY"
[ -s "$TRY_BODY" ] || fail "cannot extract certbundle_lock_try() from certify_reference_bundle.sh"
DIED_LINE=$(grep -n 'outcome=died' "$TRY_BODY" | head -1 | cut -d: -f1)
KILL_LINE=$(grep -n 'kill -0 "\$CERT_BUNDLE_LOCK_HELPER_PID"' "$TRY_BODY" | head -1 | cut -d: -f1)
[ -n "$DIED_LINE" ] && [ -n "$KILL_LINE" ] || fail "cannot locate the helper-death branch in certbundle_lock_try()"
RETESTS=$(awk -v a="$KILL_LINE" -v b="$DIED_LINE" 'NR>a && NR<b && (/\[ -e "\$ready" \]/ || /\[ -e "\$busy" \]/)' "$TRY_BODY" | grep -c .)
[ "$RETESTS" = "2" ] \
  || fail "certbundle_lock_try() must re-test BOTH ready and busy after kill -0 reports the helper gone (found $RETESTS of 2 between lines $KILL_LINE and $DIED_LINE); otherwise a helper that reports busy and exits is misreported as a crash"
pass "an uncreatable rendezvous and an unknown backend both refuse by name; the busy/died window is closed by a re-read of both markers"

# The orderings below cannot be reached without docker and a full bundle, so
# they are pinned against the shipped source rather than simulated: a suite
# that quietly stopped covering them would be worse than no coverage.
say "case 14 — shipped ordering: the lock is acquired before any preflight or mutation"
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

say "case 15 — shipped ordering: one EXIT trap clears the work root, then releases, and a failed removal cannot skip the release"
CLEANUP_BODY="$SCRATCH/cleanup_run.body"
awk '/^cleanup_run\(\) \{$/{inside=1} inside{print} inside && /^\}$/{exit}' "$SHIPPED" > "$CLEANUP_BODY"
[ -s "$CLEANUP_BODY" ] || fail "cannot extract cleanup_run() from certify_reference_bundle.sh"
RM_LINE=$(grep -n 'rm -rf -- "\$WORK_ROOT"' "$CLEANUP_BODY" | head -1 | cut -d: -f1)
REL_LINE=$(grep -n '^  certbundle_lock_release$' "$CLEANUP_BODY" | head -1 | cut -d: -f1)
[ -n "$RM_LINE" ] && [ -n "$REL_LINE" ] \
  || fail "cleanup_run() must both clear the work root and release the lock (rm:'$RM_LINE' release:'$REL_LINE')"
[ "$RM_LINE" -lt "$REL_LINE" ] \
  || fail "cleanup_run() releases the lock before clearing the work root; the next run must never start while this one's work root is still on disk"
grep -q 'WARNING: could not remove the work root' "$CLEANUP_BODY" \
  || fail "cleanup_run()'s removal is unguarded: under set -e a failed rm aborts the trap, skipping the release and failing a green bundle over a cleanup error"
grep -q '^trap cleanup_run EXIT' "$SHIPPED" || fail "cleanup_run is defined but never installed as the EXIT trap"
grep -q '^      trap certbundle_lock_release EXIT$' "$SHIPPED" \
  || fail "certbundle_lock_acquire must arm its own release trap before writing anything"
pass "cleanup_run clears the work root (body line $RM_LINE), tolerates a failed removal, then releases (body line $REL_LINE)"

say "case 16 — the mechanisms review raced are absent by construction, not merely fixed"
grep -q 'take_over_stale' "$SHIPPED" \
  && fail "a takeover path is back in certify_reference_bundle.sh; the kernel already reclaims an flock, and every reproduced race lived in that path"
grep -q '\.stale\.' "$SHIPPED" \
  && fail "a rename-the-corpse path is back in certify_reference_bundle.sh"
grep -q 'certbundle_pid_alive' "$SHIPPED" \
  && fail "a holder-liveness heuristic is back in certify_reference_bundle.sh; flock(2) is the liveness authority"
assert_in "$SHIPPED" "flock" "the mutual exclusion no longer names flock"
assert_in "$SHIPPED" "pair.sh:569-740" "the section no longer cites the pair-budget lock it is modelled on"
pass "no takeover, no rename, no liveness heuristic: the raced mechanisms cannot be reintroduced silently"

say "case 17 — the source-only hook cannot be used to run a certification"
rc=0
CERT_BUNDLE_LOCK_LIB_ONLY=1 bash "$SHIPPED" > "$SCRATCH/hook.log" 2>&1 || rc=$?
[ "$rc" = "1" ] || fail "executing the shipped script with CERT_BUNDLE_LOCK_LIB_ONLY exited $rc, expected a refusal (1)"
assert_in "$SCRATCH/hook.log" "source-only hook" "the hook refusal does not explain itself"
[ ! -f "$HOLDER_FILE" ] || fail "the refused hook invocation left a naming record behind"
pass "the test seam refuses to run a bundle instead of silently skipping the lock"

say "case 18 — a transient ps failure must never be read as the acquirer's death"
# Review reproduced this against the previous revision: the flock helper read
# certbundle_process_identity, and an EMPTY result (ps failing to fork under
# load) was indistinguishable from "the acquirer died", so the helper dropped
# the descriptor and exited while its acquirer ran on — "SECOND RUN ACQUIRED
# while the first is alive". The helper spawns that ps thousands of times per
# bundle on a fork-pressured host, and the failure was completely silent.
mkdir -p "$SCRATCH/psshim"
REAL_PS=$(command -v ps) || fail "ps required"
cat > "$SCRATCH/psshim/ps" <<PSSHIM
#!/usr/bin/env bash
# Empty output, exit 0 — a ps that failed to fork, not a dead process.
[ -e "$SCRATCH/ps-fails" ] && exit 0
exec $REAL_PS "\$@"
PSSHIM
chmod +x "$SCRATCH/psshim/ps"
rm -f "$SCRATCH/ps-fails"
: > "$HOLD"
PATH="$SCRATCH/psshim:$PATH" CERT_BUNDLE_LOCK_BACKEND=flock CERT_BUNDLE_PAIR=survivor \
  bash "$SCRATCH/driver.sh" hold "$SCRATCH/ready.survivor" "$HOLD" > "$SCRATCH/survivor.log" 2>&1 &
SURVIVOR_JOB=$!; SPAWNED+=("$SURVIVOR_JOB")
wait_until 30 "the survivor to acquire with a healthy ps" test -f "$SCRATCH/ready.survivor"
SURVIVOR_PID=$(cat "$SCRATCH/ready.survivor")
: > "$SCRATCH/ps-fails"   # every identity read from here on comes back empty
sleep 2                   # several identity checks, at one per 0.5s
kill -0 "$SURVIVOR_PID" 2>/dev/null \
  || fail "fixture lost its point: the holder must still be alive while its identity is unreadable"
rm -f "$SCRATCH/ps-fails"
rc=0
CERT_BUNDLE_PAIR=intruder bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.intruder" "$SCRATCH/unused" \
  > "$SCRATCH/intruder.log" 2>&1 || rc=$?
[ "$rc" = "1" ] \
  || { show "$SCRATCH/intruder.log"; fail "a second run acquired while the first was alive: the helper read a transient ps failure as death and dropped the lock"; }
assert_in "$SCRATCH/intruder.log" "refusing to start a second certification bundle" "the intruder did not refuse"
assert_in "$SCRATCH/intruder.log" "holder pid     : $SURVIVOR_PID" "the intruder did not name the surviving holder"
# The conservative reading must not cost the crash-safety it protects.
kill -9 "$SURVIVOR_PID"
rc=0; wait_spawned "$SURVIVOR_JOB" || rc=$?
[ "$rc" = "137" ] || fail "the survivor exited $rc, not 137"
PSLAT=$(acquire_after_kill afterps "$SCRATCH/afterps.log" 20)
assert_in "$SCRATCH/afterps.log" "host certification lock acquired" \
  "keeping the lock through an unreadable identity also kept it through a real death"
rm -f "$HOLD"
pass "an unreadable identity kept the lock (intruder refused), and a real kill still reclaimed it in ${PSLAT}s"

say "case 19 — the two backends exclude each other, in both directions"
for pairing in "flock python" "python flock"; do
  set -- $pairing; HB="$1"; CB="$2"
  : > "$HOLD"
  CERT_BUNDLE_LOCK_BACKEND="$HB" CERT_BUNDLE_PAIR=xhold \
    bash "$SCRATCH/driver.sh" hold "$SCRATCH/ready.x$HB" "$HOLD" > "$SCRATCH/x$HB.log" 2>&1 &
  XJOB=$!; SPAWNED+=("$XJOB")
  wait_until 30 "the $HB holder to acquire" test -f "$SCRATCH/ready.x$HB"
  XPID=$(cat "$SCRATCH/ready.x$HB")
  rc=0
  CERT_BUNDLE_LOCK_BACKEND="$CB" CERT_BUNDLE_PAIR=xcontend \
    bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.xc$CB" "$SCRATCH/unused" \
    > "$SCRATCH/xc$CB.log" 2>&1 || rc=$?
  [ "$rc" = "1" ] \
    || { show "$SCRATCH/xc$CB.log"; fail "a $CB contender acquired while a $HB holder held it; the two backends must be the same flock(2) on the same file"; }
  assert_in "$SCRATCH/xc$CB.log" "holder pid     : $XPID" "the $CB contender did not name the $HB holder"
  rm -f "$HOLD"; rc=0; wait_spawned "$XJOB" || rc=$?
  pass "$HB holder excluded a $CB contender, which named it correctly"
done

say "case 20 — no helper control directory started by THIS run outlives it"
own_helper_dirs() {
  find "$TMPDIR" "$CASE9_TMPDIR" -maxdepth 1 -name 'duo-certbundle-helper.*' 2>/dev/null
}
# A helper removes its own control directory on the liveness tick that notices
# its acquirer is gone, so a killed holder's directory can trail the case that
# killed it. Give it a couple of ticks before calling it a leak — the claim is
# that nothing outlives the suite, not that removal is synchronous.
LEAK_DEADLINE=$(( $(date +%s) + 3 ))
while [ -n "$(own_helper_dirs)" ] && [ "$(date +%s)" -lt "$LEAK_DEADLINE" ]; do sleep 0.1; done
LEAKED=$(own_helper_dirs | head -5)
[ -z "$LEAKED" ] || fail "helper control directories leaked: $LEAKED"
# Foreign helpers — another agent's suite, or a real bundle holding the host
# lock right now — are reported and never failed on. This suite has no
# authority over another run's files, and asserting about them is what made
# this check fail on other people's work.
FOREIGN=$(find "$OUTER_TMPDIR" -maxdepth 1 -name 'duo-certbundle-helper.*' 2>/dev/null | head -3)
if [ -n "$FOREIGN" ]; then
  printf 'WARNING: helper control directories from OTHER runs are present in %s.\n' "$OUTER_TMPDIR" >&2
  printf 'They are not this suite%s and not a failure; a bundle may be holding the host lock.\n' "'s" >&2
  printf '%s\n' "$FOREIGN" | sed 's/^/  /' >&2
fi
pass "every helper this suite started cleaned up its own control directory"

# ---------------------------------------------------------------------------
# DUO-3396: the pair budget's reservation for the pair this lock names.
#
# The lock above is held for one whole ~50-minute bundle, but the bundle
# destroys and recreates ONE pair per leg (each leg's conformance/run.sh calls
# `pair.sh up`), so every leg re-enters sandbox/bin/pair.sh's host budget check.
# On 2026-08-09 five legs ran green, other agents filled the host's 4-pair
# budget in between, leg 6's `up` refused over budget ("5 > 4"), and the bundle
# recorded an immutable FAIL after ~25 minutes of earned evidence.
# DUO_PAIR_BUDGET_OVERRIDE=1 is not available to a certification: nothing in
# the bundle detects the override today, so its manifest would affirmatively
# claim force_hatches:[] for a run whose budget WAS forced -- silently wrong
# evidence (DUO-3404 tracks making the bundle detect and record it).
#
# So while this lock is HELD, pair.sh treats the exact pair name its record
# carries as already budgeted. The cases below drive the SHIPPED pair.sh
# against the SHIPPED lock -- a real holder process, a real flock, a real
# record -- with a fake `docker` on PATH: the host budget is a fixture, no pair
# is ever started, and nothing outside this run's own $SCRATCH is touched.
PAIR_SH="$(cd .. && pwd)/bin/pair.sh"
[ -f "$PAIR_SH" ] || fail "cannot find the pair tool under test: $PAIR_SH"
# Empty on a host with no util-linux flock(1) (an ordinary macOS/BSD box):
# the probe cases that need a REAL flock to shim say so and skip.
REAL_FLOCK="$(command -v flock 2>/dev/null || true)"

write_pair_fakes() { # write_pair_fakes <bin-dir>
  mkdir -p "$1"
  cat > "$1/docker" <<'FAKE_DOCKER'
#!/usr/bin/env bash
# Answers ONLY the two queries pair.sh's budget reservation makes, so the
# budget is a fixture instead of whatever this host happens to be running.
# Every other docker call is a sentinel that ends the run there: reaching one
# means the budget gate ADMITTED this pair, which is exactly what the admitted
# cases assert, and stopping means no case ever waits on a container that is
# never going to exist.
set -euo pipefail
if [ "${1:-}" = info ]; then
  case "${3:-}" in
    '{{.NCPU}}')     printf '%s\n' "${DUO_PAIR_TEST_CPU:?}" ;;
    '{{.MemTotal}}') printf '%s\n' "${DUO_PAIR_TEST_MEM:?}" ;;
  esac
  exit 0
fi
if [ "${1:-}" = compose ] && [ "${2:-}" = ls ]; then
  printf '%s\n' "${DUO_PAIR_TEST_LIVE_PAIRS:?}"
  exit 0
fi
printf 'FAKE-DOCKER-SENTINEL: %s\n' "$*" >&2
exit 42
FAKE_DOCKER
  cat > "$1/git" <<'FAKE_GIT'
#!/usr/bin/env bash
# canonical_root() only, which is all pair.sh needs to reach its budget gate
# with DUO_EXPECTED_SOURCE_SHA unset. pair.sh's own suites fake git this way.
set -euo pipefail
[ "${1:-}" = rev-parse ] || exit 1
printf '%s/.git\n' "${DUO_PAIR_TEST_CANONICAL_ROOT:?}"
FAKE_GIT
  chmod +x "$1/docker" "$1/git"
}

PAIR_HOST_UTILITIES=(bash python3 jq awk mkdir grep sed tr seq sleep chmod find rm dirname cat mktemp mkfifo rmdir)
link_host_utilities() { # link_host_utilities <bin-dir>; 1 = this host cannot
  local bin="$1" utility path
  for utility in "${PAIR_HOST_UTILITIES[@]}"; do
    path="$(command -v "$utility")" || return 1
    ln -sf "$path" "$bin/$utility"
  done
  return 0
}

write_broken_shared_flock() { # write_broken_shared_flock <bin-dir>
  # An flock(1) that implements everything EXCEPT shared locks: `-s` exits 64
  # (the usage-error shape), anything else is the real tool, so pair.sh's own
  # exclusive budget lock still works and only the read-side probe is broken.
  # Review reproduced admission-over-budget through exactly this: a probe that
  # reads EVERY non-zero flock status as "a holder has it" turns a tool failure
  # into a reservation, with a stale record and nobody holding anything.
  cat > "$1/flock" <<SHIM
#!/usr/bin/env bash
set -euo pipefail
for a in "\$@"; do
  case "\$a" in
    -s|--shared) printf 'flock: unrecognized option -- s\n' >&2; exit 64 ;;
  esac
done
exec $REAL_FLOCK "\$@"
SHIM
  chmod +x "$1/flock"
}

PAIR_UP_STATUS=0
PAIR_UP_SKIPPED=
pair_up() { # pair_up <label> <pair> <rendezvous> [probe: auto|python|brokenshared] [override: 0|1]
  # One private copy of the shipped pair.sh per case, under this run's scratch:
  # pair.sh writes sandbox/.env and sandbox/siterepo/ relative to its own
  # location, and no regression may write those into the checkout it is testing.
  #
  # A variant this host cannot stage sets PAIR_UP_SKIPPED and runs nothing, and
  # every caller must look at it before asserting: signalling a skip through a
  # RETURN value would leave PAIR_UP_STATUS and the log file holding the
  # PREVIOUS case's answer, so a host missing a tool would assert against stale
  # evidence and call it a pass. PAIR_UP_STATUS is reset here either way, so a
  # caller that forgets fails loudly instead of inheriting one.
  local label="$1" pair="$2" rendezvous="$3" probe="${4:-auto}" override="${5:-0}"
  local root="$SCRATCH/pair.$label" path_value
  PAIR_UP_STATUS=0
  PAIR_UP_SKIPPED=
  mkdir -p "$root/sandbox/bin" "$root/bin"
  cp "$PAIR_SH" "$root/sandbox/bin/pair.sh"
  chmod +x "$root/sandbox/bin/pair.sh"
  write_pair_fakes "$root/bin"
  path_value="$root/bin:$PATH"
  case "$probe" in
    python)
      # A PATH with no flock(1) on it at all: the read-side probe must fall back
      # to the documented python3 fcntl.flock backend, the same fallback pair.sh's
      # own budget lock and the lock above already offer a macOS/BSD host.
      if ! link_host_utilities "$root/bin"; then
        PAIR_UP_SKIPPED="this host lacks one of the documented pair.sh utilities"
        return 0
      fi
      path_value="$root/bin"
      ;;
    brokenshared)
      # Nothing to break on a host with no flock(1): pair.sh would take the
      # python branch, and a shim there would only misroute its budget lock.
      if [ -z "$REAL_FLOCK" ]; then
        PAIR_UP_SKIPPED="this host has no flock(1) to shim"
        return 0
      fi
      write_broken_shared_flock "$root/bin"
      ;;
  esac
  # Three cores / 5GiB is exactly one budget unit (see pair_budget()), and one
  # foreign pair is already live: the host is AT its cap for every case below.
  # DUO_PAIR_BUDGET_OVERRIDE is always passed EXPLICITLY, 0 unless a case is
  # about it: a value inherited from whoever launched this suite would decide
  # cases that are supposed to be deciding on the reservation.
  env PATH="$path_value" \
    DUO_PAIR_BUDGET_OVERRIDE="$override" \
    CERT_BUNDLE_LOCK_DIR="$rendezvous" \
    DUO_PAIR_TEST_CPU=3 DUO_PAIR_TEST_MEM=5368709120 \
    DUO_PAIR_TEST_LIVE_PAIRS='[{"ConfigFiles":"/fake/pair.yml","Name":"duo-existing"}]' \
    DUO_PAIR_TEST_CANONICAL_ROOT="$root/canonical" \
    bash "$root/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless \
    > "$SCRATCH/$label.log" 2>&1 || PAIR_UP_STATUS=$?
  return 0
}

assert_pair_refused() { # assert_pair_refused <label> <pair> <why>
  local log="$SCRATCH/$1.log"
  [ "$PAIR_UP_STATUS" = "1" ] || { show "$log"; fail "$3: pair.sh exited $PAIR_UP_STATUS, expected the budget refusal (1)"; }
  assert_in "$log" "refusing to bring up new pair '$2' over budget" "$3"
  assert_not_in "$log" "FAKE-DOCKER-SENTINEL" "$3: the refusal was reached only after touching Docker beyond the budget queries"
  assert_not_in "$log" "already budgeted for that run" "$3: a reservation was announced for a pair that must not have one"
}

assert_pair_admitted() { # assert_pair_admitted <label> <pair> <why>
  local log="$SCRATCH/$1.log"
  assert_not_in "$log" "refusing to bring up new pair '$2' over budget" "$3"
  assert_in "$log" "is the pair recorded by the HELD host certification lock" "$3: the reservation was not announced"
  assert_in "$log" "already budgeted for that run" "$3: the reservation did not say what it rests on"
  assert_not_in "$log" "DUO_PAIR_BUDGET_OVERRIDE=1 set" "$3: the admission leaned on the override hatch instead of the reservation"
  # Past the gate, the next thing `up` does is bring the shared DB up.
  assert_in "$log" "FAKE-DOCKER-SENTINEL" "$3: nothing beyond the budget gate was reached"
  [ "$PAIR_UP_STATUS" = "42" ] \
    || { show "$log"; fail "$3: pair.sh exited $PAIR_UP_STATUS, expected the fake docker sentinel (42) that follows the gate"; }
}

say "case 21 — shipped ordering: the pair is recorded before the bundle's first pair.sh call, and both sides name one rendezvous"
RECORD_LINE=$(grep -n 'CERT_BUNDLE_LOCK_HOLDER_FILE\.\$\$' "$SHIPPED" | head -1 | cut -d: -f1)
FIRST_PAIR_LINE=$(grep -n 'bin/pair\.sh' "$SHIPPED" | grep -v '^[0-9]*:#' | head -1 | cut -d: -f1)
[ -n "$RECORD_LINE" ] || fail "certify_reference_bundle.sh no longer writes a naming record"
[ -n "$FIRST_PAIR_LINE" ] || fail "cannot locate the bundle's first pair.sh invocation"
[ "$ACQUIRE_LINE" -lt "$FIRST_PAIR_LINE" ] \
  || fail "the lock is acquired at line $ACQUIRE_LINE, after the first pair.sh call at $FIRST_PAIR_LINE"
[ "$RECORD_LINE" -lt "$ACQUIRE_LINE" ] \
  || fail "the naming record is written at line $RECORD_LINE, outside the acquire called at $ACQUIRE_LINE -- every leg after the first would find no record to be exempt by"
assert_in "$SHIPPED" '--argjson pid "$$" --arg pair "$PAIR"' "the record no longer carries the pair name the exemption is keyed on"
assert_in "$SHIPPED" 'CONF_PAIR="$PAIR"' "the legs no longer bring up the pair the lock records; the recorded name would be exempting nothing"
# One rendezvous or there is nothing to be exempt from: the same default
# literal, the same record filename, on both sides.
assert_in "$SHIPPED" 'CERT_BUNDLE_LOCK_DIR="${CERT_BUNDLE_LOCK_DIR:-/tmp/duo-certbundle.lock}"' \
  "the bundle's rendezvous default changed"
assert_in "$PAIR_SH" 'CERT_BUNDLE_LOCK_DIR="${CERT_BUNDLE_LOCK_DIR:-/tmp/duo-certbundle.lock}"' \
  "pair.sh does not name the same rendezvous default as the bundle"
assert_in "$SHIPPED" 'CERT_BUNDLE_LOCK_HOLDER_FILE="$CERT_BUNDLE_LOCK_DIR/holder.json"' \
  "the bundle's record filename changed"
assert_in "$PAIR_SH" 'holder_file="$CERT_BUNDLE_LOCK_DIR/holder.json"' \
  "pair.sh does not read the same record file the bundle writes"
pass "record written at line $RECORD_LINE inside the acquire (line $ACQUIRE_LINE), both before the first pair.sh call at line $FIRST_PAIR_LINE; one rendezvous, one record filename"

say "case 22 — at the cap, a foreign pair name still refuses while the lock is held (the budget is not weakened)"
: > "$HOLD"
CERT_BUNDLE_PAIR=certbundle bash "$SCRATCH/driver.sh" hold "$SCRATCH/ready.certbundle" "$HOLD" \
  > "$SCRATCH/certbundle.log" 2>&1 &
CERTBUNDLE_JOB=$!; SPAWNED+=("$CERTBUNDLE_JOB")
wait_until 30 "the certification holder to acquire" test -f "$SCRATCH/ready.certbundle"
[ "$(jq -r '.pair' "$HOLDER_FILE")" = certbundle ] || fail "the holder did not record the pair it holds for"
pair_up foreign otherpair "$LOCK_DIR"
assert_pair_refused foreign otherpair "a foreign pair was admitted on someone else's reservation"
# Exact name, never a pattern: DUO-3382's own discipline, and the reason a
# prefix match is the wrong tool everywhere in this rendezvous.
pair_up superstring certbundlex "$LOCK_DIR"
assert_pair_refused superstring certbundlex "a name CONTAINING the recorded one was admitted; the match must be exact"
pair_up prefix certbund "$LOCK_DIR"
assert_pair_refused prefix certbund "a PREFIX of the recorded name was admitted; the match must be exact"
pass "at the cap, 'otherpair', 'certbundlex' and 'certbund' all refuse while 'certbundle' holds the lock"

say "case 23 — at the cap, the pair the held lock records is admitted"
pair_up recorded certbundle "$LOCK_DIR"
assert_pair_admitted recorded certbundle "the pair its own certification lock reserved was refused over budget"
assert_in "$SCRATCH/recorded.log" "$LOCK_DIR" "the reservation does not name the rendezvous it rests on"
if command -v flock >/dev/null 2>&1; then
  printf 'note: this host has flock(1); the default probe above used it\n'
else
  printf 'note: this host has no flock(1); the default probe above used the python3 fcntl fallback\n'
fi
pair_up recordedpy certbundle "$LOCK_DIR" python
if [ -n "$PAIR_UP_SKIPPED" ]; then
  printf 'note: %s; python-probe variant skipped\n' "$PAIR_UP_SKIPPED"
else
  assert_pair_admitted recordedpy certbundle "the python3 fcntl read-side probe did not see the held lock"
  pass "admitted through both the default probe and the python3 fcntl fallback"
fi
pass "the recorded pair is admitted at ${LOCK_DIR}'s cap while its lock is held, without the override hatch"

say "case 24 — a reservation is answered as a reservation even when the override is also set"
# The two admitting branches are not interchangeable, and a swap between them
# is invisible to every case above: with the hatch unset they behave
# identically. Review mutated the order and the suite stayed green. Here both
# apply at once, so only the ordering can produce this log -- the reservation
# must be the reason, and the run must not report itself as forced. It matters
# beyond wording: DUO_PAIR_BUDGET_OVERRIDE is what an operator sets for a
# reason of their own, and a bundle whose slot was RESERVED must not have its
# evidence say the host budget was overridden to get it.
pair_up recordedoverride certbundle "$LOCK_DIR" auto 1
assert_pair_admitted recordedoverride certbundle "the reservation did not answer first while DUO_PAIR_BUDGET_OVERRIDE=1 was also set"
pass "with both the reservation and DUO_PAIR_BUDGET_OVERRIDE=1 in play, the reservation answers and the hatch is never mentioned"

say "case 25 — the reservation dies with its holder: a SIGKILLed bundle's surviving record grants nothing"
CERTBUNDLE_PID=$(cat "$SCRATCH/ready.certbundle")
kill -9 "$CERTBUNDLE_PID"
rc=0; wait "$CERTBUNDLE_JOB" || rc=$?
[ "$rc" = "137" ] || fail "the certification holder exited $rc, not 137; the fixture must prove SIGKILL ran no trap"
[ -f "$HOLDER_FILE" ] \
  || fail "fixture lost its point: the naming record must SURVIVE the kill, or this case cannot tell a record from a lock"
# The helper drops the descriptor on the tick that notices its acquirer is
# gone, so the reservation lapses fast but not instantaneously -- the same
# bound acquire_after_kill() asserts above, for the same kernel reason. No
# operator step is involved either way.
REFUSE_START=$(date +%s)
while :; do
  pair_up phantom certbundle "$LOCK_DIR"
  [ "$PAIR_UP_STATUS" = "1" ] && break
  [ $(( $(date +%s) - REFUSE_START )) -lt 20 ] \
    || { show "$SCRATCH/phantom.log"; fail "a dead bundle's record still reserved 'certbundle' 20s after its holder was killed; the record must never outrank the flock"; }
  sleep 0.1
done
assert_pair_refused phantom certbundle "a crashed bundle left a phantom reservation behind"
[ -f "$HOLDER_FILE" ] \
  || fail "the record vanished during this case; the refusal must be the flock's answer WITH the record still in place"
pass "the record outlived its holder and reserved nothing: refused $(( $(date +%s) - REFUSE_START ))s after the kill, record still on disk"

say "case 26 — no lock at all is the ordinary budget, and the read side creates nothing"
EMPTY_RENDEZVOUS="$SCRATCH/never-created.lock"
[ ! -e "$EMPTY_RENDEZVOUS" ] || fail "fixture: $EMPTY_RENDEZVOUS already exists"
pair_up nolock certbundle "$EMPTY_RENDEZVOUS"
assert_pair_refused nolock certbundle "a pair was admitted with no certification lock anywhere"
[ ! -e "$EMPTY_RENDEZVOUS" ] \
  || fail "pair.sh created the rendezvous $EMPTY_RENDEZVOUS; this side READS the lock and must never make one"
rm -f -- "$HOLDER_FILE"
pair_up norecord certbundle "$LOCK_DIR"
assert_pair_refused norecord certbundle "a pair was admitted from a rendezvous with no naming record"
[ -f "$LOCK_FILE" ] || fail "the read side removed the lock file"
pass "no rendezvous and a record-less rendezvous both fall through to the ordinary budget, with nothing created or removed"

say "case 27 — a probe that cannot ask the kernel is doubt, not a holder"
# Review reproduced admission-over-budget here: the first revision of the
# read-side probe mapped EVERY non-zero flock status to "held", so a shared
# lock the tool could not take -- a usage error, an unsupported filesystem, an
# flock that is not util-linux's -- read exactly like a live bundle, and a
# stale record with NOBODY holding anything was enough to admit a pair over
# the cap. Only flock(1)'s documented could-not-acquire status is a holder;
# every other outcome falls through to the ordinary refusal, which is the same
# shape the python backend has always had (only BlockingIOError is a holder).
if [ -z "$REAL_FLOCK" ]; then
  printf 'note: this host has no flock(1) to shim; broken-probe case skipped\n'
else
  CORPSE=$(dead_pid)
  forge_record "$CORPSE" certbundle "$(( $(date +%s) - 60 ))"   # a crashed run's record
  [ "$(jq -r '.pair' "$HOLDER_FILE")" = certbundle ] || fail "the forged record does not name the pair under test"
  # Nobody holds the lock: the case above released it, and this record is a
  # corpse's. A working probe answers "free" here, so anything admitted is the
  # BROKEN probe being believed.
  pair_up brokenprobe certbundle "$LOCK_DIR" brokenshared
  [ -z "$PAIR_UP_SKIPPED" ] || fail "the broken-probe fixture did not install ($PAIR_UP_SKIPPED); REAL_FLOCK was lost mid-run"
  assert_pair_refused brokenprobe certbundle "a probe that could not take a shared lock was read as a live holder, admitting a pair over the cap on a dead run's record"
  assert_in "$SCRATCH/brokenprobe.log" "1 running pairs" "the refusal is not the ordinary budget refusal"
  [ -f "$HOLDER_FILE" ] || fail "the read side removed the record it could not act on"
  pass "a shared-lock probe the tool could not perform refuses, with the record still naming this exact pair"
fi

printf '\n\033[1;32m✔ REGRESS_CERTBUNDLE_LOCK PASSED\033[0m\n'
