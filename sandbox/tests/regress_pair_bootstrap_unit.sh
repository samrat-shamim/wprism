#!/usr/bin/env bash
# Offline regression for pair.sh's two-phase container bootstrap.
#
# The pair's named webroot volume must be initialized by wp1/wp2 before the
# nested Duo MU directory/file mounts are visible to cli1/cli2. This harness
# runs the real pair.sh in a temporary copied sandbox with a fake `docker`
# executable: it proves the compose service/flag ordering without contacting
# Docker, MariaDB, or the repository's persistent siterepo directories.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/duo-pair-bootstrap.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT
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
log="${DUO_PAIR_TEST_LOG:?}"
{
  printf 'docker'
  for arg in "$@"; do printf ' <%s>' "$arg"; done
  printf ' env[DUO_AGENT_SRC]=%s env[DUO_MANIFESTS_SRC]=%s\n' \
    "${DUO_AGENT_SRC:-}" "${DUO_MANIFESTS_SRC:-}"
} >> "$log"

if [ "${1:-}" = inspect ]; then
  if [ "${DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER:-}" = "${2:-}" ]; then
    printf 'fake docker inspect failure\n' >&2
    exit 31
  fi
  if [ "${3:-}" = --format ]; then
    printf '%s\n' "${DUO_PAIR_TEST_INSPECT_MOUNTS:-}"
  else
    case "${3:-}" in
      "{{.State.Health.Status}}") printf 'healthy\n' ;;
      "{{.State.Status}} ({{.State.Health.Status}})") printf 'running (healthy)\n' ;;
    esac
  fi
elif [ "${1:-}" = info ]; then
  if [ "${DUO_PAIR_TEST_FAIL_INFO:-0}" = 1 ]; then
    printf 'fake docker info failure\n' >&2
    exit 17
  fi
  case "${3:-}" in
    "{{.NCPU}}") printf '%s\n' "${DUO_PAIR_TEST_CPU:-8}" ;;
    "{{.MemTotal}}") printf '%s\n' "${DUO_PAIR_TEST_MEM:-8589934592}" ;;
  esac
elif [ "${1:-}" = ps ]; then
  if [ "${DUO_PAIR_TEST_FAIL_PS:-0}" = 1 ]; then
    printf 'fake docker ps failure\n' >&2
    exit 29
  fi
  printf '%s\n' "${DUO_PAIR_TEST_CONTAINERS:-}"
elif [ "${1:-}" = compose ] && [ "${2:-}" = ls ]; then
  if [ "${DUO_PAIR_TEST_FAIL_LIVE:-0}" = 1 ]; then
    printf 'fake compose ls failure\n' >&2
    exit 23
  fi
  race_gate="${DUO_PAIR_TEST_RACE_GATE:-}"
  if [ -n "$race_gate" ] && mkdir "$race_gate/first" 2>/dev/null; then
    : > "$race_gate/first-ready"
    while [ ! -e "$race_gate/release" ]; do sleep 0.02; done
  fi
  if [ -n "${DUO_PAIR_TEST_LIVE_FILE:-}" ] && [ -f "$DUO_PAIR_TEST_LIVE_FILE" ]; then
    cat "$DUO_PAIR_TEST_LIVE_FILE"
  else
    printf '%s\n' "${DUO_PAIR_TEST_LIVE_PAIRS:-[]}"
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
     [ -n "${DUO_PAIR_TEST_LIVE_FILE:-}" ]; then
    printf '[{"ConfigFiles":"/fake/pair.yml","Name":"%s"}]\n' "$project" > "$DUO_PAIR_TEST_LIVE_FILE"
  fi
  if [ "$has_start" = 1 ] && [ -n "${DUO_PAIR_TEST_LIVE_FILE:-}" ]; then
    printf '[{"ConfigFiles":"/fake/pair.yml","Name":"%s"}]\n' "$project" > "$DUO_PAIR_TEST_LIVE_FILE"
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
if [ "${1:-}" = rev-parse ]; then
  printf '%s/.git\n' "${DUO_PAIR_TEST_CANONICAL_ROOT:?}"
  exit 0
fi
exit 1
FAKE_GIT
  chmod +x "$fake_bin/git"
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
    [ "$state" != Z ] || return 1
  fi
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

run_case() {
  local label="$1" pair="$2" codebind="$3" git_mode="${4:-canonical}"
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" log="$TMP/$label/docker.log"
  local output="$TMP/$label/output.log" web cli mount1 mount2 compose_prefix canonical_root
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  canonical_root="$case_root/canonical"
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"

  # The fake has no side effects beyond its log. Its successful health/info
  # responses let the real shell control flow reach the compose calls under
  # test; all compose/exec/run operations are otherwise no-ops.
  write_fake_docker "$fake_bin"
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$canonical_root" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
    DUO_PAIR_TEST_CONTAINERS='' DUO_PAIR_TEST_FAIL_PS=0 DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER='' \
    PATH="$fake_bin:$ORIGINAL_PATH"
  if [ "$git_mode" = canonical ]; then
    write_fake_git "$fake_bin"
  fi

  if [ -n "$codebind" ]; then
    "$case_root/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless --codebind "$codebind" \
      >"$output" 2>&1 || { cat "$output" >&2; fail "$label pair bootstrap failed"; }
  else
    "$case_root/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless \
      >"$output" 2>&1 || { cat "$output" >&2; fail "$label pair bootstrap failed"; }
  fi

  compose_prefix="docker <compose> <-p> <duo-$pair> <-f> <pair.yml>"
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
  assert_file_contains "$log" "env[DUO_AGENT_SRC]=$canonical_root/agent" "$label did not use the canonical agent bind source"
  assert_file_contains "$log" "env[DUO_MANIFESTS_SRC]=$canonical_root/manifests" "$label did not use the canonical manifests bind source"
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

run_python_lock_fallback_case() {
  local label=python_lock_fallback pair=pylock
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" output="$TMP/$label/output.log" \
    canonical_root="$TMP/$label/canonical" utility
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  # Recreate the documented Unix host tools in a private PATH while
  # intentionally omitting util-linux flock. The copied script is launched
  # through the private bash symlink, so this exercises Python fcntl rather
  # than the Linux fast path without contacting Docker.
  install_python_lock_path "$fake_bin"
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$canonical_root" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
    DUO_PAIR_TEST_CONTAINERS='' DUO_PAIR_TEST_FAIL_PS=0 DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER=''

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
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$ROOT" PATH="$fake_bin:$ORIGINAL_PATH"

  if env -u GIT_DIR -u GIT_WORK_TREE -u GIT_COMMON_DIR \
      "$case_root/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless \
      >"$output" 2>&1; then
    cat "$output" >&2
    fail "$label unexpectedly succeeded outside a Git checkout"
  fi
  grep -q "could not resolve this repo's canonical checkout" "$output" \
    || fail "$label did not fail closed with the canonical checkout diagnostic"
  if [ -f "$log" ] && grep -F "<-p> <duo-$pair>" "$log" >/dev/null; then
    fail "$label reached pair Compose after canonical-root failure"
  fi
  if [ -f "$log" ] && grep -F "<-p> <duo-db>" "$log" >/dev/null; then
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
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  export DUO_PAIR_TEST_LOG="$log" \
    DUO_PAIR_TEST_LIVE_PAIRS='[{"ConfigFiles":"/canonical/sandbox/pair.yml","Name":"duo-existing"}]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=2 DUO_PAIR_TEST_MEM=3221225472 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$case_root" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
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
  if grep -F "<-p> <duo-db>" "$log" >/dev/null; then
    fail "$label touched shared DB Compose before refusing"
  fi
  pass "$label: over-budget refusal precedes DB and site-root mutations"
}

run_start_budget_refusal_case() {
  local label=start_budget_refusal pair=startrefuse
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" output="$TMP/$label/output.log"
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  export DUO_PAIR_TEST_LOG="$log" \
    DUO_PAIR_TEST_LIVE_PAIRS='[{"ConfigFiles":"/canonical/sandbox/pair.yml","Name":"duo-existing"}]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=2 DUO_PAIR_TEST_MEM=3221225472 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$case_root" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
    DUO_PAIR_TEST_CONTAINERS='' DUO_PAIR_TEST_FAIL_PS=0 \
    DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER='' DUO_PAIR_BUDGET_OVERRIDE=0 \
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
  if grep -F '<-p> <duo-db>' "$log" >/dev/null || \
     grep -F "<-p> <duo-$pair>" "$log" >/dev/null; then
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
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$case_root" DUO_PAIR_TEST_LIVE_FILE="$live_file" \
    DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
    DUO_PAIR_TEST_CONTAINERS='' DUO_PAIR_TEST_FAIL_PS=0 \
    DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER='' DUO_PAIR_BUDGET_OVERRIDE=0 \
    PATH="$fake_bin:$ORIGINAL_PATH"

  "$case_root/sandbox/bin/pair.sh" start "$pair" >"$output" 2>&1 \
    || { cat "$output" >&2; fail "$label rejected a start within the fake host budget"; }
  grep -q "running again — same ports/config as before the stop" "$output" \
    || fail "$label did not complete after Compose start became visible"
  grep -F "<-p> <duo-$pair> <-f> <pair.yml> <start>" "$log" >/dev/null \
    || fail "$label did not issue the expected Compose start operation"
  assert_file_contains "$log" "docker <compose> <-p> <duo-db> <-f> <db.yml> <up> <-d>" \
    "$label did not preserve the shared-DB prerequisite"
  pass "$label: in-budget start keeps DB readiness and Compose visibility behind reservation"
}

run_start_budget_override_case() {
  local label=start_budget_override pair=startoverride
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" output="$TMP/$label/output.log" \
    live_file="$TMP/$label/live.json"
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[{"ConfigFiles":"/canonical/sandbox/pair.yml","Name":"duo-existing"}]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=2 DUO_PAIR_TEST_MEM=3221225472 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$case_root" DUO_PAIR_TEST_LIVE_FILE="$live_file" \
    DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
    DUO_PAIR_TEST_CONTAINERS='' DUO_PAIR_TEST_FAIL_PS=0 \
    DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER='' DUO_PAIR_BUDGET_OVERRIDE=1 \
    PATH="$fake_bin:$ORIGINAL_PATH"

  "$case_root/sandbox/bin/pair.sh" start "$pair" >"$output" 2>&1 \
    || { cat "$output" >&2; fail "$label did not honor the explicit budget override"; }
  grep -q "DUO_PAIR_BUDGET_OVERRIDE=1 set" "$output" \
    || fail "$label did not report the explicit budget override"
  grep -F "<-p> <duo-$pair> <-f> <pair.yml> <start>" "$log" >/dev/null \
    || fail "$label did not issue Compose start after the explicit override"
  pass "$label: explicit start budget override is honored only after reservation"
}

run_live_reconverge_case() {
  local label=live_reconverge pair=reconverge
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" \
    log="$TMP/$label/docker.log" output="$TMP/$label/output.log"
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  export DUO_PAIR_TEST_LOG="$log" \
    DUO_PAIR_TEST_LIVE_PAIRS='[{"ConfigFiles":"/canonical/sandbox/pair.yml","Name":"duo-reconverge"}]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=2 DUO_PAIR_TEST_MEM=3221225472 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$case_root" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
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
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$case_root" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=1 DUO_PAIR_TEST_FAIL_LIVE=0 \
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
  if grep -F '<-p> <duo-db>' "$log" >/dev/null; then
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
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$case_root" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=1 \
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
  if grep -F '<-p> <duo-db>' "$log" >/dev/null; then
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
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"

  # Three cores/five GiB yields exactly one budget unit. The fake Compose
  # daemon records the first pair as live only after its web services are
  # created. The second process must remain outside Docker/site state while
  # the first process owns the cross-process reservation, then refuse against
  # the first pair's now-live listing.
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=3 DUO_PAIR_TEST_MEM=5368709120 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$case_root/canonical" \
    DUO_PAIR_TEST_LIVE_FILE="$live_file" DUO_PAIR_TEST_RACE_GATE="$gate" \
    DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 PATH="$fake_bin:$ORIGINAL_PATH"

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
  if grep -F '<-p> <duo-racetwo>' "$log" >/dev/null; then
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
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  install_python_lock_path "$fake_bin"
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=3 DUO_PAIR_TEST_MEM=5368709120 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$case_root/canonical" \
    DUO_PAIR_TEST_LIVE_FILE="$live_file" DUO_PAIR_TEST_RACE_GATE="$gate" \
    DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0

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
  if grep -F '<-p> <duo-pysecond>' "$log" >/dev/null; then
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
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  install_python_lock_path "$fake_bin"
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=3 DUO_PAIR_TEST_MEM=5368709120 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$canonical_root" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE="$gate" DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
    DUO_PAIR_TEST_CONTAINERS='' DUO_PAIR_TEST_FAIL_PS=0 \
    DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER='' DUO_PAIR_BUDGET_OVERRIDE=0

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
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  lock_path="$canonical_root/sandbox/siterepo/.pair-budget.lock"
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$canonical_root" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE="$gate" DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
    DUO_PAIR_TEST_CONTAINERS='' DUO_PAIR_TEST_FAIL_PS=0 \
    DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER='' DUO_PAIR_BUDGET_OVERRIDE=0

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
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  path_value="$fake_bin:$ORIGINAL_PATH"
  if [ "$mode" = python ]; then
    install_python_lock_path "$fake_bin"
    path_value="$fake_bin"
  fi
  lock_path="$canonical_root/sandbox/siterepo/.pair-budget.lock"
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$canonical_root" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE="$gate" DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
    DUO_PAIR_TEST_CONTAINERS='' DUO_PAIR_TEST_FAIL_PS=0 \
    DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER='' DUO_PAIR_BUDGET_OVERRIDE=0

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
  if grep -F "<-p> <duo-$contender>" "$log" >/dev/null; then
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
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  root="$case_root/sandbox/siterepo/${pair}1"
  nested="$root/code/wp-content/plugins/demo-plugin"
  mkdir -p "$nested" "$case_root/sandbox/siterepo/${pair}2"
  printf 'do not delete this mounted code\n' > "$nested/marker.php"
  inode_before="$(inode_of "$nested")"
  mount_line="${nested}"$'\t/var/www/html/wp-content/plugins/demo-plugin'
  write_fake_docker "$fake_bin"
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS="$mount_line" \
    DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$case_root" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
    DUO_PAIR_TEST_CONTAINERS="duo-${pair}-wp1-1" DUO_PAIR_TEST_FAIL_PS=0 \
    DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER='' DUO_PAIR_BUDGET_OVERRIDE=0 \
    PATH="$fake_bin:$ORIGINAL_PATH"

  if "$case_root/sandbox/bin/pair.sh" reset "$pair" >"$output" 2>&1; then
    cat "$output" >&2
    fail "$label unexpectedly reset a codebind-mounted pair"
  fi
  grep -q "codebind mount" "$output" \
    || fail "$label did not identify the nested codebind mount"
  inode_after="$(inode_of "$nested")"
  [ "$inode_before" = "$inode_after" ] || fail "$label changed the nested codebind inode"
  [ -f "$nested/marker.php" ] || fail "$label deleted content from the nested codebind source"
  if grep -F "<-p> <duo-db>" "$log" >/dev/null; then
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
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  printf 'must survive failed reset preflight\n' > "$root/marker.txt"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  if [ "$kind" = enumeration ]; then
    expected="could not enumerate pair containers before reset"
    export DUO_PAIR_TEST_CONTAINERS='' DUO_PAIR_TEST_FAIL_PS=1 \
      DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER=''
  else
    expected="could not inspect existing pair container duo-${pair}-wp1-1"
    export DUO_PAIR_TEST_CONTAINERS="duo-${pair}-wp1-1" DUO_PAIR_TEST_FAIL_PS=0 \
      DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER="duo-${pair}-wp1-1"
  fi
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$case_root" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
    DUO_PAIR_BUDGET_OVERRIDE=0 PATH="$fake_bin:$ORIGINAL_PATH"

  if "$case_root/sandbox/bin/pair.sh" reset "$pair" >"$output" 2>&1; then
    cat "$output" >&2
    fail "$label unexpectedly proceeded after strict Docker reset preflight failure"
  fi
  grep -q "$expected" "$output" \
    || fail "$label did not report the strict preflight failure"
  grep -q 'must survive failed reset preflight' "$root/marker.txt" \
    || fail "$label changed site state after the strict preflight failure"
  if grep -F '<-p> <duo-db>' "$log" >/dev/null; then
    fail "$label touched the shared DB before the strict preflight failure"
  fi
  pass "$label: reset fails closed on Docker container ${kind} failure"
}

run_reset_inode_preservation_case() {
  local label=reset_inode_preservation pair=resetplain
  local case_root="$TMP/$label" \
    fake_bin="$TMP/$label/fake-bin" log="$TMP/$label/docker.log" \
    output="$TMP/$label/output.log" root inode_before inode_after
  mkdir -p "$case_root/sandbox/bin" "$fake_bin"
  cp "$ROOT/sandbox/bin/pair.sh" "$case_root/sandbox/bin/pair.sh"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  root="$case_root/sandbox/siterepo/${pair}1"
  mkdir -p "$root/code/wp-content/plugins/demo-plugin" "$case_root/sandbox/siterepo/${pair}2"
  printf 'old state\n' > "$root/code/wp-content/plugins/demo-plugin/marker.php"
  mkdir -p "$case_root/sandbox/siterepo/origin-${pair}.git"
  inode_before="$(inode_of "$root")"
  write_fake_docker "$fake_bin"
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$case_root" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
    DUO_PAIR_TEST_CONTAINERS='' DUO_PAIR_TEST_FAIL_PS=0 \
    DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER='' DUO_PAIR_BUDGET_OVERRIDE=0 \
    PATH="$fake_bin:$ORIGINAL_PATH"

  "$case_root/sandbox/bin/pair.sh" reset "$pair" >"$output" 2>&1 \
    || { cat "$output" >&2; fail "$label reset failed"; }
  inode_after="$(inode_of "$root")"
  [ "$inode_before" = "$inode_after" ] || fail "$label replaced the ordinary site-repo root inode"
  [ ! -e "$root/code" ] || fail "$label retained old site-repo content"
  [ ! -e "$case_root/sandbox/siterepo/origin-${pair}.git" ] \
    || fail "$label retained the origin repository"
  pass "$label: ordinary reset clears contents while preserving bind-root inode"
}

say "bash syntax checks"
bash -n "$ROOT/sandbox/bin/pair.sh" "$ROOT/sandbox/tests/regress_pair_bootstrap_unit.sh"
command -v stat >/dev/null 2>&1 || fail "stat is required for inode-preservation regression"
pass "pair launcher and offline regression parse cleanly"

say "default pair.sh bootstrap (fake compose; no Docker/DB)"
run_case default pairunit "" canonical

say "--codebind pair.sh bootstrap (fake compose; no Docker/DB)"
run_case codebind pairbind demo-plugin canonical

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

printf '\n\033[1;32m✔ REGRESS_PAIR_BOOTSTRAP_UNIT PASSED\033[0m\n'
