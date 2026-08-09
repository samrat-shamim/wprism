#!/usr/bin/env bash
# pair.sh — lifecycle tool for the sandbox redesign (task #74): one
# parameterized pair template (sandbox/pair.yml) against one shared MariaDB
# (sandbox/db.yml), instead of sandbox/docker-compose.yml's ~10 hand-
# duplicated pair profiles each carrying their own dedicated MariaDB.
#
# Subcommands:
#   pair.sh up <name> <port1> <port2> [--journal] [--codebind <plugin-dir>] [--http|--headless]
#   pair.sh reset <name>
#   pair.sh destroy <name>
#   pair.sh list
#
# Run with `bash sandbox/bin/pair.sh ...` (this repo's shell is zsh; every
# script here is bash and is always invoked that way — see Makefile).
#
# Design notes worth knowing before reading further:
#
# - No per-pair MariaDB. Every pair's two databases (wp_<name>1/wp_<name>2)
#   live on the ONE server sandbox/db.yml brings up (its own compose
#   project, duo-db). A clean-room reset is DROP DATABASE + CREATE DATABASE
#   against an already-warm server — no InnoDB re-init from an empty
#   datadir, which is what made the old per-pair-volume reset slow.
#
# - Readiness is checked at the DB level, deliberately NOT via
#   `wp core version` (the pattern sandbox/setup.sh, sandbox/conformance/
#   run.sh, and the spike scripts all use today). `wp core version` reads a
#   static PHP file — it never touches the database — so it reports "ready"
#   before the database is actually reachable. This file waits on the
#   shared server's own healthcheck (--connect --innodb_initialized) and
#   then, per pair, on `wp db query "SELECT 1"` actually succeeding through
#   that pair's own cli container — the same dependency chain a real
#   `core install` is about to exercise.
#
# - Every pair is its own compose project (`duo-<name>`, via `-p`), so any
#   number of pairs can come and go independently. They all attach to one
#   external network (duo-shared, owned/created by sandbox/db.yml) to reach
#   the shared db by its container name (duo-shared-db) — `depends_on`
#   can't cross compose-project boundaries, which is exactly why this
#   script's own readiness waits exist instead.
#
# - `up` performs the host-budget check before creating any pair database or
#   site-repo state. A new pair over the dynamic CPU/RAM budget is refused;
#   `DUO_PAIR_BUDGET_OVERRIDE=1` is the explicit escape hatch. `list` surfaces
#   the same budget warning for pairs already up.
set -euo pipefail
cd "$(dirname "$0")/.."   # sandbox/bin/pair.sh -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m%s\033[0m\n' "$*" >&2; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

# DUO-3277: the repo's CANONICAL checkout -- where a persistent pair's
# bind-mounted agent/manifests sources must always live, regardless of
# which worktree's own copy of THIS SCRIPT actually ran `up`. Per-issue
# worktrees are always removed at close-gate; a pair whose agent/manifests
# bind-mount source was resolved against a worktree (the historical bug --
# `../agent` in pair.yml, relative to wherever pair.sh's own `cd
# "$(dirname "$0")/.."` above landed) is left with a dead mount the moment
# that worktree goes, silently, until the next `pair.sh start` fails --
# potentially days later, by a different actor (observed live twice on the
# r3b pair; see this issue's own filing).
#
# Git's own common-dir is the right primitive, not a hardcoded directory
# name: for a LINKED worktree, `git rev-parse --git-common-dir` resolves
# to the PRIMARY worktree's own .git (a linked worktree's .git is a FILE
# pointing back to it, never a directory of its own); for the primary
# worktree itself, it's simply its own .git. The identical one-liner
# resolves correctly either way -- no special-casing "am I in a worktree"
# at all, and no assumption about what the canonical checkout is NAMED
# (this repo's own primary checkout is "duo-wp" in one clone on this host,
# "duo-wp-main" in another -- a hardcoded name would only ever match one
# of them, exactly the fragility this function exists to avoid).
canonical_root() {
  local common_dir
  common_dir=$(git rev-parse --path-format=absolute --git-common-dir 2>/dev/null) \
    || return 1
  dirname "$common_dir"
}

# DUO-3277: `start` (unlike `up`) never touches container config -- compose
# start just resumes whatever bind-mount sources were baked in when the
# container was CREATED, so a pair created before this fix shipped (or a
# pair whose agent/manifests source directory was deleted out from under
# it for any other reason) still hits a dead mount here even after the
# canonicalize fix above, until someone runs `up` again to force the
# recreate. Detect it here and say exactly what happened and how to
# recover instead of leaving it to docker's own opaque container-start
# failure (the issue's own acceptance criterion) -- only wp1/wp2 have
# persistent containers `start` ever touches; cli1/cli2 are always `run
# --rm` (see every wp_env()-style helper across this sandbox), so they
# never have a stopped container of their own to check or resume.
check_dead_mounts() { # check_dead_mounts <name>
  local name="$1" container dead=()
  for container in "duo-${name}-wp1-1" "duo-${name}-wp2-1"; do
    docker inspect "$container" >/dev/null 2>&1 || continue   # not created yet -- nothing to check
    local sources src
    sources=$(docker inspect "$container" \
      --format '{{range .Mounts}}{{if eq .Type "bind"}}{{.Source}}{{"\n"}}{{end}}{{end}}' 2>/dev/null || true)
    while IFS= read -r src; do
      [ -n "$src" ] && [ ! -e "$src" ] && dead+=("$container: $src")
    done <<< "$sources"
  done
  if [ "${#dead[@]}" -gt 0 ]; then
    fail "pair '$name' has a dead bind-mount source -- the checkout its containers were created against no longer exists on disk (DUO-3277's own worktree-bind-mount hazard: a pair started with 'up' before that fix shipped, or from a worktree since removed, still has the OLD source baked in):
$(printf '  %s\n' "${dead[@]}")
recovery: run \"pair.sh up $name <port1> <port2> [same flags you originally used]\" from ANY checkout of this repo (worktree or canonical, doesn't matter now) -- this recreates the container against the canonical checkout's own agent/manifests (docker compose detects the config drift and recreates automatically); this pair's own database and webroot volumes are untouched either way"
  fi
}

DB_CONTAINER=duo-shared-db
DB_ROOT_USER=root
DB_ROOT_PASS=root
APP_USER=wordpress
APP_PASS=wordpress
DB_COMPOSE=(docker compose -p duo-db -f db.yml)

# Host/worktree-shared budget state.  Git linked worktrees resolve to the same
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

validate_name() { # validate_name <name>
  # Used bare both as a MySQL identifier fragment (wp_<name>1/2) and as a
  # docker compose project suffix (duo-<name>) — lowercase letters/digits
  # only, starting with a letter, keeps it unambiguously safe in both
  # without needing identifier-quoting gymnastics anywhere in this script.
  [[ "$1" =~ ^[a-z][a-z0-9]*$ ]] \
    || fail "pair name '$1' invalid — lowercase letters/digits only, starting with a letter"
  # Reserved: "db" is duo-db, this file's own shared-MariaDB project — `pair.sh
  # destroy db` would otherwise `down -v` the server every other pair depends
  # on. "sandbox" is duo-sandbox, the legacy mega-compose's project — same
  # risk, against a file this tool must never touch. Neither is a real pair.
  case "$1" in
    db|sandbox) fail "pair name '$1' is reserved (duo-$1 is already a different project — see pair.sh's validate_name)" ;;
  esac
}

# --- shared db: bring-up, readiness, admin SQL ------------------------------

db_sql() { # db_sql — run SQL read from stdin as root against the shared server
  # mariadb:11's image only ships the `mariadb` client binary (no `mysql`
  # symlink — confirmed empirically while authoring this: MariaDB has been
  # renaming its client tools, mysql -> mariadb, mysqldump -> mariadb-dump,
  # etc., and this image has already dropped the old names entirely).
  docker exec -i -e MYSQL_PWD="$DB_ROOT_PASS" "$DB_CONTAINER" mariadb -u"$DB_ROOT_USER"
}

ensure_db_up() {
  "${DB_COMPOSE[@]}" up -d >/dev/null
  for _ in $(seq 1 60); do
    if [ "$(docker inspect -f '{{.State.Health.Status}}' "$DB_CONTAINER" 2>/dev/null || true)" = "healthy" ]; then
      return 0
    fi
    sleep 2
  done
  fail "shared db ($DB_CONTAINER) never became healthy"
}

ensure_app_user() {
  # Wildcard grant, not a per-pair user: `wp\_%` matches every wp_<name>{1,2}
  # database this or any other pair will ever create. Quoted heredoc (no
  # variable interpolation needed) so the backticks and backslash reach
  # mysql literally instead of bash trying to parse them.
  db_sql <<'SQL'
CREATE USER IF NOT EXISTS 'wordpress'@'%' IDENTIFIED BY 'wordpress';
GRANT ALL PRIVILEGES ON `wp\_%`.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
SQL
}

create_pair_dbs() { # create_pair_dbs <name>
  local name="$1"
  db_sql <<SQL
CREATE DATABASE IF NOT EXISTS wp_${name}1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS wp_${name}2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL
}

drop_pair_dbs() { # drop_pair_dbs <name>
  local name="$1"
  db_sql <<SQL
DROP DATABASE IF EXISTS wp_${name}1;
DROP DATABASE IF EXISTS wp_${name}2;
SQL
}

# --- pair-level compose plumbing --------------------------------------------

# Sets the global array PAIR_COMPOSE to the full `docker compose` argv for
# pair <name>, given whichever overlay files this call wants layered in.
pair_compose() { # pair_compose <name> [overlay-file ...]
  local name="$1"; shift
  PAIR_COMPOSE=(docker compose -p "duo-${name}" -f pair.yml)
  local f
  for f in "$@"; do PAIR_COMPOSE+=(-f "$f"); done
  # DUO-3277: every PAIR_COMPOSE invocation needs DUO_AGENT_SRC/
  # DUO_MANIFESTS_SRC in the environment now, not just `up` -- pair.yml
  # references them unconditionally, so `stop`/`start`/`destroy` (which
  # never went through cmd_up's own export) would otherwise hand compose
  # an EMPTY bind-mount source (":/var/www/html/...:ro", invalid spec) the
  # moment it re-parses pair.yml at all, which compose does for every
  # subcommand regardless of whether it ends up creating anything.
  # Exported HERE, the one place every subcommand already funnels through,
  # rather than duplicated at each call site (caught live: the first
  # version of this fix only set them in cmd_up and `stop` broke instantly).
  local root="${PAIR_CANONICAL_ROOT:-}"
  if [ -z "$root" ]; then
    if ! root="$(canonical_root)"; then
      fail "could not resolve this repo's canonical checkout via git (not a git repository?) -- DUO_AGENT_SRC/DUO_MANIFESTS_SRC cannot be computed"
    fi
    PAIR_CANONICAL_ROOT="$root"
  fi
  export DUO_AGENT_SRC="$root/agent" DUO_MANIFESTS_SRC="$root/manifests"

  # DUO-3277 (CI caught this the first version above missed): that export
  # only reaches pair.sh's OWN "${PAIR_COMPOSE[@]}" calls -- it dies with
  # this process and never reaches the many OTHER scripts (sandbox/
  # conformance/run.sh, every regress_*.sh/grind_*.sh) that invoke `pair.sh
  # up` once as a subprocess and then make their own separate, direct
  # `docker compose -f pair.yml ...` calls afterward (confirmed: that's how
  # essentially every one of them actually works, not a hypothetical edge
  # case -- see run.sh's own $COMPOSE + its wp_env() helper). Those scripts
  # already re-export DUO_PAIR/DUO_PORT1/DUO_PORT2 themselves for the same
  # process-boundary reason (see run.sh's comment by its own export line),
  # but making every caller duplicate canonical_root()'s git logic too
  # would be fragile -- easy to add a new call site and forget it, with no
  # loud failure until that exact path runs.
  #
  # Persist the same two values to sandbox/.env instead, in addition to the
  # export above: docker compose auto-loads a file by that exact name from
  # the CWD (verified live with `env -i` stripping every inherited
  # variable -- compose still resolved both mounts correctly from .env
  # alone), and every caller in this codebase already `cd`s into sandbox/
  # before making its own compose calls (this script's own line 45 above;
  # run.sh's equivalent). One write here, in the single choke point every
  # subcommand already funnels through, covers every current AND future
  # caller with zero changes to any of them. Overwritten (never appended)
  # so a stale value can never survive a worktree/checkout change; safe
  # under concurrent pair.sh invocations against the same checkout too,
  # since canonical_root() is a pure function of the checkout, not the pair
  # name -- any two concurrent writers here always agree on the value.
  printf 'DUO_AGENT_SRC=%s\nDUO_MANIFESTS_SRC=%s\n' "$DUO_AGENT_SRC" "$DUO_MANIFESTS_SRC" > .env
}

prepare_siterepo_roots() { # prepare_siterepo_roots <name>
  local name="$1"
  mkdir -p "siterepo/${name}1" "siterepo/${name}2"

  # These are disposable sandbox bind-mount roots, shared by two different
  # users: the host process creates/commits the repository, while wp-cli runs
  # as uid 33 and creates capture locks plus atomic staging directories at the
  # repository root. Linux CI preserves host ownership on bind mounts (unlike
  # some desktop Docker filesystems), so mkdir's ordinary 0755 would leave a
  # fresh checkout host-only and every capture would fail before it
  # could acquire state.capture.lock. Keep this deliberately scoped to the two
  # throwaway sandbox roots; it is not a production permission recommendation.
  chmod 0777 "siterepo/${name}1" "siterepo/${name}2"
}

live_pairs() { # live_pairs — one live pair name per line
  # Filtered by ConfigFiles (must include this sandbox's pair.yml), not by
  # project-name pattern: the legacy sandbox/docker-compose.yml's own
  # project is literally named "duo-sandbox", which — being lowercase
  # letters only — would otherwise pass right through a naming-convention
  # filter and get miscounted as one of this redesign's own pairs.  Every
  # command in this query is checked: unavailable Docker, malformed JSON, or
  # unavailable jq is a refusal condition, never an empty list.
  local json
  json="$(docker compose ls --format json 2>/dev/null)" || return 1
  [ -n "$json" ] || return 1
  printf '%s\n' "$json" | jq -r '
    if type != "array" then error("compose ls did not return an array")
    else .[]
      | select((.ConfigFiles // "") | type == "string")
      | select((.ConfigFiles // "") | test("/pair\\.yml(,|$)"))
      | select((.Name // "") | type == "string")
      | select((.Name // "") | startswith("duo-"))
      | .Name[4:]
    end
  '
}

wait_pair_visible() { # wait_pair_visible <name>
  local name="$1" live
  for _ in $(seq 1 60); do
    if ! live="$(live_pairs)"; then
      fail "could not verify pair '$name' became live after compose start"
    fi
    if printf '%s\n' "$live" | grep -Fqx -- "$name"; then
      return 0
    fi
    sleep 1
  done
  fail "pair '$name' did not become visible in Compose after start"
}

stopped_pairs() { # stopped_pairs — one stopped pair name per line
  local json
  json="$(docker compose ls -a --format json 2>/dev/null)" || return 1
  [ -n "$json" ] || return 1
  printf '%s\n' "$json" | jq -r '
    if type != "array" then error("compose ls did not return an array")
    else .[]
      | select((.ConfigFiles // "") | type == "string")
      | select((.ConfigFiles // "") | test("/pair\\.yml(,|$)"))
      | select((.Status // "") | type == "string")
      | select((.Status // "") | contains("running") | not)
      | select((.Name // "") | type == "string")
      | select((.Name // "") | startswith("duo-"))
      | .Name[4:]
    end
  '
}

pair_budget() {
  # Dynamic host budget instead of a hardcoded pair count: **1 docker core
  # per RUNNING pair**, computed from what the docker VM actually has right
  # now — a fixed number calibrated to one machine's load (the old "2",
  # set while an unrelated kind cluster ate half this host) goes stale the
  # moment the machine changes. Two reserves come off the top before the
  # 1-core-per-pair rule applies:
  #   - CPU: 2 cores for the shared MariaDB (its own cpus cap is 2.0) plus
  #     daemon/system churn.
  #   - RAM guard: on most machines memory binds before cores — an ACTIVELY
  #     verifying pair peaks around 2GiB (wp1+wp2 at their 1GiB caps), so
  #     also cap at (docker mem - 3GiB reserve for the db's 2GiB cap +
  #     overhead) / 2GiB per pair, and take the smaller of the two budgets.
  # Floor of 1: a tiny VM still gets one pair (nothing works otherwise).
  local cores mem_bytes mem_gib cpu_budget ram_budget budget
  cores="$(docker info -f '{{.NCPU}}' 2>/dev/null)" || return 1
  mem_bytes="$(docker info -f '{{.MemTotal}}' 2>/dev/null)" || return 1
  [[ "$cores" =~ ^[0-9]+$ ]] || return 1
  [[ "$mem_bytes" =~ ^[0-9]+$ ]] || return 1
  [ "$cores" -ge 1 ] || return 1
  [ "$mem_bytes" -ge 1 ] || return 1
  mem_gib=$(( mem_bytes / 1073741824 ))
  cpu_budget=$(( cores - 2 ))
  ram_budget=$(( (mem_gib - 3) / 2 ))
  budget=$(( cpu_budget < ram_budget ? cpu_budget : ram_budget ))
  [ "$budget" -lt 1 ] && budget=1
  printf '%s\n' "$budget"
}

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

reserve_pair_budget() { # reserve_pair_budget <candidate>; leaves lock held
  local candidate="$1" root live budget live_count candidate_live=0 total
  if ! root="$(canonical_root)"; then
    fail "could not resolve this repo's canonical checkout via git (not a git repository?) -- pair budget reservation cannot be shared safely"
  fi
  PAIR_CANONICAL_ROOT="$root"
  budget_lock_acquire "$root"

  if ! live="$(live_pairs)"; then
    budget_lock_release
    fail "could not enumerate live pair Compose projects; refusing without a verified budget"
  fi
  PAIR_BUDGET_LIVE_PAIRS="$live"
  if ! budget="$(pair_budget)"; then
    budget_lock_release
    fail "could not query Docker host capacity; refusing without a verified budget"
  fi

  live_count="$(printf '%s\n' "$live" | awk 'NF {n++} END {print n+0}')"
  if [ -n "$candidate" ] && printf '%s\n' "$live" | grep -Fqx -- "$candidate"; then
    candidate_live=1
  fi
  total="$live_count"
  [ "$candidate_live" -eq 1 ] || total=$((total + 1))

  if [ "$total" -gt "$budget" ]; then
    warn ""
    warn "!! ${live_count} running pairs (budget for this host: ${budget} — 1 docker core per pair, RAM-guarded; see pair_budget())"
    warn "!! pairs: $(printf '%s' "$live" | tr '\n' ' ')"
    warn "!! stop pairs you're not actively using (pair.sh stop <name>) or destroy finished ones"
    if [ -n "$candidate" ] && [ "$candidate_live" -eq 0 ]; then
      if [ "${DUO_PAIR_BUDGET_OVERRIDE:-0}" = "1" ]; then
        warn "!! DUO_PAIR_BUDGET_OVERRIDE=1 set — bringing up '$candidate' ANYWAY, ${total}/${budget} over budget"
      else
        budget_lock_release
        fail "refusing to bring up new pair '$candidate' over budget (${total} > ${budget}); stop/destroy another pair first, or set DUO_PAIR_BUDGET_OVERRIDE=1 to proceed anyway"
      fi
    fi
  fi
}

# --- generic WordPress bootstrap (idempotent) -------------------------------

write_htaccess() { # write_htaccess <side (1|2)>
  # wp-cli can't write .htaccess without extra config; apache needs it for
  # pretty permalinks, and the HTTP_AUTHORIZATION line is required for
  # basic-auth REST — identical to every other script in this sandbox.
  "${PAIR_COMPOSE[@]}" exec -T -u www-data "wp$1" tee /var/www/html/.htaccess >/dev/null <<'EOF'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF
}

wait_db_ready() { # wait_db_ready <name> <side (1|2)>
  # THE readiness fix task #74 called for: a check that actually requires
  # the database to be reachable, run through this pair's own cli
  # container (so it also proves the network path and WORDPRESS_DB_* creds
  # are right) — not `wp core version`, which is a static-file read that
  # would happily report "ready" even if the database were unreachable.
  # `wp db query "SELECT 1"` works against a database that exists but has
  # zero tables yet (our exact state right after CREATE DATABASE, before
  # `core install` has run), unlike `wp db check` (mysqlcheck), which
  # checks tables and would have nothing to check yet.
  local name="$1" side="$2"
  for _ in $(seq 1 90); do
    if "${PAIR_COMPOSE[@]}" run --rm -T "cli${side}" wp db query "SELECT 1" >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  fail "env ${name}${side} never reached its database"
}

wait_web_mountpoints() { # wait_web_mountpoints <name>
  # pair.yml mounts the shared wp-content tree and then mounts the Duo MU
  # directory/file underneath that named volume. Starting cli1/cli2 in the
  # same compose transaction races Docker's volume initialization: a CLI
  # container can try to create the nested file mountpoint while the first
  # web container is still populating the volume. The web services own that
  # initialization; wait until both can see the nested mounts before asking
  # Compose to create the CLI services.
  local name="$1" side all_ready mount_check
  mount_check='test -d /var/www/html/wp-content/mu-plugins/duo && test -f /var/www/html/wp-content/mu-plugins/duo-loader.php'
  for _ in $(seq 1 60); do
    all_ready=1
    for side in 1 2; do
      if ! "${PAIR_COMPOSE[@]}" exec -T "wp$side" sh -c "$mount_check" >/dev/null 2>&1; then
        all_ready=0
      fi
    done
    if [ "$all_ready" = 1 ]; then
      return 0
    fi
    sleep 1
  done
  fail "env ${name} web containers never exposed the nested Duo MU mountpoints"
}

install_and_activate_theme() { # install_and_activate_theme <cli service> <theme slug>
  local cli="$1" theme="$2" attempt active
  # A fresh pair is an evidence boundary, but WordPress.org is not: a
  # transient theme-directory lookup must not turn an otherwise healthy
  # conformance leg red. Keep the retry narrow and bounded, preserve every
  # WP-CLI diagnostic, and independently prove the requested theme is active
  # before allowing bootstrap to continue. `theme activate` makes a partial
  # install retry-safe without forcing or overwriting theme bytes.
  for attempt in 1 2 3; do
    if "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp theme install "$theme" --activate; then
      :
    elif "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp theme activate "$theme"; then
      printf 'Warning: theme %s was already installed after install attempt %s; activated the existing exact slug\n' \
        "$theme" "$attempt" >&2
    else
      if [ "$attempt" = 3 ]; then
        fail "theme '$theme' could not be installed and activated after 3 attempts"
      fi
      printf 'Warning: theme %s install/activation attempt %s/3 failed; retrying the exact slug\n' \
        "$theme" "$attempt" >&2
      sleep "$attempt"
      continue
    fi

    if ! active=$("${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp theme list --status=active --field=name); then
      active=""
    fi
    if [ "$active" = "$theme" ]; then
      return 0
    fi
    if [ "$attempt" = 3 ]; then
      fail "theme '$theme' install completed but active theme was '${active:-none}' after 3 attempts"
    fi
    printf "Warning: theme %s attempt %s/3 did not leave the exact slug active (got '%s'); retrying\n" \
      "$theme" "$attempt" "${active:-none}" >&2
    sleep "$attempt"
  done
}

install_side() { # install_side <side (1|2)> <url> <title>
  local side="$1" url="$2" title="$3" cli="cli$1"
  if "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp core is-installed >/dev/null 2>&1; then
    echo "  side $side already installed"
    return 0
  fi
  "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp core install \
    --url="$url" --title="$title" \
    --admin_user=admin --admin_password=admin \
    --admin_email=admin@example.test --skip-email
  install_and_activate_theme "$cli" twentytwentyone
  "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp option update permalink_structure '/%postname%/'
  "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp rewrite flush
  write_htaccess "$side"
  echo "  side $side installed ($url)"
}

# --- subcommands -------------------------------------------------------------

cmd_up() {
  local name="${1:?usage: pair.sh up <name> <port1> <port2> [--journal] [--codebind <dir>] [--http|--headless]}"
  local port1="${2:?up needs <port1>}"
  local port2="${3:?up needs <port2>}"
  shift 3
  local journal=0 codebind="" http_mode=1
  while [ $# -gt 0 ]; do
    case "$1" in
      --journal) journal=1; shift ;;
      --codebind) codebind="${2:?--codebind needs a plugin directory name}"; shift 2 ;;
      --http) http_mode=1; shift ;;
      --headless) http_mode=0; shift ;;
      *) fail "up: unknown flag '$1'" ;;
    esac
  done
  validate_name "$name"

  # Reserve the host budget before touching the shared DB, creating pair
  # schemas, or creating bind roots.  The reservation lock remains held
  # through web/CLI creation so a concurrent `up` cannot observe the same
  # pre-creation live-pair list and over-commit the host.
  arm_budget_up_cleanup
  reserve_pair_budget "$name"

  # Resolve the canonical bind sources before any shared DB or pair-directory
  # mutation. A copied/non-Git launcher must fail closed without leaving
  # orphan schemas behind. pair_compose only builds argv and writes the
  # canonical-source .env; it does not contact Docker or require the codebind
  # source directories to exist yet.
  local overlays=()
  [ "$http_mode" = 1 ] && overlays+=(pair.http.yml)
  [ "$journal" = 1 ] && overlays+=(pair.journal.yml)
  [ -n "$codebind" ] && overlays+=(pair.codebind.yml)
  export DUO_PAIR="$name" DUO_PORT1="$port1" DUO_PORT2="$port2" DUO_CODEBIND_PLUGIN="$codebind"
  pair_compose "$name" "${overlays[@]}"

  say "shared infra: MariaDB (duo-db) + duo-shared network"
  ensure_db_up
  ensure_app_user
  pass "shared db up, healthy, wordpress user granted on wp\\_%"

  say "pair '$name': databases"
  create_pair_dbs "$name"
  pass "wp_${name}1, wp_${name}2 exist"

  say "pair '$name': site-repo directories"
  prepare_siterepo_roots "$name"
  local force_recreate=()
  if [ -n "$codebind" ]; then
    # Bootstrap-order requirement inherited from spike G (see
    # pair.codebind.yml's header): the bind-mount SOURCE must exist,
    # host-owned, before any container that mounts it is created.
    mkdir -p "siterepo/${name}1/code/wp-content/plugins/${codebind}" \
             "siterepo/${name}2/code/wp-content/plugins/${codebind}"
    force_recreate=(--force-recreate)
  fi

  say "pair '$name': web containers up"
  # DUO-3277: agent/manifests bind-mount sources always resolve against
  # the canonical checkout (see pair_compose()/canonical_root()), never
  # wherever this script itself was invoked from -- if that resolved
  # differently than whatever config an EXISTING container for this pair
  # was created with (e.g. a pair `up`'d from a worktree before this fix,
  # or from a different worktree than last time), compose's own standard
  # config-drift detection recreates it here automatically, on volumes
  # that never move (the r3b recovery this issue's own filing already
  # documented empirically, now happening for the RIGHT reason instead of
  # by accident).
  if [ "$DUO_AGENT_SRC" != "$(pwd)/agent" ]; then
    echo "  (bind-mount source: $(dirname "$DUO_AGENT_SRC") -- this pair.sh copy is running from $(pwd))"
  fi
  # Keep the codebind contract's force-recreate scoped to the web services,
  # but never create CLI services until the web containers have established
  # pair.yml's nested MU bind mountpoints inside their named volumes.
  "${PAIR_COMPOSE[@]}" up -d "${force_recreate[@]}" wp1 wp2
  wait_web_mountpoints "$name"
  "${PAIR_COMPOSE[@]}" up -d cli1 cli2
  # Once both web and CLI containers exist, the pair is visible to the next
  # strict compose-list query. Release before the potentially long WP
  # install/bootstrap phase; EXIT/signal cleanup still protects failures
  # before this point.
  disarm_budget_up_cleanup
  pass "web mountpoints established; CLI containers up"

  say "pair '$name': waiting for DB-level readiness (both sides)"
  wait_db_ready "$name" 1
  wait_db_ready "$name" 2
  pass "both sides reach their database"

  local url1 url2
  if [ "$http_mode" = 1 ]; then
    url1="http://localhost:${port1}"; url2="http://localhost:${port2}"
  else
    # RFC 2606 .invalid — deliberately unresolvable. Headless pairs publish
    # no host port, and nothing in this design needs siteurl/home to
    # actually resolve for wp-cli to work (most wp-cli commands never make
    # an HTTP round trip to themselves); using a real in-network hostname
    # here would be misleading anyway, since wp1/wp2/cli1/cli2 are the same
    # literal service names across every pair sharing the duo-shared
    # network, and Docker's embedded DNS does not scope those bare-name
    # aliases per compose project on a shared external network — the only
    # cross-pair-safe hostname in this whole design is duo-shared-db's
    # explicit container_name. See docs/sandbox.md.
    url1="http://${name}1.invalid"; url2="http://${name}2.invalid"
  fi

  say "pair '$name': generic WordPress bootstrap (idempotent)"
  install_side 1 "$url1" "Duo ${name}1"
  install_side 2 "$url2" "Duo ${name}2"

  say "pair '$name' ready"
  if [ "$http_mode" = 1 ]; then
    echo "  wp1: $url1 (published on host port $port1)"
    echo "  wp2: $url2 (published on host port $port2)"
  else
    echo "  wp1: $url1 (headless — no host port published)"
    echo "  wp2: $url2 (headless — no host port published)"
  fi
  echo
  echo "  wp-cli invocation pattern for this pair (run from sandbox/):"
  echo "    docker compose -p duo-${name} -f pair.yml $( [ "$journal" = 1 ] && printf -- '-f pair.journal.yml ' )$( [ -n "$codebind" ] && printf -- '-f pair.codebind.yml ' )run --rm cli1 wp <command...>"
  echo "    docker compose -p duo-${name} -f pair.yml $( [ "$journal" = 1 ] && printf -- '-f pair.journal.yml ' )$( [ -n "$codebind" ] && printf -- '-f pair.codebind.yml ' )run --rm cli2 wp <command...>"
  echo "  (the journal/codebind -f flags only matter if the command you're running cares about DUO_JOURNAL or the bound plugin dir; DUO_PAIR=${name} must stay exported, or pass -p duo-${name} and set WORDPRESS_DB_NAME/etc. yourself)"
}

clear_siterepo_root() { # clear_siterepo_root <path>
  local root="$1"

  # Preserve the bind-root inode. Docker's default rprivate bind propagation
  # pins the directory that existed when a container was created; deleting
  # and recreating that directory makes a running ordinary web/site container
  # see stale content forever. Codebind pairs are refused separately because
  # their nested plugin inode would still be replaced. Remove only children
  # in place instead. A
  # pre-existing symlink/non-directory is not a valid disposable root and is
  # removed before the real directory is made.
  if [ -L "$root" ] || { [ -e "$root" ] && [ ! -d "$root" ]; }; then
    rm -rf -- "$root"
  fi
  mkdir -p -- "$root"
  chmod -R ugo+rwX "$root" 2>/dev/null || true
  find "$root" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
  chmod 0777 "$root"
}

refuse_codebind_reset() { # refuse_codebind_reset <name>
  local name="$1" container mounts source destination existing

  # pair.codebind.yml adds a nested plugin bind source whose inode is pinned
  # independently of /siterepo/<name>. Clearing that nested directory in place
  # would still leave a running/stopped container attached to stale code, so
  # reset has an explicit fail-closed contract for codebind pairs. Destroy the
  # pair and bring it back with --codebind after the clean-room reset instead.
  if ! existing="$(docker ps -a --format '{{.Names}}' 2>/dev/null)"; then
    fail "could not enumerate pair containers before reset; refusing without a verified codebind check"
  fi
  for container in "duo-${name}-wp1-1" "duo-${name}-wp2-1" \
                   "duo-${name}-cli1-1" "duo-${name}-cli2-1"; do
    if ! printf '%s\n' "$existing" | grep -Fqx -- "$container"; then
      continue
    fi
    if ! mounts="$(docker inspect "$container" \
      --format '{{range .Mounts}}{{.Source}}{{"\t"}}{{.Destination}}{{"\n"}}{{end}}' \
      2>/dev/null)"; then
      fail "could not inspect existing pair container $container before reset; refusing without a verified codebind check"
    fi
    while IFS=$'\t' read -r source destination; do
      [ -n "${destination:-}" ] || continue
      case "$destination" in
        /var/www/html/wp-content/plugins/*)
          fail "pair '$name' has a codebind mount on $container ($source -> $destination); reset is refused because Docker pins that nested inode. Run 'pair.sh destroy $name' then 'pair.sh up $name <port1> <port2> --codebind <plugin-dir>'"
          ;;
      esac
    done <<< "$mounts"
  done
}

cmd_reset() {
  local name="${1:?usage: pair.sh reset <name>}"
  validate_name "$name"
  refuse_codebind_reset "$name"
  ensure_db_up

  say "pair '$name': reset"
  drop_pair_dbs "$name"
  create_pair_dbs "$name"
  clear_siterepo_root "siterepo/${name}1"
  clear_siterepo_root "siterepo/${name}2"
  rm -rf -- "siterepo/origin-${name}.git"
  prepare_siterepo_roots "$name"
  pass "wp_${name}1/wp_${name}2 dropped + recreated empty; siterepo/${name}{1,2} cleared in place and origin-${name}.git removed"
  echo "  reset covers: both databases (DROP/CREATE) and the site-repo contents"
  echo "  (siterepo/${name}{1,2}, origin-${name}.git). The two ordinary site-repo"
  echo "  root inodes are preserved; reset refuses while a codebind mount exists"
  echo "  because its nested plugin inode is independently pinned. It does NOT touch the wp1/wp2"
  echo "  webroot volumes and does NOT restart containers or reinstall WordPress —"
  echo "  the next wp-cli call against this pair sees an empty, uninstalled site."
  echo "  Re-run 'pair.sh up ${name} <port1> <port2> ...' (or your script's own"
  echo "  install_env) before using it again."
}

cmd_stop() {
  # Release-without-destroy: a stopped pair frees ALL of its RAM and CPU
  # (idle Apache+MariaDB churn is real — measured ~10-15MiB + fractional
  # CPU per container even at rest) while keeping containers, webroot
  # volumes, and this pair's databases exactly as they are. This is the
  # verb the LINEAR-LOOP resource-lifecycle rule wants while an agent is
  # polling/waiting/blocked rather than actively executing against the
  # pair (docs/agents/linear-loop.md).
  local name="${1:?usage: pair.sh stop <name>}"
  validate_name "$name"
  say "pair '$name': stop (free RAM/CPU; containers, volumes, databases all kept)"
  pair_compose "$name"
  export DUO_PAIR="$name"
  "${PAIR_COMPOSE[@]}" stop
  pass "stopped — resume with: pair.sh start $name"
}

cmd_start() {
  # Resume a stopped pair. compose start reuses the EXISTING containers
  # (same ports, same overlay config they were created with), so none of
  # up's flags or port args are needed — and none can be changed here; a
  # config change means destroy + up.
  local name="${1:?usage: pair.sh start <name>}"
  validate_name "$name"
  arm_budget_up_cleanup
  reserve_pair_budget "$name"
  # Keep the existing resume contract: the shared MariaDB must be healthy
  # before a stopped pair is started. The reservation is already held, so a
  # concurrent up/start cannot over-commit while this prerequisite runs.
  ensure_db_up
  check_dead_mounts "$name"
  say "pair '$name': start (state exactly as it was at stop)"
  pair_compose "$name"
  export DUO_PAIR="$name"
  "${PAIR_COMPOSE[@]}" start
  wait_pair_visible "$name"
  disarm_budget_up_cleanup
  pass "running again — same ports/config as before the stop"
}

cmd_destroy() {
  local name="${1:?usage: pair.sh destroy <name>}"
  validate_name "$name"

  say "pair '$name': destroy"
  pair_compose "$name"
  export DUO_PAIR="$name"
  "${PAIR_COMPOSE[@]}" down -v --remove-orphans
  ensure_db_up
  drop_pair_dbs "$name"
  pass "containers + webroot volumes removed; wp_${name}1/wp_${name}2 dropped"
  echo "  siterepo/${name}{1,2} left on disk untouched — remove by hand if you want it gone too."
}

cmd_list() {
  say "live sandbox pairs (duo-* compose projects, excluding duo-db)"
  local pairs
  # The reservation performs exactly one live query while holding the shared
  # lock. Reuse that result instead of first enumerating outside the gate.
  arm_budget_up_cleanup
  reserve_pair_budget ""
  pairs="$PAIR_BUDGET_LIVE_PAIRS"
  disarm_budget_up_cleanup
  if [ -z "$pairs" ]; then
    echo "  (none)"
  else
    printf '%s\n' "$pairs" | sed 's/^/  - /'
  fi

  say "stopped pairs (kept, zero footprint — resume with: pair.sh start <name>)"
  local stopped
  stopped=$(stopped_pairs)
  if [ -z "$stopped" ]; then
    echo "  (none)"
  else
    printf '%s\n' "$stopped" | sed 's/^/  - /'
  fi

  say "shared db"
  if docker inspect "$DB_CONTAINER" >/dev/null 2>&1; then
    echo "  ${DB_CONTAINER}: $(docker inspect -f '{{.State.Status}} ({{.State.Health.Status}})' "$DB_CONTAINER")"
  else
    echo "  ${DB_CONTAINER}: not running"
  fi
}

usage() {
  cat <<'USAGE'
usage:
  pair.sh up <name> <port1> <port2> [--journal] [--codebind <plugin-dir>] [--http|--headless]
  pair.sh reset <name>
  pair.sh stop <name>
  pair.sh start <name>
  pair.sh destroy <name>
  pair.sh list

  up       Bring up (or converge) a pair. Idempotent: ensures the shared
           MariaDB is up, creates this pair's two databases, brings up
           wp1/wp2, waits for their nested MU mountpoints, then brings up
           cli1/cli2 and waits for DB-level readiness on both sides,
           runs the generic WordPress bootstrap (core install, theme,
           permalinks, .htaccess) on each side if not already installed,
           then prints the wp-cli invocation pattern for the pair.
             --journal          turn on DUO_JOURNAL (pair.journal.yml)
             --codebind <dir>   bind wp-content/plugins/<dir> from this
                                 pair's own siterepo/<name>{1,2}/code/ tree
                                 (pair.codebind.yml; spike G's pattern)
             --http             publish wp1/wp2 on <port1>/<port2> (default)
             --headless         don't publish any host port for this pair

  reset    DROP/CREATE this pair's two databases + clear ordinary site-repo
           contents in place. Refuses if a live/stopped codebind mount is
           detected (destroy + up --codebind is the safe clean-room path).
           Does NOT touch webroot volumes, restart containers, or reinstall
           WordPress.

  stop     Free the pair's RAM/CPU without losing anything: containers
           stopped, webroot volumes and databases untouched. Use while
           polling/waiting/blocked instead of leaving the pair hot.

  start    Resume a stopped pair exactly as it was (same ports/config —
           compose start reuses the existing containers).

  destroy  compose -p down -v (containers + webroot volumes) + drop this
           pair's two databases. Site-repo directories are left on disk.

  list     Show live pairs, stopped pairs, and the shared db's status;
           warns if crowded.

Names: lowercase letters/digits only, starting with a letter (no
hyphens/underscores) — used bare as both a MySQL database-name fragment
and a docker compose project suffix.
USAGE
}

case "${1:-}" in
  up)      shift; cmd_up "$@" ;;
  reset)   shift; cmd_reset "$@" ;;
  stop)    shift; cmd_stop "$@" ;;
  start)   shift; cmd_start "$@" ;;
  destroy) shift; cmd_destroy "$@" ;;
  list)    shift; cmd_list "$@" ;;
  -h|--help|"") usage ;;
  *) echo "unknown subcommand '$1'" >&2; usage >&2; exit 1 ;;
esac
