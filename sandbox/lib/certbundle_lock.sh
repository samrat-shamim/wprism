#!/usr/bin/env bash
# Host-wide reference-certification lock protocol.
#
# Source-only library. The caller owns strict-shell mode and provides fail(),
# pass(), PAIR, PORT1, PORT2, and REPO_ROOT. Keeping the descriptor helper,
# naming record, wait/refusal diagnostics, and release path together makes the
# certification runner orchestration-only without creating a generic framework.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  printf 'FAIL: certbundle_lock.sh is a source-only library; source it from a certification runner or its offline regression\n' >&2
  exit 1
fi

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
# descriptor, exactly as sandbox/lib/pair_budget_lock.sh acquires the shared
# pair budget. That choice is what keeps this boundary self-contained. The
# kernel releases an
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
# The flock helper needs `/proc`'s per-process start tick rather than the
# coarse wall-clock text `ps -o lstart` reports. The Python fcntl helper has
# direct parent-PID liveness and deliberately does not depend on this value,
# which keeps the documented macOS/BSD Python fallback usable. The override
# is an offline-fixture seam only.
CERT_BUNDLE_LOCK_PROC_ROOT="${CERT_BUNDLE_LOCK_PROC_ROOT:-/proc}"
CERT_BUNDLE_WAIT="${CERT_BUNDLE_WAIT:-0}"
CERT_BUNDLE_WAIT_TIMEOUT="${CERT_BUNDLE_WAIT_TIMEOUT:-5400}"   # 90 min, > one bundle
CERT_BUNDLE_WAIT_POLL="${CERT_BUNDLE_WAIT_POLL:-30}"
CERT_BUNDLE_LOCK_OWNED=0
CERT_BUNDLE_LOCK_HELPER_DIR=
CERT_BUNDLE_LOCK_HELPER_PID=
CERT_BUNDLE_LOCK_WATCHER_PIDS=()
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
      if command -v flock >/dev/null 2>&1 \
          && [ -n "$(certbundle_process_identity "$$")" ]; then
        printf flock
      elif command -v python3 >/dev/null 2>&1; then
        # `/proc` is unavailable on supported BSD/macOS hosts. The Python
        # helper observes getppid() directly, so it is the safe automatic
        # fallback when flock exists but cannot establish a start token.
        printf python
      else
        fail "the host certification lock needs Python 3 (fcntl.flock), or flock(1) with a readable Linux /proc start token; refusing to run a bundle without crash-safe mutual exclusion"
      fi
      ;;
  esac
}

certbundle_process_identity() { # certbundle_process_identity <pid>
  # Linux /proc/<pid>/stat field 22 is the kernel's start tick for this exact
  # PID lifetime. Unlike ps lstart it is not coarse wall-clock text, so an
  # immediately recycled PID cannot impersonate the killed acquirer. Read it
  # with Bash builtins because an identity probe runs continuously under host
  # pressure and, more importantly, absence of a safe token must refuse the
  # acquisition rather than degrade to PID-only liveness.
  local pid="$1" stat rest
  local -a fields=()
  case "$pid" in ''|*[!0-9]*) printf ''; return 0 ;; esac
  # Offline lock tests use this optional marker to prove an identity read is
  # actually blocked below, rather than merely sleeping near it. It has no
  # authority over identity or liveness: production leaves all three values
  # unset, and a set marker only records this process's exact PID.
  if [ -n "${CERT_BUNDLE_LOCK_IDENTITY_ENTERED:-}" ] \
      && [ -n "${CERT_BUNDLE_LOCK_IDENTITY_PID_FILE:-}" ] \
      && [ -e "${CERT_BUNDLE_LOCK_IDENTITY_ENTERED_GATE:-}" ]; then
    printf '%s\n' "$BASHPID" > "$CERT_BUNDLE_LOCK_IDENTITY_PID_FILE" 2>/dev/null || true
    : > "$CERT_BUNDLE_LOCK_IDENTITY_ENTERED" 2>/dev/null || true
  fi
  if IFS= read -r stat < "$CERT_BUNDLE_LOCK_PROC_ROOT/$pid/stat" 2>/dev/null; then
    # `comm` is parenthesized and may contain whitespace, so discard through
    # its final close-paren before reading fields 3..22. Start time is index
    # 19 after that discard (field 3 is index 0).
    rest="${stat##*) }"
    read -r -a fields <<< "$rest" || true
    case "${fields[19]:-}" in
      ''|*[!0-9]*) ;;
      *) printf 'linux-start:%s' "${fields[19]}"; return 0 ;;
    esac
  fi
  printf ''
}

certbundle_acquirer_gone() { # certbundle_acquirer_gone <pid> <recorded-identity>
  # Whether the process that acquired the lock has stopped existing. Only
  # POSITIVE evidence counts, because the helper releases the lock on a true
  # answer and an unnecessary hold is recoverable while a wrong release is
  # not.
  #
  # An unreadable identity is NOT death. A transient proc read under host
  # pressure must keep the lock while kill -0 still answers; an earlier
  # revision treated that uncertainty exactly like death, dropped the
  # descriptor, and admitted a second live bundle.
  local pid="$1" recorded="$2" ident
  # A confirmed missing PID is the common crash path. Check it before the
  # proc read so the fast watcher releases promptly when its acquirer dies.
  if ! kill -0 "$pid" 2>/dev/null; then return 0; fi
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

certbundle_lock_watchers_stop() {
  local dir="$CERT_BUNDLE_LOCK_HELPER_DIR" pid waited
  local -a pids=("${CERT_BUNDLE_LOCK_WATCHER_PIDS[@]}")
  CERT_BUNDLE_LOCK_WATCHER_PIDS=()
  [ -n "$dir" ] || return 0
  # The recorded helper directory is private to this invocation. Its marker
  # tells both observers that an ordinary release owns their shutdown; a
  # killed acquirer never writes it, so its watchers remain responsible for
  # releasing the helper's descriptor.
  [ -d "$dir" ] && { : > "$dir/watcher-stop" 2>/dev/null || true; }
  for pid in "${pids[@]}"; do
    waited=0
    while kill -0 "$pid" 2>/dev/null; do
      waited=$((waited + 1))
      if [ "$waited" -gt 200 ]; then
        # This PID was recorded when this shell launched the watcher. It has
        # no lock descriptor, but keeping cleanup PID-scoped matters just as
        # much here as it does for the bundle process itself.
        kill -9 "$pid" 2>/dev/null || true
        break
      fi
      sleep 0.05
    done
  done
  return 0
}

certbundle_lock_helper_stop() {
  local dir="$CERT_BUNDLE_LOCK_HELPER_DIR" pid="$CERT_BUNDLE_LOCK_HELPER_PID" waited=0
  certbundle_lock_watchers_stop
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

certbundle_lock_cancel_orphan() { # <helper-dir>
  # The cancellation path belongs to a watcher with no lock descriptor. It
  # waits for the exact helper PID the acquirer forked, then asks that helper
  # to stop through its private marker. It deliberately does not signal a
  # PID: a recycled PID after an extraordinary helper exit must at worst
  # leave harmless private controls, never receive a signal for an old run.
  local dir="$1" helper_pid= waited=0
  [ -d "$dir" ] || return 0
  # The watcher starts before the helper so no post-acquire/pre-watcher gap
  # exists. The helper writes its own exact PID before attempting flock(2);
  # wait briefly for that causal handoff rather than guessing from a pattern
  # or a process list.
  while [ "$waited" -lt 200 ]; do
    if [ -s "$dir/helper.pid" ]; then
      helper_pid=$(cat "$dir/helper.pid" 2>/dev/null || true)
      case "$helper_pid" in ''|*[!0-9]*) helper_pid= ;; *) break ;; esac
    fi
    waited=$((waited + 1))
    sleep 0.05
  done
  # Write cancel BEFORE optional helper-PID observation. If a host under
  # pressure cannot read the PID marker, deleting this directory would also
  # delete the only cancellation signal and strand a live descriptor forever.
  # Leaving the private controls is harmless; the helper sees cancel and
  # removes them through its own EXIT trap.
  : > "$dir/acquirer-gone" 2>/dev/null || return 0
  : > "$dir/cancel" 2>/dev/null || true
  [ -n "$helper_pid" ] || return 0
  waited=0
  while kill -0 "$helper_pid" 2>/dev/null; do
    waited=$((waited + 1))
    # The helper sees cancel on its next cheap 0.05s tick and clears this
    # directory through its own EXIT trap. If a recycled PID keeps answering,
    # retain this private marker rather than deleting it under a live helper.
    [ "$waited" -le 200 ] || return 0
    sleep 0.05
  done
  rm -rf -- "$dir" 2>/dev/null || true
}

certbundle_lock_start_watchers() { # <helper-dir> <acquirer-pid> <acquirer-identity>
  # The shell that owns fd 9 deliberately does no liveness observation. Any
  # external command it starts inherits that descriptor; a wedged observer used
  # to leave exactly such a child holding the flock after its acquirer had
  # been SIGKILLed. These observers are children of the acquirer instead, so
  # they own no lock descriptor and can never strand one.
  local dir="$1" parent_pid="$2" parent_ident="$3"
  local cancel="$dir/cancel" stop="$dir/watcher-stop"
  CERT_BUNDLE_LOCK_WATCHER_PIDS=()

  (
    trap - EXIT
    # Fast, fork-free crash detection. A PID-reuse false positive can only
    # hold longer, never release another process's lock; the identity watcher
    # below resolves that uncommon shape without putting an observer in the holder.
    while [ -d "$dir" ] && [ ! -e "$stop" ]; do
      if ! kill -0 "$parent_pid" 2>/dev/null; then
        certbundle_lock_cancel_orphan "$dir"
        exit 0
      fi
      sleep 0.05
    done
  ) &
  CERT_BUNDLE_LOCK_WATCHER_PIDS+=("$!")

  (
    trap - EXIT
    # PID recycling is distinct from an ordinary death: identity comparison
    # is retained as a second, fail-closed observer, but this process owns no
    # descriptor so a stalled identity read cannot keep the flock alive.
    while [ -d "$dir" ] && [ ! -e "$stop" ] && [ ! -e "$cancel" ]; do
      if certbundle_acquirer_gone "$parent_pid" "$parent_ident"; then
        certbundle_lock_cancel_orphan "$dir"
        exit 0
      fi
      sleep 0.5
    done
  ) &
  CERT_BUNDLE_LOCK_WATCHER_PIDS+=("$!")
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
  parent_pid="$$"
  # The flock watcher needs a safe start token. Its direct child can outlive
  # a killed acquirer, so PID-only reclamation risks a recycled PID keeping a
  # dead flock reserved indefinitely. The Python helper instead observes its
  # direct parent through getppid(), which cannot be resurrected by PID reuse
  # and is therefore portable without /proc.
  parent_ident="$(certbundle_process_identity "$parent_pid")"
  [ -n "$parent_ident" ] || parent_ident="$(certbundle_process_identity "$parent_pid")"
  if [ "$backend" = flock ] && [ -z "$parent_ident" ]; then
    fail "cannot establish an unambiguous Linux /proc start token for certification flock acquirer pid $parent_pid; refusing rather than rely on PID-only orphan reclamation"
  fi
  # The helper's control files are private to this run, so they live in this
  # process's own TMPDIR -- only the flock and the naming record belong in the
  # shared rendezvous.
  helper_dir="$(mktemp -d "${TMPDIR:-/tmp}/duo-certbundle-helper.XXXXXX")" \
    || fail "cannot create the certification lock helper directory"
  ready="$helper_dir/ready"; busy="$helper_dir/busy"
  cancel="$helper_dir/cancel"; err="$helper_dir/stderr"
  # Launch the descriptor-free observers before the helper even begins its
  # flock attempt. Once the helper writes helper.pid, an acquirer SIGKILL at
  # any later point has a watcher that can cancel that exact helper; there is
  # no ready-before-watcher gap in which the descriptor could be stranded.
  CERT_BUNDLE_LOCK_HELPER_DIR="$helper_dir"
  CERT_BUNDLE_LOCK_HELPER_PID=
  if [ "$backend" = flock ]; then
    certbundle_lock_start_watchers "$helper_dir" "$parent_pid" "$parent_ident"
  fi

  if [ "$backend" = flock ]; then
    (
      # Traps are not inherited into this subshell, and it must never run the
      # acquiring shell's release: state it rather than rely on it.
      trap - EXIT
      printf '%s\n' "$BASHPID" > "$helper_dir/helper.pid"
      if flock -n 9; then
        # A killed acquirer can also terminate this helper before it observes
        # the watchers' cancel marker. Its private controls must never depend
        # on that observation to be removed. Keep this trap inside the
        # acquired branch: a busy helper must leave its marker behind long
        # enough for the acquiring shell to classify the ordinary refusal.
        trap 'rm -rf -- "$helper_dir" 9>&- 2>/dev/null || true' EXIT
        : > "$ready"
        # This process is the sole descriptor owner. Do not run an observer, or
        # any other potentially stalled observer, here: children inherit fd
        # 9 and can then retain the flock after this helper exits. The two
        # descriptor-free watchers started by the acquiring shell own the
        # liveness proof and leave this loop a cheap cancel wait.
        while [ ! -e "$cancel" ]; do
          sleep 0.05 9>&-
        done
      else
        : > "$busy"
      fi
    ) 9>"$CERT_BUNDLE_LOCK_FILE" 2>"$err" &
  else
    python3 -c '
import fcntl, os, shutil, sys, time
lock_path, ready_path, busy_path, cancel_path, helper_dir, helper_pid_path, parent_pid = sys.argv[1:]
parent_pid = int(parent_pid)
with open(helper_pid_path, "w") as helper_pid_file:
    helper_pid_file.write(str(os.getpid()) + "\\n")
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
' "$CERT_BUNDLE_LOCK_FILE" "$ready" "$busy" "$cancel" "$helper_dir" "$helper_dir/helper.pid" "$parent_pid" 2>"$err" &
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
    acquired)
      return 0
      ;;
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
