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
# Cleanup kills only PIDs this suite recorded when it spawned them — the very
# discipline the issue mandates. A `pkill -f driver.sh` here would be the
# defect under test, committed by its own regression.
cleanup() {
  local pid
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
for a in "\$@"; do
  case "\$a" in *schema_version*) sleep "\${DUO_SHIM_SLEEP:-2}" ;; esac
done
exec $REAL_JQ "\$@"
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
  rc=0; wait "${RACERS[$((i - 1))]}" || rc=$?
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
rc=0; wait "${RACERS[$((WINNER_INDEX - 1))]}" || rc=$?
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
rc=0; wait "$HOLDER_JOB" || rc=$?
[ "$rc" = "0" ] || fail "the holder exited $rc"
rc=0; wait "$WAITER_JOB" || rc=$?
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
rm -f "$HOLD"; rc=0; wait "$STUBBORN_JOB" || rc=$?
pass "a 6h00m-old LIVE holder keeps its lock; nothing in this design expires by age"

say "case 7 — a SIGKILLed holder's lock is released by the kernel: no corpse, no takeover, no cleanup"
: > "$HOLD"
CERT_BUNDLE_PAIR=victim bash "$SCRATCH/driver.sh" hold "$SCRATCH/ready.victim" "$HOLD" \
  > "$SCRATCH/victim.log" 2>&1 &
VICTIM_JOB=$!; SPAWNED+=("$VICTIM_JOB")
wait_until 30 "the victim to acquire" test -f "$SCRATCH/ready.victim"
VICTIM_PID=$(cat "$SCRATCH/ready.victim")
kill -9 "$VICTIM_PID"
rc=0; wait "$VICTIM_JOB" || rc=$?
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
for p in "${CLAIMERS[@]}"; do wait "$p" 2>/dev/null || true; done
pass "3 concurrent arrivals over a dead run's leftover record produced exactly 1 holder and 0 takeover artifacts"

say "case 9 — RACE 2 (reviewer's race_a): a holder killed between acquiring and naming itself never wedges the rendezvous"
rm -f "$HOLDER_FILE"
: > "$HOLD"
PATH="$SCRATCH/shim:$PATH" DUO_SHIM_SLEEP=5 CERT_BUNDLE_PAIR=nameless \
  bash "$SCRATCH/driver.sh" hold "$SCRATCH/ready.nameless" "$HOLD" > "$SCRATCH/nameless.log" 2>&1 &
NAMELESS_JOB=$!; SPAWNED+=("$NAMELESS_JOB")
# The shim stalls the record write, so this is the window: lock held, nobody nameable.
wait_until 30 "the nameless holder to hold the lock without a record" \
  bash -c '[ -f "$1" ] && [ ! -f "$2" ]' _ "$LOCK_FILE" "$HOLDER_FILE"
rc=0
CERT_BUNDLE_PAIR=probe bash "$SCRATCH/driver.sh" acquire "$SCRATCH/ready.probe" "$SCRATCH/unused" \
  > "$SCRATCH/probe.log" 2>&1 || rc=$?
[ "$rc" = "1" ] || fail "a contender arriving in the naming window exited $rc; the lock must still exclude while its holder is unnamed"
assert_in "$SCRATCH/probe.log" "cannot be named right now" "the refusal does not admit that the holder is unnamed"
assert_in "$SCRATCH/probe.log" "it is the kernel that says so" "the refusal does not state what the exclusion actually rests on"
kill -9 "$NAMELESS_JOB"
rc=0; wait "$NAMELESS_JOB" || rc=$?
[ "$rc" = "137" ] || fail "the nameless holder exited $rc, not 137"
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
rc=0; wait "$PARENT_JOB" || rc=$?
kill -0 "$CHILD_PID" 2>/dev/null \
  || fail "fixture lost its point: the descendant must still be alive to prove it does not hold the lock"
acquire_after_kill nextrun "$SCRATCH/nextrun.log" 20 >/dev/null
assert_in "$SCRATCH/nextrun.log" "host certification lock acquired" \
  "an orphaned descendant kept the lock held — the helper must own the descriptor, not the run's process tree"
kill -9 "$CHILD_PID" 2>/dev/null || true
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
  kill -9 "$BPID"; rc=0; wait "$BJOB" || rc=$?
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
rc=0; wait "$SURVIVOR_JOB" || rc=$?
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
  rm -f "$HOLD"; rc=0; wait "$XJOB" || rc=$?
  pass "$HB holder excluded a $CB contender, which named it correctly"
done

say "case 20 — no helper control directory started by THIS run outlives it"
own_helper_dirs() { find "$TMPDIR" -maxdepth 1 -name 'duo-certbundle-helper.*' 2>/dev/null; }
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

printf '\n\033[1;32m✔ REGRESS_CERTBUNDLE_LOCK PASSED\033[0m\n'
