#!/usr/bin/env bash
# DUO-3306 reference certification: every shipped conformance manifest emits
# named machine evidence, followed by the executable multisite refusal boundary
# and exact-artifact version matrix. Every run, pass or fail, is reduced to
# result/diff JSON plus a full log and published by content digest.
set -euo pipefail
# BASH_SOURCE, not $0: the lock section below is also sourced by
# sandbox/tests/regress_certbundle_lock.sh, where $0 is the regression.
cd "$(dirname "${BASH_SOURCE[0]}")/.."   # -> sandbox/
REPO_ROOT=$(cd .. && pwd)

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

PAIR="${CERT_BUNDLE_PAIR:-certbundle}"
PORT1="${CERT_BUNDLE_PORT1:-8880}"
PORT2="${CERT_BUNDLE_PORT2:-8881}"
OUT_ROOT="${CERT_BUNDLE_OUT:-$PWD/certification-bundles}"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "invalid CERT_BUNDLE_PAIR '$PAIR'"
command -v jq >/dev/null || fail "jq required"
command -v php >/dev/null || fail "php required"
command -v git >/dev/null || fail "git required"

# DUO-3382: per-host advisory lock around the whole run.
#
# A bundle run owns a docker pair, a fixed port pair, and 50-70 minutes of
# host, and it publishes evidence by content digest; two concurrent runs on
# one host collide on all three. Nothing enforced that, so launchers invented
# their own serialization -- and one of them "cleared the way" with a
# `pkill -f certify_reference_bundle` preamble, killing three unrelated runs'
# parent+wrapper on 2026-08-09, orphaning their conformance children and
# losing their work roots (~40 min each). Pattern-killing is the same defect
# class as the pgrep-f self-match already catalogued in
# docs/agents/linear-loop.md: a pattern matches whatever merely CONTAINS the
# string, including every other agent's run. So serialization lives HERE, in
# the thing being serialized, where it holds no matter which launcher starts
# the run, and the refusal NAMES the holder -- an anonymous "locked" is what
# makes an operator reach for pkill in the first place.
#
# The mutual exclusion is flock(2), acquired by a helper process that owns the
# descriptor, exactly as sandbox/bin/pair.sh:569-740 acquires the shared pair
# budget. That choice is what makes this section short. The kernel releases an
# flock when the last descriptor on it closes, so a killed holder -- SIGKILL,
# a pattern kill, a crashed VM -- is released by the kernel itself, with no
# corpse to detect, no age or liveness heuristic to get wrong, and no takeover
# or rename path that a second claimer could race. An earlier revision of this
# section gated on mkdir(2) and hand-rolled that recovery; review reproduced
# two races in it (two claimers of one corpse producing two announced holders;
# a claimer killed between mkdir and the record write wedging the rendezvous
# into a state nobody could be named from). Neither race is expressible here,
# because neither mechanism exists.
#
# The helper owns the descriptor rather than this shell because descendants
# inherit open descriptors: a conformance child or docker client that outlived
# a killed parent would otherwise keep the reservation held forever. This is
# the same reasoning pair.sh's helper documents, and the incident this issue
# exists for is precisely a killed parent leaving a running child behind.
#
# The rendezvous path is a FIXED LITERAL and deliberately not TMPDIR-relative:
# mutual exclusion exists only if every invocation on the host names the same
# path, and co-hosted agents each carry a private TMPDIR (per-session
# scratchpads; macOS per-user /var/folders), so honoring TMPDIR would hand
# every agent its own lock and exclude nothing. The work root below already
# commits to literal /tmp for the same reason. CERT_BUNDLE_LOCK_DIR exists so
# the offline regression can drive these functions against a private
# rendezvous; a real run that sets it opts out of host-wide serialization and
# says so on stderr rather than pretending to be serialized.
#
# holder.json is a NAMING record, not the lock. It is written after the lock
# is held and removed before it is dropped, and a contender that cannot read
# it says so and still refuses -- the flock already told it the truth. Do not
# "fix" the ordering so the record precedes the lock: the record is
# informational by design, and making it authoritative is what reintroduces
# every race above.
CERT_BUNDLE_LOCK_DIR="${CERT_BUNDLE_LOCK_DIR:-/tmp/duo-certbundle.lock}"
CERT_BUNDLE_LOCK_FILE="$CERT_BUNDLE_LOCK_DIR/lock"
CERT_BUNDLE_LOCK_HOLDER_FILE="$CERT_BUNDLE_LOCK_DIR/holder.json"
CERT_BUNDLE_LOCK_BACKEND="${CERT_BUNDLE_LOCK_BACKEND:-auto}"
CERT_BUNDLE_WAIT="${CERT_BUNDLE_WAIT:-0}"
CERT_BUNDLE_WAIT_TIMEOUT="${CERT_BUNDLE_WAIT_TIMEOUT:-5400}"   # 90 min, > one bundle
CERT_BUNDLE_WAIT_POLL="${CERT_BUNDLE_WAIT_POLL:-30}"
CERT_BUNDLE_LOCK_OWNED=0
CERT_BUNDLE_LOCK_HELPER_DIR=
CERT_BUNDLE_LOCK_HELPER_PID=
CERT_BUNDLE_LOCK_HELPER_BACKEND=
CERT_BUNDLE_ARGV=("$0" "$@")
LOCK_PID= LOCK_PAIR= LOCK_STARTED_AT= LOCK_STARTED_EPOCH= LOCK_CHECKOUT= LOCK_ARGV=
case "$CERT_BUNDLE_WAIT" in
  ''|0|1) ;;
  *) fail "CERT_BUNDLE_WAIT must be 0 or 1 (got '$CERT_BUNDLE_WAIT'); it is a switch, not a duration -- the budget is CERT_BUNDLE_WAIT_TIMEOUT" ;;
esac
case "$CERT_BUNDLE_WAIT_TIMEOUT" in
  ''|*[!0-9]*) fail "CERT_BUNDLE_WAIT_TIMEOUT must be whole seconds (got '$CERT_BUNDLE_WAIT_TIMEOUT')" ;;
esac
case "$CERT_BUNDLE_WAIT_POLL" in
  ''|*[!0-9]*) fail "CERT_BUNDLE_WAIT_POLL must be whole seconds (got '$CERT_BUNDLE_WAIT_POLL')" ;;
esac
# 10# so a zero-padded value is decimal, not a bash octal parse error.
CERT_BUNDLE_WAIT_TIMEOUT=$((10#$CERT_BUNDLE_WAIT_TIMEOUT))
CERT_BUNDLE_WAIT_POLL=$((10#$CERT_BUNDLE_WAIT_POLL))
[ "$CERT_BUNDLE_WAIT_POLL" -gt 0 ] || fail "CERT_BUNDLE_WAIT_POLL must be positive; a zero poll would spin instead of waiting"
case "$CERT_BUNDLE_LOCK_DIR" in
  /*/*) ;;
  *) fail "CERT_BUNDLE_LOCK_DIR must be an absolute path below a directory (got '$CERT_BUNDLE_LOCK_DIR')" ;;
esac
case "$CERT_BUNDLE_LOCK_BACKEND" in
  auto|flock|python) ;;
  *) fail "CERT_BUNDLE_LOCK_BACKEND must be auto, flock, or python (got '$CERT_BUNDLE_LOCK_BACKEND')" ;;
esac

certbundle_lock_backend() {
  # flock(1) where it exists, the documented python3 fcntl.flock helper
  # otherwise -- the same two implementations, in the same order, that
  # pair.sh's budget lock offers, so a host that can run a pair can run this.
  # Both are flock(2) on the same file and interoperate, so a host that has
  # both stays serialized even if two runs pick different backends.
  case "$CERT_BUNDLE_LOCK_BACKEND" in
    flock)  command -v flock   >/dev/null 2>&1 || fail "CERT_BUNDLE_LOCK_BACKEND=flock but flock(1) is not on PATH"; printf flock ;;
    python) command -v python3 >/dev/null 2>&1 || fail "CERT_BUNDLE_LOCK_BACKEND=python but python3 is not on PATH"; printf python ;;
    *)
      if command -v flock >/dev/null 2>&1; then printf flock
      elif command -v python3 >/dev/null 2>&1; then printf python
      else
        # pair.sh refuses on the same footing, and the bundle calls pair.sh,
        # so this costs a host nothing it had.
        fail "the host certification lock needs flock(1) or python3 (fcntl.flock); refusing to run a bundle without crash-safe mutual exclusion"
      fi
      ;;
  esac
}

certbundle_process_identity() { # certbundle_process_identity <pid>
  # A start-time token, empty when the pid cannot be read. It changes when a
  # PID is recycled, so a helper cannot mistake a new process for the run that
  # spawned it. pair.sh reads /proc/<pid>/stat field 22 for the same purpose;
  # ps -o lstart= is the portable equivalent and works where there is no /proc.
  # Trimmed with parameter expansion rather than `| tr | sed`: this runs
  # thousands of times over one bundle, and one fork per read instead of three
  # is three times less of exactly the fork pressure that makes it fail.
  local out
  out="$(ps -o lstart= -p "$1" 2>/dev/null)" || out=
  out="${out#"${out%%[![:space:]]*}"}"
  out="${out%"${out##*[![:space:]]}"}"
  printf '%s' "$out"
}

certbundle_acquirer_gone() { # certbundle_acquirer_gone <pid> <recorded-identity>
  # Whether the process that acquired the lock has stopped existing. Only
  # POSITIVE evidence counts, because the helper releases the lock on a true
  # answer and an unnecessary hold is recoverable while a wrong release is
  # not.
  #
  # An empty identity read is NOT that evidence. ps can fail transiently --
  # fork failure under load is the ordinary case on a host running several
  # bundles and pairs -- and an earlier revision treated empty exactly like
  # death: the helper dropped the descriptor and exited while its acquirer
  # ran happily on with its naming record intact, and the next invocation
  # acquired a lock somebody else was still holding. Review reproduced that
  # with a ps that returns empty once ("SECOND RUN ACQUIRED while the first
  # is alive"), and it would have been silent in production.
  local pid="$1" recorded="$2" ident
  ident="$(certbundle_process_identity "$pid")"
  # Explicit returns throughout: `set -e` is only suspended for a function
  # called in a condition, and this one must be safe to call anywhere.
  if [ -n "$ident" ] && [ -n "$recorded" ]; then
    # Both reads succeeded: a different token means the pid was recycled, so
    # our acquirer is gone; the same token means it is still there.
    if [ "$ident" != "$recorded" ]; then return 0; fi
    return 1
  fi
  # The read told us nothing. kill -0 is a syscall in the shell itself, with
  # no fork to fail, so it can corroborate: an unreadable identity while the
  # pid still answers is a failed probe BY CONSTRUCTION, and we keep holding.
  if kill -0 "$pid" 2>/dev/null; then return 1; fi
  return 0
}

certbundle_lock_read_holder() { # populates LOCK_*; nonzero when unreadable
  # Informational only. A nonzero return means "the holder could not be
  # named", never "there is no holder" -- that question was already answered
  # by the flock.
  local tsv
  LOCK_PID= LOCK_PAIR= LOCK_STARTED_AT= LOCK_STARTED_EPOCH= LOCK_CHECKOUT= LOCK_ARGV=
  tsv=$(jq -r '[((.pid // "")|tostring),(.pair//""),(.started_at//""),((.started_epoch//0)|tostring),(.checkout//""),((.argv//[])|join(" "))]|@tsv' \
    "$CERT_BUNDLE_LOCK_HOLDER_FILE" 2>/dev/null) || return 1
  IFS=$'\t' read -r LOCK_PID LOCK_PAIR LOCK_STARTED_AT LOCK_STARTED_EPOCH LOCK_CHECKOUT LOCK_ARGV <<<"$tsv"
  case "$LOCK_PID" in ''|*[!0-9]*) return 1 ;; esac
  return 0
}

certbundle_lock_read_holder_settled() {
  # The lock is taken a beat before the record is written, so a contender that
  # loses a tight race can arrive while the winner is still naming itself.
  # Re-read briefly before giving up: naming the holder is the entire reason
  # an operator does not reach for pkill. Unlike the mkdir-gated revision this
  # replaces, running out of tries here decides NOTHING -- the refusal happens
  # either way, it just says "cannot be named" -- so this wait can never wedge
  # a rendezvous or authorize a removal.
  local tries=0
  while ! certbundle_lock_read_holder; do
    tries=$((tries + 1))
    [ "$tries" -lt 40 ] || return 1   # ~2s, against a write that takes milliseconds
    sleep 0.05
  done
  return 0
}

certbundle_lock_age() { # certbundle_lock_age <started-epoch>
  local started="$1" secs
  # Reported, never acted on. Nothing in this section expires a holder by age:
  # a bundle legitimately runs for over an hour, and the kernel already
  # releases the lock the instant its holder stops existing.
  case "$started" in ''|0|*[!0-9]*) printf 'age unknown'; return 0 ;; esac
  secs=$(( $(date -u +%s) - started ))
  [ "$secs" -ge 0 ] || secs=0
  printf 'holding %dh%02dm' "$((secs / 3600))" "$(((secs % 3600) / 60))"
}

certbundle_lock_refuse() { # certbundle_lock_refuse <headline>
  printf '\n\033[1;31mFAIL: %s\033[0m\n' "$1" >&2
  printf '  lock           : %s\n' "$CERT_BUNDLE_LOCK_DIR" >&2
  if certbundle_lock_read_holder_settled; then
    printf '  holder pid     : %s\n' "$LOCK_PID" >&2
    printf '  holder pair    : %s\n' "${LOCK_PAIR:-unknown}" >&2
    printf '  holder started : %s (%s)\n' "${LOCK_STARTED_AT:-unknown}" "$(certbundle_lock_age "$LOCK_STARTED_EPOCH")" >&2
    printf '  holder checkout: %s\n' "${LOCK_CHECKOUT:-unknown}" >&2
    printf '  holder argv    : %s\n' "${LOCK_ARGV:-unknown}" >&2
  else
    printf '  holder         : cannot be named right now -- it is still starting up, or its\n' >&2
    printf '                   record was lost. The lock itself is held; that is what this\n' >&2
    printf '                   refusal rests on, and it is the kernel that says so.\n' >&2
  fi
  printf 'Two bundles cannot share a host: they fight over the pair, the ports, and each\n' >&2
  printf "other's evidence. Wait for the holder instead of displacing it:\n" >&2
  printf '  CERT_BUNDLE_WAIT=1 CERT_BUNDLE_PAIR=%s CERT_BUNDLE_PORT1=%s CERT_BUNDLE_PORT2=%s bash sandbox/tests/certify_reference_bundle.sh\n' \
    "$PAIR" "$PORT1" "$PORT2" >&2
  printf 'Do NOT clear the way with `pkill -f certify_reference_bundle` or any other\n' >&2
  printf 'pattern kill: the pattern matches every agent -- parent, wrapper, and watcher --\n' >&2
  printf 'orphaning the conformance child and destroying its work root (DUO-3382: three\n' >&2
  printf 'runs lost this way on 2026-08-09). Kill only a PID your own launcher recorded.\n' >&2
  printf 'There is nothing to clean up by hand: the lock is an flock(2) held by a live\n' >&2
  printf 'process, so the kernel drops it the moment that process stops existing.\n' >&2
  exit 1
}

certbundle_lock_helper_stop() {
  local dir="$CERT_BUNDLE_LOCK_HELPER_DIR" pid="$CERT_BUNDLE_LOCK_HELPER_PID" waited=0
  CERT_BUNDLE_LOCK_HELPER_DIR=
  CERT_BUNDLE_LOCK_HELPER_PID=
  [ -n "$dir" ] || return 0
  [ -d "$dir" ] && { : > "$dir/cancel" 2>/dev/null || true; }
  if [ -n "$pid" ]; then
    while kill -0 "$pid" 2>/dev/null; do
      waited=$((waited + 1))
      if [ "$waited" -gt 200 ]; then
        # By the PID this shell recorded when it forked the helper -- never by
        # pattern. See docs/agents/linear-loop.md's field note.
        kill -9 "$pid" 2>/dev/null || true
        break
      fi
      sleep 0.05
    done
  fi
  rm -rf -- "$dir" 2>/dev/null || true
  return 0
}

certbundle_lock_release() {
  [ "$CERT_BUNDLE_LOCK_OWNED" = 1 ] || return 0
  CERT_BUNDLE_LOCK_OWNED=0
  # The naming record must never outlive the lock it names, so it goes first.
  # No ownership check is needed or possible to get wrong: we hold the flock,
  # so this record is ours by construction.
  rm -f -- "$CERT_BUNDLE_LOCK_HOLDER_FILE" 2>/dev/null || true
  # The lock FILE is deliberately not removed, ever. Unlinking it while a
  # helper still holds the flock would let the next run create a fresh inode
  # and lock that instead -- two holders, two inodes, no mutual exclusion.
  certbundle_lock_helper_stop
  return 0
}

certbundle_lock_try() { # 0 = acquired, 1 = held by someone else
  local backend helper_dir ready busy cancel err parent_pid parent_ident outcome=
  backend="$(certbundle_lock_backend)"
  CERT_BUNDLE_LOCK_HELPER_BACKEND="$backend"
  mkdir -p -- "$CERT_BUNDLE_LOCK_DIR" \
    || fail "cannot create the host certification rendezvous $CERT_BUNDLE_LOCK_DIR"
  : >> "$CERT_BUNDLE_LOCK_FILE" \
    || fail "cannot create the host certification lock file $CERT_BUNDLE_LOCK_FILE"
  # The helper's control files are private to this run, so they live in this
  # process's own TMPDIR -- only the flock and the naming record belong in the
  # shared rendezvous.
  helper_dir="$(mktemp -d "${TMPDIR:-/tmp}/duo-certbundle-helper.XXXXXX")" \
    || fail "cannot create the certification lock helper directory"
  ready="$helper_dir/ready"; busy="$helper_dir/busy"
  cancel="$helper_dir/cancel"; err="$helper_dir/stderr"
  parent_pid="$$"
  # Recorded once, retried once: an empty token here costs the helper its
  # PID-reuse defence for the whole run (certbundle_acquirer_gone then falls
  # back to kill -0 alone, which holds too long rather than releasing early).
  parent_ident="$(certbundle_process_identity "$parent_pid")"
  [ -n "$parent_ident" ] || parent_ident="$(certbundle_process_identity "$parent_pid")"

  if [ "$backend" = flock ]; then
    (
      # Traps are not inherited into this subshell, and it must never run the
      # acquiring shell's release: state it rather than rely on it.
      trap - EXIT
      if flock -n 9; then
        : > "$ready"
        # Two exits, deliberately at different rates. The cancel marker is a
        # file test, so an ordinary release is noticed within one 0.05s tick.
        # Detecting a KILLED acquirer costs a ps(1), so it runs every tenth
        # tick: a bundle holds this lock for the better part of an hour, and
        # half a second of extra hold after a kill is not worth 20 process
        # spawns a second for the whole run. A control FIFO would signal both
        # instantly, but its descriptor would be inherited by the docker and
        # conformance descendants exactly as the lock's would, which is the
        # inheritance this helper exists to avoid.
        checks=0
        while [ ! -e "$cancel" ]; do
          checks=$((checks + 1))
          if [ "$((checks % 10))" -eq 0 ] \
              && certbundle_acquirer_gone "$parent_pid" "$parent_ident"; then
            rm -rf -- "$helper_dir" 2>/dev/null || true
            exit 0
          fi
          sleep 0.05
        done
      else
        : > "$busy"
      fi
    ) 9>"$CERT_BUNDLE_LOCK_FILE" 2>"$err" &
  else
    python3 -c '
import fcntl, os, shutil, sys, time
lock_path, ready_path, busy_path, cancel_path, helper_dir, parent_pid = sys.argv[1:]
parent_pid = int(parent_pid)
lock = open(lock_path, "a+")
try:
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
except BlockingIOError:
    open(busy_path, "w").close()
    raise SystemExit(0)
open(ready_path, "w").close()
# os.getppid() stops matching the moment the acquiring shell dies, and a
# recycled PID cannot resurrect the match, so this needs no /proc and no
# start-time comparison.
# getppid() is a syscall, not a process spawn, so unlike the flock(1) helper
# above this can afford to check for a killed acquirer every tick.
while not os.path.exists(cancel_path):
    if os.getppid() != parent_pid:
        shutil.rmtree(helper_dir, ignore_errors=True)
        raise SystemExit(0)
    time.sleep(0.05)
' "$CERT_BUNDLE_LOCK_FILE" "$ready" "$busy" "$cancel" "$helper_dir" "$parent_pid" 2>"$err" &
  fi
  CERT_BUNDLE_LOCK_HELPER_PID="$!"
  CERT_BUNDLE_LOCK_HELPER_DIR="$helper_dir"

  while :; do
    if [ -e "$ready" ]; then outcome=acquired; break; fi
    if [ -e "$busy" ]; then outcome=busy; break; fi
    if ! kill -0 "$CERT_BUNDLE_LOCK_HELPER_PID" 2>/dev/null; then
      # The helper writes its marker and then exits, so it can do both between
      # the two tests above and this one. Re-read before calling a normal
      # "the lock is taken" outcome a crashed helper: that misreport would
      # turn an ordinary refusal into a hard failure naming the wrong cause.
      if [ -e "$ready" ]; then outcome=acquired; break; fi
      if [ -e "$busy" ]; then outcome=busy; break; fi
      outcome=died
      break
    fi
    sleep 0.02
  done

  case "$outcome" in
    acquired) return 0 ;;
    busy)
      certbundle_lock_helper_stop
      return 1
      ;;
    *)
      local detail
      detail="$(cat "$err" 2>/dev/null || true)"
      certbundle_lock_helper_stop
      fail "the $backend certification lock helper exited without acquiring the lock or reporting it busy${detail:+: $detail}"
      ;;
  esac
}

certbundle_lock_acquire() {
  local waited=0
  if [ "$CERT_BUNDLE_LOCK_DIR" != /tmp/duo-certbundle.lock ]; then
    printf 'NOTE: host certification lock redirected to %s; this run is NOT serialized\n' "$CERT_BUNDLE_LOCK_DIR" >&2
    printf 'against bundles using the default rendezvous /tmp/duo-certbundle.lock\n' >&2
  fi
  while :; do
    if certbundle_lock_try; then
      # Owned and armed BEFORE the naming record is written: a signal during
      # the write must still drop the lock.
      CERT_BUNDLE_LOCK_OWNED=1
      trap certbundle_lock_release EXIT
      # Clear any record a crashed predecessor left behind before writing
      # ours, so a contender arriving in this window reads nothing and says
      # "cannot be named" rather than confidently naming a dead run. The
      # half-written `holder.json.<pid>` of a run killed mid-write goes too:
      # it is inert, but one per killed run would accumulate in a shared /tmp
      # rendezvous forever. Sweeping here is unambiguous precisely because we
      # hold the lock -- nobody else can be writing into this directory.
      rm -f -- "$CERT_BUNDLE_LOCK_HOLDER_FILE" "$CERT_BUNDLE_LOCK_HOLDER_FILE".* 2>/dev/null || true
      jq -n --argjson pid "$$" --arg pair "$PAIR" \
        --arg started_at "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" \
        --argjson started_epoch "$(date -u +%s)" \
        --arg checkout "$REPO_ROOT" --arg backend "$CERT_BUNDLE_LOCK_HELPER_BACKEND" \
        --argjson argv "$(printf '%s\n' "${CERT_BUNDLE_ARGV[@]}" | jq -R . | jq -s .)" \
        '{schema_version:2,pid:$pid,pair:$pair,started_at:$started_at,started_epoch:$started_epoch,checkout:$checkout,backend:$backend,argv:$argv}' \
        > "$CERT_BUNDLE_LOCK_HOLDER_FILE.$$" \
        && mv -f "$CERT_BUNDLE_LOCK_HOLDER_FILE.$$" "$CERT_BUNDLE_LOCK_HOLDER_FILE"
      pass "host certification lock acquired: $CERT_BUNDLE_LOCK_DIR (pid $$, pair $PAIR, $CERT_BUNDLE_LOCK_HELPER_BACKEND)"
      return 0
    fi
    if [ "$CERT_BUNDLE_WAIT" = 1 ]; then
      if [ "$waited" -ge "$CERT_BUNDLE_WAIT_TIMEOUT" ]; then
        certbundle_lock_refuse "waited ${waited}s for the host certification lock (CERT_BUNDLE_WAIT_TIMEOUT=${CERT_BUNDLE_WAIT_TIMEOUT}s) and it is still held"
      fi
      certbundle_lock_read_holder_settled || true
      printf 'waiting for the host certification lock: holder pid %s pair %s (%s); %ss elapsed of %ss budget\n' \
        "${LOCK_PID:-unnamed}" "${LOCK_PAIR:-unknown}" "$(certbundle_lock_age "$LOCK_STARTED_EPOCH")" \
        "$waited" "$CERT_BUNDLE_WAIT_TIMEOUT"
      sleep "$CERT_BUNDLE_WAIT_POLL"
      waited=$((waited + CERT_BUNDLE_WAIT_POLL))
      continue
    fi
    certbundle_lock_refuse "refusing to start a second certification bundle on this host"
  done
}

# The offline lock regression (sandbox/tests/regress_certbundle_lock.sh)
# sources this file to drive the functions above against its own rendezvous --
# it runs the shipped bytes, not a transcription of them. Nothing below this
# point may run in that mode.
if [ -n "${CERT_BUNDLE_LOCK_LIB_ONLY:-}" ]; then
  [ "${BASH_SOURCE[0]}" != "$0" ] \
    || fail "CERT_BUNDLE_LOCK_LIB_ONLY is a source-only hook for the offline lock regression; it cannot run a certification"
  return 0
fi

certbundle_lock_acquire

# pair.sh intentionally resolves agent/manifests bind mounts through Git's
# common directory so a long-lived pair never depends on an ephemeral linked
# worktree.  Certification has the opposite requirement: every exercised byte
# must come from the exact clean HEAD whose hashes enter the evidence bundle.
# Refuse before allocating a work root or starting Docker when those roots
# would differ, or when uncommitted/stale mount bytes would make the run
# irreproducible.
assert_exact_certification_checkout() {
  local checkout_root git_dir common_dir common_root env_file
  local expected_agent expected_manifests mounted_agent mounted_manifests
  checkout_root="$(cd "$REPO_ROOT" && pwd -P)"
  git_dir="$(git -C "$REPO_ROOT" rev-parse --path-format=absolute --git-dir 2>/dev/null)" \
    || fail "refusing certification: cannot resolve git-dir for checkout $checkout_root"
  common_dir="$(git -C "$REPO_ROOT" rev-parse --path-format=absolute --git-common-dir 2>/dev/null)" \
    || fail "refusing certification: cannot resolve git-common-dir for checkout $checkout_root"
  common_root="$(dirname "$common_dir")"
  if [ ! -d "$git_dir" ] || [ "$git_dir" != "$common_dir" ] \
      || [ "$common_root" != "$checkout_root" ] || [ -f "$REPO_ROOT/.git" ]; then
    fail "refusing certification from a linked worktree or stale canonical mount; use a clean primary or standalone exact-HEAD clone"
  fi
  if [ -n "$(git -C "$REPO_ROOT" status --porcelain=v1 --untracked-files=all)" ]; then
    fail "refusing certification from a dirty checkout; use a clean primary or standalone exact-HEAD clone"
  fi
  expected_agent="$checkout_root/agent"
  expected_manifests="$checkout_root/manifests"
  env_file="$checkout_root/sandbox/.env"
  if [ -e "$env_file" ]; then
    mounted_agent="$(sed -n 's/^DUO_AGENT_SRC=//p' "$env_file" | head -1)"
    mounted_manifests="$(sed -n 's/^DUO_MANIFESTS_SRC=//p' "$env_file" | head -1)"
    if [ "$mounted_agent" != "$expected_agent" ] || [ "$mounted_manifests" != "$expected_manifests" ]; then
      fail "refusing certification with stale canonical mount registry $env_file; remove it or refresh pair.sh from the clean checkout"
    fi
  fi
  [ -d "$expected_agent" ] || fail "refusing certification: canonical agent mount source is absent: $expected_agent"
  [ -d "$expected_manifests" ] || fail "refusing certification: canonical manifest mount source is absent: $expected_manifests"
}
assert_exact_certification_checkout

# Freeze the commit identity before the first child process or Docker/pair
# mutation. A clean checkout is only a point-in-time fact; without this SHA
# handoff, a stale/moved source mount could be exercised and the bundle could
# later label those results with a different HEAD. Respect any launcher-owned
# expectation, then give every existing gate the same exact commit.
SOURCE_SHA=$(git -C "$REPO_ROOT" rev-parse --verify 'HEAD^{commit}') \
  || fail "refusing certification: cannot resolve the exact source commit"
assert_expected_source_sha() { # assert_expected_source_sha <name> <value>
  local name="$1" value="$2"
  [ -z "$value" ] && return 0
  [[ "$value" =~ ^[0-9a-f]{40}$ ]] \
    || fail "refusing certification: $name must be one full lowercase Git commit SHA"
  [ "$value" = "$SOURCE_SHA" ] \
    || fail "refusing certification: $name names $value but the clean checkout is $SOURCE_SHA"
}
assert_expected_source_sha CERT_BUNDLE_EXPECTED_SOURCE_SHA "${CERT_BUNDLE_EXPECTED_SOURCE_SHA:-}"
assert_expected_source_sha DUO_EXPECTED_SOURCE_SHA "${DUO_EXPECTED_SOURCE_SHA:-}"
assert_expected_source_sha CONF_EXPECTED_SOURCE_SHA "${CONF_EXPECTED_SOURCE_SHA:-}"
export DUO_EXPECTED_SOURCE_SHA="$SOURCE_SHA"
export CONF_EXPECTED_SOURCE_SHA="$SOURCE_SHA"

assert_exact_source_unchanged() {
  local current
  current=$(git -C "$REPO_ROOT" rev-parse --verify 'HEAD^{commit}' 2>/dev/null) \
    || fail "refusing certification: source HEAD became unreadable during the run"
  [ "$current" = "$SOURCE_SHA" ] \
    || fail "refusing certification: source HEAD moved from $SOURCE_SHA to $current during the run"
  [ -z "$(git -C "$REPO_ROOT" status --porcelain=v1 --untracked-files=all)" ] \
    || fail "refusing certification: source checkout changed after the exact-SHA preflight"
}

WORK_ROOT=$(mktemp -d /tmp/duo-certbundle.XXXXXX)
cleanup_run() {
  case "$WORK_ROOT" in
    # DUO-3382: the removal is allowed to fail without taking the rest of the
    # trap down with it. Under `set -e` a bare `rm -rf` that hit a busy or
    # read-only path would abort the trap -- skipping the release below, and
    # exiting a GREEN bundle 1 over a cleanup failure that changed nothing.
    /tmp/duo-certbundle.*)
      rm -rf -- "$WORK_ROOT" \
        || printf 'WARNING: could not remove the work root %s; remove it by hand\n' "$WORK_ROOT" >&2
      ;;
    *) printf 'refusing unsafe work cleanup path: %s\n' "$WORK_ROOT" >&2 ;;
  esac
  # The lock is released LAST, and from the same trap that clears the work
  # root -- the next invocation must never win the lock while this run's work
  # root is still on disk.
  certbundle_lock_release
}
trap cleanup_run EXIT   # replaces the release-only trap armed by certbundle_lock_acquire

ENV_FILE="$WORK_ROOT/environment.json"
MULTISITE_LOG="$WORK_ROOT/multisite-refusal.log"
MATRIX_LOG="$WORK_ROOT/exact-artifact-version-matrix.log"
INIT_CONTRACT_LOG="$WORK_ROOT/init-contract.log"
INIT_GOLDEN_LOG="$WORK_ROOT/duo-init-golden-path.log"
CONFORMANCE_MANIFESTS=(
  core fse acf contact-form-7 elementor ninja-forms
  polylang woocommerce yoast paid-memberships-pro
)
TEST_FRAGMENTS=()

write_result() { # write_result <id> <rc> <reason> <assertions-json> <path>
  local id="$1" rc="$2" reason="$3" assertions="$4" path="$5" verdict=fail
  [ "$rc" -eq 0 ] && verdict=pass
  jq -n \
    --arg test "$id" --arg verdict "$verdict" --arg reason "$reason" \
    --argjson exit_code "$rc" --argjson assertions "$assertions" \
    '{schema_version:1,test:$test,verdict:$verdict,exit_code:$exit_code,reason:$reason,assertions:$assertions}' > "$path"
}

write_scoped_result() { # write_scoped_result <id> <rc> <reason> <assertions-json> <scope> <exclusions-json> <path>
  local id="$1" rc="$2" reason="$3" assertions="$4" scope="$5" exclusions="$6" path="$7" tmp
  write_result "$id" "$rc" "$reason" "$assertions" "$path"
  tmp="$path.tmp"
  jq --arg scope "$scope" --argjson exclusions "$exclusions" \
    '. + {scope:$scope,exclusions:$exclusions}' "$path" > "$tmp"
  mv "$tmp" "$path"
}

write_fragment() { # write_fragment <id> <manifest> <result> <diff> <fragment>
  jq -n --arg id "$1" --arg manifest "$2" --arg result "$3" --arg diff "$4" \
    '{id:$id,manifest:$manifest,result:$result,diff:$diff}' > "$5"
}

write_skipped() { # write_skipped <id> <manifest> <log> <result> <diff> <fragment>
  local id="$1" manifest="$2" log="$3" result="$4" diff="$5" fragment="$6"
  printf 'SKIPPED: blocked by an earlier failed reference-certification leg\n' > "$log"
  write_result "$id" 99 blocked_by_prior_failure '[]' "$result"
  jq -n --arg manifest "$manifest" \
    '{status:"unknown",manifest:$manifest,reason:"blocked_by_prior_failure"}' > "$diff"
  write_fragment "$id" "$manifest" "$result" "$diff" "$fragment"
}

append_fragment() { # append_fragment <fragment> <log>
  local fragment="$1" log="$2"
  TEST_FRAGMENTS+=("$(jq -c --arg log "$log" '. + {log:$log} | del(.manifest)' "$fragment")")
}

destroy_own_pair() {
  local rc
  set +e
  bash bin/pair.sh destroy "$PAIR"
  rc=$?
  set -e
  return "$rc"
}

say "source/static preflight"
php -l bin/certification-bundle.php >/dev/null
php -l tests/regress_init_contract.php >/dev/null
bash -n conformance/run.sh tests/regress_multisite_refusal.sh tests/certify_version_matrix.sh tests/regress_duo_init.sh
bash bin/pair.sh list
pass "bundle builder and all invoked harnesses parse; pair load inspected"

overall=0
leg=0
total_legs=$((${#CONFORMANCE_MANIFESTS[@]} + 4))
for manifest in "${CONFORMANCE_MANIFESTS[@]}"; do
  leg=$((leg + 1))
  id="conformance-$manifest"
  log="$WORK_ROOT/$id.log"
  result="$WORK_ROOT/$id.result.json"
  diff="$WORK_ROOT/$id.diff.json"
  fragment="$WORK_ROOT/$id.fragment.json"

  if [ "$overall" -eq 0 ]; then
    say "reference leg $leg/$total_legs: $manifest conformance through the real deploy/apply path"
    set +e
    CONF_PAIR="$PAIR" CONF1_PORT="$PORT1" CONF2_PORT="$PORT2" \
      CONFORMANCE_EVIDENCE_DIR="$WORK_ROOT" \
      bash conformance/run.sh "$manifest" > "$log" 2>&1
    rc=$?
    set -e

    reason=passed
    if [ "$rc" -eq 0 ] && ! grep -qF "✔ CONFORMANCE PASSED ($manifest)" "$log"; then
      rc=70
      reason=invalid_checker_output
    fi
    if [ "$rc" -eq 0 ] && ! jq -e \
      --arg id "$id" --arg manifest "$manifest" --arg result "$result" --arg diff "$diff" \
      '.id == $id and .manifest == $manifest and .result == $result and .diff == $diff' \
      "$fragment" >/dev/null 2>&1; then
      rc=70
      reason=invalid_checker_output
    fi
    if [ "$rc" -eq 0 ] && ! jq -e --arg id "$id" \
      '.test == $id and .verdict == "pass" and .exit_code == 0' "$result" >/dev/null 2>&1; then
      rc=70
      reason=invalid_checker_output
    fi
    if [ "$rc" -eq 0 ] && ! jq -e --arg manifest "$manifest" \
      '.status == "clean" and .manifest == $manifest' "$diff" >/dev/null 2>&1; then
      rc=70
      reason=invalid_checker_output
    fi

    # Capture the exact environment while core's successful target still
    # exists. All other conformance pairs can be destroyed immediately.
    if [ "$manifest" = core ] && [ "$rc" -eq 0 ]; then
      export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2"
      COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml)
      set +e
      ENV_OUT=$("${COMPOSE[@]}" run --rm -T cli2 wp eval '
global $wpdb;
echo wp_json_encode([
  "wordpress" => get_bloginfo("version"),
  "php" => PHP_VERSION,
  "database_client" => $wpdb->db_version(),
  "database_server" => $wpdb->get_var("SELECT VERSION()"),
  "multisite" => is_multisite(),
  "active_plugins" => array_values((array) get_option("active_plugins", [])),
  "theme" => ["template" => get_option("template"), "stylesheet" => get_option("stylesheet")],
]);
' 2>"$WORK_ROOT/environment.stderr")
      env_rc=$?
      set -e
      if [ "$env_rc" -eq 0 ] && jq -e 'type == "object"' <<<"$ENV_OUT" >/dev/null 2>&1; then
        printf '%s\n' "$ENV_OUT" | jq . > "$ENV_FILE"
      else
        rc=71
        reason=environment_collection_failed
      fi
    fi

    if [ "$rc" -ne 0 ]; then
      overall=1
      [ "$reason" = passed ] && reason=command_failed
      write_result "$id" "$rc" "$reason" '[]' "$result"
      jq -n --arg manifest "$manifest" '{status:"unknown",manifest:$manifest}' > "$diff"
      write_fragment "$id" "$manifest" "$result" "$diff" "$fragment"
      if [ "$manifest" = core ] && [ ! -f "$ENV_FILE" ]; then
        jq -n --arg host_php "$(php -r 'echo PHP_VERSION;')" \
          '{collection:"failed",host_php:$host_php}' > "$ENV_FILE"
      fi
    fi

    tail -30 "$log"
    if ! destroy_own_pair; then
      overall=1
      printf 'FAIL: own pair %s could not be destroyed after %s\n' "$PAIR" "$manifest" >&2
    elif [ "$rc" -eq 0 ]; then
      pass "$manifest conformance passed; its named fragment was imported and pair destroyed"
    fi
  else
    write_skipped "$id" "$manifest" "$log" "$result" "$diff" "$fragment"
  fi
  append_fragment "$fragment" "$log"
done

[ -f "$ENV_FILE" ] || jq -n --arg host_php "$(php -r 'echo PHP_VERSION;')" \
  '{collection:"failed",host_php:$host_php}' > "$ENV_FILE"

leg=$((leg + 1))
if [ "$overall" -eq 0 ]; then
  say "reference leg $leg/$total_legs: real WordPress multisite must refuse with zero mutation"
  set +e
  MULTISITE_PAIR="$PAIR" MULTISITE_PORT1="$PORT1" MULTISITE_PORT2="$PORT2" \
    bash tests/regress_multisite_refusal.sh > "$MULTISITE_LOG" 2>&1
  multisite_rc=$?
  set -e
  tail -30 "$MULTISITE_LOG"
  multisite_reason=passed
  if [ "$multisite_rc" -eq 0 ] && ! grep -qF '✔ REGRESS_MULTISITE_REFUSAL PASSED' "$MULTISITE_LOG"; then
    multisite_rc=70
    multisite_reason=invalid_checker_output
  fi
  if [ "$multisite_rc" -ne 0 ]; then
    overall=1
    [ "$multisite_reason" = passed ] && multisite_reason=command_failed
  fi
  write_result multisite-refusal "$multisite_rc" "$multisite_reason" \
    '["wordpress_runtime_reports_multisite","capture_nonzero","actionable_single_site_boundary","no_repository_publication","authored_canary_unchanged"]' \
    "$WORK_ROOT/multisite-refusal.result.json"
  jq -n --arg status "$([ "$multisite_rc" -eq 0 ] && printf no_mutation || printf unknown)" \
    '{status:$status,checked:["site.duo.json","state","capture-staging","capture-backup","wordpress-option-canary"]}' \
    > "$WORK_ROOT/multisite-refusal.diff.json"
  destroy_own_pair || overall=1
else
  write_skipped multisite-refusal multisite "$MULTISITE_LOG" \
    "$WORK_ROOT/multisite-refusal.result.json" "$WORK_ROOT/multisite-refusal.diff.json" \
    "$WORK_ROOT/multisite-refusal.fragment.json"
fi
write_fragment multisite-refusal multisite "$WORK_ROOT/multisite-refusal.result.json" \
  "$WORK_ROOT/multisite-refusal.diff.json" "$WORK_ROOT/multisite-refusal.fragment.json"
append_fragment "$WORK_ROOT/multisite-refusal.fragment.json" "$MULTISITE_LOG"

leg=$((leg + 1))
if [ "$overall" -eq 0 ]; then
  say "reference leg $leg/$total_legs: exact-artifact version matrix (including typed tables and refusal fixtures)"
  bash bin/pair.sh list
  set +e
  VMATRIX_PAIR="$PAIR" VMATRIX_PORT1="$PORT1" VMATRIX_PORT2="$PORT2" \
    bash tests/certify_version_matrix.sh > "$MATRIX_LOG" 2>&1
  matrix_rc=$?
  set -e
  tail -40 "$MATRIX_LOG"
  matrix_reason=passed
  if [ "$matrix_rc" -eq 0 ] && ! grep -qF '✔ CERTIFY_VERSION_MATRIX PASSED' "$MATRIX_LOG"; then
    matrix_rc=70
    matrix_reason=invalid_checker_output
  fi
  if [ "$matrix_rc" -ne 0 ]; then
    overall=1
    [ "$matrix_reason" = passed ] && matrix_reason=command_failed
  fi
  write_result exact-artifact-version-matrix "$matrix_rc" "$matrix_reason" \
    '["digest_verified_artifacts","declared_min_boundaries","max_practical_boundaries","typed_table_ninja_forms","byte_identical_recapture","below_range_loud_refusal"]' \
    "$WORK_ROOT/exact-artifact-version-matrix.result.json"
  jq -n --arg status "$([ "$matrix_rc" -eq 0 ] && printf clean || printf unknown)" \
    '{status:$status,diffs:["all-in-range-boundary-recaptures"],negative_controls:"all-below-range-releases-refused"}' \
    > "$WORK_ROOT/exact-artifact-version-matrix.diff.json"
  destroy_own_pair || overall=1
else
  write_skipped exact-artifact-version-matrix version-matrix "$MATRIX_LOG" \
    "$WORK_ROOT/exact-artifact-version-matrix.result.json" "$WORK_ROOT/exact-artifact-version-matrix.diff.json" \
    "$WORK_ROOT/exact-artifact-version-matrix.fragment.json"
fi
write_fragment exact-artifact-version-matrix version-matrix \
  "$WORK_ROOT/exact-artifact-version-matrix.result.json" "$WORK_ROOT/exact-artifact-version-matrix.diff.json" \
  "$WORK_ROOT/exact-artifact-version-matrix.fragment.json"
append_fragment "$WORK_ROOT/exact-artifact-version-matrix.fragment.json" "$MATRIX_LOG"

leg=$((leg + 1))
init_contract_assertions='["authenticated_target_proposal","digest_bound_confirmation","separate_code_and_state_declarations","redacted_risk_rendering","fail_closed_transport","generic_authored_only_scope","initial_baseline_lifecycle"]'
init_contract_exclusions='["live_wordpress_runtime","plugin_semantic_conformance","agent_installation_or_adoption"]'
if [ "$overall" -eq 0 ]; then
  say "reference leg $leg/$total_legs: existing-site init platform contract"
  set +e
  php tests/regress_init_contract.php > "$INIT_CONTRACT_LOG" 2>&1
  init_contract_rc=$?
  set -e
  tail -30 "$INIT_CONTRACT_LOG"
  init_contract_reason=passed
  if [ "$init_contract_rc" -eq 0 ] && ! grep -qF 'REGRESS_INIT_CONTRACT PASSED' "$INIT_CONTRACT_LOG"; then
    init_contract_rc=70
    init_contract_reason=invalid_checker_output
  fi
  if [ "$init_contract_rc" -ne 0 ]; then
    overall=1
    [ "$init_contract_reason" = passed ] && init_contract_reason=command_failed
    init_contract_result_assertions='[]'
    init_contract_status=unknown
  else
    init_contract_result_assertions="$init_contract_assertions"
    init_contract_status=clean
  fi
  write_scoped_result init-contract "$init_contract_rc" "$init_contract_reason" \
    "$init_contract_result_assertions" platform-init-contract "$init_contract_exclusions" \
    "$WORK_ROOT/init-contract.result.json"
  jq -n --arg status "$init_contract_status" --arg scope platform-init-contract \
    --argjson assertions "$init_contract_result_assertions" --argjson exclusions "$init_contract_exclusions" \
    '{status:$status,scope:$scope,assertions:$assertions,exclusions:$exclusions}' \
    > "$WORK_ROOT/init-contract.diff.json"
else
  printf 'SKIPPED: blocked by an earlier failed reference-certification leg\n' > "$INIT_CONTRACT_LOG"
  write_scoped_result init-contract 99 blocked_by_prior_failure '[]' \
    platform-init-contract "$init_contract_exclusions" "$WORK_ROOT/init-contract.result.json"
  jq -n --arg scope platform-init-contract --argjson exclusions "$init_contract_exclusions" \
    '{status:"unknown",scope:$scope,reason:"blocked_by_prior_failure",assertions:[],exclusions:$exclusions}' \
    > "$WORK_ROOT/init-contract.diff.json"
fi
write_fragment init-contract platform-init-contract \
  "$WORK_ROOT/init-contract.result.json" "$WORK_ROOT/init-contract.diff.json" \
  "$WORK_ROOT/init-contract.fragment.json"
append_fragment "$WORK_ROOT/init-contract.fragment.json" "$INIT_CONTRACT_LOG"

leg=$((leg + 1))
init_golden_assertions='["no_write_blockers_and_cancel","public_digest_confirmation","separate_code_and_state_baselines","selected_authored_product_and_taxonomy_scope","runtime_order_exclusion","clean_public_status","completed_within_fifteen_minutes"]'
init_golden_exclusions='["woocommerce_semantic_conformance","full_site_coverage","code_and_database_rollback","agent_installation_or_adoption"]'
if [ "$overall" -eq 0 ]; then
  say "reference leg $leg/$total_legs: public existing-site duo init golden path"
  set +e
  DUO_INIT_PAIR="$PAIR" DUO_INIT_PORT1="$PORT1" DUO_INIT_PORT2="$PORT2" \
    bash tests/regress_duo_init.sh > "$INIT_GOLDEN_LOG" 2>&1
  init_golden_rc=$?
  set -e
  tail -40 "$INIT_GOLDEN_LOG"
  init_golden_reason=passed
  if [ "$init_golden_rc" -eq 0 ] && ! grep -qF '✔ REGRESS_DUO_INIT PASSED' "$INIT_GOLDEN_LOG"; then
    init_golden_rc=70
    init_golden_reason=invalid_checker_output
  fi
  if ! destroy_own_pair; then
    init_golden_rc=72
    init_golden_reason=cleanup_failed
  fi
  if [ "$init_golden_rc" -ne 0 ]; then
    overall=1
    [ "$init_golden_reason" = passed ] && init_golden_reason=command_failed
    init_golden_result_assertions='[]'
    init_golden_status=unknown
  else
    init_golden_result_assertions="$init_golden_assertions"
    init_golden_status=clean
  fi
  write_scoped_result duo-init-golden-path "$init_golden_rc" "$init_golden_reason" \
    "$init_golden_result_assertions" existing-site-init-workflow "$init_golden_exclusions" \
    "$WORK_ROOT/duo-init-golden-path.result.json"
  jq -n --arg status "$init_golden_status" --arg scope existing-site-init-workflow \
    --argjson assertions "$init_golden_result_assertions" --argjson exclusions "$init_golden_exclusions" \
    '{status:$status,scope:$scope,assertions:$assertions,exclusions:$exclusions,
      fixture:{plugin:"woocommerce",version:"11.0.0",version_checked_only:true,
        semantic_conformance:"not_certified_by_this_test"}}' \
    > "$WORK_ROOT/duo-init-golden-path.diff.json"
else
  printf 'SKIPPED: blocked by an earlier failed reference-certification leg\n' > "$INIT_GOLDEN_LOG"
  write_scoped_result duo-init-golden-path 99 blocked_by_prior_failure '[]' \
    existing-site-init-workflow "$init_golden_exclusions" \
    "$WORK_ROOT/duo-init-golden-path.result.json"
  jq -n --arg scope existing-site-init-workflow --argjson exclusions "$init_golden_exclusions" \
    '{status:"unknown",scope:$scope,reason:"blocked_by_prior_failure",assertions:[],exclusions:$exclusions,
      fixture:{plugin:"woocommerce",version:"11.0.0",version_checked_only:true,
        semantic_conformance:"not_certified_by_this_test"}}' \
    > "$WORK_ROOT/duo-init-golden-path.diff.json"
fi
write_fragment duo-init-golden-path existing-site-init-workflow \
  "$WORK_ROOT/duo-init-golden-path.result.json" "$WORK_ROOT/duo-init-golden-path.diff.json" \
  "$WORK_ROOT/duo-init-golden-path.fragment.json"
append_fragment "$WORK_ROOT/duo-init-golden-path.fragment.json" "$INIT_GOLDEN_LOG"

assert_exact_source_unchanged
say "materialize the content-addressed machine-readable bundle"
BOUND_INPUTS=$({ git -C "$REPO_ROOT" ls-files \
  agent cli manifests sandbox/bin sandbox/conformance \
  sandbox/tests/certify_reference_bundle.sh \
  sandbox/tests/certify_version_matrix.sh \
  sandbox/tests/regress_multisite_refusal.sh \
  sandbox/tests/regress_init_contract.php \
  sandbox/tests/regress_duo_init.sh \
  sandbox/pair.yml sandbox/db.yml sandbox/init-cli.Dockerfile \
  scripts/capability-registry.php docs/compatibility-baseline.json \
  DESIGN.md spec/repo-format.md Makefile .github/workflows/conformance.yml; \
  printf '%s\n' manifests/dispositions.json; } \
  | grep -v '^manifests/capabilities/' | sort -u | jq -R . | jq -s .)
ARTIFACTS=$(jq '[to_entries[] as $slug | $slug.value | to_entries[] | {name:$slug.key,version:.key,url:.value.url,sha256:.value.sha256,role:.value.role}]' conformance/artifacts.lock.json)
TESTS=$(printf '%s\n' "${TEST_FRAGMENTS[@]}" | jq -s .)
CREATED_AT=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
GIT_REVISION="$SOURCE_SHA"
jq -n \
  --arg repo_root "$REPO_ROOT" --arg created_at "$CREATED_AT" --arg git_revision "$GIT_REVISION" \
  --arg environment "$ENV_FILE" --argjson bound_inputs "$BOUND_INPUTS" --argjson artifacts "$ARTIFACTS" \
  --arg ratification "$REPO_ROOT/manifests/dispositions.json" --argjson tests "$TESTS" \
  '{
    repo_root:$repo_root,created_at:$created_at,git_revision:$git_revision,
    harness:{name:"duo-reference-certification",version:4},force_hatches:[],
    environment:$environment,ratification:$ratification,bound_inputs:$bound_inputs,artifacts:$artifacts,
    tests:$tests
  }' > "$WORK_ROOT/spec.json"

set +e
BUILD_OUT=$(php bin/certification-bundle.php build "$WORK_ROOT/spec.json" "$OUT_ROOT")
build_rc=$?
set -e
printf '%s\n' "$BUILD_OUT" | jq .
BUNDLE=$(jq -r '.bundle // empty' <<<"$BUILD_OUT")
[ -n "$BUNDLE" ] || fail "bundle builder produced no bundle path"

set +e
VERIFY_OUT=$(php bin/certification-bundle.php verify "$BUNDLE" "$REPO_ROOT")
verify_rc=$?
set -e
printf '%s\n' "$VERIFY_OUT" | jq .

assert_exact_source_unchanged

if [ "$overall" -ne 0 ] || [ "$build_rc" -ne 0 ] || [ "$verify_rc" -ne 0 ]; then
  fail "reference certification failed; immutable evidence remains at $BUNDLE"
fi

LIVE_CONTAINERS=$(docker ps --format '{{.Names}}')
if grep -qE "^duo-${PAIR}-" <<<"$LIVE_CONTAINERS"; then
  fail "own pair '$PAIR' is still running after a green reference certification"
fi

pass "reference bundle is valid, content-addressed, input-bound, and immediately re-verified"
printf '\n\033[1;32m✔ CERTIFY_REFERENCE_BUNDLE PASSED (%s)\033[0m\n' "$(basename "$BUNDLE")"
