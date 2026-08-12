#!/usr/bin/env bash
# Pair-budget resource lock primitives shared by pair orchestration.
#
# This library owns only the crash-safe cross-worktree reservation protocol:
# a helper process holds the kernel lock and watches its direct shell owner.
# Pair discovery, host-capacity arithmetic, and certification policy remain in
# pair.sh; they consume this narrow acquire/release boundary.

# Shared launcher budget state. Git linked worktrees resolve to the same
# canonical root, so this descriptor serializes the live-pair query and the
# first container creation across every checkout.  flock releases it when an
# owner crashes; the lock file is never removed by a release path.
PAIR_CANONICAL_ROOT=""
PAIR_BUDGET_LOCK_FD=""
PAIR_BUDGET_LOCK_MODE=""
PAIR_BUDGET_LOCK_HELPER_PID=""
PAIR_BUDGET_LOCK_HELPER_READ_FD=""
PAIR_BUDGET_LOCK_HELPER_WRITE_FD=""
PAIR_BUDGET_LOCK_HELPER_DIR=""
PAIR_BUDGET_LIVE_PAIRS=""

# Acquire the one host/worktree-shared budget lock. A self-monitoring helper
# owns an acquired descriptor, so Docker/sleep descendants of this shell
# cannot retain a reservation after the shell is killed. Linux's `flock`
# utility is preferred; macOS/BSD hosts use the documented Python `fcntl.flock`
# helper, whose control FIFO also closes on an owner crash.
budget_lock_acquire() { # budget_lock_acquire <canonical-root>
  local root="$1" lock_path="$1/sandbox/siterepo/.pair-budget.lock"
  mkdir -p -- "${root}/sandbox/siterepo" \
    || fail "could not create the shared pair-budget lock directory: ${root}/sandbox/siterepo"
  if command -v flock >/dev/null 2>&1; then
    local helper_dir ready_path cancel_path parent_pid parent_start helper_ready=0
    helper_dir="$(mktemp -d "${root}/sandbox/siterepo/.pair-budget-helper.XXXXXX")" \
      || fail "could not create the flock pair-budget helper directory"
    ready_path="$helper_dir/ready"
    cancel_path="$helper_dir/cancel"
    parent_pid="$$"
    parent_start="$(awk '{print $22}' "/proc/$parent_pid/stat" 2>/dev/null || true)"
    PAIR_BUDGET_LOCK_MODE=flock
    PAIR_BUDGET_LOCK_FD=""
    PAIR_BUDGET_LOCK_HELPER_DIR="$helper_dir"
    # The helper retries nonblocking probes. Once one succeeds, the command
    # passed to flock owns the descriptor and waits on the unique cancel
    # marker while checking this shell's PID and Linux start time. A recycled
    # PID therefore cannot make an old helper act on a newer process.
    (
      parent_alive() {
        if [ -n "$parent_start" ]; then
          [ -r "/proc/$parent_pid/stat" ] || return 1
          [ "$(awk '{print $3}' "/proc/$parent_pid/stat" 2>/dev/null || true)" != Z ] || return 1
          [ "$(awk '{print $22}' "/proc/$parent_pid/stat" 2>/dev/null || true)" = "$parent_start" ]
        else
          kill -0 "$parent_pid" 2>/dev/null
        fi
      }
      trap 'rm -f -- "$ready_path" "$cancel_path" 2>/dev/null || true; rmdir -- "$helper_dir" 2>/dev/null || true' EXIT
      while parent_alive && [ ! -e "$cancel_path" ]; do
        if flock -n "$lock_path" bash -c '
lock_ready="$1" lock_cancel="$2" lock_parent="$3" lock_start="$4"
: > "$lock_ready"
while [ ! -e "$lock_cancel" ]; do
  if [ -n "$lock_start" ]; then
    if [ ! -r "/proc/$lock_parent/stat" ]; then
      exit 0
    fi
    [ "$(cut -d" " -f3 "/proc/$lock_parent/stat" 2>/dev/null || true)" != Z ] || exit 0
    [ "$(cut -d" " -f22 "/proc/$lock_parent/stat" 2>/dev/null || true)" = "$lock_start" ] || exit 0
  elif ! kill -0 "$lock_parent" 2>/dev/null; then
    exit 0
  fi
  sleep 0.05
done
' _ "$ready_path" "$cancel_path" "$parent_pid" "$parent_start"; then
          break
        fi
        parent_alive || break
        [ -e "$cancel_path" ] && break
        sleep 0.05
      done
    ) &
    PAIR_BUDGET_LOCK_HELPER_PID="$!"
    while [ ! -f "$ready_path" ]; do
      if ! kill -0 "$PAIR_BUDGET_LOCK_HELPER_PID" 2>/dev/null; then
        break
      fi
      sleep 0.01
    done
    [ -f "$ready_path" ] && helper_ready=1
    if [ "$helper_ready" -ne 1 ]; then
      budget_lock_release
      fail "could not acquire the shared pair-budget lock with flock: $lock_path"
    fi
    return 0
  fi

  if command -v python3 >/dev/null 2>&1; then
    # The helper owns the file descriptor and blocks on a unique FIFO. The
    # parent writes a cancellation byte then closes its writer on release
    # (and implicitly closes it on a crash), while the helper also checks its
    # direct parent's identity so it cannot leave a stale lock behind while
    # waiting for a writer.
    local helper_dir control_path ready_path helper_ready=0
    helper_dir="$(mktemp -d "${root}/sandbox/siterepo/.pair-budget-helper.XXXXXX")" \
      || fail "could not create the Python pair-budget helper directory"
    control_path="$helper_dir/control"
    ready_path="$helper_dir/ready"
    if ! mkfifo "$control_path"; then
      rmdir "$helper_dir" 2>/dev/null || true
      fail "could not create the Python pair-budget control FIFO"
    fi
    PAIR_BUDGET_LOCK_MODE=python
    PAIR_BUDGET_LOCK_HELPER_DIR="$helper_dir"
    PAIR_BUDGET_LOCK_HELPER_WRITE_FD=8
    # Open both ends before the helper starts. A parent-held RDWR descriptor
    # prevents an O_NONBLOCK reader from seeing EOF during the handshake.
    if ! exec 8<>"$control_path"; then
      rmdir "$helper_dir" 2>/dev/null || true
      PAIR_BUDGET_LOCK_MODE=""
      PAIR_BUDGET_LOCK_HELPER_DIR=""
      PAIR_BUDGET_LOCK_HELPER_WRITE_FD=""
      fail "could not open the Python pair-budget control FIFO"
    fi
    python3 -c '
import fcntl, os, select, sys
lock_path, control_path, ready_path, parent_pid = sys.argv[1:]
parent_pid = int(parent_pid)

def parent_alive():
    if os.getppid() != parent_pid:
        return False
    try:
        with open("/proc/%d/stat" % parent_pid) as proc:
            state = proc.read().rsplit(")", 1)[1].split()[0]
        return state != "Z"
    except OSError:
        return True
try:
    os.close(8)
except OSError:
    pass
control_fd = None
try:
    control_fd = os.open(control_path, os.O_RDONLY | os.O_NONBLOCK)
    with open(lock_path, "a+") as lock:
        while True:
            if not parent_alive():
                raise SystemExit(0)
            readable, _, _ = select.select([control_fd], [], [], 0.05)
            if readable:
                os.read(control_fd, 1)
                raise SystemExit(0)
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                continue
        with open(ready_path, "w") as ready:
            ready.write("ready\n")
            ready.flush()
        while parent_alive():
            readable, _, _ = select.select([control_fd], [], [], 0.25)
            if readable:
                os.read(control_fd, 1)
                break
finally:
    if control_fd is not None:
        os.close(control_fd)
    for path in (control_path, ready_path):
        try:
            os.unlink(path)
        except FileNotFoundError:
            pass
    try:
        os.rmdir(os.path.dirname(control_path))
    except OSError:
        pass
' "$lock_path" "$control_path" "$ready_path" "$$" &
    PAIR_BUDGET_LOCK_HELPER_PID="$!"
    while [ ! -f "$ready_path" ]; do
      if ! kill -0 "$PAIR_BUDGET_LOCK_HELPER_PID" 2>/dev/null; then
        break
      fi
      sleep 0.01
    done
    [ -f "$ready_path" ] && helper_ready=1
    if [ "$helper_ready" -ne 1 ]; then
      budget_lock_release
      fail "could not acquire the shared pair-budget lock with Python fcntl: $lock_path"
    fi
    return 0
  fi

  fail "pair budget reservation requires flock or python3/fcntl; refusing without a crash-safe cross-worktree lock"
}

budget_lock_release() {
  local fd="${PAIR_BUDGET_LOCK_FD:-}" read_fd="${PAIR_BUDGET_LOCK_HELPER_READ_FD:-}"
  local write_fd="${PAIR_BUDGET_LOCK_HELPER_WRITE_FD:-}" helper_pid="${PAIR_BUDGET_LOCK_HELPER_PID:-}"
  local helper_dir="${PAIR_BUDGET_LOCK_HELPER_DIR:-}"
  case "${PAIR_BUDGET_LOCK_MODE:-}" in
    flock)
      # The helper, not this shell, owns the acquired flock descriptor. A
      # private cancel marker asks either its retry loop or its lock-holding
      # monitor to exit; no PID/path belonging to another reservation is
      # touched.
      if [ -n "$helper_dir" ]; then
        : > "$helper_dir/cancel" 2>/dev/null || true
      fi
      [ -n "$helper_pid" ] && wait "$helper_pid" 2>/dev/null || true
      if [ -n "$helper_dir" ]; then
        rm -f -- "$helper_dir/ready" "$helper_dir/cancel" 2>/dev/null || true
        rmdir -- "$helper_dir" 2>/dev/null || true
      fi
      ;;
    python)
      # Closing only our own FIFO writer asks our own helper to exit; no
      # pathname/PID belonging to another reservation is removed or killed.
      if [ -n "$write_fd" ]; then
        # Send an explicit cancellation byte before closing. A Docker/sleep
        # child may have inherited the parent's FIFO writer, in which case
        # EOF alone would never become readable by the helper.
        printf 'x' >&"$write_fd" 2>/dev/null || true
        eval "exec ${write_fd}>&-" 2>/dev/null || true
      fi
      [ -n "$helper_pid" ] && wait "$helper_pid" 2>/dev/null || true
      [ -n "$read_fd" ] && eval "exec ${read_fd}<&-" 2>/dev/null || true
      if [ -n "$helper_dir" ]; then
        rm -f -- "$helper_dir/control" "$helper_dir/ready" 2>/dev/null || true
        rmdir -- "$helper_dir" 2>/dev/null || true
      fi
      ;;
  esac
  PAIR_BUDGET_LOCK_FD=""
  PAIR_BUDGET_LOCK_MODE=""
  PAIR_BUDGET_LOCK_HELPER_PID=""
  PAIR_BUDGET_LOCK_HELPER_READ_FD=""
  PAIR_BUDGET_LOCK_HELPER_WRITE_FD=""
  PAIR_BUDGET_LOCK_HELPER_DIR=""
}

budget_up_cleanup() {
  local status=$?
  trap - EXIT INT TERM
  budget_lock_release
  exit "$status"
}

arm_budget_up_cleanup() {
  trap budget_up_cleanup EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
}

disarm_budget_up_cleanup() {
  trap - EXIT INT TERM
  budget_lock_release
}
