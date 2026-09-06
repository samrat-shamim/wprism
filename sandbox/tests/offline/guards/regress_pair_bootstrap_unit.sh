#!/usr/bin/env bash
# Offline regression for pair.sh's two-phase container bootstrap.
#
# The pair's named webroot volume must be initialized by wp1/wp2 before the
# nested WPrism MU directory/file mounts are visible to cli1/cli2. This harness
# runs the real pair.sh in a temporary copied sandbox with a fake `docker`
# executable: it proves the compose service/flag ordering without contacting
# Docker, MariaDB, or the repository's persistent siterepo directories.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/wprism-pair-bootstrap.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT
# issue #3438: resolve to the physical path once, up front, the same way
# issue #3420's pair_siterepo_host_one() resolves its own bind-mount source
# (`cd "$(dirname "$root")" && pwd -P`) -- two independent mismatches this
# collapses into one no-op comparison: macOS's $TMPDIR sits under
# /var/folders, and /var -> /private/var is an OS-provided symlink (the same
# class issue #3432 hit for /tmp); separately, macOS's $TMPDIR carries a
# trailing slash, so plain `mktemp -d "$TMPDIR/wprism-pair-bootstrap.XXXXXX"`
# above yields a doubled slash (".../T//wprism-pair-bootstrap...", visible in
# the pre-fix failure text) that `pwd -P` also normalizes away. Every path
# this suite builds from $TMP must already be canonical so a later `pwd -P`
# inside pair.sh is a no-op against it, not a silent second resolution the
# fixed-string assertions below never anticipated. A no-op wherever $TMPDIR
# has no symlink alias and no trailing slash to begin with.
TMP="$(cd "$TMP" && pwd -P)"
ORIGINAL_PATH="$PATH"
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

inode_of() {
  local path="$1" inode
  if inode="$(stat -c '%i' "$path" 2>/dev/null)"; then
    : # GNU stat.
  elif inode="$(stat -f '%i' "$path" 2>/dev/null)"; then
    : # BSD stat (macOS).
  else
    fail "cannot read inode for $path"
  fi
  case "$inode" in
    ''|*[!0-9]*) fail "stat returned a malformed inode for $path: $inode" ;;
  esac
  printf '%s\n' "$inode"
}

physical_path() {
  # pair.sh resolves a bind source with `pwd -P`, rather than preserving the
  # caller's lexical spelling. On macOS /tmp is /private/tmp, so assertions
  # against a mktemp path must name the physical directory Docker receives.
  local path="$1" directory base
  directory="$(cd -P "$(dirname "$path")" && pwd -P)" \
    || fail "cannot resolve physical path for $path"
  base="$(basename "$path")"
  printf '%s/%s\n' "$directory" "$base"
}

assert_file_contains() {
  local file="$1" needle="$2" message="$3"
  grep -F -- "$needle" "$file" >/dev/null || fail "$message (missing: $needle)"
}

line_for() {
  local file="$1" needle="$2"
  grep -nF -- "$needle" "$file" | head -n 1 | cut -d: -f1 || true
}

assert_before() {
  local file="$1" first="$2" second="$3" first_line second_line
  first_line="$(line_for "$file" "$first")"
  second_line="$(line_for "$file" "$second")"
  [ -n "$first_line" ] || fail "missing first ordered call: $first"
  [ -n "$second_line" ] || fail "missing second ordered call: $second"
  [ "$first_line" -lt "$second_line" ] || fail "expected '$first' before '$second'"
}

write_fake_docker() {
  local fake_bin="$1"
  cat > "$fake_bin/docker" <<'FAKE_DOCKER'
#!/usr/bin/env bash
set -euo pipefail
log="${WPRISM_PAIR_TEST_LOG:?}"
{
  printf 'docker'
  for arg in "$@"; do printf ' <%s>' "$arg"; done
  printf ' env[WPRISM_AGENT_SRC]=%s env[WPRISM_ADAPTER_PACKAGES_SRC]=%s env[WPRISM_PLATFORM_SRC]=%s\n' \
    "${WPRISM_AGENT_SRC:-}" "${WPRISM_ADAPTER_PACKAGES_SRC:-}" "${WPRISM_PLATFORM_SRC:-}"
} >> "$log"

# The artifact-cache branch intentionally executes the real bounded shell
# command emitted by fetch-artifact.sh, but redirects its container mount to
# this regression's private directory. Every other compose command remains a
# no-op recorder below.
if [ -n "${WPRISM_PAIR_TEST_ARTIFACT_CACHE:-}" ]; then
  args=("$@")
  for index in "${!args[@]}"; do
    if [ "${args[$index]}" = sh ] && [[ "${args[$((index + 1))]:-}" == */artifact-cache-fetch.sh ]]; then
      export WPRISM_ARTIFACT_TEST_MODE=1 WPRISM_ARTIFACT_TEST_CACHE_ROOT="$WPRISM_PAIR_TEST_ARTIFACT_CACHE"
      args[$((index + 1))]="${WPRISM_PAIR_TEST_ARTIFACT_RUNNER:?}"
      "${args[@]:$index}"
      exit $?
    fi
  done
fi

# lease-batch-acquire's real database census and ordinary pair lifecycle SQL
# share pair_db_sql(). Model the census markers exactly; every other admin
# statement remains a successful no-op as it was before this branch existed.
if [ "${1:-}" = exec ]; then
  sql="$(cat 2>/dev/null || true)"
  if grep -Fq '__WPRISM_PAIR_SCHEMA_COUNT__' <<<"$sql"; then
    rows="${WPRISM_PAIR_TEST_SCHEMAS:-}"
    count="$(printf '%s\n' "$rows" | awk 'NF { count++ } END { print count + 0 }')"
    printf 'wprism_pair_schema_count\n__WPRISM_PAIR_SCHEMA_COUNT__:%s\n' "$count"
    if [ "$count" -gt 0 ]; then
      printf 'wprism_pair_schema_found\n'
      while IFS= read -r schema; do
        [ -n "$schema" ] && printf '__WPRISM_PAIR_SCHEMA_FOUND__:%s\n' "$schema"
      done <<<"$rows"
    fi
  fi
  exit 0
fi

if [ "${WPRISM_PAIR_TEST_FAIL_REPO_HANDOFF:-0}" = 1 ]; then
  case " $* " in
    *" run --rm -u root --mount "*)
      printf 'fake exact-root ownership handback failure\n' >&2
      exit 47
      ;;
  esac
fi

# The ownership-proof fixture needs its fake stat readback to change only
# after pair.sh has issued its one exact-root-plus-probe handback command.
# Touching this test-owned marker models that ordering without changing either
# fixture repository tree.
if [ -n "${WPRISM_PAIR_TEST_REPO_HANDOFF_MARKER:-}" ]; then
  case " $* " in
    *" run --rm -u root --mount "*) : > "$WPRISM_PAIR_TEST_REPO_HANDOFF_MARKER" ;;
  esac
fi

# Optional theme state makes the real pair bootstrap's retry contract
# fault-injectable without Docker or WordPress.org. The fake records an
# installed/active theme only after the configured number of exact install
# failures; activation cannot fabricate an install that never completed.
theme_state_dir="${WPRISM_PAIR_TEST_THEME_STATE_DIR:-}"
if [ -n "$theme_state_dir" ]; then
  mkdir -p "$theme_state_dir"
  case " $* " in
    *" cli1 wp core is-installed "*) [ -f "$theme_state_dir/core1" ]; exit $? ;;
    *" cli2 wp core is-installed "*) [ -f "$theme_state_dir/core2" ]; exit $? ;;
    # issue #3412: NOOP models the issue #3381 shape at the bootstrap layer — wp-cli
    # exits 0 while the database still has no WordPress in it, so every later
    # is-installed answer stays FALSE. Default 0 leaves every pre-existing
    # case's install semantics untouched.
    *" cli1 wp core install "*)
      [ "${WPRISM_PAIR_TEST_CORE_INSTALL_NOOP:-0}" = 1 ] || : > "$theme_state_dir/core1"
      exit 0 ;;
    *" cli2 wp core install "*)
      [ "${WPRISM_PAIR_TEST_CORE_INSTALL_NOOP:-0}" = 1 ] || : > "$theme_state_dir/core2"
      exit 0 ;;
    *" wp theme install twentytwentyone --activate "*)
      attempts=0
      [ ! -f "$theme_state_dir/attempts" ] || attempts="$(cat "$theme_state_dir/attempts")"
      attempts=$((attempts + 1))
      printf '%s\n' "$attempts" > "$theme_state_dir/attempts"
      if [ "$attempts" -le "${WPRISM_PAIR_TEST_THEME_FAILURES:-0}" ]; then
        printf 'fake transient WordPress.org theme lookup failure\n' >&2
        exit 37
      fi
      : > "$theme_state_dir/installed"
      : > "$theme_state_dir/active"
      exit 0
      ;;
    *" wp theme install /artifacts-cache/theme-twentytwentyone-"*" --force "*)
      rm -f "$theme_state_dir/installed" "$theme_state_dir/active"
      : > "$theme_state_dir/archive-installed"
      exit 0
      ;;
    *" sh /wprism-harness/artifact-archive-root.sh /var/www/html/wp-content/themes fixture-theme-source twentytwentyone "*)
      [ -f "$theme_state_dir/archive-installed" ] || {
        printf 'fake pinned archive root was not installed\n' >&2
        exit 39
      }
      : > "$theme_state_dir/normalized"
      : > "$theme_state_dir/installed"
      exit 0
      ;;
    *" wp theme activate twentytwentyone "*)
      [ -f "$theme_state_dir/installed" ] || {
        printf 'fake theme is not installed\n' >&2
        exit 38
      }
      : > "$theme_state_dir/active"
      exit 0
      ;;
    *" wp theme list --status=active --field=name "*)
      [ ! -f "$theme_state_dir/active" ] || printf 'twentytwentyone\n'
      exit 0
      ;;
    *" wp theme get twentytwentyone --field=version "*)
      printf '2.8\n'
      exit 0
      ;;
  esac
fi

if [ "${1:-}" = inspect ]; then
  if [ "${WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER:-}" = "${2:-}" ]; then
    printf 'fake docker inspect failure\n' >&2
    exit 31
  fi
  if [ "${3:-}" = --format ]; then
    printf '%s\n' "${WPRISM_PAIR_TEST_INSPECT_MOUNTS:-}"
  else
    case "${3:-}" in
      "{{.State.Health.Status}}") printf 'healthy\n' ;;
      "{{.State.Status}} ({{.State.Health.Status}})") printf 'running (healthy)\n' ;;
    esac
  fi
elif [ "${1:-}" = info ]; then
  if [ "${WPRISM_PAIR_TEST_FAIL_INFO:-0}" = 1 ]; then
    printf 'fake docker info failure\n' >&2
    exit 17
  fi
  case "${3:-}" in
    "{{.NCPU}}") printf '%s\n' "${WPRISM_PAIR_TEST_CPU:-8}" ;;
    "{{.MemTotal}}") printf '%s\n' "${WPRISM_PAIR_TEST_MEM:-8589934592}" ;;
  esac
elif [ "${1:-}" = ps ]; then
  if [ "${WPRISM_PAIR_TEST_FAIL_PS:-0}" = 1 ]; then
    printf 'fake docker ps failure\n' >&2
    exit 29
  fi
  if [ "${WPRISM_PAIR_TEST_FAIL_RAW_CENSUS:-}" = container ] && [[ " $* " == *" --filter label=com.docker.compose.project="* ]]; then
    printf 'fake raw container census failure\n' >&2
    exit 48
  fi
  if [ -n "${WPRISM_PAIR_TEST_RAW_CONTAINER_PROJECT:-}" ] \
      && [[ " $* " == *" --filter label=com.docker.compose.project=wprism-${WPRISM_PAIR_TEST_RAW_CONTAINER_PROJECT} "* ]]; then
    printf 'deadc0ffee01\n'
  else
    printf '%s\n' "${WPRISM_PAIR_TEST_CONTAINERS:-}"
  fi
elif [ "${1:-}" = volume ] && [ "${2:-}" = ls ]; then
  if [ "${WPRISM_PAIR_TEST_FAIL_RAW_CENSUS:-}" = volume ]; then
    printf 'fake raw volume census failure\n' >&2
    exit 49
  fi
  if [ -n "${WPRISM_PAIR_TEST_RAW_VOLUME_PROJECT:-}" ] \
      && [[ " $* " == *" --filter label=com.docker.compose.project=wprism-${WPRISM_PAIR_TEST_RAW_VOLUME_PROJECT} "* ]]; then
    printf 'wprism_%s_orphan\n' "$WPRISM_PAIR_TEST_RAW_VOLUME_PROJECT"
  fi
elif [ "${1:-}" = network ] && [ "${2:-}" = ls ]; then
  if [ "${WPRISM_PAIR_TEST_FAIL_RAW_CENSUS:-}" = network ]; then
    printf 'fake raw network census failure\n' >&2
    exit 50
  fi
  if [ -n "${WPRISM_PAIR_TEST_RAW_NETWORK_PROJECT:-}" ] \
      && [[ " $* " == *" --filter label=com.docker.compose.project=wprism-${WPRISM_PAIR_TEST_RAW_NETWORK_PROJECT} "* ]]; then
    printf 'deadc0ffee02\n'
  fi
elif [ "${1:-}" = compose ] && [ "${2:-}" = ls ]; then
  if [ "${WPRISM_PAIR_TEST_FAIL_LIVE:-0}" = 1 ]; then
    printf 'fake compose ls failure\n' >&2
    exit 23
  fi
  race_gate="${WPRISM_PAIR_TEST_RACE_GATE:-}"
  if [ -n "$race_gate" ] && mkdir "$race_gate/first" 2>/dev/null; then
    : > "$race_gate/first-ready"
    while [ ! -e "$race_gate/release" ]; do sleep 0.02; done
  fi
  if [ -n "${WPRISM_PAIR_TEST_LIVE_FILE:-}" ] && [ -f "$WPRISM_PAIR_TEST_LIVE_FILE" ]; then
    cat "$WPRISM_PAIR_TEST_LIVE_FILE"
  else
    printf '%s\n' "${WPRISM_PAIR_TEST_LIVE_PAIRS:-[]}"
  fi
elif [ "${1:-}" = compose ]; then
  project="" has_wp1=0 has_wp2=0 has_up=0 has_start=0 previous=""
  for arg in "$@"; do
    [ "$previous" = -p ] && project="$arg"
    [ "$arg" = up ] && has_up=1
    [ "$arg" = start ] && has_start=1
    [ "$arg" = wp1 ] && has_wp1=1
    [ "$arg" = wp2 ] && has_wp2=1
    previous="$arg"
  done
  if [ "$has_up" = 1 ] && [ "$has_wp1" = 1 ] && [ "$has_wp2" = 1 ] && \
     [ "${WPRISM_PAIR_TEST_FAIL_WEB_UP:-0}" = 1 ]; then
    printf 'fake partial web up failure\n' >&2
    exit 61
  fi
  if [ "$has_up" = 1 ] && [ "$has_wp1" = 1 ] && [ "$has_wp2" = 1 ] && \
     [ -n "${WPRISM_PAIR_TEST_LIVE_FILE:-}" ]; then
    printf '[{"ConfigFiles":"/fake/pair.yml","Name":"%s"}]\n' "$project" > "$WPRISM_PAIR_TEST_LIVE_FILE"
  fi
  if [ "$has_start" = 1 ] && [ -n "${WPRISM_PAIR_TEST_LIVE_FILE:-}" ]; then
    printf '[{"ConfigFiles":"/fake/pair.yml","Name":"%s"}]\n' "$project" > "$WPRISM_PAIR_TEST_LIVE_FILE"
  fi
fi
FAKE_DOCKER
  chmod +x "$fake_bin/docker"
}

write_fake_git() {
  local fake_bin="$1"
  cat > "$fake_bin/git" <<'FAKE_GIT'
#!/usr/bin/env bash
set -euo pipefail
if [ "${1:-}" = -C ]; then
  requested="${2:?}"
  shift 2
  if [ "${1:-}" = rev-parse ]; then
    case " $* " in
      *" --show-toplevel "*) (cd "$requested" && pwd -P); exit $? ;;
      *" --path-format=absolute --git-common-dir "*)
        printf '%s/.git\n' "${WPRISM_PAIR_TEST_CANONICAL_ROOT:?}"
        exit 0
        ;;
    esac
  fi
  exit 1
fi
if [ "${1:-}" = rev-parse ]; then
  printf '%s/.git\n' "${WPRISM_PAIR_TEST_CANONICAL_ROOT:?}"
  exit 0
fi
exit 1
FAKE_GIT
  chmod +x "$fake_bin/git"
}

# The repository handback proof uses a real pair.sh path with a fake Docker
# plus a fake host stat ABI. The fake switches root readback after Docker and
# after an exact-root host chgrp, so this no-daemon suite exercises native and
# Docker Desktop GID behavior without inventing a sibling capability probe.
write_fake_owner_identity_tools() {
  local fake_bin="$1"
  cat > "$fake_bin/id" <<'FAKE_ID'
#!/usr/bin/env bash
set -euo pipefail
case "${1:-}" in
  -u) printf '%s\n' "${WPRISM_PAIR_TEST_HOST_UID:?}" ;;
  -g) printf '%s\n' "${WPRISM_PAIR_TEST_HOST_GID:?}" ;;
  *) exit 64 ;;
esac
FAKE_ID
  cat > "$fake_bin/stat" <<'FAKE_STAT'
#!/usr/bin/env bash
set -euo pipefail
flavor="${WPRISM_PAIR_TEST_STAT_FLAVOR:?}"
format="${2:-}"
path="${3:-}"
is_root() {
  [ "$1" = "${WPRISM_PAIR_TEST_STAT_ROOT:?}" ] || [ "$1" = "${WPRISM_PAIR_TEST_STAT_ROOT_LEXICAL:?}" ]
}
owner_after() {
  is_root "$1" || exit 64
  [ -e "${WPRISM_PAIR_TEST_REPO_HANDOFF_MARKER:?}" ] || exit 64
  if [ -e "${WPRISM_PAIR_TEST_ROOT_NORMALIZED_MARKER:?}" ]; then
    printf '%s\n' "${WPRISM_PAIR_TEST_STAT_ROOT_OWNER_NORMALIZED:?}"
  else
    printf '%s\n' "${WPRISM_PAIR_TEST_STAT_ROOT_OWNER_AFTER_DOCKER:?}"
  fi
}
inode_at_phase() {
  is_root "$1" || exit 64
  if [ -e "${WPRISM_PAIR_TEST_ROOT_NORMALIZED_MARKER:?}" ]; then
    printf '%s\n' "${WPRISM_PAIR_TEST_STAT_ROOT_INODE_AFTER_NORMALIZED:?}"
  elif [ -e "${WPRISM_PAIR_TEST_REPO_HANDOFF_MARKER:?}" ]; then
    printf '%s\n' "${WPRISM_PAIR_TEST_STAT_ROOT_INODE_AFTER_DOCKER:?}"
  else
    printf '%s\n' "${WPRISM_PAIR_TEST_STAT_ROOT_INODE_BEFORE:?}"
  fi
}
case "${1:-}" in
  -c)
    [ "$flavor" = gnu ] || exit 1
    case "$format" in
      '%u:%g') owner_after "$path" ;;
      '%i') inode_at_phase "$path" ;;
      *) exit 64 ;;
    esac
    ;;
  -f)
    [ "$flavor" = bsd ] || exit 1
    case "$format" in
      '%u:%g') owner_after "$path" ;;
      '%i') inode_at_phase "$path" ;;
      *) exit 64 ;;
    esac
    ;;
  *) exit 64 ;;
esac
FAKE_STAT
  cat > "$fake_bin/chgrp" <<'FAKE_CHGRP'
#!/usr/bin/env bash
set -euo pipefail
log="${WPRISM_PAIR_TEST_CHGRP_LOG:?}"
{
  printf 'chgrp'
  for arg in "$@"; do printf ' <%s>' "$arg"; done
  printf '\n'
} >> "$log"
[ "${WPRISM_PAIR_TEST_FAIL_ROOT_CHGRP:-0}" = 0 ] || exit 73
[ "${1:-}" = -h ] && [ "${2:-}" = "${WPRISM_PAIR_TEST_HOST_GID:?}" ] \
  && [ "${3:-}" = "${WPRISM_PAIR_TEST_STAT_ROOT:?}" ] && [ "$#" = 3 ] || exit 64
: > "${WPRISM_PAIR_TEST_ROOT_NORMALIZED_MARKER:?}"
if [ "${WPRISM_PAIR_TEST_REPLACE_ROOT_WITH_SYMLINK:-0}" = 1 ]; then
  mv "${WPRISM_PAIR_TEST_STAT_ROOT:?}" "${WPRISM_PAIR_TEST_REPLACED_ROOT:?}"
  ln -s "${WPRISM_PAIR_TEST_REPLACEMENT_TARGET:?}" "${WPRISM_PAIR_TEST_STAT_ROOT:?}"
fi
FAKE_CHGRP
  chmod +x "$fake_bin/id" "$fake_bin/stat" "$fake_bin/chgrp"
}

install_python_lock_path() {
  local fake_bin="$1" utility
  for utility in bash python3 jq awk mkdir grep sed tr seq sleep chmod find rm dirname cat mktemp mkfifo rmdir; do
    ln -s "$(command -v "$utility")" "$fake_bin/$utility"
  done
}

pid_running() {
  local pid="$1" state
  kill -0 "$pid" 2>/dev/null || return 1
  if [ -r "/proc/$pid/stat" ]; then
    state="$(awk '{print $3}' "/proc/$pid/stat" 2>/dev/null || true)"
  else
    # macOS has no procfs. Its `kill -0` still succeeds for an unreaped
    # zombie, which made the SIGKILL contender check wait for its full
    # deadline even though the contender had exited. `ps` is available on
    # both host families this offline harness supports; only an explicit
    # zombie state means "not running", while an unavailable observation
    # conservatively leaves the process live for the caller's deadline.
    state="$(ps -o stat= -p "$pid" 2>/dev/null | tr -d '[:space:]' || true)"
  fi
  case "$state" in
    Z*) return 1 ;;
  esac
  return 0
}

wait_pid_exit() {
  local pid="$1" attempts="${2:-200}" index
  for index in $(seq 1 "$attempts"); do
    pid_running "$pid" || return 0
    sleep 0.01
  done
  return 1
}

run_pid_running_zombie_case() {
  local label=pid_running_zombie parent_pid zombie_pid="" i
  # Keep a child zombie beneath a deliberately non-reaping Python parent.
  # This deterministically exposes macOS's `kill -0` behaviour without
  # touching a real pair: the former helper called it live and therefore
  # made the Python contender SIGKILL assertion timing-dependent.
  python3 -c 'import os, time; child = os.fork(); os._exit(0) if child == 0 else time.sleep(5)' &
  parent_pid=$!
  for i in $(seq 1 100); do
    zombie_pid="$(ps -Ao pid=,ppid=,stat= | awk -v parent="$parent_pid" '$2 == parent && $3 ~ /^Z/ { print $1; exit }')"
    [ -n "$zombie_pid" ] && break
    sleep 0.01
  done
  [ -n "$zombie_pid" ] \
    || { wait "$parent_pid" 2>/dev/null || true; fail "$label could not create its controlled zombie probe"; }
  kill -0 "$zombie_pid" 2>/dev/null \
    || { wait "$parent_pid" 2>/dev/null || true; fail "$label probe was not retained as a kill-0-visible zombie"; }
  if ! wait_pid_exit "$zombie_pid" 1; then
    wait "$parent_pid" 2>/dev/null || true
    fail "$label treated a kill-0-visible zombie as a live contender past its exit deadline"
  fi
  wait "$parent_pid" 2>/dev/null || true
  pass "$label: kill-0-visible zombies count as exited on hosts without procfs"
}

flock_waiter_for_path() {
  local lock_path="$1" proc target command
  [ -d /proc ] || return 1
  for proc in /proc/[0-9]*; do
    target="$(readlink "$proc/fd/9" 2>/dev/null || true)"
    [ "$target" = "$lock_path" ] || continue
    command="$(tr '\0' ' ' < "$proc/cmdline" 2>/dev/null || true)"
    case "$command" in
      *flock*) printf '%s: %s\n' "${proc##*/}" "$command"; return 0 ;;
    esac
  done
  return 1
}

abort_lock_cancellation_case() {
  local gate="$1" first_pid="$2" contender_pid="$3" message="$4" pid
  : > "$gate/release"
  for pid in "$contender_pid" "$first_pid"; do
    if pid_running "$pid"; then
      kill -KILL "$pid" 2>/dev/null || true
    fi
    wait "$pid" 2>/dev/null || true
  done
  fail "$message"
}

copy_pair_launcher() { # copy_pair_launcher <sandbox-bin-dir>
  local bin_dir="$1"
  mkdir -p "$bin_dir/../lib"
  cp "$ROOT/sandbox/bin/pair.sh" "$bin_dir/pair.sh"
  cp "$ROOT/sandbox/lib/pair_identity.sh" "$bin_dir/../lib/pair_identity.sh"
  cp "$ROOT/sandbox/lib/pair_budget_lock.sh" "$bin_dir/../lib/pair_budget_lock.sh"
  cp "$ROOT/sandbox/lib/pair_force_hatch.sh" "$bin_dir/../lib/pair_force_hatch.sh"
  cp "$ROOT/sandbox/lib/pair_db.sh" "$bin_dir/../lib/pair_db.sh"
  cp "$ROOT/sandbox/lib/pair_compose.sh" "$bin_dir/../lib/pair_compose.sh"
  cp "$ROOT/sandbox/lib/pair_lease.sh" "$bin_dir/../lib/pair_lease.sh"
  cp "$ROOT/sandbox/lib/pair_readiness.sh" "$bin_dir/../lib/pair_readiness.sh"
  cp "$ROOT/sandbox/lib/pair_bootstrap.sh" "$bin_dir/../lib/pair_bootstrap.sh"
  cp "$ROOT/sandbox/lib/pair_siterepo.sh" "$bin_dir/../lib/pair_siterepo.sh"
}

copy_artifact_library_runtime() { # copy_artifact_library_runtime <case-root>
  local case_root="$1"
  mkdir -p "$case_root/adapter-packages" "$case_root/platform/artifact-library" \
    "$case_root/tools/src"
  cp "$ROOT/sandbox/bin/fetch-artifact.sh" "$case_root/sandbox/bin/fetch-artifact.sh"
  cp "$ROOT/sandbox/bin/artifact-library.sh" "$case_root/sandbox/bin/artifact-library.sh"
  cp "$ROOT/tools/artifact-library.php" "$case_root/tools/artifact-library.php"
  cp "$ROOT/tools/src/ArtifactLibrary.php" "$case_root/tools/src/ArtifactLibrary.php"
  cp "$ROOT/platform/artifact-library/artifacts.lock.json" \
    "$case_root/platform/artifact-library/artifacts.lock.json"
}

run_pair_lease_namespace_case() {
  local label=pair_lease_namespace case_root="$TMP/pair-lease-namespace"
  local fake_bin="$case_root/fake-bin" pair_tool="$case_root/sandbox/bin/pair.sh"
  local log="$case_root/docker.log" owner_start token owner_token contender_token output schema
  local real_ln real_ps real_rm foreign_tool rollback_token pristine_tool pristine_token
  local lease_dir="$case_root/sandbox/siterepo/.pair-leases"
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  copy_pair_launcher "$case_root/sandbox/bin"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  cat > "$fake_bin/lsof" <<'FAKE_LSOF'
#!/usr/bin/env bash
set -euo pipefail
case " $* " in
  *" -iTCP:${WPRISM_PAIR_TEST_LISTEN_PORT:-absent} "*) exit 0 ;;
esac
exit 1
FAKE_LSOF
  real_ln="$(PATH="$ORIGINAL_PATH" command -v ln)"
  real_ps="$(PATH="$ORIGINAL_PATH" command -v ps)"
  real_rm="$(PATH="$ORIGINAL_PATH" command -v rm)"
  cat > "$fake_bin/ln" <<'FAKE_LN'
#!/usr/bin/env bash
set -euo pipefail
destination=''
for argument in "$@"; do destination="$argument"; done
if [[ "$destination" == */.pair-leases/*.json ]] && [ -n "${WPRISM_PAIR_TEST_LN_FAIL_AT:-}" ]; then
  count=0
  [ ! -f "${WPRISM_PAIR_TEST_LN_STATE:?}" ] || count="$(cat "$WPRISM_PAIR_TEST_LN_STATE")"
  count=$((count + 1))
  printf '%s\n' "$count" > "$WPRISM_PAIR_TEST_LN_STATE"
  [ "$count" -ne "$WPRISM_PAIR_TEST_LN_FAIL_AT" ] || exit 73
fi
exec "${WPRISM_PAIR_TEST_REAL_LN:?}" "$@"
FAKE_LN
  cat > "$fake_bin/rm" <<'FAKE_RM'
#!/usr/bin/env bash
set -euo pipefail
destination=''
for argument in "$@"; do destination="$argument"; done
if [ -n "${WPRISM_PAIR_TEST_RM_FAIL_PATH:-}" ] \
    && [ "$destination" = "$WPRISM_PAIR_TEST_RM_FAIL_PATH" ]; then
  exit 74
fi
exec "${WPRISM_PAIR_TEST_REAL_RM:?}" "$@"
FAKE_RM
  cat > "$fake_bin/ps" <<'FAKE_PS'
#!/usr/bin/env bash
set -euo pipefail
if [[ " $* " == *" -p ${WPRISM_PAIR_TEST_UNOBSERVABLE_PID:-none} "* ]]; then
  exit 2
fi
exec "${WPRISM_PAIR_TEST_REAL_PS:?}" "$@"
FAKE_PS
  chmod +x "$fake_bin/lsof" "$fake_bin/ln" "$fake_bin/rm" "$fake_bin/ps" "$pair_tool"
  : > "$log"
  export PATH="$fake_bin:$ORIGINAL_PATH"
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=8 WPRISM_PAIR_TEST_MEM=8589934592 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$case_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    WPRISM_PAIR_TEST_CONTAINERS='' WPRISM_PAIR_TEST_FAIL_PS=0 WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER='' \
    WPRISM_PAIR_TEST_FAIL_WEB_UP=0 WPRISM_PAIR_TEST_SCHEMAS='' WPRISM_PAIR_TEST_LISTEN_PORT='' \
    WPRISM_PAIR_TEST_RAW_CONTAINER_PROJECT='' WPRISM_PAIR_TEST_RAW_VOLUME_PROJECT='' \
    WPRISM_PAIR_TEST_RAW_NETWORK_PROJECT='' WPRISM_PAIR_TEST_FAIL_RAW_CENSUS='' \
    WPRISM_PAIR_TEST_REAL_LN="$real_ln" WPRISM_PAIR_TEST_REAL_PS="$real_ps" \
    WPRISM_PAIR_TEST_REAL_RM="$real_rm" WPRISM_PAIR_TEST_RM_FAIL_PATH='' \
    WPRISM_PAIR_TEST_LN_FAIL_AT='' WPRISM_PAIR_TEST_LN_STATE="$case_root/ln-state" \
    WPRISM_PAIR_TEST_UNOBSERVABLE_PID=''

  owner_start="$(
    source "$ROOT/sandbox/lib/pair_lease.sh"
    TZ=Pacific/Auckland pair_lease_owner_start "$$"
  )"
  [ -n "$owner_start" ] || fail "$label: could not identify this test process"
  [ "$owner_start" = "$(
    source "$ROOT/sandbox/lib/pair_lease.sh"
    TZ=America/Los_Angeles pair_lease_owner_start "$$"
  )" ] || fail "$label: lease owner start identity changes with the caller timezone"
  token="$(
    # shellcheck source=../../../lib/pair_lease.sh
    source "$ROOT/sandbox/lib/pair_lease.sh"
    pair_lease_token "$$" "$owner_start"
  )"
  contender_token="$(
    source "$ROOT/sandbox/lib/pair_lease.sh"
    pair_lease_token "$$" "$owner_start"
  )"
  [[ "$token" =~ ^[a-f0-9]{32}$ && "$contender_token" =~ ^[a-f0-9]{32}$ && "$token" != "$contender_token" ]] \
    || fail "$label: lease tokens are not collision-resistant 32-hex identities"

  for schema in container volume network; do
    case "$schema" in
      container) export WPRISM_PAIR_TEST_RAW_CONTAINER_PROJECT=rawcontainer ;;
      volume) export WPRISM_PAIR_TEST_RAW_VOLUME_PROJECT=rawvolume ;;
      network) export WPRISM_PAIR_TEST_RAW_NETWORK_PROJECT=rawnetwork ;;
    esac
    output="$case_root/raw-$schema.txt"
    if "$pair_tool" lease-batch-acquire "$token" "$$" "$owner_start" "raw$schema" 9430 9431 \
        >"$output" 2>&1; then
      fail "$label: orphan raw Docker $schema crossed lease admission"
    fi
    grep -Fq "pair 'raw$schema' has existing raw Docker resources labeled" "$output" \
      || fail "$label: raw Docker $schema produced the wrong refusal: $(cat "$output")"
    [ ! -e "$lease_dir/raw$schema.json" ] \
      || fail "$label: raw Docker $schema refusal published a lease"
    export WPRISM_PAIR_TEST_RAW_CONTAINER_PROJECT='' WPRISM_PAIR_TEST_RAW_VOLUME_PROJECT='' \
      WPRISM_PAIR_TEST_RAW_NETWORK_PROJECT=''
  done
  export WPRISM_PAIR_TEST_FAIL_RAW_CENSUS=network
  output="$case_root/raw-census-failure.txt"
  if "$pair_tool" lease-batch-acquire "$token" "$$" "$owner_start" censusfailure 9430 9431 \
      >"$output" 2>&1; then
    fail "$label: failed raw Docker census was interpreted as absence"
  fi
  grep -Fq "could not census raw Docker resources for pair 'censusfailure'" "$output" \
    || fail "$label: failed raw Docker census produced the wrong refusal: $(cat "$output")"
  export WPRISM_PAIR_TEST_FAIL_RAW_CENSUS=''
  pass "$label: raw container, volume and network label census refuses orphans and observation failure"

  for schema in wp_orphan1 wp_orphan2; do
    export WPRISM_PAIR_TEST_SCHEMAS="$schema"
    output="$case_root/$schema.txt"
    if "$pair_tool" lease-batch-acquire "$token" "$$" "$owner_start" orphan 9400 9401 \
        >"$output" 2>&1; then
      fail "$label: orphan schema $schema was adopted"
    fi
    grep -Fq "pair database schema already exists ($schema); pair namespace is not empty" "$output" \
      || fail "$label: orphan schema $schema produced the wrong refusal: $(cat "$output")"
    [ ! -e "$lease_dir/orphan.json" ] \
      || fail "$label: orphan schema refusal published a destructive lease"
  done
  export WPRISM_PAIR_TEST_SCHEMAS=''
  pass "$label: either exact orphan schema refuses before lease publication"

  export WPRISM_PAIR_TEST_SCHEMAS='wp_batchb2'
  output="$case_root/batch-orphan.txt"
  if "$pair_tool" lease-batch-acquire "$token" "$$" "$owner_start" \
      batcha 9410 9411 batchb 9412 9413 >"$output" 2>&1; then
    fail "$label: a later request's orphan schema admitted a partial batch"
  fi
  [ ! -e "$lease_dir/batcha.json" ] && [ ! -e "$lease_dir/batchb.json" ] \
    || fail "$label: batch publication was visible before every schema passed its census"
  export WPRISM_PAIR_TEST_SCHEMAS=''
  pass "$label: the complete batch passes its database census before any lease is published"

  rm -f -- "$WPRISM_PAIR_TEST_LN_STATE"
  export WPRISM_PAIR_TEST_LN_FAIL_AT=2
  output="$case_root/second-publication-failure.txt"
  if "$pair_tool" lease-batch-acquire "$token" "$$" "$owner_start" \
      publishone 9440 9441 publishtwo 9442 9443 >"$output" 2>&1; then
    fail "$label: injected second lease publication unexpectedly succeeded"
  fi
  grep -Fq 'could not publish complete pair lease batch; this acquisition retained no lease' "$output" \
    || fail "$label: second-publication failure produced the wrong refusal: $(cat "$output")"
  [ ! -e "$lease_dir/publishone.json" ] && [ ! -e "$lease_dir/publishtwo.json" ] \
    || fail "$label: second-publication failure retained a partial lease batch"
  if find "$lease_dir" -maxdepth 1 -type d -name ".lease-${token}.*" | grep -q .; then
    fail "$label: second-publication rollback retained private staging"
  fi
  export WPRISM_PAIR_TEST_LN_FAIL_AT=''
  pass "$label: an injected second publication failure rolls back every record in this acquisition"

  rollback_token="$(
    source "$ROOT/sandbox/lib/pair_lease.sh"
    pair_lease_token "$$" "$owner_start"
  )"
  rm -f -- "$WPRISM_PAIR_TEST_LN_STATE"
  export WPRISM_PAIR_TEST_LN_FAIL_AT=2
  export WPRISM_PAIR_TEST_RM_FAIL_PATH="$lease_dir/retainone.json"
  output="$case_root/publication-rollback-failure.txt"
  if "$pair_tool" lease-batch-acquire "$rollback_token" "$$" "$owner_start" \
      retainone 9446 9447 retaintwo 9448 9449 >"$output" 2>&1; then
    fail "$label: publication plus rollback-unlink injection unexpectedly succeeded"
  fi
  grep -Fq "rollback retained cleanup authority for token $rollback_token at: $lease_dir/retainone.json" "$output" \
    || fail "$label: rollback-unlink failure hid retained cleanup authority: $(cat "$output")"
  [ -e "$lease_dir/retainone.json" ] && [ ! -e "$lease_dir/retaintwo.json" ] \
    || fail "$label: rollback-unlink fixture did not retain exactly the first published record"
  if find "$lease_dir" -maxdepth 1 -type d -name ".lease-${rollback_token}.*" | grep -q .; then
    fail "$label: rollback-unlink failure retained private staging in addition to explicit authority"
  fi
  export WPRISM_PAIR_TEST_LN_FAIL_AT='' WPRISM_PAIR_TEST_RM_FAIL_PATH=''
  "$real_rm" -f -- "$lease_dir/retainone.json"
  pass "$label: failed rollback names the exact retained token and cleanup-authority record"

  mkdir -p "$case_root/sandbox/siterepo"
  : > "$case_root/sandbox/siterepo/.marked1.needs-install"
  output="$case_root/marker.txt"
  if "$pair_tool" lease-batch-acquire "$token" "$$" "$owner_start" marked 9402 9403 \
      >"$output" 2>&1; then
    fail "$label: existing needs-install marker was adopted"
  fi
  grep -Fq "pair 'marked' has existing site state at $case_root/sandbox/siterepo/.marked1.needs-install" "$output" \
    || fail "$label: install marker produced the wrong refusal: $(cat "$output")"
  [ -e "$case_root/sandbox/siterepo/.marked1.needs-install" ] \
    && [ ! -e "$lease_dir/marked.json" ] \
    || fail "$label: marker refusal mutated the marker or published a lease"
  pass "$label: install markers remain foreign state and are never cleanup-adopted"

  owner_token="$token"
  "$pair_tool" lease-batch-acquire "$owner_token" "$$" "$owner_start" owned 9404 9405 \
    >"$case_root/owner.txt" 2>&1 \
    || { cat "$case_root/owner.txt" >&2; fail "$label: empty namespace did not acquire"; }
  jq -e --arg token "$owner_token" --arg root "$case_root/sandbox/siterepo" '
    .token == $token and .ports == [9404,9405]
    and .db_engine == "mariadb" and .db_container == "wprism-shared-db"
    and .site_root == $root
  ' \
    "$lease_dir/owned.json" >/dev/null \
    || fail "$label: published lease does not bind token, ports, selected database and physical site root"

  output="$case_root/reused-token.txt"
  if "$pair_tool" lease-batch-acquire "$owner_token" "$$" "$owner_start" reused 9444 9445 \
      >"$output" 2>&1; then
    fail "$label: one token was reused across two acquisitions"
  fi
  grep -Fq "pair lease token $owner_token is already active; tokens cannot be reused" "$output" \
    || fail "$label: token reuse produced the wrong refusal: $(cat "$output")"
  [ ! -e "$lease_dir/reused.json" ] \
    || fail "$label: token reuse published another cleanup authority record"
  pass "$label: one collision-resistant token identifies exactly one acquisition"

  : > "$log"
  output="$case_root/wrong-engine-release.txt"
  if WPRISM_DB_ENGINE=mysql WPRISM_PAIR_LEASE_TOKEN="$owner_token" \
      "$pair_tool" lease-batch-release "$owner_token" >"$output" 2>&1; then
    fail "$label: a MariaDB lease was released through the MySQL lane"
  fi
  grep -Fq 'selected database mysql/wprism-shared-mysql, but the lease is bound to mariadb/wprism-shared-db' "$output" \
    || fail "$label: wrong-engine release produced the wrong refusal: $(cat "$output")"
  ! grep -Fq 'docker <exec>' "$log" \
    || fail "$label: wrong-engine release reached database mutation before context refusal"
  [ -e "$lease_dir/owned.json" ] \
    || fail "$label: wrong-engine release removed the lease"

  foreign_tool="$case_root/foreign/sandbox/bin/pair.sh"
  mkdir -p "$case_root/foreign/sandbox/bin" "$case_root/foreign/sandbox/siterepo"
  copy_pair_launcher "$case_root/foreign/sandbox/bin"
  chmod +x "$foreign_tool"
  : > "$log"
  output="$case_root/wrong-root-release.txt"
  if WPRISM_DB_ENGINE=mariadb WPRISM_PAIR_LEASE_TOKEN="$owner_token" \
      "$foreign_tool" lease-batch-release "$owner_token" >"$output" 2>&1; then
    fail "$label: a lease was released from a different physical worktree site root"
  fi
  grep -Fq "but the lease is bound to $case_root/sandbox/siterepo; refusing before mutation" "$output" \
    || fail "$label: wrong-root release produced the wrong refusal: $(cat "$output")"
  ! grep -Fq 'docker <exec>' "$log" \
    || fail "$label: wrong-root release reached database mutation before context refusal"
  [ -e "$lease_dir/owned.json" ] \
    || fail "$label: wrong-root release removed the lease"
  pass "$label: database lane and physical worktree root are release authority, checked before mutation"

  # The canonical lease store already exists, while this distinct worktree
  # has never run pair up. A fixture that pre-creates both roots misses the
  # real first leased-run failure before any pair namespace was acquired.
  pristine_tool="$case_root/pristine/sandbox/bin/pair.sh"
  mkdir -p "$case_root/pristine/sandbox/bin"
  copy_pair_launcher "$case_root/pristine/sandbox/bin"
  chmod +x "$pristine_tool"
  [ ! -e "$case_root/pristine/sandbox/siterepo" ] \
    || fail "$label: pristine worktree already has its site root"
  output="$case_root/pristine-release.txt"
  if "$pristine_tool" lease-batch-release "$owner_token" >"$output" 2>&1; then
    fail "$label: missing-context release accepted another worktree's authority"
  fi
  [ ! -e "$case_root/pristine/sandbox/siterepo" ] && [ -e "$lease_dir/owned.json" ] \
    || fail "$label: read-only release created missing context or removed another lease"
  pristine_token="$(
    source "$ROOT/sandbox/lib/pair_lease.sh"
    pair_lease_token "$$" "$owner_start"
  )"
  "$pristine_tool" lease-batch-acquire "$pristine_token" "$$" "$owner_start" pristine 9454 9455 \
    >"$case_root/pristine-acquire.txt" 2>&1 \
    || { cat "$case_root/pristine-acquire.txt" >&2; fail "$label: pristine worktree could not acquire before its first pair up"; }
  jq -e --arg token "$pristine_token" --arg root "$case_root/pristine/sandbox/siterepo" '
    .token == $token and .ports == [9454,9455] and .site_root == $root
    and .db_engine == "mariadb" and .db_container == "wprism-shared-db"
  ' "$lease_dir/pristine.json" >/dev/null \
    || fail "$label: first lease did not bind the separate physical site root"
  [ -d "$case_root/pristine/sandbox/siterepo" ] \
    && [ ! -e "$case_root/pristine/sandbox/siterepo/pristine1" ] \
    && [ ! -e "$case_root/pristine/sandbox/siterepo/pristine2" ] \
    || fail "$label: parent preparation created pair-owned children before up"
  "$pristine_tool" lease-batch-release "$pristine_token" >"$case_root/pristine-release-ok.txt" 2>&1 \
    || { cat "$case_root/pristine-release-ok.txt" >&2; fail "$label: empty pristine lease did not release"; }
  [ ! -e "$lease_dir/pristine.json" ] && [ -e "$lease_dir/owned.json" ] \
    || fail "$label: pristine release removed the wrong cleanup authority"
  rmdir "$case_root/pristine/sandbox/siterepo"
  printf 'foreign non-directory site root\n' > "$case_root/pristine/sandbox/siterepo"
  output="$case_root/pristine-file-root.txt"
  if "$pristine_tool" lease-batch-acquire "$pristine_token" "$$" "$owner_start" pristine 9454 9455 \
      >"$output" 2>&1; then
    fail "$label: a non-directory site root crossed lease acquisition"
  fi
  grep -Fq "could not prepare the current sandbox's pair site root" "$output" \
    && [ "$(cat "$case_root/pristine/sandbox/siterepo")" = 'foreign non-directory site root' ] \
    && [ ! -e "$lease_dir/pristine.json" ] && [ -e "$lease_dir/owned.json" ] \
    || fail "$label: failed parent preparation hid its cause, changed foreign bytes or published authority"
  pass "$label: first worktree acquisition prepares only its exact parent; read-only release and non-directory refusals preserve authority"

  TZ=Asia/Tokyo "$pair_tool" capacity >"$case_root/capacity-cross-tz.json" 2>&1 \
    || fail "$label: a live owner became unobservable after the caller timezone changed"
  [ -e "$lease_dir/owned.json" ] \
    || fail "$label: timezone-dependent owner identity pruned a live lease"

  jq '.owner_pid = 2147483647 | .owner_start = "dead-owner" | .token = "dddddddddddddddddddddddddddddddd" | .ports = [9450,9451]' \
    "$lease_dir/owned.json" > "$lease_dir/deadowner.json"
  "$pair_tool" capacity >"$case_root/capacity-dead-owner.json" 2>&1 \
    || fail "$label: positively dead lease owner made capacity unobservable"
  [ ! -e "$lease_dir/deadowner.json" ] \
    || fail "$label: positively dead lease owner was not pruned"

  jq '.owner_pid = 2147483646 | .owner_start = "unobservable-owner" | .token = "eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee" | .ports = [9452,9453]' \
    "$lease_dir/owned.json" > "$lease_dir/unobservable.json"
  export WPRISM_PAIR_TEST_UNOBSERVABLE_PID=2147483646
  output="$case_root/capacity-unobservable-owner.txt"
  if "$pair_tool" capacity >"$output" 2>&1; then
    fail "$label: unobservable lease owner was treated as dead"
  fi
  grep -Fq 'could not observe pair lease owner PID 2147483646 safely' "$output" \
    || fail "$label: unobservable owner produced the wrong refusal: $(cat "$output")"
  [ -e "$lease_dir/unobservable.json" ] \
    || fail "$label: observation failure pruned a potentially live lease"
  export WPRISM_PAIR_TEST_UNOBSERVABLE_PID=''
  "$pair_tool" capacity >"$case_root/capacity-prune-unobservable.json" 2>&1 \
    || fail "$label: formerly unobservable dead lease did not become prunable"
  [ ! -e "$lease_dir/unobservable.json" ] \
    || fail "$label: positively dead owner remained after observation recovered"
  pass "$label: UTC owner identity survives TZ changes, prunes only positive death, and fails closed on observation errors"

  export WPRISM_PAIR_TEST_SCHEMAS='wp_owned1'
  output="$case_root/release-with-schema.txt"
  if "$pair_tool" lease-batch-release "$owner_token" >"$output" 2>&1; then
    fail "$label: release discarded authority while an owned schema remained"
  fi
  grep -Fq 'pair database schema already exists (wp_owned1); pair namespace is not empty' "$output" \
    || fail "$label: release schema recensus produced the wrong refusal: $(cat "$output")"
  [ -e "$lease_dir/owned.json" ] \
    || fail "$label: failed cleanup recensus removed the lease"
  export WPRISM_PAIR_TEST_SCHEMAS=''
  pass "$label: final release rechecks database absence and retains authority on failure"

  output="$case_root/name-contender.txt"
  if "$pair_tool" lease-batch-acquire "$contender_token" "$$" "$owner_start" owned 9406 9407 \
      >"$output" 2>&1; then
    fail "$label: competing lease acquired the same pair name"
  fi
  grep -Fq "pair 'owned' is already leased" "$output" \
    || fail "$label: name contender produced the wrong refusal: $(cat "$output")"

  output="$case_root/port-contender.txt"
  if "$pair_tool" lease-batch-acquire "$contender_token" "$$" "$owner_start" other 9404 9405 \
      >"$output" 2>&1; then
    fail "$label: competing lease acquired the same pair ports"
  fi
  grep -Fq 'pair lease request collides on port 9404' "$output" \
    || fail "$label: port contender produced the wrong refusal: $(cat "$output")"

  export WPRISM_PAIR_TEST_LISTEN_PORT=9408
  output="$case_root/listener.txt"
  if "$pair_tool" lease-batch-acquire "$contender_token" "$$" "$owner_start" listener 9408 9409 \
      >"$output" 2>&1; then
    fail "$label: a listening port crossed lease admission"
  fi
  grep -Fq 'pair lease port 9408 is already listening' "$output" \
    || fail "$label: listening port produced the wrong refusal: $(cat "$output")"
  export WPRISM_PAIR_TEST_LISTEN_PORT=''
  pass "$label: competing name, reserved ports, and active listeners all refuse under one lease lock"

  export WPRISM_PAIR_LEASE_TOKEN="$owner_token" WPRISM_PAIR_TEST_FAIL_WEB_UP=1
  output="$case_root/partial-up.txt"
  if "$pair_tool" up owned 9404 9405 --headless >"$output" 2>&1; then
    fail "$label: injected partial up unexpectedly succeeded"
  fi
  grep -Fq 'fake partial web up failure' "$output" \
    || fail "$label: injected up did not reach the post-schema/pre-visible failure: $(cat "$output")"
  [ -d "$case_root/sandbox/siterepo/owned1" ] && [ -d "$case_root/sandbox/siterepo/owned2" ] \
    || fail "$label: partial up did not create the cleanup-owned roots"
  [ -e "$lease_dir/owned.json" ] \
    || fail "$label: partial up prematurely released its namespace lease"

  export WPRISM_PAIR_TEST_FAIL_WEB_UP=0
  "$pair_tool" destroy owned >"$case_root/destroy.txt" 2>&1 \
    || { cat "$case_root/destroy.txt" >&2; fail "$label: partial up destroy failed"; }
  [ -e "$lease_dir/owned.json" ] \
    || fail "$label: destroy released the caller-owned lease before cleanup verification"
  find "$case_root/sandbox/siterepo/owned1" "$case_root/sandbox/siterepo/owned2" -depth -delete
  "$pair_tool" lease-batch-release "$owner_token" >"$case_root/release.txt" 2>&1 \
    || { cat "$case_root/release.txt" >&2; fail "$label: checked lease release failed"; }
  [ ! -e "$lease_dir/owned.json" ] \
    || fail "$label: lease remained after verified partial-up teardown"
  pass "$label: a partial up remains cleanup-owned through destroy/root removal and releases the lease last"
  unset WPRISM_PAIR_LEASE_TOKEN
}

run_case() {
  local label="$1" pair="$2" codebind="$3" git_mode="${4:-canonical}"
  local artifacts="${5:-0}" wordpress_offline="${6:-0}"
  local launcher="${7:-}" git_cli="${8:-0}"
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" log="$TMP/$label/docker.log"
  local output="$TMP/$label/output.log" web cli mount1 mount2 compose_prefix canonical_root
  local up_args=(up "$pair" 9911 9912 --headless)
  mkdir -p "$case_root/sandbox/bin" "$case_root/sandbox/conformance" "$fake_bin"
  canonical_root="$case_root/canonical"
  mkdir -p "$canonical_root"
  copy_pair_launcher "$case_root/sandbox/bin"
  copy_artifact_library_runtime "$case_root"
  if [ -n "${WPRISM_PAIR_TEST_LOCK_OVERRIDE:-}" ]; then
    cp "$WPRISM_PAIR_TEST_LOCK_OVERRIDE" "$case_root/platform/artifact-library/artifacts.lock.json"
  fi
  chmod +x "$case_root/sandbox/bin/pair.sh"

  # The fake has no side effects beyond its log. Its successful health/info
  # responses let the real shell control flow reach the compose calls under
  # test; all compose/exec/run operations are otherwise no-ops.
  write_fake_docker "$fake_bin"
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=8 WPRISM_PAIR_TEST_MEM=8589934592 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$canonical_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    WPRISM_PAIR_TEST_CONTAINERS='' WPRISM_PAIR_TEST_FAIL_PS=0 WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER='' \
    PATH="$fake_bin:$ORIGINAL_PATH"
  if [ "$git_mode" = canonical ]; then
    write_fake_git "$fake_bin"
  fi

  [ "$artifacts" = 0 ] || up_args+=(--artifacts)
  [ "$wordpress_offline" = 0 ] || up_args+=(--wordpress-offline)
  if [ "$git_cli" = 1 ]; then
    export WPRISM_CLI_IMAGE="wprism-pair-test-cli:$pair"
    up_args+=(--git-cli)
  fi
  if [ -n "$codebind" ]; then
    up_args+=(--codebind "$codebind")
  fi
  if [ -n "$launcher" ]; then
    "$launcher" "$case_root/sandbox/bin/pair.sh" "${up_args[@]}" \
      >"$output" 2>&1 || { cat "$output" >&2; fail "$label pair bootstrap failed"; }
  else
    "$case_root/sandbox/bin/pair.sh" "${up_args[@]}" \
      >"$output" 2>&1 || { cat "$output" >&2; fail "$label pair bootstrap failed"; }
  fi

  compose_prefix="docker <compose> <-p> <wprism-$pair> <-f> <pair.yml>"
  [ "$artifacts" = 0 ] || compose_prefix="$compose_prefix <-f> <pair.artifacts.yml>"
  [ "$wordpress_offline" = 0 ] || compose_prefix="$compose_prefix <-f> <pair.wordpress-offline.yml>"
  if [ -n "$codebind" ]; then
    compose_prefix="$compose_prefix <-f> <pair.codebind.yml>"
    web="$compose_prefix <up> <-d> <--force-recreate> <wp1> <wp2>"
    mount1="$compose_prefix <exec> <-T> <wp1> <sh> <-c>"
    mount2="$compose_prefix <exec> <-T> <wp2> <sh> <-c>"
  else
    web="$compose_prefix <up> <-d> <wp1> <wp2>"
    mount1="$compose_prefix <exec> <-T> <wp1> <sh> <-c>"
    mount2="$compose_prefix <exec> <-T> <wp2> <sh> <-c>"
  fi
  cli="$compose_prefix <up> <-d> <cli1> <cli2>"

  assert_file_contains "$log" "$web" "$label did not start web services explicitly"
  assert_file_contains "$log" "$cli" "$label did not start CLI services explicitly"
  assert_file_contains "$log" "$mount1" "$label did not probe wp1 nested MU mount"
  assert_file_contains "$log" "$mount2" "$label did not probe wp2 nested MU mount"
  assert_file_contains "$log" "env[WPRISM_AGENT_SRC]=$canonical_root/agent" "$label did not use the canonical agent bind source"
  assert_file_contains "$log" "env[WPRISM_ADAPTER_PACKAGES_SRC]=$canonical_root/adapter-packages" "$label did not use the canonical adapter-package bind source"
  assert_file_contains "$log" "env[WPRISM_PLATFORM_SRC]=$canonical_root/platform" "$label did not use the canonical platform bind source"
  local recipe_env="WPRISM_PAIR=$pair WPRISM_PORT1=9911 WPRISM_PORT2=9912 WPRISM_CLI_IMAGE=${WPRISM_CLI_IMAGE:-wordpress:cli-php8.3}"
  [ -z "$codebind" ] || recipe_env="$recipe_env WPRISM_CODEBIND_PLUGIN=$codebind"
  assert_file_contains "$output" "$recipe_env docker compose -p wprism-$pair" \
    "$label printed a wp-cli recipe that loses pair.yml's required environment across the pair.sh process boundary"
  if [ "$git_cli" = 1 ]; then
    assert_file_contains "$log" "docker <build> <-q> <-f> <init-cli.Dockerfile> <-t> <wprism-pair-test-cli:$pair> <.>" \
      "$label did not build the Git-enabled CLI image before pair bootstrap"
    assert_before "$log" \
      "docker <build> <-q> <-f> <init-cli.Dockerfile> <-t> <wprism-pair-test-cli:$pair> <.>" \
      "$web"
    unset WPRISM_CLI_IMAGE
  fi
  assert_before "$log" "$web" "$mount1"
  assert_before "$log" "$mount2" "$cli"
  assert_before "$log" "$mount1" "$cli"
  assert_before "$log" "$mount2" "$cli"
  if grep -F '<up> <-d> <wp1> <wp2> <cli1> <cli2>' "$log" >/dev/null; then
    fail "$label used a single concurrent web+CLI compose up"
  fi

  if [ -n "$codebind" ]; then
    if grep -F "$compose_prefix <up> <-d> <--force-recreate> <cli1> <cli2>" "$log" >/dev/null; then
      fail "$label incorrectly force-recreated CLI services"
    fi
  elif grep -F '<--force-recreate>' "$log" >/dev/null; then
    fail "$label unexpectedly used --force-recreate without --codebind"
  fi
  pass "$label: web-first nested-MU bootstrap ordering and service flags"
}

run_theme_retry_case() {
  local label=theme_retry state_dir="$TMP/theme_retry/theme-state"
  mkdir -p "$state_dir"
  export WPRISM_PAIR_TEST_THEME_STATE_DIR="$state_dir" WPRISM_PAIR_TEST_THEME_FAILURES=1
  run_case "$label" pairtheme "" canonical
  unset WPRISM_PAIR_TEST_THEME_STATE_DIR WPRISM_PAIR_TEST_THEME_FAILURES

  [ "$(cat "$state_dir/attempts")" = 3 ] \
    || fail "$label did not perform one bounded retry plus one install for the other side"
  assert_file_contains "$TMP/$label/output.log" \
    'Warning: theme twentytwentyone install/activation attempt 1/3 failed; retrying the exact slug' \
    "$label did not report the transient retry"
  [ "$(grep -cF 'wp> <theme> <list> <--status=active> <--field=name>' "$TMP/$label/docker.log")" = 2 ] \
    || fail "$label did not verify the exact active theme on both sides"
  pass "$label: one transient lookup retries, both sides prove the exact active theme"
}

run_artifact_theme_case() {
  local label=artifact_theme pair=pairartifact case_root="$TMP/artifact_theme"
  local state_dir="$case_root/theme-state" cache_dir="$case_root/cache"
  local payload="$case_root/twentytwentyone.zip" digest cache_file
  mkdir -p "$state_dir" "$cache_dir"
  printf 'exact pinned theme fixture\n' > "$payload"
  digest="$(sha256sum "$payload" | awk '{print $1}')"
  cache_file="$cache_dir/theme-twentytwentyone-2.8-$digest.zip"
  cp "$payload" "$cache_file"
  export WPRISM_PAIR_TEST_THEME_STATE_DIR="$state_dir" WPRISM_PAIR_TEST_THEME_FAILURES=0
  export WPRISM_PAIR_TEST_ARTIFACT_CACHE="$cache_dir"
  export WPRISM_PAIR_TEST_ARTIFACT_RUNNER="$ROOT/sandbox/bin/artifact-cache-fetch.sh"

  # run_case copies the platform fragment before launching; replace only this
  # private copy with a same-shaped deterministic fixture matching the warm
  # cache bytes above.
  mkdir -p "$case_root/platform/artifact-library"
  printf '{"plugins":{},"themes":{"twentytwentyone":{"2.8":{"url":"https://fixture.invalid/theme.zip","sha256":"%s","role":"exercise-fixture","archive_root":"fixture-theme-source"}}}}\n' \
    "$digest" > "$case_root/platform/artifact-library/artifacts.lock.json.override"
  WPRISM_PAIR_TEST_LOCK_OVERRIDE="$case_root/platform/artifact-library/artifacts.lock.json.override"
  export WPRISM_PAIR_TEST_LOCK_OVERRIDE
  run_case "$label" "$pair" "" canonical 1 1
  unset WPRISM_PAIR_TEST_THEME_STATE_DIR WPRISM_PAIR_TEST_THEME_FAILURES \
    WPRISM_PAIR_TEST_ARTIFACT_CACHE WPRISM_PAIR_TEST_LOCK_OVERRIDE
  unset WPRISM_PAIR_TEST_ARTIFACT_RUNNER

  [ -f "$state_dir/active" ] || fail "$label did not activate the cached exact theme"
  [ -f "$state_dir/normalized" ] || fail "$label did not normalize the platform-declared theme archive root"
  assert_file_contains "$case_root/output.log" 'source=cache-hit' \
    "$label did not report the warm-cache source path"
  [ "$(grep -cF '<sh> </wprism-harness/artifact-archive-root.sh> </var/www/html/wp-content/themes> <fixture-theme-source> <twentytwentyone>' "$case_root/docker.log")" = 2 ] \
    || fail "$label did not normalize the exact platform theme archive root on both sides"
  assert_before "$case_root/docker.log" \
    '<sh> </wprism-harness/artifact-archive-root.sh> </var/www/html/wp-content/themes> <fixture-theme-source> <twentytwentyone>' \
    '<wp> <theme> <activate> <twentytwentyone>'
  assert_file_contains "$case_root/docker.log" '<-f> <pair.artifacts.yml> <-f> <pair.wordpress-offline.yml>' \
    "$label did not layer both artifact and WordPress.org-offline controls"
  if grep -F '<theme> <install> <twentytwentyone>' "$case_root/docker.log" >/dev/null; then
    fail "$label fell back to a bare WordPress.org theme install"
  fi
  pass "$label: offline bootstrap consumes only the exact warm cached theme and verifies its version"
}

run_invalid_artifact_lock_preflight_case() {
  local label=invalid_artifact_lock_preflight pair=pairinvalid
  local case_root="$TMP/$label"
  local fake_bin="$case_root/fake-bin"
  local log="$case_root/docker.log" output="$case_root/output.log"
  local canonical_root="$case_root/canonical"
  mkdir -p "$case_root/sandbox/bin" "$case_root/sandbox/conformance" "$fake_bin"
  copy_pair_launcher "$case_root/sandbox/bin"
  copy_artifact_library_runtime "$case_root"
  jq '.plugins["wpforms-lite"]["2.0.0.4"].role = "unknown-role"' \
    "$ROOT/platform/artifact-library/artifacts.lock.json" \
    > "$case_root/platform/artifact-library/artifacts.lock.json"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=8 WPRISM_PAIR_TEST_MEM=8589934592 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$canonical_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    WPRISM_PAIR_TEST_CONTAINERS='' WPRISM_PAIR_TEST_FAIL_PS=0 WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER='' \
    PATH="$fake_bin:$ORIGINAL_PATH"

  if "$case_root/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless --artifacts \
      >"$output" 2>&1; then
    fail "$label accepted an artifact library with an unknown role"
  fi
  assert_file_contains "$output" 'artifact library is malformed' \
    "$label did not return the bounded preflight refusal"
  [ ! -e "$log" ] || [ ! -s "$log" ] \
    || fail "$label contacted Docker before refusing the malformed lock"
  [ ! -e "$case_root/sandbox/siterepo" ] \
    || fail "$label created pair state before refusing the malformed lock"
  pass "$label: malformed typed lock refuses before Docker, DB, or pair-root mutation"
}

run_invalid_bootstrap_theme_preflight_case() {
  local variant case_root fake_bin log output canonical_root
  for variant in missing ambiguous; do
    case_root="$TMP/bootstrap_theme_$variant"
    fake_bin="$case_root/fake-bin"
    log="$case_root/docker.log"
    output="$case_root/output.log"
    canonical_root="$case_root/canonical"
    mkdir -p "$case_root/sandbox/bin" "$case_root/sandbox/conformance" "$fake_bin"
    copy_pair_launcher "$case_root/sandbox/bin"
    copy_artifact_library_runtime "$case_root"
    case "$variant" in
      missing)
        jq 'del(.themes.twentytwentyone)' \
          "$ROOT/platform/artifact-library/artifacts.lock.json" \
          > "$case_root/platform/artifact-library/artifacts.lock.json"
        ;;
      ambiguous)
        jq '.themes.twentytwentyone["2.9"] = .themes.twentytwentyone["2.8"]' \
          "$ROOT/platform/artifact-library/artifacts.lock.json" \
          > "$case_root/platform/artifact-library/artifacts.lock.json"
        ;;
    esac
    chmod +x "$case_root/sandbox/bin/pair.sh"
    write_fake_docker "$fake_bin"
    write_fake_git "$fake_bin"
    export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
      WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=8 WPRISM_PAIR_TEST_MEM=8589934592 \
      WPRISM_PAIR_TEST_CANONICAL_ROOT="$canonical_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
      WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
      WPRISM_PAIR_TEST_CONTAINERS='' WPRISM_PAIR_TEST_FAIL_PS=0 WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER='' \
      PATH="$fake_bin:$ORIGINAL_PATH"

    if "$case_root/sandbox/bin/pair.sh" up "pair${variant}" 9911 9912 --headless --artifacts \
        >"$output" 2>&1; then
      fail "bootstrap_theme_$variant accepted a non-singleton twentytwentyone pin"
    fi
    assert_file_contains "$output" 'bootstrap-theme registry entry is missing or ambiguous' \
      "bootstrap_theme_$variant did not return the bounded preflight refusal"
    [ ! -e "$log" ] || [ ! -s "$log" ] \
      || fail "bootstrap_theme_$variant contacted Docker before refusing"
    [ ! -e "$canonical_root/sandbox/siterepo" ] \
      || fail "bootstrap_theme_$variant created the shared budget-lock root before refusing"
    [ ! -e "$case_root/sandbox/siterepo" ] \
      || fail "bootstrap_theme_$variant created pair state before refusing"
  done
  pass "missing and ambiguous bootstrap-theme pins refuse before budget, Docker, DB, or pair-root mutation"
}

run_python_lock_fallback_case() {
  local label=python_lock_fallback pair=pylock
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" output="$TMP/$label/output.log" \
    canonical_root="$TMP/$label/canonical" utility
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  # Recreate the documented Unix host tools in a private PATH while
  # intentionally omitting util-linux flock. The copied script is launched
  # through the private bash symlink, so this exercises Python fcntl rather
  # than the Linux fast path without contacting Docker.
  install_python_lock_path "$fake_bin"
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=8 WPRISM_PAIR_TEST_MEM=8589934592 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$canonical_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    WPRISM_PAIR_TEST_CONTAINERS='' WPRISM_PAIR_TEST_FAIL_PS=0 WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER=''

  local index
  for index in a b c; do
    timeout 15 env PATH="$fake_bin" "$case_root/sandbox/bin/pair.sh" up "${pair}${index}" 9911 9912 --headless \
      >"$output" 2>&1 || { cat "$output" >&2; fail "$label did not acquire/release the Python fcntl lock on iteration $index"; }
    grep -q "pair '${pair}${index}' ready" "$output" \
      || fail "$label did not complete after Python fcntl reservation on iteration $index"
  done
  pass "$label: repeated Python fcntl fallback acquire/release is crash-safe and portable"
}

run_non_git_failure_case() {
  local label=non_git_failure pair=nongit
  local case_root="$TMP/$label" \
    fake_bin="$TMP/$label/fake-bin" log="$TMP/$label/docker.log" \
    output="$TMP/$label/output.log"
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=8 WPRISM_PAIR_TEST_MEM=8589934592 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$ROOT" PATH="$fake_bin:$ORIGINAL_PATH"

  if env -u GIT_DIR -u GIT_WORK_TREE -u GIT_COMMON_DIR \
      "$case_root/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless \
      >"$output" 2>&1; then
    cat "$output" >&2
    fail "$label unexpectedly succeeded outside a Git checkout"
  fi
  grep -q "could not resolve this repo's canonical checkout" "$output" \
    || fail "$label did not fail closed with the canonical checkout diagnostic"
  if [ -f "$log" ] && grep -F "<-p> <wprism-$pair>" "$log" >/dev/null; then
    fail "$label reached pair Compose after canonical-root failure"
  fi
  if [ -f "$log" ] && grep -F "<-p> <wprism-db>" "$log" >/dev/null; then
    fail "$label touched shared DB Compose before canonical-root failure"
  fi
  pass "$label: canonical bind resolution fails closed outside Git"
}

run_budget_refusal_case() {
  local label=budget_refusal pair=budgetpair
  local case_root="$TMP/$label" \
    fake_bin="$TMP/$label/fake-bin" log="$TMP/$label/docker.log" \
    output="$TMP/$label/output.log"
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  export WPRISM_PAIR_TEST_LOG="$log" \
    WPRISM_PAIR_TEST_LIVE_PAIRS='[{"ConfigFiles":"/canonical/sandbox/pair.yml","Name":"wprism-existing"}]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=2 WPRISM_PAIR_TEST_MEM=3221225472 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$case_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    PATH="$fake_bin:$ORIGINAL_PATH"
  write_fake_git "$fake_bin"

  if "$case_root/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless \
      >"$output" 2>&1; then
    cat "$output" >&2
    fail "$label unexpectedly allowed a new pair over the fake host budget"
  fi
  grep -q "refusing to bring up new pair '$pair' over budget" "$output" \
    || fail "$label did not report the budget refusal"
  [ ! -e "$case_root/sandbox/siterepo/${pair}1" ] \
    || fail "$label created side-1 site state before refusing"
  [ ! -e "$case_root/sandbox/siterepo/${pair}2" ] \
    || fail "$label created side-2 site state before refusing"
  if grep -F "<-p> <wprism-db>" "$log" >/dev/null; then
    fail "$label touched shared DB Compose before refusing"
  fi
  pass "$label: over-budget refusal precedes DB and site-root mutations"
}

run_budget_formula_pin_case() { # <label> <cpu> <mem_bytes> <live_count> <expected_budget>
  # Pin pair_budget()'s exact arithmetic — 2 pairs per docker core after the
  # 2-core reserve, RAM-guarded at 1GiB per pair after the 3GiB reserve,
  # min of the two, floor 1 — via the refusal path's own printed number:
  # fake a host of <cpu>/<mem_bytes> with <expected_budget> pairs already
  # live, and the next `up` must refuse while printing exactly
  # "budget for this host: <expected_budget> — 2 pairs per docker core".
  # Reverting the formula to the old (cores-2)/((mem-3)/2) moves the number
  # on the RAM-bound and CPU-bound points below, so the pin bites.
  local label="budget_formula_$1" cpu="$2" mem="$3" live_count="$4" expected="$5" pair=formulapair
  local case_root="$TMP/$label" \
    fake_bin="$TMP/$label/fake-bin" log="$TMP/$label/docker.log" \
    output="$TMP/$label/output.log" live_json i
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  live_json='['
  for i in $(seq 1 "$live_count"); do
    [ "$i" -gt 1 ] && live_json+=','
    live_json+='{"ConfigFiles":"/canonical/sandbox/pair.yml","Name":"wprism-existing'"$i"'"}'
  done
  live_json+=']'
  export WPRISM_PAIR_TEST_LOG="$log" \
    WPRISM_PAIR_TEST_LIVE_PAIRS="$live_json" \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU="$cpu" WPRISM_PAIR_TEST_MEM="$mem" \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$case_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    PATH="$fake_bin:$ORIGINAL_PATH"

  if "$case_root/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless \
      >"$output" 2>&1; then
    cat "$output" >&2
    fail "$label unexpectedly allowed a pair beyond the expected budget of $expected"
  fi
  grep -q "refusing to bring up new pair '$pair' over budget" "$output" \
    || fail "$label did not report the budget refusal"
  grep -Fq "budget for this host: $expected — 2 pairs per docker core" "$output" \
    || { cat "$output" >&2; fail "$label did not compute the expected budget of $expected (cpu=$cpu mem=$mem)"; }
  pass "$label: cpu=$cpu mem_bytes=$mem => budget exactly $expected"
}

run_start_budget_refusal_case() {
  local label=start_budget_refusal pair=startrefuse
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" output="$TMP/$label/output.log"
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  export WPRISM_PAIR_TEST_LOG="$log" \
    WPRISM_PAIR_TEST_LIVE_PAIRS='[{"ConfigFiles":"/canonical/sandbox/pair.yml","Name":"wprism-existing"}]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=2 WPRISM_PAIR_TEST_MEM=3221225472 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$case_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    WPRISM_PAIR_TEST_CONTAINERS='' WPRISM_PAIR_TEST_FAIL_PS=0 \
    WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER='' WPRISM_PAIR_BUDGET_OVERRIDE=0 \
    PATH="$fake_bin:$ORIGINAL_PATH"

  if "$case_root/sandbox/bin/pair.sh" start "$pair" >"$output" 2>&1; then
    cat "$output" >&2
    fail "$label unexpectedly allowed a stopped pair to exceed the fake host budget"
  fi
  grep -q "refusing to bring up new pair '$pair' over budget" "$output" \
    || fail "$label did not report the start budget refusal"
  [ ! -e "$case_root/sandbox/siterepo/${pair}1" ] \
    || fail "$label created side-1 site state before the start budget refusal"
  [ ! -e "$case_root/sandbox/siterepo/${pair}2" ] \
    || fail "$label created side-2 site state before the start budget refusal"
  if grep -F '<-p> <wprism-db>' "$log" >/dev/null || \
     grep -F "<-p> <wprism-$pair>" "$log" >/dev/null; then
    fail "$label touched Compose before the start budget refusal"
  fi
  pass "$label: start reserves before any DB, site, or pair Compose mutation"
}

run_start_safe_case() {
  local label=start_safe pair=startsafely
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" output="$TMP/$label/output.log" \
    live_file="$TMP/$label/live.json"
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=8 WPRISM_PAIR_TEST_MEM=8589934592 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$case_root" WPRISM_PAIR_TEST_LIVE_FILE="$live_file" \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    WPRISM_PAIR_TEST_CONTAINERS='' WPRISM_PAIR_TEST_FAIL_PS=0 \
    WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER='' WPRISM_PAIR_BUDGET_OVERRIDE=0 \
    PATH="$fake_bin:$ORIGINAL_PATH"

  "$case_root/sandbox/bin/pair.sh" start "$pair" >"$output" 2>&1 \
    || { cat "$output" >&2; fail "$label rejected a start within the fake host budget"; }
  grep -q "running again — same ports/config as before the stop" "$output" \
    || fail "$label did not complete after Compose start became visible"
  grep -F "<-p> <wprism-$pair> <-f> <pair.yml> <start>" "$log" >/dev/null \
    || fail "$label did not issue the expected Compose start operation"
  assert_file_contains "$log" "docker <compose> <-p> <wprism-db> <-f> <db.yml> <up> <-d> <--no-recreate>" \
    "$label did not preserve the non-recreating shared-DB prerequisite"
  if grep -F "docker <compose> <-p> <wprism-db> <-f> <db.yml>" "$log" \
      | grep -F "<--force-recreate>" >/dev/null; then
    fail "$label allowed ordinary pair lifecycle to replace the fleet-shared database"
  fi
  pass "$label: in-budget start keeps DB readiness and a non-recreating Compose prerequisite behind reservation"
}

run_start_budget_override_case() {
  local label=start_budget_override pair=startoverride
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" output="$TMP/$label/output.log" \
    live_file="$TMP/$label/live.json"
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[{"ConfigFiles":"/canonical/sandbox/pair.yml","Name":"wprism-existing"}]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=2 WPRISM_PAIR_TEST_MEM=3221225472 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$case_root" WPRISM_PAIR_TEST_LIVE_FILE="$live_file" \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    WPRISM_PAIR_TEST_CONTAINERS='' WPRISM_PAIR_TEST_FAIL_PS=0 \
    WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER='' WPRISM_PAIR_BUDGET_OVERRIDE=1 \
    PATH="$fake_bin:$ORIGINAL_PATH"

  "$case_root/sandbox/bin/pair.sh" start "$pair" >"$output" 2>&1 \
    || { cat "$output" >&2; fail "$label did not honor the explicit budget override"; }
  grep -q "WPRISM_PAIR_BUDGET_OVERRIDE=1 set" "$output" \
    || fail "$label did not report the explicit budget override"
  grep -F "<-p> <wprism-$pair> <-f> <pair.yml> <start>" "$log" >/dev/null \
    || fail "$label did not issue Compose start after the explicit override"
  pass "$label: explicit start budget override is honored only after reservation"
}

run_live_reconverge_case() {
  local label=live_reconverge pair=reconverge
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" output="$TMP/$label/output.log"
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  export WPRISM_PAIR_TEST_LOG="$log" \
    WPRISM_PAIR_TEST_LIVE_PAIRS='[{"ConfigFiles":"/canonical/sandbox/pair.yml","Name":"wprism-reconverge"}]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=2 WPRISM_PAIR_TEST_MEM=3221225472 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$case_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    PATH="$fake_bin:$ORIGINAL_PATH"

  "$case_root/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless \
    >"$output" 2>&1 || { cat "$output" >&2; fail "$label rejected an already-live pair"; }
  if grep -q "refusing to bring up new pair '$pair' over budget" "$output"; then
    fail "$label treated an already-live candidate as a new budget reservation"
  fi
  grep -q "pair '$pair' ready" "$output" \
    || fail "$label did not complete the reconvergence path"
  pass "$label: an already-live pair remains reconvergeable at the budget boundary"
}

run_budget_query_failure_case() {
  local label=budget_query_failure pair=budgetfail
  local case_root="$TMP/$label" \
    fake_bin="$TMP/$label/fake-bin" log="$TMP/$label/docker.log" \
    output="$TMP/$label/output.log"
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=8 WPRISM_PAIR_TEST_MEM=8589934592 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$case_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=1 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    PATH="$fake_bin:$ORIGINAL_PATH"
  write_fake_git "$fake_bin"

  if "$case_root/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless \
      >"$output" 2>&1; then
    cat "$output" >&2
    fail "$label unexpectedly guessed capacity after Docker info failed"
  fi
  grep -q "could not query Docker host capacity" "$output" \
    || fail "$label did not report the strict Docker capacity-query refusal"
  [ ! -e "$case_root/sandbox/siterepo/${pair}1" ] \
    || fail "$label created side-1 site state after capacity-query failure"
  [ ! -e "$case_root/sandbox/siterepo/${pair}2" ] \
    || fail "$label created side-2 site state after capacity-query failure"
  if grep -F '<-p> <wprism-db>' "$log" >/dev/null; then
    fail "$label touched shared DB Compose after capacity-query failure"
  fi
  pass "$label: Docker capacity failure refuses closed before pair mutation"
}

run_live_query_failure_case() {
  local label=live_query_failure pair=livefail
  local case_root="$TMP/$label" \
    fake_bin="$TMP/$label/fake-bin" log="$TMP/$label/docker.log" \
    output="$TMP/$label/output.log"
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=8 WPRISM_PAIR_TEST_MEM=8589934592 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$case_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=1 \
    PATH="$fake_bin:$ORIGINAL_PATH"
  write_fake_git "$fake_bin"

  if "$case_root/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless \
      >"$output" 2>&1; then
    cat "$output" >&2
    fail "$label unexpectedly treated Compose enumeration failure as an empty list"
  fi
  grep -q "could not enumerate live pair Compose projects" "$output" \
    || fail "$label did not report the strict Compose-list refusal"
  [ ! -e "$case_root/sandbox/siterepo/${pair}1" ] \
    || fail "$label created side-1 site state after Compose enumeration failure"
  [ ! -e "$case_root/sandbox/siterepo/${pair}2" ] \
    || fail "$label created side-2 site state after Compose enumeration failure"
  if grep -F '<-p> <wprism-db>' "$log" >/dev/null; then
    fail "$label touched shared DB Compose after Compose enumeration failure"
  fi
  pass "$label: Compose enumeration failure refuses closed before pair mutation"
}

run_concurrent_budget_race_case() {
  local label=budget_concurrent_race
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" gate="$TMP/$label/gate" \
    live_file="$TMP/$label/live.json" output1="$TMP/$label/one.log" \
    output2="$TMP/$label/two.log" p1 p2 first_ready=0 i status1 status2
  mkdir -p "$case_root/sandbox/bin" "$fake_bin" "$gate"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"

  # Three cores/four GiB yields exactly one budget unit under the 2-per-core
  # formula (cpu (3-2)*2 = 2, ram 4-3 = 1, min = 1). The fake Compose
  # daemon records the first pair as live only after its web services are
  # created. The second process must remain outside Docker/site state while
  # the first process owns the cross-process reservation, then refuse against
  # the first pair's now-live listing.
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=3 WPRISM_PAIR_TEST_MEM=4294967296 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$case_root/canonical" \
    WPRISM_PAIR_TEST_LIVE_FILE="$live_file" WPRISM_PAIR_TEST_RACE_GATE="$gate" \
    WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 PATH="$fake_bin:$ORIGINAL_PATH"

  ("$case_root/sandbox/bin/pair.sh" up raceone 9911 9912 --headless >"$output1" 2>&1) &
  p1=$!
  for i in $(seq 1 200); do
    if [ -e "$gate/first-ready" ]; then first_ready=1; break; fi
    if ! kill -0 "$p1" 2>/dev/null; then break; fi
    sleep 0.01
  done
  [ "$first_ready" = 1 ] \
    || { : > "$gate/release"; wait "$p1" || true; cat "$output1" >&2; fail "$label did not reach the first holder gate"; }

  ("$case_root/sandbox/bin/pair.sh" up racetwo 9913 9914 --headless >"$output2" 2>&1) &
  p2=$!
  sleep 0.25
  [ ! -e "$case_root/sandbox/siterepo/racetwo1" ] \
    || { : > "$gate/release"; wait "$p1" || true; wait "$p2" || true; fail "$label contender created side-1 site state while reservation was held"; }
  [ ! -e "$case_root/sandbox/siterepo/racetwo2" ] \
    || { : > "$gate/release"; wait "$p1" || true; wait "$p2" || true; fail "$label contender created side-2 site state while reservation was held"; }
  if grep -F '<-p> <wprism-racetwo>' "$log" >/dev/null; then
    : > "$gate/release"; wait "$p1" || true; wait "$p2" || true
    fail "$label contender reached Docker before the first reservation released"
  fi

  : > "$gate/release"
  status1=0; wait "$p1" || status1=$?
  status2=0; wait "$p2" || status2=$?
  [ "$status1" -eq 0 ] \
    || { cat "$output1" >&2; fail "$label first pair failed under the fake Compose daemon"; }
  [ "$status2" -ne 0 ] \
    || { cat "$output2" >&2; fail "$label contender unexpectedly succeeded after the first pair became live"; }
  grep -q "refusing to bring up new pair 'racetwo' over budget" "$output2" \
    || fail "$label contender did not report the post-lock budget refusal"
  [ ! -e "$case_root/sandbox/siterepo/racetwo1" ] \
    || fail "$label contender created side-1 site state after refusal"
  [ ! -e "$case_root/sandbox/siterepo/racetwo2" ] \
    || fail "$label contender created side-2 site state after refusal"
  pass "$label: atomic cross-process reservation closes the concurrent-up race"
}

run_python_lock_concurrent_case() {
  local label=python_lock_concurrent
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" gate="$TMP/$label/gate" \
    live_file="$TMP/$label/live.json" output1="$TMP/$label/one.log" \
    output2="$TMP/$label/two.log" p1 p2 first_ready=0 i status1 status2
  mkdir -p "$case_root/sandbox/bin" "$fake_bin" "$gate"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  install_python_lock_path "$fake_bin"
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=3 WPRISM_PAIR_TEST_MEM=4294967296 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$case_root/canonical" \
    WPRISM_PAIR_TEST_LIVE_FILE="$live_file" WPRISM_PAIR_TEST_RACE_GATE="$gate" \
    WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0

  (timeout 20 env PATH="$fake_bin" "$case_root/sandbox/bin/pair.sh" up pyfirst 9911 9912 --headless >"$output1" 2>&1) &
  p1=$!
  for i in $(seq 1 200); do
    if [ -e "$gate/first-ready" ]; then first_ready=1; break; fi
    if ! kill -0 "$p1" 2>/dev/null; then break; fi
    sleep 0.01
  done
  [ "$first_ready" = 1 ] \
    || { : > "$gate/release"; wait "$p1" || true; cat "$output1" >&2; fail "$label did not reach the first Python-lock holder gate"; }

  (timeout 20 env PATH="$fake_bin" "$case_root/sandbox/bin/pair.sh" up pysecond 9913 9914 --headless >"$output2" 2>&1) &
  p2=$!
  # This deliberately exceeds the old two-second admission timeout. A
  # contender must remain blocked in the portable fcntl path until the first
  # owner releases its reservation, then re-query the live pair list.
  sleep 3
  if grep -q "could not acquire the shared pair-budget lock with Python fcntl" "$output2"; then
    : > "$gate/release"; wait "$p1" || true; wait "$p2" || true
    fail "$label contender gave up while the first owner legitimately held the lock"
  fi
  [ ! -e "$case_root/sandbox/siterepo/pysecond1" ] \
    || { : > "$gate/release"; wait "$p1" || true; wait "$p2" || true; fail "$label contender created side-1 state while Python lock was held"; }
  if grep -F '<-p> <wprism-pysecond>' "$log" >/dev/null; then
    : > "$gate/release"; wait "$p1" || true; wait "$p2" || true
    fail "$label contender reached Docker before the Python lock released"
  fi
  : > "$gate/release"
  status1=0; wait "$p1" || status1=$?
  status2=0; wait "$p2" || status2=$?
  [ "$status1" -eq 0 ] \
    || { cat "$output1" >&2; fail "$label first Python-lock pair failed"; }
  [ "$status2" -ne 0 ] \
    || { cat "$output2" >&2; fail "$label Python-lock contender unexpectedly succeeded"; }
  grep -q "refusing to bring up new pair 'pysecond' over budget" "$output2" \
    || fail "$label contender did not report the Python-lock budget refusal"
  [ ! -e "$case_root/sandbox/siterepo/pysecond1" ] \
    || fail "$label contender created side-1 state after Python-lock refusal"
  pass "$label: Python fcntl fallback preserves mutual exclusion under concurrent up"
}

run_python_lock_sigkill_case() {
  local label=python_lock_sigkill pair=pykilled
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" gate="$TMP/$label/gate" \
    output="$TMP/$label/output.log" canonical_root="$TMP/$label/canonical" \
    pid residue i first_ready=0
  mkdir -p "$case_root/sandbox/bin" "$fake_bin" "$gate"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  install_python_lock_path "$fake_bin"
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=3 WPRISM_PAIR_TEST_MEM=4294967296 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$canonical_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE="$gate" WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    WPRISM_PAIR_TEST_CONTAINERS='' WPRISM_PAIR_TEST_FAIL_PS=0 \
    WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER='' WPRISM_PAIR_BUDGET_OVERRIDE=0

  # Kill the shell owner while its helper is blocked in the first live-pair
  # query. The helper must notice that its direct parent disappeared and
  # remove only its own FIFO/ready/helper directory even while the blocked
  # fake Docker child still holds an inherited FIFO writer.
  (env PATH="$fake_bin" "$case_root/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless >"$output" 2>&1) &
  pid=$!
  for i in $(seq 1 300); do
    if [ -e "$gate/first-ready" ]; then first_ready=1; break; fi
    if ! kill -0 "$pid" 2>/dev/null; then break; fi
    sleep 0.01
  done
  [ "$first_ready" = 1 ] \
    || { : > "$gate/release"; wait "$pid" || true; cat "$output" >&2; fail "$label did not reach the Python-lock holder gate"; }
  kill -KILL "$pid" 2>/dev/null || true

  residue=""
  for i in $(seq 1 200); do
    residue="$(find "$canonical_root/sandbox/siterepo" -maxdepth 1 -type d \
      -name '.pair-budget-helper.*' -print -quit 2>/dev/null || true)"
    [ -z "$residue" ] && break
    sleep 0.05
  done
  [ -z "$residue" ] \
    || fail "$label left a Python helper directory after its parent was SIGKILLed: $residue"
  : > "$gate/release"
  wait "$pid" 2>/dev/null || true
  pass "$label: SIGKILLed Python-lock owner leaves no helper residue"
}

run_flock_lock_sigkill_case() {
  local label=flock_lock_sigkill pair=flockkilled
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" gate="$TMP/$label/gate" \
    output1="$TMP/$label/holder.log" output2="$TMP/$label/contender.log" \
    canonical_root="$TMP/$label/canonical" lock_path \
    holder=flockholder holder_pid contender_pid first_ready=0 helper_count=1 i status2=0
  mkdir -p "$case_root/sandbox/bin" "$fake_bin" "$gate"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  lock_path="$canonical_root/sandbox/siterepo/.pair-budget.lock"
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=8 WPRISM_PAIR_TEST_MEM=8589934592 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$canonical_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE="$gate" WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    WPRISM_PAIR_TEST_CONTAINERS='' WPRISM_PAIR_TEST_FAIL_PS=0 \
    WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER='' WPRISM_PAIR_BUDGET_OVERRIDE=0

  # The fake compose live query deliberately remains blocked after the shell
  # has acquired its reservation. The lock must be owned only by the helper:
  # killing this shell must let a new contender acquire immediately even
  # though the orphaned fake Docker child still waits on the gate.
  env PATH="$fake_bin:$ORIGINAL_PATH" "$case_root/sandbox/bin/pair.sh" up "$holder" 9911 9912 --headless \
    >"$output1" 2>&1 &
  holder_pid=$!
  for i in $(seq 1 300); do
    if [ -e "$gate/first-ready" ]; then first_ready=1; break; fi
    if ! pid_running "$holder_pid"; then break; fi
    sleep 0.01
  done
  [ "$first_ready" = 1 ] \
    || abort_lock_cancellation_case "$gate" "$holder_pid" "$holder_pid" \
      "$label holder did not reach the blocked Docker child"
  kill -KILL "$holder_pid" 2>/dev/null || true

  helper_count=1
  for i in $(seq 1 200); do
    helper_count="$(find "$canonical_root/sandbox/siterepo" -maxdepth 1 -type d \
      -name '.pair-budget-helper.*' -print 2>/dev/null | wc -l | tr -d ' ')"
    [ "${helper_count:-0}" -eq 0 ] && break
    sleep 0.01
  done
  [ "${helper_count:-0}" -eq 0 ] \
    || fail "$label left a flock helper after the owner was SIGKILLed"
  wait "$holder_pid" 2>/dev/null || true

  env PATH="$fake_bin:$ORIGINAL_PATH" "$case_root/sandbox/bin/pair.sh" up "$pair" 9913 9914 --headless \
    >"$output2" 2>&1 &
  contender_pid=$!
  if ! wait_pid_exit "$contender_pid" 400; then
    : > "$gate/release"
    kill -KILL "$contender_pid" 2>/dev/null || true
    wait "$contender_pid" 2>/dev/null || true
    fail "$label contender remained blocked by an orphaned lock holder"
  fi
  wait "$contender_pid" 2>/dev/null || status2=$?
  [ "$status2" -eq 0 ] \
    || { : > "$gate/release"; cat "$output2" >&2; fail "$label contender failed after owner SIGKILL"; }
  [ -e "$case_root/sandbox/siterepo/${pair}1" ] \
    || { : > "$gate/release"; fail "$label contender did not acquire after owner SIGKILL"; }
  : > "$gate/release"
  pass "$label: SIGKILLed owner cannot strand flock in its blocked Docker child"
}

run_pair_lock_cancellation_case() {
  local mode="$1" signal="$2" signal_label label
  case "$signal" in
    TERM) signal_label=term ;;
    KILL) signal_label=kill ;;
    *) fail "unsupported lock-cancellation signal: $signal" ;;
  esac
  label="${mode}_lock_${signal_label}"
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" gate="$TMP/$label/gate" \
    output1="$TMP/$label/holder.log" output2="$TMP/$label/contender.log" \
    canonical_root="$TMP/$label/canonical" lock_path \
    holder="holder${mode}${signal_label}" contender="contender${mode}${signal_label}" \
    first_pid contender_pid first_ready=0 i status1=0 status2=0 \
    helper_count ls_held ls_before ls_after waiter path_value
  mkdir -p "$case_root/sandbox/bin" "$fake_bin" "$gate"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  path_value="$fake_bin:$ORIGINAL_PATH"
  if [ "$mode" = python ]; then
    install_python_lock_path "$fake_bin"
    path_value="$fake_bin"
  fi
  lock_path="$canonical_root/sandbox/siterepo/.pair-budget.lock"
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=8 WPRISM_PAIR_TEST_MEM=8589934592 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$canonical_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE="$gate" WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    WPRISM_PAIR_TEST_CONTAINERS='' WPRISM_PAIR_TEST_FAIL_PS=0 \
    WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER='' WPRISM_PAIR_BUDGET_OVERRIDE=0

  # The holder is stopped in its first live-pair query, after it owns the
  # reservation. This leaves the contender blocked for the entire cancellation
  # assertion and makes any post-cancellation acquisition observable.
  env PATH="$path_value" "$case_root/sandbox/bin/pair.sh" up "$holder" 9911 9912 --headless \
    >"$output1" 2>&1 &
  first_pid=$!
  for i in $(seq 1 300); do
    if [ -e "$gate/first-ready" ]; then first_ready=1; break; fi
    if ! pid_running "$first_pid"; then break; fi
    sleep 0.01
  done
  [ "$first_ready" = 1 ] \
    || abort_lock_cancellation_case "$gate" "$first_pid" "$first_pid" \
      "$label holder did not reach the lock-holder gate"

  env PATH="$path_value" "$case_root/sandbox/bin/pair.sh" up "$contender" 9913 9914 --headless \
    >"$output2" 2>&1 &
  contender_pid=$!
  sleep 0.2
  [ ! -e "$case_root/sandbox/siterepo/${contender}1" ] \
    || abort_lock_cancellation_case "$gate" "$first_pid" "$contender_pid" \
      "$label contender created side-1 state while the holder owned the lock"
  if grep -F "<-p> <wprism-$contender>" "$log" >/dev/null; then
    abort_lock_cancellation_case "$gate" "$first_pid" "$contender_pid" \
      "$label contender reached Compose while the holder owned the lock"
  fi
  ls_held="$(grep -cF 'docker <compose> <ls>' "$log" 2>/dev/null || true)"

  kill -"$signal" "$contender_pid" 2>/dev/null || true
  if ! wait_pid_exit "$contender_pid" 200; then
    abort_lock_cancellation_case "$gate" "$first_pid" "$contender_pid" \
      "$label contender did not exit promptly after SIG$signal"
  fi
  wait "$contender_pid" 2>/dev/null || status2=$?
  [ "$status2" -ne 0 ] \
    || abort_lock_cancellation_case "$gate" "$first_pid" "$contender_pid" \
      "$label contender unexpectedly succeeded after SIG$signal"
  [ "$(grep -cF 'docker <compose> <ls>' "$log" 2>/dev/null || true)" = "$ls_held" ] \
    || abort_lock_cancellation_case "$gate" "$first_pid" "$contender_pid" \
      "$label contender performed a live-pair query before holder release"

  # A blocked external `flock -x 9` would survive the shell's signal and keep
  # this exact lock descriptor open. The new nonblocking shell loop must leave
  # no such waiter while the holder is still gated.
  waiter=""
  for i in $(seq 1 200); do
    waiter="$(flock_waiter_for_path "$lock_path" || true)"
    [ -z "$waiter" ] && break
    sleep 0.01
  done
  [ -z "$waiter" ] \
    || abort_lock_cancellation_case "$gate" "$first_pid" "$contender_pid" \
      "$label left an external flock waiter after SIG$signal: $waiter"

  if [ "$mode" = python ]; then
    # The contender's helper owns a unique directory. TERM must close its
    # control FIFO and SIGKILL must be noticed by the helper itself; either
    # way, only the holder's one helper directory may remain before release.
    helper_count=2
    for i in $(seq 1 200); do
      helper_count="$(find "$canonical_root/sandbox/siterepo" -maxdepth 1 -type d \
        -name '.pair-budget-helper.*' -print 2>/dev/null | wc -l | tr -d ' ')"
      [ "${helper_count:-0}" -le 1 ] && break
      sleep 0.01
    done
    [ "${helper_count:-0}" -le 1 ] \
      || abort_lock_cancellation_case "$gate" "$first_pid" "$contender_pid" \
        "$label left a contender Python helper directory after SIG$signal"
  fi

  # Release only after all cancellation checks. If any waiter survived, it
  # would acquire here and make a second live query or create the contender's
  # site roots, which is explicitly forbidden after cancellation.
  ls_before="$(grep -cF 'docker <compose> <ls>' "$log" 2>/dev/null || true)"
  : > "$gate/release"
  wait "$first_pid" 2>/dev/null || status1=$?
  [ "$status1" -eq 0 ] \
    || { cat "$output1" >&2; fail "$label holder failed after its gate was released"; }
  for i in $(seq 1 200); do
    [ ! -e "$case_root/sandbox/siterepo/${contender}1" ] || break
    sleep 0.01
  done
  ls_after="$(grep -cF 'docker <compose> <ls>' "$log" 2>/dev/null || true)"
  [ "$ls_after" = "$ls_before" ] \
    || fail "$label cancelled contender reacquired the reservation after holder release"
  [ ! -e "$case_root/sandbox/siterepo/${contender}1" ] \
    || fail "$label cancelled contender created site state after holder release"
  pass "$label: blocked contender exits on SIG$signal without an orphan waiter"
}

run_reset_codebind_refusal_case() {
  local label=reset_codebind_refusal pair=resetbind
  local case_root="$TMP/$label" \
    fake_bin="$TMP/$label/fake-bin" log="$TMP/$label/docker.log" \
    output="$TMP/$label/output.log" root nested mount_line inode_before inode_after
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  root="$case_root/sandbox/siterepo/${pair}1"
  nested="$root/code/wp-content/plugins/demo-plugin"
  mkdir -p "$nested" "$case_root/sandbox/siterepo/${pair}2"
  printf 'do not delete this mounted code\n' > "$nested/marker.php"
  inode_before="$(inode_of "$nested")"
  mount_line="${nested}"$'\t/var/www/html/wp-content/plugins/demo-plugin'
  write_fake_docker "$fake_bin"
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS="$mount_line" \
    WPRISM_PAIR_TEST_CPU=8 WPRISM_PAIR_TEST_MEM=8589934592 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$case_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    WPRISM_PAIR_TEST_CONTAINERS="wprism-${pair}-wp1-1" WPRISM_PAIR_TEST_FAIL_PS=0 \
    WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER='' WPRISM_PAIR_BUDGET_OVERRIDE=0 \
    PATH="$fake_bin:$ORIGINAL_PATH"

  if "$case_root/sandbox/bin/pair.sh" reset "$pair" >"$output" 2>&1; then
    cat "$output" >&2
    fail "$label unexpectedly reset a codebind-mounted pair"
  fi
  grep -q "codebind mount" "$output" \
    || { cat "$output" >&2; fail "$label did not identify the nested codebind mount"; }
  inode_after="$(inode_of "$nested")"
  [ "$inode_before" = "$inode_after" ] || fail "$label changed the nested codebind inode"
  [ -f "$nested/marker.php" ] || fail "$label deleted content from the nested codebind source"
  if grep -F "<-p> <wprism-db>" "$log" >/dev/null; then
    fail "$label touched the DB before refusing codebind reset"
  fi
  pass "$label: reset refuses before deleting a nested codebind source"
}

run_reset_container_query_failure_case() {
  local kind="$1" label pair
  label="reset_${kind}_failure"
  pair="reset${kind}"
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" output="$TMP/$label/output.log" \
    root="$TMP/$label/sandbox/siterepo/${pair}1" expected
  mkdir -p "$case_root/sandbox/bin" "$fake_bin" "$root"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  printf 'must survive failed reset preflight\n' > "$root/marker.txt"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  if [ "$kind" = enumeration ]; then
    expected="could not enumerate pair containers before reset"
    export WPRISM_PAIR_TEST_CONTAINERS='' WPRISM_PAIR_TEST_FAIL_PS=1 \
      WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER=''
  else
    expected="could not inspect existing pair container wprism-${pair}-wp1-1"
    export WPRISM_PAIR_TEST_CONTAINERS="wprism-${pair}-wp1-1" WPRISM_PAIR_TEST_FAIL_PS=0 \
      WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER="wprism-${pair}-wp1-1"
  fi
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=8 WPRISM_PAIR_TEST_MEM=8589934592 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$case_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    WPRISM_PAIR_BUDGET_OVERRIDE=0 PATH="$fake_bin:$ORIGINAL_PATH"

  if "$case_root/sandbox/bin/pair.sh" reset "$pair" >"$output" 2>&1; then
    cat "$output" >&2
    fail "$label unexpectedly proceeded after strict Docker reset preflight failure"
  fi
  grep -q "$expected" "$output" \
    || fail "$label did not report the strict preflight failure"
  grep -q 'must survive failed reset preflight' "$root/marker.txt" \
    || fail "$label changed site state after the strict preflight failure"
  if grep -F '<-p> <wprism-db>' "$log" >/dev/null; then
    fail "$label touched the shared DB before the strict preflight failure"
  fi
  pass "$label: reset fails closed on Docker container ${kind} failure"
}

run_reset_inode_preservation_case() {
  local label=reset_inode_preservation pair=resetplain
  local case_root="$TMP/$label" \
    fake_bin="$TMP/$label/fake-bin" log="$TMP/$label/docker.log" \
    output="$TMP/$label/output.log" root root1_abs root2_abs inode_before inode_after
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  root="$case_root/sandbox/siterepo/${pair}1"
  mkdir -p "$root/code/wp-content/plugins/demo-plugin" "$case_root/sandbox/siterepo/${pair}2"
  printf 'old state\n' > "$root/code/wp-content/plugins/demo-plugin/marker.php"
  mkdir -p "$case_root/sandbox/siterepo/origin-${pair}.git"
  root1_abs="$(physical_path "$root")"
  root2_abs="$(physical_path "$case_root/sandbox/siterepo/${pair}2")"
  inode_before="$(inode_of "$root")"
  write_fake_docker "$fake_bin"
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=8 WPRISM_PAIR_TEST_MEM=8589934592 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$case_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    WPRISM_PAIR_TEST_CONTAINERS='' WPRISM_PAIR_TEST_FAIL_PS=0 \
    WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER='' WPRISM_PAIR_BUDGET_OVERRIDE=0 \
    PATH="$fake_bin:$ORIGINAL_PATH"

  "$case_root/sandbox/bin/pair.sh" reset "$pair" >"$output" 2>&1 \
    || { cat "$output" >&2; fail "$label reset failed"; }
  inode_after="$(inode_of "$root")"
  [ "$inode_before" = "$inode_after" ] || fail "$label replaced the ordinary site-repo root inode"
  [ ! -e "$root/code" ] || fail "$label retained old site-repo content"
  [ ! -e "$case_root/sandbox/siterepo/origin-${pair}.git" ] \
    || fail "$label retained the origin repository"
  assert_file_contains "$log" "<run> <--rm> <-u> <root> <--mount> <type=bind,src=${root1_abs},dst=/siterepo>" \
    "$label did not hand side 1 back through its exact resolved root mount"
  assert_file_contains "$log" "<run> <--rm> <-u> <root> <--mount> <type=bind,src=${root2_abs},dst=/siterepo>" \
    "$label did not hand side 2 back through its exact resolved root mount"
  assert_before "$log" "<type=bind,src=${root1_abs},dst=/siterepo>" "<-p> <wprism-db>"
  pass "$label: ordinary reset clears contents while preserving bind-root inode"
}

run_repo_host_scope_case() {
  local label=repo_host_scope pair=handoff
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" output="$TMP/$label/output.log" root1 root2 root1_abs root2_abs inode_before inode_after
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  root1="$case_root/sandbox/siterepo/${pair}1"
  root2="$case_root/sandbox/siterepo/${pair}2"
  mkdir -p "$root1/state/nested" "$root2/state/other"
  printf 'uid-bound fixture\n' > "$root1/state/nested/record.json"
  printf 'other side must survive\n' > "$root2/state/other/marker"
  root1_abs="$(physical_path "$root1")"
  root2_abs="$(physical_path "$root2")"
  inode_before="$(inode_of "$root1")"
  write_fake_docker "$fake_bin"
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=8 WPRISM_PAIR_TEST_MEM=8589934592 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$case_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    WPRISM_PAIR_TEST_CONTAINERS='' WPRISM_PAIR_TEST_FAIL_PS=0 \
    WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER='' WPRISM_PAIR_TEST_FAIL_REPO_HANDOFF=0 \
    PATH="$fake_bin:$ORIGINAL_PATH"

  "$case_root/sandbox/bin/pair.sh" repo-host "$pair" 1 >"$output" 2>&1 \
    || { cat "$output" >&2; fail "$label exact-side handback failed"; }
  inode_after="$(inode_of "$root1")"
  [ "$inode_before" = "$inode_after" ] || fail "$label replaced the pair root inode"
  [ -f "$root1/state/nested/record.json" ] || fail "$label deleted pair content during handback"
  [ -f "$root2/state/other/marker" ] || fail "$label touched the unselected peer root"
  [ "$(grep -cF "<type=bind,src=${root1_abs},dst=/siterepo>" "$log")" = 1 ] \
    || fail "$label did not use exactly one side-1 root container"
  [ "$(grep -cF "<type=bind,src=${root2_abs},dst=/siterepo>" "$log" 2>/dev/null || true)" = 0 ] \
    || fail "$label touched side 2 while only side 1 was selected"
  pass "$label: one exact pair side is handed back without inode/content/peer mutation"
}

run_repo_host_shape_refusal_case() {
  local label=repo_host_shape_refusal pair=hostshape
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" output="$TMP/$label/output.log" root peer outside
  mkdir -p "$case_root/sandbox/bin" "$fake_bin" "$case_root/outside"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  root="$case_root/sandbox/siterepo/${pair}1"
  peer="$case_root/sandbox/siterepo/${pair}2"
  outside="$case_root/outside/not-a-pair-root"
  mkdir -p "$peer/state"
  printf 'unselected peer must survive\n' > "$peer/state/marker"
  write_fake_docker "$fake_bin"

  ln -s "$outside" "$root"
  if env WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_FAIL_REPO_HANDOFF=0 PATH="$fake_bin:$ORIGINAL_PATH" \
    "$case_root/sandbox/bin/pair.sh" repo-host "$pair" 1 >"$output" 2>&1; then
    fail "$label accepted a symlink instead of an exact ordinary root"
  fi
  assert_file_contains "$output" 'repository root is not an ordinary directory' \
    "$label did not name the symlink refusal"
  rm "$root"

  printf 'not a directory\n' > "$root"
  if env WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_FAIL_REPO_HANDOFF=0 PATH="$fake_bin:$ORIGINAL_PATH" \
    "$case_root/sandbox/bin/pair.sh" repo-host "$pair" 1 >"$output" 2>&1; then
    fail "$label accepted a non-directory instead of an exact ordinary root"
  fi
  assert_file_contains "$output" 'repository root is not an ordinary directory' \
    "$label did not name the non-directory refusal"
  if [ -f "$log" ] && grep -F '<run> <-u> <root>' "$log" >/dev/null; then
    fail "$label reached the root handback container after a shape refusal"
  fi
  [ -f "$peer/state/marker" ] || fail "$label touched the unselected peer after a shape refusal"
  pass "$label: symlink and non-directory roots refuse before probe/container/peer mutation"
}

run_repo_host_refusal_case() {
  local label=repo_host_refusal pair=handofffail
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" output="$TMP/$label/output.log" root
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  root="$case_root/sandbox/siterepo/${pair}1"
  mkdir -p "$root/state/nested" "$case_root/sandbox/siterepo/${pair}2"
  printf 'must survive handback refusal\n' > "$root/state/nested/record.json"
  write_fake_docker "$fake_bin"
  export WPRISM_PAIR_TEST_LOG="$log" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=8 WPRISM_PAIR_TEST_MEM=8589934592 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$case_root" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    WPRISM_PAIR_TEST_CONTAINERS='' WPRISM_PAIR_TEST_FAIL_PS=0 \
    WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER='' WPRISM_PAIR_TEST_FAIL_REPO_HANDOFF=1 \
    PATH="$fake_bin:$ORIGINAL_PATH"

  if "$case_root/sandbox/bin/pair.sh" reset "$pair" >"$output" 2>&1; then
    fail "$label reset continued after ownership handback failure"
  fi
  assert_file_contains "$output" "could not return exact pair repository" \
    "$label did not report the exact-root handback refusal"
  [ -f "$root/state/nested/record.json" ] \
    || fail "$label changed repository content after handback refusal"
  if grep -F '<-p> <wprism-db>' "$log" >/dev/null; then
    fail "$label touched the database after handback refusal"
  fi
  export WPRISM_PAIR_TEST_FAIL_REPO_HANDOFF=0
  pass "$label: failed ownership handback refuses before database or repository mutation"
}

run_repo_host_platform_ownership_proof_case() {
  local label=repo_host_platform_ownership_proof pair=hostproof
  local case_root="${TMP}/${label}" fake_bin="${TMP}/${label}/fake-bin" \
    log="${TMP}/${label}/docker.log" output="${TMP}/${label}/output.log" \
    chgrp_log marker normalized_marker replaced_root root peer root_abs peer_abs inode_before inode_after
  mkdir -p "${case_root}/sandbox/bin" "${fake_bin}"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "${case_root}/sandbox/bin/pair.sh"
  root="${case_root}/sandbox/siterepo/${pair}1"
  peer="${case_root}/sandbox/siterepo/${pair}2"
  mkdir -p "${root}/state/nested" "${peer}/state"
  printf 'ownership proof fixture\n' > "${root}/state/nested/record.json"
  printf 'unselected peer\n' > "${peer}/state/marker"
  root_abs="$(physical_path "${root}")"
  peer_abs="$(physical_path "${peer}")"
  marker="${case_root}/handback-complete"
  normalized_marker="${case_root}/root-normalized"
  replaced_root="${case_root}/replaced-root"
  chgrp_log="${case_root}/chgrp.log"
  inode_before="$(inode_of "${root}")"
  write_fake_docker "${fake_bin}"
  write_fake_owner_identity_tools "${fake_bin}"

  run_handback() { # flavor post-Docker-owner post-chgrp-owner post-chgrp-inode chgrp-fails replace-root
    local flavor="$1" owner_after="$2" owner_normalized="$3" inode_normalized="$4" fail_chgrp="$5" replace_root="$6"
    : > "${log}"
    : > "${chgrp_log}"
    rm -f "${marker}" "${normalized_marker}"
    env \
      WPRISM_PAIR_TEST_LOG="${log}" WPRISM_PAIR_TEST_CHGRP_LOG="${chgrp_log}" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
      WPRISM_PAIR_TEST_HOST_UID=501 WPRISM_PAIR_TEST_HOST_GID=20 \
      WPRISM_PAIR_TEST_STAT_FLAVOR="${flavor}" WPRISM_PAIR_TEST_STAT_ROOT="${root_abs}" WPRISM_PAIR_TEST_STAT_ROOT_LEXICAL="siterepo/${pair}1" \
      WPRISM_PAIR_TEST_STAT_ROOT_INODE_BEFORE=424242 WPRISM_PAIR_TEST_STAT_ROOT_INODE_AFTER_DOCKER=424242 \
      WPRISM_PAIR_TEST_STAT_ROOT_INODE_AFTER_NORMALIZED="${inode_normalized}" \
      WPRISM_PAIR_TEST_STAT_ROOT_OWNER_AFTER_DOCKER="${owner_after}" WPRISM_PAIR_TEST_STAT_ROOT_OWNER_NORMALIZED="${owner_normalized}" \
      WPRISM_PAIR_TEST_ROOT_NORMALIZED_MARKER="${normalized_marker}" WPRISM_PAIR_TEST_REPO_HANDOFF_MARKER="${marker}" \
      WPRISM_PAIR_TEST_FAIL_ROOT_CHGRP="${fail_chgrp}" WPRISM_PAIR_TEST_REPLACE_ROOT_WITH_SYMLINK="${replace_root}" \
      WPRISM_PAIR_TEST_REPLACED_ROOT="${replaced_root}" WPRISM_PAIR_TEST_REPLACEMENT_TARGET="${peer_abs}" \
      WPRISM_PAIR_TEST_FAIL_REPO_HANDOFF=0 \
      PATH="${fake_bin}:${ORIGINAL_PATH}" \
      "${case_root}/sandbox/bin/pair.sh" repo-host "${pair}" 1 >"${output}" 2>&1
  }
  assert_root_scope_unchanged() {
    inode_after="$(inode_of "${root}")"
    [ "${inode_before}" = "${inode_after}" ] || fail "${label} replaced the selected root inode"
    [ -f "${root}/state/nested/record.json" ] || fail "${label} changed selected-root content"
    [ -f "${peer}/state/marker" ] || fail "${label} touched the unselected peer root"
  }
  assert_exact_root_chgrp() {
    assert_file_contains "${chgrp_log}" "chgrp <-h> <20> <${root_abs}>" \
      "${label} did not normalize only the exact physical selected root"
    ! grep -Fq '<-R>' "${chgrp_log}" \
      || fail "${label} widened exact-root group normalization recursively"
    ! grep -Fq -- "${peer_abs}" "${chgrp_log}" \
      || fail "${label} normalized the unselected peer root"
  }

  # Native ownership remains literally exact after Docker, so no host chgrp
  # is needed. The real inode/content and peer prove the one-root mount did
  # not widen the operation's scope.
  if ! run_handback gnu 501:20 501:20 424242 0 0; then
    cat "${output}" >&2
    fail "${label} rejected native exact ownership handback"
  fi
  [ -e "${marker}" ] && [ ! -e "${normalized_marker}" ] && [ ! -s "${chgrp_log}" ] \
    || fail "${label} used host chgrp despite native exact ownership"
  assert_file_contains "${log}" "<type=bind,src=${root_abs},dst=/siterepo>" \
    "${label} did not use the exact selected bind root"
  ! grep -Fq 'owner-probe' "${log}" \
    || fail "${label} retained a capability-probe mount"
  assert_root_scope_unchanged

  # Docker Desktop can faithfully return the host UID while translating the
  # bind-root GID to 0. Only the already-checked exact physical root is
  # repaired, followed by a final literal ownership readback.
  if ! run_handback bsd 501:0 501:20 424242 0 0; then
    cat "${output}" >&2
    fail "${label} rejected exact-root Docker Desktop GID normalization"
  fi
  [ -e "${marker}" ] && [ -e "${normalized_marker}" ] \
    || fail "${label} did not perform the required post-Docker root normalization"
  assert_exact_root_chgrp
  assert_root_scope_unchanged

  # A foreign UID is never a candidate for host-side group repair, even if a
  # chgrp readback would otherwise look exact.
  if run_handback bsd 502:0 501:20 424242 0 0; then
    fail "${label} accepted a foreign root UID after Docker handback"
  fi
  assert_file_contains "${output}" 'has foreign uid after handback (got 502:0; expected uid 501)' \
    "${label} did not report the foreign-UID ownership refusal"
  [ -e "${marker}" ] && [ ! -e "${normalized_marker}" ] && [ ! -s "${chgrp_log}" ] \
    || fail "${label} reached chgrp after a foreign root UID"
  assert_root_scope_unchanged

  # A root-only chgrp command failure is fatal after revalidation and cannot
  # advance into any later compose/database path.
  if run_handback bsd 501:0 501:20 424242 1 0; then
    fail "${label} continued after exact-root chgrp failure"
  fi
  assert_file_contains "${output}" 'could not normalize exact pair repository siterepo/hostproof1 to host group 20 after handback' \
    "${label} did not report the exact-root chgrp refusal"
  [ -e "${marker}" ] && [ ! -e "${normalized_marker}" ] \
    || fail "${label} recorded a successful normalization after chgrp failure"
  assert_exact_root_chgrp
  assert_root_scope_unchanged

  # A successful command is insufficient: the post-normalization readback
  # must be the caller's literal uid:gid, not merely the expected UID.
  if run_handback bsd 501:0 501:0 424242 0 0; then
    fail "${label} accepted an ineffective exact-root chgrp"
  fi
  assert_file_contains "${output}" 'still has owner 501:0 after handback (expected 501:20)' \
    "${label} did not report the ineffective exact-root chgrp refusal"
  [ -e "${marker}" ] && [ -e "${normalized_marker}" ] \
    || fail "${label} did not record the ineffective chgrp attempt"
  assert_exact_root_chgrp
  assert_root_scope_unchanged

  # Revalidate the root's shape/inode after the one permitted host mutation;
  # an inode swap is a hard refusal even when its fake ownership is exact.
  if run_handback bsd 501:0 501:20 515151 0 0; then
    fail "${label} accepted a post-chgrp root inode mismatch"
  fi
  assert_file_contains "${output}" 'exact pair repository inode changed during ownership handback' \
    "${label} did not report the post-chgrp inode refusal"
  [ -e "${marker}" ] && [ -e "${normalized_marker}" ] \
    || fail "${label} did not record the post-chgrp inode-mismatch path"
  assert_exact_root_chgrp
  assert_root_scope_unchanged

  # The same post-chgrp revalidation also refuses a root that became a
  # symlink. The fake retains the original directory separately so this
  # assertion proves neither its content nor the peer was touched further.
  if run_handback bsd 501:0 501:20 424242 0 1; then
    fail "${label} accepted a post-chgrp root symlink replacement"
  fi
  assert_file_contains "${output}" 'exact pair repository root changed from an ordinary directory during ownership handback' \
    "${label} did not report the post-chgrp root-shape refusal"
  [ -e "${marker}" ] && [ -e "${normalized_marker}" ] && [ -L "${root_abs}" ] \
    || fail "${label} did not exercise the post-chgrp root-shape refusal"
  [ -f "${replaced_root}/state/nested/record.json" ] && [ -f "${peer}/state/marker" ] \
    || fail "${label} changed selected-root content or the unselected peer after a root-shape refusal"
  assert_exact_root_chgrp
  ! grep -Fq 'owner-probe' "${log}" \
    || fail "${label} retained a capability-probe mount after normalization"
  pass "${label}: exact root-only GID repair preserves native ownership and refuses foreign UID, chgrp failure/ineffectiveness, and root shape/inode change"
}

# --- issue #3412: the needs-install marker's whole lifecycle --------------------
#
# reset DROP/CREATEs both databases and then leaves the containers running;
# `up` decides whether to install by asking that still-warm site `wp core
# is-installed`. One TRUE from a probe made against a database this harness
# itself emptied seconds earlier is enough to skip the reinstall, and the
# sweep then dies a manifest later on wp-cli's bare "Error: The site you have
# requested is not installed". These cases pin the structural answer: reset
# RECORDS the drop, `up` acts on the record instead of on a probe, and a
# bootstrap that ends with an uninstalled side refuses in its own domain.
#
# The stale probe is the fake's whole point here: WPRISM_PAIR_TEST_THEME_STATE_DIR
# seeded with core1/core2 makes `wp core is-installed` answer TRUE for a pair
# whose databases were just dropped — a state the real world can only reach
# through the bug, and the fake reaches deterministically.
marker_path() { # marker_path <case-root> <pair> <side>
  printf '%s/sandbox/siterepo/.%s%s.needs-install\n' "$1" "$2" "$3"
}

# Shared fixture for the marker-lifecycle cases: one copied pair.sh, one fake
# docker/git, one env block. Sets CASE_ROOT/FAKE_BIN/LOG/OUTPUT/THEME_STATE.
prepare_marker_case() { # prepare_marker_case <label>
  local label="$1"
  CASE_ROOT="$TMP/$label"
  FAKE_BIN="$CASE_ROOT/fake-bin"
  LOG="$CASE_ROOT/docker.log"
  OUTPUT="$CASE_ROOT/output.log"
  THEME_STATE="$CASE_ROOT/theme-state"
  mkdir -p "$CASE_ROOT/sandbox/bin" "$FAKE_BIN"
  copy_pair_launcher "$CASE_ROOT/sandbox/bin"
  chmod +x "$CASE_ROOT/sandbox/bin/pair.sh"
  write_fake_docker "$FAKE_BIN"
  write_fake_git "$FAKE_BIN"
  export WPRISM_PAIR_TEST_LOG="$LOG" WPRISM_PAIR_TEST_LIVE_PAIRS='[]' \
    WPRISM_PAIR_TEST_INSPECT_MOUNTS='' WPRISM_PAIR_TEST_CPU=8 WPRISM_PAIR_TEST_MEM=8589934592 \
    WPRISM_PAIR_TEST_CANONICAL_ROOT="$CASE_ROOT" WPRISM_PAIR_TEST_LIVE_FILE='' \
    WPRISM_PAIR_TEST_RACE_GATE='' WPRISM_PAIR_TEST_FAIL_INFO=0 WPRISM_PAIR_TEST_FAIL_LIVE=0 \
    WPRISM_PAIR_TEST_CONTAINERS='' WPRISM_PAIR_TEST_FAIL_PS=0 \
    WPRISM_PAIR_TEST_FAIL_INSPECT_CONTAINER='' WPRISM_PAIR_BUDGET_OVERRIDE=0 \
    WPRISM_PAIR_TEST_CORE_INSTALL_NOOP=0 WPRISM_PAIR_TEST_THEME_FAILURES=0 \
    PATH="$FAKE_BIN:$ORIGINAL_PATH"
  unset WPRISM_PAIR_TEST_THEME_STATE_DIR
}

assert_markers_present() { # assert_markers_present <label> <case-root> <pair> <what>
  local label="$1" case_root="$2" pair="$3" what="$4" side
  for side in 1 2; do
    [ -f "$(marker_path "$case_root" "$pair" "$side")" ] \
      || fail "$label: side $side has no needs-install marker ($what)"
  done
}

assert_markers_absent() { # assert_markers_absent <label> <case-root> <pair> <what>
  local label="$1" case_root="$2" pair="$3" what="$4" side
  for side in 1 2; do
    [ ! -e "$(marker_path "$case_root" "$pair" "$side")" ] \
      || fail "$label: side $side still carries a needs-install marker ($what)"
  done
}

run_reset_marks_needs_install_case() {
  local label=reset_marks_needs_install pair=resetmark root inode_before inode_after stray
  prepare_marker_case "$label"
  root="$CASE_ROOT/sandbox/siterepo/${pair}1"
  mkdir -p "$root/code/wp-content" "$CASE_ROOT/sandbox/siterepo/${pair}2/code"
  printf 'old state\n' > "$root/code/wp-content/marker.php"
  inode_before="$(inode_of "$root")"

  "$CASE_ROOT/sandbox/bin/pair.sh" reset "$pair" >"$OUTPUT" 2>&1 \
    || { cat "$OUTPUT" >&2; fail "$label reset failed"; }

  assert_markers_present "$label" "$CASE_ROOT" "$pair" "reset must record its own DROP"
  # The marker belongs beside pair.sh's other dot-file state, never inside a
  # site-repo root: those are bind-mounted into the containers as /siterepo and
  # are the repository the agent captures and commits, and reset's own
  # pair_siterepo_clear_root() empties them. Both roots must be completely empty
  # after reset — which proves the clearing contract and the location at once.
  stray="$(find "$CASE_ROOT/sandbox/siterepo/${pair}1" "$CASE_ROOT/sandbox/siterepo/${pair}2" -mindepth 1)"
  [ -z "$stray" ] || fail "$label left content inside a site-repo root after reset: $stray"
  # reset's pre-existing contract, unchanged by the new record.
  inode_after="$(inode_of "$root")"
  [ "$inode_before" = "$inode_after" ] || fail "$label replaced the ordinary site-repo root inode"
  assert_file_contains "$OUTPUT" 'dropped + recreated empty' \
    "$label changed reset's own completion report"
  assert_file_contains "$OUTPUT" "reset also recorded siterepo/.${pair}{1,2}.needs-install" \
    "$label did not tell the operator what the next up will do"
  pass "$label: reset records both needs-install markers beside pair.sh's own state"
}

run_reset_up_stale_probe_case() {
  local label=reset_up_stale_probe pair=staleprobe side second_log
  prepare_marker_case "$label"
  mkdir -p "$THEME_STATE"
  # THE stale probe: is-installed answers TRUE on both sides even though reset
  # is about to drop both databases. This is the false positive issue #3412 says
  # `up` must not act on.
  : > "$THEME_STATE/core1"
  : > "$THEME_STATE/core2"
  export WPRISM_PAIR_TEST_THEME_STATE_DIR="$THEME_STATE"

  "$CASE_ROOT/sandbox/bin/pair.sh" reset "$pair" >"$OUTPUT" 2>&1 \
    || { cat "$OUTPUT" >&2; fail "$label reset failed"; }
  assert_markers_present "$label" "$CASE_ROOT" "$pair" "fixture premise for the race case"

  "$CASE_ROOT/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless >>"$OUTPUT" 2>&1 \
    || { cat "$OUTPUT" >&2; fail "$label up failed over the stale is-installed probe"; }

  for side in 1 2; do
    # THE load-bearing assertion: the install happened anyway.
    assert_file_contains "$LOG" "<run> <--rm> <-T> <cli${side}> <wp> <core> <install>" \
      "$label skipped side $side's install on a stale is-installed TRUE"
    if grep -F "  side $side already installed" "$OUTPUT" >/dev/null; then
      fail "$label trusted the stale probe and reported side $side already installed"
    fi
  done
  assert_markers_absent "$label" "$CASE_ROOT" "$pair" "a successful install must consume the marker"
  assert_file_contains "$OUTPUT" "reset emptied wp_${pair}1" \
    "$label did not name why side 1's install was unconditional"
  assert_file_contains "$OUTPUT" "pair '$pair' ready" "$label did not finish the bootstrap"

  # Consumed, not merely overridden: a second `up` finds no marker, takes the
  # ordinary probe path, and installs nothing.
  second_log="$CASE_ROOT/docker-second-up.log"
  WPRISM_PAIR_TEST_LOG="$second_log" "$CASE_ROOT/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless \
    >>"$OUTPUT" 2>&1 || { cat "$OUTPUT" >&2; fail "$label second up failed"; }
  if grep -F '<wp> <core> <install>' "$second_log" >/dev/null; then
    fail "$label reinstalled on the second up — the marker was not consumed"
  fi
  unset WPRISM_PAIR_TEST_THEME_STATE_DIR
  pass "$label: reset->up installs across a stale is-installed TRUE and consumes the marker exactly once"
}

run_reset_up_stale_probe_mutation_case() {
  local label=reset_up_stale_probe_mutation pair=staleprobemut side launcher bootstrap_lib anchor
  prepare_marker_case "$label"
  launcher="$CASE_ROOT/sandbox/bin/pair.sh"
  bootstrap_lib="$CASE_ROOT/sandbox/lib/pair_bootstrap.sh"
  # THE MUTATION, stated exactly: remove install_side's marker consumption by
  # making its branch unreachable, which leaves the pre-issue #3412 code path
  # verbatim — one is-installed probe, skip on TRUE. Nothing else is touched.
  # The anchor count is asserted first: a drifted anchor would silently turn
  # this proof into a second copy of the passing case.
  anchor='if [ -e "$marker" ]; then'
  [ "$(grep -cF "$anchor" "$bootstrap_lib")" = 1 ] \
    || fail "$label could not uniquely locate install_side's marker branch to mutate ($anchor)"
  sed 's/if \[ -e "\$marker" \]; then/if false; then/' "$bootstrap_lib" > "$bootstrap_lib.mutant"
  mv "$bootstrap_lib.mutant" "$bootstrap_lib"
  [ "$(grep -cF "$anchor" "$bootstrap_lib")" = 0 ] || fail "$label mutation did not remove the marker branch"
  grep -qF 'if false; then' "$bootstrap_lib" || fail "$label mutation did not apply"

  mkdir -p "$THEME_STATE"
  : > "$THEME_STATE/core1"
  : > "$THEME_STATE/core2"
  export WPRISM_PAIR_TEST_THEME_STATE_DIR="$THEME_STATE"
  "$launcher" reset "$pair" >"$OUTPUT" 2>&1 \
    || { cat "$OUTPUT" >&2; fail "$label reset failed"; }
  "$launcher" up "$pair" 9911 9912 --headless >>"$OUTPUT" 2>&1 \
    || { cat "$OUTPUT" >&2; fail "$label up failed"; }

  # The mutant exits 0 and reports the pair ready — the issue #3412 failure is
  # silent at bootstrap by construction — while skipping the install that the
  # unmutated case above asserts. That skip IS reset_up_stale_probe's
  # load-bearing assertion failing.
  for side in 1 2; do
    if grep -F "<run> <--rm> <-T> <cli${side}> <wp> <core> <install>" "$LOG" >/dev/null; then
      fail "$label: mutant still installed side $side — the marker branch is not what makes the race case pass"
    fi
    assert_file_contains "$OUTPUT" "  side $side already installed" \
      "$label: mutant did not take the pre-fix probe-and-skip path on side $side"
  done
  assert_markers_present "$label" "$CASE_ROOT" "$pair" "mutant never consumes the marker"
  unset WPRISM_PAIR_TEST_THEME_STATE_DIR
  pass "$label: with marker consumption removed, the stale TRUE skips the install (race case fails on the mutant)"
}

run_install_idempotence_case() {
  local label=install_idempotence pair=idempotent side
  prepare_marker_case "$label"
  # No reset, so no marker, and the fake's default `wp core is-installed`
  # answers TRUE (every pre-existing case in this suite depends on that). The
  # no-marker path must be byte-for-byte today's contract: exactly one probe
  # per side, no install, nothing reinstalled.
  "$CASE_ROOT/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless >"$OUTPUT" 2>&1 \
    || { cat "$OUTPUT" >&2; fail "$label up failed"; }
  if grep -F '<wp> <core> <install>' "$LOG" >/dev/null; then
    fail "$label reinstalled a healthy pair that carried no needs-install marker"
  fi
  for side in 1 2; do
    assert_file_contains "$OUTPUT" "  side $side already installed" \
      "$label did not take the idempotent skip on side $side"
    [ "$(grep -cF "<run> <--rm> <-T> <cli${side}> <wp> <core> <is-installed>" "$LOG")" = 1 ] \
      || fail "$label did not probe side $side exactly once — the no-marker path is not byte-preserved"
  done
  assert_markers_absent "$label" "$CASE_ROOT" "$pair" "up must not invent markers"
  pass "$label: no marker + is-installed TRUE still probes once per side and installs nothing"
}

run_install_premise_assert_case() { # run_install_premise_assert_case <marker|nomarker>
  local mode="$1" label pair
  label="install_premise_assert_${mode}"
  pair="premise${mode}"
  prepare_marker_case "$label"
  mkdir -p "$THEME_STATE"
  # `core install` "succeeds" without installing anything, so is-installed
  # keeps answering FALSE. Reached through the forced path (after reset) and
  # through the ordinary probe path, because both must refuse.
  export WPRISM_PAIR_TEST_THEME_STATE_DIR="$THEME_STATE" WPRISM_PAIR_TEST_CORE_INSTALL_NOOP=1
  if [ "$mode" = marker ]; then
    "$CASE_ROOT/sandbox/bin/pair.sh" reset "$pair" >"$OUTPUT" 2>&1 \
      || { cat "$OUTPUT" >&2; fail "$label reset failed"; }
    assert_markers_present "$label" "$CASE_ROOT" "$pair" "fixture premise"
  fi

  if "$CASE_ROOT/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless >>"$OUTPUT" 2>&1; then
    cat "$OUTPUT" >&2
    fail "$label reported a healthy pair over a database that never got WordPress"
  fi
  assert_file_contains "$OUTPUT" \
    "pair bootstrap premise failed: side 1 (wp_${pair}1) is not installed after install_side" \
    "$label did not name the bootstrap premise failure in pair.sh's own domain"
  assert_file_contains "$OUTPUT" 'the sweep would die at its first seed call' \
    "$label did not say what the unasserted failure would have looked like"
  if grep -F "pair '$pair' ready" "$OUTPUT" >/dev/null; then
    fail "$label proceeded to report the pair ready after the premise failure"
  fi
  unset WPRISM_PAIR_TEST_THEME_STATE_DIR
  export WPRISM_PAIR_TEST_CORE_INSTALL_NOOP=0
  pass "$label: an install that leaves the side uninstalled refuses at bootstrap, not at the first seed"
}

run_destroy_clears_marker_case() {
  local label=destroy_clears_marker pair=destroymark
  prepare_marker_case "$label"
  "$CASE_ROOT/sandbox/bin/pair.sh" reset "$pair" >"$OUTPUT" 2>&1 \
    || { cat "$OUTPUT" >&2; fail "$label reset failed"; }
  assert_markers_present "$label" "$CASE_ROOT" "$pair" "fixture premise"

  "$CASE_ROOT/sandbox/bin/pair.sh" destroy "$pair" >>"$OUTPUT" 2>&1 \
    || { cat "$OUTPUT" >&2; fail "$label destroy failed"; }
  assert_markers_absent "$label" "$CASE_ROOT" "$pair" "destroy owns this pair's state"
  assert_file_contains "$OUTPUT" 'containers + webroot volumes removed' \
    "$label did not complete destroy's own contract"
  pass "$label: destroy leaves no needs-install marker behind for a recycled pair name"
}

say "bash syntax checks"
bash -n "$ROOT/sandbox/bin/pair.sh" "$ROOT/sandbox/lib/pair_identity.sh" "$ROOT/sandbox/lib/pair_force_hatch.sh" "$ROOT/sandbox/lib/pair_db.sh" \
  "$ROOT/sandbox/lib/pair_compose.sh" "$ROOT/sandbox/lib/pair_readiness.sh" "$ROOT/sandbox/lib/pair_bootstrap.sh" \
  "$ROOT/sandbox/lib/pair_siterepo.sh" "$ROOT/sandbox/lib/pair_lease.sh" \
  "$ROOT/sandbox/tests/offline/guards/regress_pair_bootstrap_unit.sh"
assert_file_contains "$ROOT/sandbox/bin/pair.sh" 'source "lib/pair_readiness.sh"' \
  'pair launcher no longer loads its readiness library'
assert_file_contains "$ROOT/sandbox/bin/pair.sh" 'source "lib/pair_force_hatch.sh"' \
  'pair launcher no longer loads its actual-use force-hatch ledger'
assert_file_contains "$ROOT/sandbox/lib/pair_readiness.sh" 'pair_readiness_wait_pair_visible()' \
  'pair-readiness library no longer owns Compose visibility observation'
assert_file_contains "$ROOT/sandbox/lib/pair_readiness.sh" 'pair_readiness_wait_db()' \
  'pair-readiness library no longer owns per-side database readiness'
assert_file_contains "$ROOT/sandbox/lib/pair_readiness.sh" 'pair_readiness_wait_web_mountpoints()' \
  'pair-readiness library no longer owns nested-MU mountpoint readiness'
if grep -qE '^wait_(pair_visible|db_ready|web_mountpoints)\\(\\)' "$ROOT/sandbox/bin/pair.sh"; then
  fail 'pair launcher still owns a readiness wait instead of delegating to pair_readiness.sh'
fi
assert_file_contains "$ROOT/sandbox/bin/pair.sh" 'source "lib/pair_bootstrap.sh"' \
  'pair launcher no longer loads its WordPress bootstrap library'
assert_file_contains "$ROOT/sandbox/lib/pair_bootstrap.sh" 'pair_bootstrap_install_side()' \
  'pair-bootstrap library no longer owns core installation and premise verification'
assert_file_contains "$ROOT/sandbox/lib/pair_bootstrap.sh" 'pair_bootstrap_install_and_activate_theme()' \
  'pair-bootstrap library no longer owns bounded theme activation'
assert_file_contains "$ROOT/sandbox/lib/pair_bootstrap.sh" 'pair_bootstrap_mark_sides_need_install()' \
  'pair-bootstrap library no longer owns reset-to-bootstrap state'
if grep -qE '^(write_htaccess|install_and_activate_theme|install_side|needs_install_marker|mark_sides_need_install|clear_needs_install_markers)\(\)' "$ROOT/sandbox/bin/pair.sh"; then
  fail 'pair launcher still owns WordPress bootstrap helpers instead of delegating to pair_bootstrap.sh'
fi
assert_file_contains "$ROOT/sandbox/bin/pair.sh" 'source "lib/pair_siterepo.sh"' \
  'pair launcher no longer loads its exact site-repository library'
assert_file_contains "$ROOT/sandbox/lib/pair_siterepo.sh" 'pair_siterepo_host()' \
  'pair-siterepo library no longer owns exact uid-33 handback'
assert_file_contains "$ROOT/sandbox/lib/pair_siterepo.sh" 'pair_siterepo_clear_root()' \
  'pair-siterepo library no longer owns inode-preserving root clearing'
assert_file_contains "$ROOT/sandbox/lib/pair_siterepo.sh" 'pair_siterepo_refuse_codebind_reset()' \
  'pair-siterepo library no longer owns the nested-codebind reset refusal'
if grep -qE '^(prepare_siterepo_roots|repo_host(_stat_owner|_stat_inode|_revalidate_root|_one)?|clear_siterepo_root|refuse_codebind_reset)\(\)' "$ROOT/sandbox/bin/pair.sh"; then
  fail 'pair launcher still owns site-repository cleanup helpers instead of delegating to pair_siterepo.sh'
fi
command -v stat >/dev/null 2>&1 || fail "stat is required for inode-preservation regression"
grep -Fq 'GIT_CONFIG_KEY_0: safe.directory' "$ROOT/sandbox/pair.yml" \
  || fail "pair CLI services do not declare the exact Git trust key"
grep -Fq 'GIT_CONFIG_VALUE_0: /siterepo' "$ROOT/sandbox/pair.yml" \
  || fail "pair CLI services do not scope Git trust to exact /siterepo"
! grep -Fq 'safe.directory=*' "$ROOT/sandbox/pair.yml" \
  || fail "pair Git trust widened to a wildcard"
assert_file_contains "$ROOT/sandbox/lib/pair_siterepo.sh" 'if ! chgrp -h "$host_gid" "$root_abs"; then' \
  'pair handback does not narrowly normalize the exact physical root after Docker'
assert_file_contains "$ROOT/sandbox/lib/pair_siterepo.sh" 'if [ -L "$root" ] || [ ! -d "$root" ]; then' \
  'pair handback does not revalidate the root as an ordinary directory after Docker returns'
assert_file_contains "$ROOT/sandbox/lib/pair_siterepo.sh" 'if [ -L "$root_abs" ] || [ ! -d "$root_abs" ]; then' \
  'pair handback does not check the resolved physical root before Docker owns it'
assert_file_contains "$ROOT/sandbox/lib/pair_siterepo.sh" 'pair_siterepo_revalidate_root "$root_abs" "$root_inode_before"' \
  'pair handback does not revalidate the physical root shape and inode after Docker returns'
assert_file_contains "$ROOT/sandbox/bin/pair.sh" '"${PAIR_COMPOSE[@]}" up -d --force-recreate wp1 wp2' \
  'codebind bootstrap no longer passes --force-recreate explicitly'
assert_file_contains "$ROOT/sandbox/bin/pair.sh" '"${PAIR_COMPOSE[@]}" up -d wp1 wp2' \
  'ordinary bootstrap no longer has an empty-optional-argument-free compose path'
assert_file_contains "$ROOT/sandbox/bin/pair.sh" 'if [ "${#overlays[@]}" -gt 0 ]; then' \
  'plain bootstrap no longer guards its legitimately empty overlay array'
if grep -Fq '${force_recreate[@]}' "$ROOT/sandbox/bin/pair.sh"; then
  fail 'ordinary bootstrap still expands an empty optional array under stock Bash 3.2'
fi
assert_file_contains "$ROOT/sandbox/lib/pair_siterepo.sh" 'owner_uid="${owner%%:*}"' \
  'pair handback does not isolate and check the returned root uid before chgrp'
assert_before "$ROOT/sandbox/lib/pair_siterepo.sh" 'owner_uid="${owner%%:*}"' 'if ! chgrp -h "$host_gid" "$root_abs"; then'
assert_before "$ROOT/sandbox/lib/pair_siterepo.sh" 'if ! chgrp -h "$host_gid" "$root_abs"; then' '[ "$owner" = "${host_uid}:${host_gid}" ]'
! grep -Fq 'owner-probe' "$ROOT/sandbox/lib/pair_siterepo.sh" \
  || fail "pair handback retained a sibling capability probe"
! grep -Fq 'chgrp -R' "$ROOT/sandbox/lib/pair_siterepo.sh" \
  || fail "pair handback widened exact-root group normalization recursively"
pass "pair launcher, readiness/bootstrap/site-repository libraries, and offline regression parse cleanly"

say "default pair.sh bootstrap (fake compose; no Docker/DB)"
run_case default pairunit "" canonical

say "Git-enabled CLI image bootstrap (fake compose; no Docker/DB)"
run_case git_cli pairgit "" canonical 0 0 "" 1

say "stock /bin/bash pair.sh bootstrap (fake compose; no Docker/DB)"
run_case stock_bash pairbash "" canonical 0 0 /bin/bash

say "--codebind pair.sh bootstrap (fake compose; no Docker/DB)"
run_case codebind pairbind demo-plugin canonical

say "warm-cache WordPress.org-offline bootstrap (fake compose; no Docker/DB)"
run_artifact_theme_case

say "malformed artifact-lock preflight (fake compose; no Docker/DB)"
run_invalid_artifact_lock_preflight_case

say "bootstrap-theme singleton preflight (fake compose; no Docker/DB)"
run_invalid_bootstrap_theme_preflight_case

say "bounded theme lookup retry + exact active-state proof (fake compose; no Docker/DB)"
run_theme_retry_case

say "Python fcntl budget-lock fallback (fake compose; no Docker/DB)"
run_python_lock_fallback_case

say "canonical-root failure outside Git (fake compose; no Docker/DB)"
run_non_git_failure_case

say "budget refusal before pair state creation (fake compose; no Docker/DB)"
run_budget_refusal_case

say "already-live pair reconvergence at budget boundary"
run_live_reconverge_case

say "Docker capacity query failure refuses before pair state creation"
run_budget_query_failure_case

say "Compose live-pair query failure refuses before pair state creation"
run_live_query_failure_case

say "concurrent pair budget reservation (fake compose; no Docker/DB)"
run_concurrent_budget_race_case

say "atomic pair lease owns the complete disposable namespace (fake compose/DB)"
run_pair_lease_namespace_case

say "portable zombie detection for SIGKILL cancellation (no Docker/DB)"
run_pid_running_zombie_case

say "concurrent Python fcntl budget reservation (fake compose; no Docker/DB)"
run_python_lock_concurrent_case

say "SIGKILLed Python fcntl owner cleanup (fake compose; no Docker/DB)"
run_python_lock_sigkill_case

say "SIGKILLed flock owner with blocked Docker child (fake compose; no Docker/DB)"
run_flock_lock_sigkill_case

say "flock contender TERM cancellation (fake compose; no Docker/DB)"
run_pair_lock_cancellation_case flock TERM

say "flock contender SIGKILL cancellation (fake compose; no Docker/DB)"
run_pair_lock_cancellation_case flock KILL

say "Python fcntl contender TERM cancellation (fake compose; no Docker/DB)"
run_pair_lock_cancellation_case python TERM

say "Python fcntl contender SIGKILL cancellation (fake compose; no Docker/DB)"
run_pair_lock_cancellation_case python KILL

say "budget formula pins: 2 pairs per core, 1GiB RAM guard, floor 1"
run_budget_formula_pin_case ram_bound 10 12884901888 9 9
run_budget_formula_pin_case cpu_bound 4 68719476736 4 4
run_budget_formula_pin_case floor 1 2147483648 1 1

say "start budget refusal before pair state or Compose mutation"
run_start_budget_refusal_case

say "in-budget start waits for Compose visibility"
run_start_safe_case

say "explicit start budget override"
run_start_budget_override_case

say "reset refuses nested codebind inode replacement (fake compose; no Docker/DB)"
run_reset_codebind_refusal_case

say "reset container enumeration failure refuses before mutation"
run_reset_container_query_failure_case enumeration

say "reset container inspect failure refuses before mutation"
run_reset_container_query_failure_case inspect

say "ordinary reset preserves bind-root inode (fake compose; no Docker/DB)"
run_reset_inode_preservation_case

say "exact side ownership handback preserves inode/content/peer isolation"
run_repo_host_scope_case

say "ownership handback rejects symlink and non-directory roots before allocating a probe/container"
run_repo_host_shape_refusal_case

say "ownership handback failure refuses before reset mutation"
run_repo_host_refusal_case

say "exact-root ownership handback repair preserves literal host ownership"
run_repo_host_platform_ownership_proof_case

say "issue #3412: reset records a needs-install marker for both sides"
run_reset_marks_needs_install_case

say "issue #3412: reset->up installs across a stale is-installed TRUE (the race)"
run_reset_up_stale_probe_case

say "issue #3412 mutation proof: marker consumption removed => stale TRUE skips the install"
run_reset_up_stale_probe_mutation_case

say "issue #3412: no marker + is-installed TRUE stays idempotent (no reinstall)"
run_install_idempotence_case

say "issue #3412: a still-uninstalled side refuses at bootstrap (forced path)"
run_install_premise_assert_case marker

say "issue #3412: a still-uninstalled side refuses at bootstrap (probe path)"
run_install_premise_assert_case nomarker

say "issue #3412: destroy clears this pair's needs-install markers"
run_destroy_clears_marker_case

printf '\n\033[1;32m✔ REGRESS_PAIR_BOOTSTRAP_UNIT PASSED\033[0m\n'
assert_file_contains "$ROOT/sandbox/bin/pair.sh" 'source "lib/pair_lease.sh"' \
  'pair launcher sources the pair-lease boundary'
