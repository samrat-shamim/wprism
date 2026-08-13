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
# DUO-3438: resolve to the physical path once, up front, the same way
# DUO-3420's repo_host_one() resolves its own bind-mount source
# (`cd "$(dirname "$root")" && pwd -P`) -- two independent mismatches this
# collapses into one no-op comparison: macOS's $TMPDIR sits under
# /var/folders, and /var -> /private/var is an OS-provided symlink (the same
# class DUO-3432 hit for /tmp); separately, macOS's $TMPDIR carries a
# trailing slash, so plain `mktemp -d "$TMPDIR/duo-pair-bootstrap.XXXXXX"`
# above yields a doubled slash (".../T//duo-pair-bootstrap...", visible in
# the pre-fix failure text) that `pwd -P` also normalizes away. Every path
# this suite builds from $TMP must already be canonical so a later `pwd -P`
# inside pair.sh is a no-op against it, not a silent second resolution the
# fixed-string assertions below never anticipated. A no-op wherever $TMPDIR
# has no symlink alias and no trailing slash to begin with.
TMP="$(cd "$TMP" && pwd -P)"
ORIGINAL_PATH="$PATH"
# DUO-3396: pair.sh's budget refusal now consults the host certification
# rendezvous (read-only) to see whether the candidate is the pair a HELD
# certification lock reserved. Point it at a path under this suite's own
# scratch that is never created, so these cases decide against a fixture
# instead of against whatever bundle happens to be running on this host.
export CERT_BUNDLE_LOCK_DIR="$TMP/no-certbundle-rendezvous"

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
log="${DUO_PAIR_TEST_LOG:?}"
{
  printf 'docker'
  for arg in "$@"; do printf ' <%s>' "$arg"; done
  printf ' env[DUO_AGENT_SRC]=%s env[DUO_MANIFESTS_SRC]=%s\n' \
    "${DUO_AGENT_SRC:-}" "${DUO_MANIFESTS_SRC:-}"
} >> "$log"

# The artifact-cache branch intentionally executes the real bounded shell
# command emitted by fetch-artifact.sh, but redirects its container mount to
# this regression's private directory. Every other compose command remains a
# no-op recorder below.
if [ -n "${DUO_PAIR_TEST_ARTIFACT_CACHE:-}" ]; then
  args=("$@")
  for index in "${!args[@]}"; do
    if [ "${args[$index]}" = sh ] && [[ "${args[$((index + 1))]:-}" == */artifact-cache-fetch.sh ]]; then
      export DUO_ARTIFACT_TEST_MODE=1 DUO_ARTIFACT_TEST_CACHE_ROOT="$DUO_PAIR_TEST_ARTIFACT_CACHE"
      args[$((index + 1))]="${DUO_PAIR_TEST_ARTIFACT_RUNNER:?}"
      "${args[@]:$index}"
      exit $?
    fi
  done
fi

if [ "${DUO_PAIR_TEST_FAIL_REPO_HANDOFF:-0}" = 1 ]; then
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
if [ -n "${DUO_PAIR_TEST_REPO_HANDOFF_MARKER:-}" ]; then
  case " $* " in
    *" run --rm -u root --mount "*) : > "$DUO_PAIR_TEST_REPO_HANDOFF_MARKER" ;;
  esac
fi

# Optional theme state makes the real pair bootstrap's retry contract
# fault-injectable without Docker or WordPress.org. The fake records an
# installed/active theme only after the configured number of exact install
# failures; activation cannot fabricate an install that never completed.
theme_state_dir="${DUO_PAIR_TEST_THEME_STATE_DIR:-}"
if [ -n "$theme_state_dir" ]; then
  mkdir -p "$theme_state_dir"
  case " $* " in
    *" cli1 wp core is-installed "*) [ -f "$theme_state_dir/core1" ]; exit $? ;;
    *" cli2 wp core is-installed "*) [ -f "$theme_state_dir/core2" ]; exit $? ;;
    # DUO-3412: NOOP models the DUO-3381 shape at the bootstrap layer — wp-cli
    # exits 0 while the database still has no WordPress in it, so every later
    # is-installed answer stays FALSE. Default 0 leaves every pre-existing
    # case's install semantics untouched.
    *" cli1 wp core install "*)
      [ "${DUO_PAIR_TEST_CORE_INSTALL_NOOP:-0}" = 1 ] || : > "$theme_state_dir/core1"
      exit 0 ;;
    *" cli2 wp core install "*)
      [ "${DUO_PAIR_TEST_CORE_INSTALL_NOOP:-0}" = 1 ] || : > "$theme_state_dir/core2"
      exit 0 ;;
    *" wp theme install twentytwentyone --activate "*)
      attempts=0
      [ ! -f "$theme_state_dir/attempts" ] || attempts="$(cat "$theme_state_dir/attempts")"
      attempts=$((attempts + 1))
      printf '%s\n' "$attempts" > "$theme_state_dir/attempts"
      if [ "$attempts" -le "${DUO_PAIR_TEST_THEME_FAILURES:-0}" ]; then
        printf 'fake transient WordPress.org theme lookup failure\n' >&2
        exit 37
      fi
      : > "$theme_state_dir/installed"
      : > "$theme_state_dir/active"
      exit 0
      ;;
    *" wp theme install /artifacts-cache/theme-twentytwentyone-"*" --activate --force "*)
      : > "$theme_state_dir/installed"
      : > "$theme_state_dir/active"
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
  -u) printf '%s\n' "${DUO_PAIR_TEST_HOST_UID:?}" ;;
  -g) printf '%s\n' "${DUO_PAIR_TEST_HOST_GID:?}" ;;
  *) exit 64 ;;
esac
FAKE_ID
  cat > "$fake_bin/stat" <<'FAKE_STAT'
#!/usr/bin/env bash
set -euo pipefail
flavor="${DUO_PAIR_TEST_STAT_FLAVOR:?}"
format="${2:-}"
path="${3:-}"
is_root() {
  [ "$1" = "${DUO_PAIR_TEST_STAT_ROOT:?}" ] || [ "$1" = "${DUO_PAIR_TEST_STAT_ROOT_LEXICAL:?}" ]
}
owner_after() {
  is_root "$1" || exit 64
  [ -e "${DUO_PAIR_TEST_REPO_HANDOFF_MARKER:?}" ] || exit 64
  if [ -e "${DUO_PAIR_TEST_ROOT_NORMALIZED_MARKER:?}" ]; then
    printf '%s\n' "${DUO_PAIR_TEST_STAT_ROOT_OWNER_NORMALIZED:?}"
  else
    printf '%s\n' "${DUO_PAIR_TEST_STAT_ROOT_OWNER_AFTER_DOCKER:?}"
  fi
}
inode_at_phase() {
  is_root "$1" || exit 64
  if [ -e "${DUO_PAIR_TEST_ROOT_NORMALIZED_MARKER:?}" ]; then
    printf '%s\n' "${DUO_PAIR_TEST_STAT_ROOT_INODE_AFTER_NORMALIZED:?}"
  elif [ -e "${DUO_PAIR_TEST_REPO_HANDOFF_MARKER:?}" ]; then
    printf '%s\n' "${DUO_PAIR_TEST_STAT_ROOT_INODE_AFTER_DOCKER:?}"
  else
    printf '%s\n' "${DUO_PAIR_TEST_STAT_ROOT_INODE_BEFORE:?}"
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
log="${DUO_PAIR_TEST_CHGRP_LOG:?}"
{
  printf 'chgrp'
  for arg in "$@"; do printf ' <%s>' "$arg"; done
  printf '\n'
} >> "$log"
[ "${DUO_PAIR_TEST_FAIL_ROOT_CHGRP:-0}" = 0 ] || exit 73
[ "${1:-}" = -h ] && [ "${2:-}" = "${DUO_PAIR_TEST_HOST_GID:?}" ] \
  && [ "${3:-}" = "${DUO_PAIR_TEST_STAT_ROOT:?}" ] && [ "$#" = 3 ] || exit 64
: > "${DUO_PAIR_TEST_ROOT_NORMALIZED_MARKER:?}"
if [ "${DUO_PAIR_TEST_REPLACE_ROOT_WITH_SYMLINK:-0}" = 1 ]; then
  mv "${DUO_PAIR_TEST_STAT_ROOT:?}" "${DUO_PAIR_TEST_REPLACED_ROOT:?}"
  ln -s "${DUO_PAIR_TEST_REPLACEMENT_TARGET:?}" "${DUO_PAIR_TEST_STAT_ROOT:?}"
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

copy_pair_launcher() { # copy_pair_launcher <sandbox-bin-dir>
  local bin_dir="$1"
  mkdir -p "$bin_dir/../lib"
  cp "$ROOT/sandbox/bin/pair.sh" "$bin_dir/pair.sh"
  cp "$ROOT/sandbox/lib/pair_identity.sh" "$bin_dir/../lib/pair_identity.sh"
  cp "$ROOT/sandbox/lib/pair_budget_lock.sh" "$bin_dir/../lib/pair_budget_lock.sh"
  cp "$ROOT/sandbox/lib/pair_db.sh" "$bin_dir/../lib/pair_db.sh"
}

run_case() {
  local label="$1" pair="$2" codebind="$3" git_mode="${4:-canonical}"
  local artifacts="${5:-0}" wordpress_offline="${6:-0}"
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin" log="$TMP/$label/docker.log"
  local output="$TMP/$label/output.log" web cli mount1 mount2 compose_prefix canonical_root
  local up_args=(up "$pair" 9911 9912 --headless)
  mkdir -p "$case_root/sandbox/bin" "$case_root/sandbox/conformance" "$fake_bin"
  canonical_root="$case_root/canonical"
  copy_pair_launcher "$case_root/sandbox/bin"
  cp "$ROOT/sandbox/bin/fetch-artifact.sh" "$case_root/sandbox/bin/fetch-artifact.sh"
  cp "$ROOT/sandbox/conformance/artifacts.lock.json" "$case_root/sandbox/conformance/artifacts.lock.json"
  if [ -n "${DUO_PAIR_TEST_LOCK_OVERRIDE:-}" ]; then
    cp "$DUO_PAIR_TEST_LOCK_OVERRIDE" "$case_root/sandbox/conformance/artifacts.lock.json"
  fi
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

  [ "$artifacts" = 0 ] || up_args+=(--artifacts)
  [ "$wordpress_offline" = 0 ] || up_args+=(--wordpress-offline)
  if [ -n "$codebind" ]; then
    up_args+=(--codebind "$codebind")
    "$case_root/sandbox/bin/pair.sh" "${up_args[@]}" \
      >"$output" 2>&1 || { cat "$output" >&2; fail "$label pair bootstrap failed"; }
  else
    "$case_root/sandbox/bin/pair.sh" "${up_args[@]}" \
      >"$output" 2>&1 || { cat "$output" >&2; fail "$label pair bootstrap failed"; }
  fi

  compose_prefix="docker <compose> <-p> <duo-$pair> <-f> <pair.yml>"
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

run_theme_retry_case() {
  local label=theme_retry state_dir="$TMP/theme_retry/theme-state"
  mkdir -p "$state_dir"
  export DUO_PAIR_TEST_THEME_STATE_DIR="$state_dir" DUO_PAIR_TEST_THEME_FAILURES=1
  run_case "$label" pairtheme "" canonical
  unset DUO_PAIR_TEST_THEME_STATE_DIR DUO_PAIR_TEST_THEME_FAILURES

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
  export DUO_PAIR_TEST_THEME_STATE_DIR="$state_dir" DUO_PAIR_TEST_THEME_FAILURES=0
  export DUO_PAIR_TEST_ARTIFACT_CACHE="$cache_dir"
  export DUO_PAIR_TEST_ARTIFACT_RUNNER="$ROOT/sandbox/bin/artifact-cache-fetch.sh"

  # run_case copies the shipped lock before launching; replace only this
  # private copy with a same-shaped deterministic fixture matching the warm
  # cache bytes above.
  mkdir -p "$case_root/sandbox/conformance"
  printf '{"plugins":{},"themes":{"twentytwentyone":{"2.8":{"url":"https://fixture.invalid/theme.zip","sha256":"%s","role":"exercise-fixture"}}}}\n' \
    "$digest" > "$case_root/sandbox/conformance/artifacts.lock.json.override"
  DUO_PAIR_TEST_LOCK_OVERRIDE="$case_root/sandbox/conformance/artifacts.lock.json.override"
  export DUO_PAIR_TEST_LOCK_OVERRIDE
  run_case "$label" "$pair" "" canonical 1 1
  unset DUO_PAIR_TEST_THEME_STATE_DIR DUO_PAIR_TEST_THEME_FAILURES \
    DUO_PAIR_TEST_ARTIFACT_CACHE DUO_PAIR_TEST_LOCK_OVERRIDE
  unset DUO_PAIR_TEST_ARTIFACT_RUNNER

  [ -f "$state_dir/active" ] || fail "$label did not activate the cached exact theme"
  assert_file_contains "$case_root/output.log" 'source=cache-hit' \
    "$label did not report the warm-cache source path"
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
  cp "$ROOT/sandbox/bin/fetch-artifact.sh" "$case_root/sandbox/bin/fetch-artifact.sh"
  jq '.plugins.woocommerce["11.0.0"].role = "unknown-role"' \
    "$ROOT/sandbox/conformance/artifacts.lock.json" \
    > "$case_root/sandbox/conformance/artifacts.lock.json"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$canonical_root" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
    DUO_PAIR_TEST_CONTAINERS='' DUO_PAIR_TEST_FAIL_PS=0 DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER='' \
    PATH="$fake_bin:$ORIGINAL_PATH"

  if "$case_root/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless --artifacts \
      >"$output" 2>&1; then
    fail "$label accepted an artifact lock with an unknown role"
  fi
  assert_file_contains "$output" 'artifact lock is malformed' \
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
    cp "$ROOT/sandbox/bin/fetch-artifact.sh" "$case_root/sandbox/bin/fetch-artifact.sh"
    case "$variant" in
      missing)
        jq 'del(.themes.twentytwentyone)' \
          "$ROOT/sandbox/conformance/artifacts.lock.json" \
          > "$case_root/sandbox/conformance/artifacts.lock.json"
        ;;
      ambiguous)
        jq '.themes.twentytwentyone["2.9"] = .themes.twentytwentyone["2.8"]' \
          "$ROOT/sandbox/conformance/artifacts.lock.json" \
          > "$case_root/sandbox/conformance/artifacts.lock.json"
        ;;
    esac
    chmod +x "$case_root/sandbox/bin/pair.sh"
    write_fake_docker "$fake_bin"
    write_fake_git "$fake_bin"
    export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
      DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
      DUO_PAIR_TEST_CANONICAL_ROOT="$canonical_root" DUO_PAIR_TEST_LIVE_FILE='' \
      DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
      DUO_PAIR_TEST_CONTAINERS='' DUO_PAIR_TEST_FAIL_PS=0 DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER='' \
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
  copy_pair_launcher "$case_root/sandbox/bin"
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
  copy_pair_launcher "$case_root/sandbox/bin"
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
    live_json+='{"ConfigFiles":"/canonical/sandbox/pair.yml","Name":"duo-existing'"$i"'"}'
  done
  live_json+=']'
  export DUO_PAIR_TEST_LOG="$log" \
    DUO_PAIR_TEST_LIVE_PAIRS="$live_json" \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU="$cpu" DUO_PAIR_TEST_MEM="$mem" \
    DUO_PAIR_TEST_CANONICAL_ROOT="$case_root" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
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
  copy_pair_launcher "$case_root/sandbox/bin"
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
  copy_pair_launcher "$case_root/sandbox/bin"
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
  copy_pair_launcher "$case_root/sandbox/bin"
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
  copy_pair_launcher "$case_root/sandbox/bin"
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
  copy_pair_launcher "$case_root/sandbox/bin"
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
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=3 DUO_PAIR_TEST_MEM=4294967296 \
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
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  install_python_lock_path "$fake_bin"
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=3 DUO_PAIR_TEST_MEM=4294967296 \
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
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"
  write_fake_git "$fake_bin"
  install_python_lock_path "$fake_bin"
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=3 DUO_PAIR_TEST_MEM=4294967296 \
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
  copy_pair_launcher "$case_root/sandbox/bin"
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
  copy_pair_launcher "$case_root/sandbox/bin"
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
  copy_pair_launcher "$case_root/sandbox/bin"
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
  assert_file_contains "$log" "<run> <--rm> <-u> <root> <--mount> <type=bind,src=${root1_abs},dst=/siterepo>" \
    "$label did not hand side 1 back through its exact resolved root mount"
  assert_file_contains "$log" "<run> <--rm> <-u> <root> <--mount> <type=bind,src=${root2_abs},dst=/siterepo>" \
    "$label did not hand side 2 back through its exact resolved root mount"
  assert_before "$log" "<type=bind,src=${root1_abs},dst=/siterepo>" "<-p> <duo-db>"
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
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$case_root" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
    DUO_PAIR_TEST_CONTAINERS='' DUO_PAIR_TEST_FAIL_PS=0 \
    DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER='' DUO_PAIR_TEST_FAIL_REPO_HANDOFF=0 \
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
  if env DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_FAIL_REPO_HANDOFF=0 PATH="$fake_bin:$ORIGINAL_PATH" \
    "$case_root/sandbox/bin/pair.sh" repo-host "$pair" 1 >"$output" 2>&1; then
    fail "$label accepted a symlink instead of an exact ordinary root"
  fi
  assert_file_contains "$output" 'repository root is not an ordinary directory' \
    "$label did not name the symlink refusal"
  rm "$root"

  printf 'not a directory\n' > "$root"
  if env DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_FAIL_REPO_HANDOFF=0 PATH="$fake_bin:$ORIGINAL_PATH" \
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
  export DUO_PAIR_TEST_LOG="$log" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$case_root" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
    DUO_PAIR_TEST_CONTAINERS='' DUO_PAIR_TEST_FAIL_PS=0 \
    DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER='' DUO_PAIR_TEST_FAIL_REPO_HANDOFF=1 \
    PATH="$fake_bin:$ORIGINAL_PATH"

  if "$case_root/sandbox/bin/pair.sh" reset "$pair" >"$output" 2>&1; then
    fail "$label reset continued after ownership handback failure"
  fi
  assert_file_contains "$output" "could not return exact pair repository" \
    "$label did not report the exact-root handback refusal"
  [ -f "$root/state/nested/record.json" ] \
    || fail "$label changed repository content after handback refusal"
  if grep -F '<-p> <duo-db>' "$log" >/dev/null; then
    fail "$label touched the database after handback refusal"
  fi
  export DUO_PAIR_TEST_FAIL_REPO_HANDOFF=0
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
      DUO_PAIR_TEST_LOG="${log}" DUO_PAIR_TEST_CHGRP_LOG="${chgrp_log}" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
      DUO_PAIR_TEST_HOST_UID=501 DUO_PAIR_TEST_HOST_GID=20 \
      DUO_PAIR_TEST_STAT_FLAVOR="${flavor}" DUO_PAIR_TEST_STAT_ROOT="${root_abs}" DUO_PAIR_TEST_STAT_ROOT_LEXICAL="siterepo/${pair}1" \
      DUO_PAIR_TEST_STAT_ROOT_INODE_BEFORE=424242 DUO_PAIR_TEST_STAT_ROOT_INODE_AFTER_DOCKER=424242 \
      DUO_PAIR_TEST_STAT_ROOT_INODE_AFTER_NORMALIZED="${inode_normalized}" \
      DUO_PAIR_TEST_STAT_ROOT_OWNER_AFTER_DOCKER="${owner_after}" DUO_PAIR_TEST_STAT_ROOT_OWNER_NORMALIZED="${owner_normalized}" \
      DUO_PAIR_TEST_ROOT_NORMALIZED_MARKER="${normalized_marker}" DUO_PAIR_TEST_REPO_HANDOFF_MARKER="${marker}" \
      DUO_PAIR_TEST_FAIL_ROOT_CHGRP="${fail_chgrp}" DUO_PAIR_TEST_REPLACE_ROOT_WITH_SYMLINK="${replace_root}" \
      DUO_PAIR_TEST_REPLACED_ROOT="${replaced_root}" DUO_PAIR_TEST_REPLACEMENT_TARGET="${peer_abs}" \
      DUO_PAIR_TEST_FAIL_REPO_HANDOFF=0 \
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

# --- DUO-3412: the needs-install marker's whole lifecycle --------------------
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
# The stale probe is the fake's whole point here: DUO_PAIR_TEST_THEME_STATE_DIR
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
  export DUO_PAIR_TEST_LOG="$LOG" DUO_PAIR_TEST_LIVE_PAIRS='[]' \
    DUO_PAIR_TEST_INSPECT_MOUNTS='' DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
    DUO_PAIR_TEST_CANONICAL_ROOT="$CASE_ROOT" DUO_PAIR_TEST_LIVE_FILE='' \
    DUO_PAIR_TEST_RACE_GATE='' DUO_PAIR_TEST_FAIL_INFO=0 DUO_PAIR_TEST_FAIL_LIVE=0 \
    DUO_PAIR_TEST_CONTAINERS='' DUO_PAIR_TEST_FAIL_PS=0 \
    DUO_PAIR_TEST_FAIL_INSPECT_CONTAINER='' DUO_PAIR_BUDGET_OVERRIDE=0 \
    DUO_PAIR_TEST_CORE_INSTALL_NOOP=0 DUO_PAIR_TEST_THEME_FAILURES=0 \
    PATH="$FAKE_BIN:$ORIGINAL_PATH"
  unset DUO_PAIR_TEST_THEME_STATE_DIR
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
  # clear_siterepo_root() empties them. Both roots must be completely empty
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
  # is about to drop both databases. This is the false positive DUO-3412 says
  # `up` must not act on.
  : > "$THEME_STATE/core1"
  : > "$THEME_STATE/core2"
  export DUO_PAIR_TEST_THEME_STATE_DIR="$THEME_STATE"

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
  DUO_PAIR_TEST_LOG="$second_log" "$CASE_ROOT/sandbox/bin/pair.sh" up "$pair" 9911 9912 --headless \
    >>"$OUTPUT" 2>&1 || { cat "$OUTPUT" >&2; fail "$label second up failed"; }
  if grep -F '<wp> <core> <install>' "$second_log" >/dev/null; then
    fail "$label reinstalled on the second up — the marker was not consumed"
  fi
  unset DUO_PAIR_TEST_THEME_STATE_DIR
  pass "$label: reset->up installs across a stale is-installed TRUE and consumes the marker exactly once"
}

run_reset_up_stale_probe_mutation_case() {
  local label=reset_up_stale_probe_mutation pair=staleprobemut side launcher anchor
  prepare_marker_case "$label"
  launcher="$CASE_ROOT/sandbox/bin/pair.sh"
  # THE MUTATION, stated exactly: remove install_side's marker consumption by
  # making its branch unreachable, which leaves the pre-DUO-3412 code path
  # verbatim — one is-installed probe, skip on TRUE. Nothing else is touched.
  # The anchor count is asserted first: a drifted anchor would silently turn
  # this proof into a second copy of the passing case.
  anchor='if [ -e "$marker" ]; then'
  [ "$(grep -cF "$anchor" "$launcher")" = 1 ] \
    || fail "$label could not uniquely locate install_side's marker branch to mutate ($anchor)"
  sed 's/if \[ -e "\$marker" \]; then/if false; then/' "$launcher" > "$launcher.mutant"
  mv "$launcher.mutant" "$launcher"
  chmod +x "$launcher"
  [ "$(grep -cF "$anchor" "$launcher")" = 0 ] || fail "$label mutation did not remove the marker branch"
  grep -qF 'if false; then' "$launcher" || fail "$label mutation did not apply"

  mkdir -p "$THEME_STATE"
  : > "$THEME_STATE/core1"
  : > "$THEME_STATE/core2"
  export DUO_PAIR_TEST_THEME_STATE_DIR="$THEME_STATE"
  "$launcher" reset "$pair" >"$OUTPUT" 2>&1 \
    || { cat "$OUTPUT" >&2; fail "$label reset failed"; }
  "$launcher" up "$pair" 9911 9912 --headless >>"$OUTPUT" 2>&1 \
    || { cat "$OUTPUT" >&2; fail "$label up failed"; }

  # The mutant exits 0 and reports the pair ready — the DUO-3412 failure is
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
  unset DUO_PAIR_TEST_THEME_STATE_DIR
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
  export DUO_PAIR_TEST_THEME_STATE_DIR="$THEME_STATE" DUO_PAIR_TEST_CORE_INSTALL_NOOP=1
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
  unset DUO_PAIR_TEST_THEME_STATE_DIR
  export DUO_PAIR_TEST_CORE_INSTALL_NOOP=0
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
bash -n "$ROOT/sandbox/bin/pair.sh" "$ROOT/sandbox/lib/pair_identity.sh" "$ROOT/sandbox/lib/pair_db.sh" \
  "$ROOT/sandbox/tests/regress_pair_bootstrap_unit.sh"
command -v stat >/dev/null 2>&1 || fail "stat is required for inode-preservation regression"
grep -Fq 'GIT_CONFIG_KEY_0: safe.directory' "$ROOT/sandbox/pair.yml" \
  || fail "pair CLI services do not declare the exact Git trust key"
grep -Fq 'GIT_CONFIG_VALUE_0: /siterepo' "$ROOT/sandbox/pair.yml" \
  || fail "pair CLI services do not scope Git trust to exact /siterepo"
! grep -Fq 'safe.directory=*' "$ROOT/sandbox/pair.yml" \
  || fail "pair Git trust widened to a wildcard"
assert_file_contains "$ROOT/sandbox/bin/pair.sh" 'if ! chgrp -h "$host_gid" "$root_abs"; then' \
  'pair handback does not narrowly normalize the exact physical root after Docker'
assert_file_contains "$ROOT/sandbox/bin/pair.sh" 'if [ -L "$root" ] || [ ! -d "$root" ]; then' \
  'pair handback does not revalidate the root as an ordinary directory after Docker returns'
assert_file_contains "$ROOT/sandbox/bin/pair.sh" 'if [ -L "$root_abs" ] || [ ! -d "$root_abs" ]; then' \
  'pair handback does not check the resolved physical root before Docker owns it'
assert_file_contains "$ROOT/sandbox/bin/pair.sh" 'repo_host_revalidate_root "$root_abs" "$root_inode_before"' \
  'pair handback does not revalidate the physical root shape and inode after Docker returns'
assert_file_contains "$ROOT/sandbox/bin/pair.sh" 'owner_uid="${owner%%:*}"' \
  'pair handback does not isolate and check the returned root uid before chgrp'
assert_before "$ROOT/sandbox/bin/pair.sh" 'owner_uid="${owner%%:*}"' 'if ! chgrp -h "$host_gid" "$root_abs"; then'
assert_before "$ROOT/sandbox/bin/pair.sh" 'if ! chgrp -h "$host_gid" "$root_abs"; then' '[ "$owner" = "${host_uid}:${host_gid}" ]'
! grep -Fq 'owner-probe' "$ROOT/sandbox/bin/pair.sh" \
  || fail "pair handback retained a sibling capability probe"
! grep -Fq 'chgrp -R' "$ROOT/sandbox/bin/pair.sh" \
  || fail "pair handback widened exact-root group normalization recursively"
pass "pair launcher, identity library, and offline regression parse cleanly"

say "default pair.sh bootstrap (fake compose; no Docker/DB)"
run_case default pairunit "" canonical

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

say "DUO-3412: reset records a needs-install marker for both sides"
run_reset_marks_needs_install_case

say "DUO-3412: reset->up installs across a stale is-installed TRUE (the race)"
run_reset_up_stale_probe_case

say "DUO-3412 mutation proof: marker consumption removed => stale TRUE skips the install"
run_reset_up_stale_probe_mutation_case

say "DUO-3412: no marker + is-installed TRUE stays idempotent (no reinstall)"
run_install_idempotence_case

say "DUO-3412: a still-uninstalled side refuses at bootstrap (forced path)"
run_install_premise_assert_case marker

say "DUO-3412: a still-uninstalled side refuses at bootstrap (probe path)"
run_install_premise_assert_case nomarker

say "DUO-3412: destroy clears this pair's needs-install markers"
run_destroy_clears_marker_case

printf '\n\033[1;32m✔ REGRESS_PAIR_BOOTSTRAP_UNIT PASSED\033[0m\n'
