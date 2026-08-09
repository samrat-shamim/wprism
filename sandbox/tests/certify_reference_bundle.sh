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
# mkdir(2) is the gate: atomic and fails-if-exists on every filesystem this
# repo runs on. holder.json is written immediately after so a contender can
# name whom it would be waiting for -- or killing.
CERT_BUNDLE_LOCK_DIR="${CERT_BUNDLE_LOCK_DIR:-/tmp/duo-certbundle.lock}"
CERT_BUNDLE_LOCK_HOLDER_FILE="$CERT_BUNDLE_LOCK_DIR/holder.json"
CERT_BUNDLE_WAIT="${CERT_BUNDLE_WAIT:-0}"
CERT_BUNDLE_WAIT_TIMEOUT="${CERT_BUNDLE_WAIT_TIMEOUT:-5400}"   # 90 min, > one bundle
CERT_BUNDLE_WAIT_POLL="${CERT_BUNDLE_WAIT_POLL:-30}"
CERT_BUNDLE_LOCK_OWNED=0
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
  *) fail "CERT_BUNDLE_LOCK_DIR must be an absolute path below a directory (got '$CERT_BUNDLE_LOCK_DIR'); this path is rm -rf'd on release" ;;
esac

certbundle_pid_alive() { # certbundle_pid_alive <pid>
  local pid="$1"
  case "$pid" in ''|*[!0-9]*) return 1 ;; esac
  [ "$pid" -gt 0 ] || return 1
  kill -0 "$pid" 2>/dev/null && return 0
  # kill -0 also fails with EPERM on a LIVE process owned by another user.
  # Calling a live foreign run dead is exactly the mistake this lock exists
  # to prevent, so ps arbitrates before anything is declared a corpse.
  ps -p "$pid" >/dev/null 2>&1
}

certbundle_lock_read_holder() { # populates LOCK_*; nonzero when unreadable
  local tsv
  LOCK_PID= LOCK_PAIR= LOCK_STARTED_AT= LOCK_STARTED_EPOCH= LOCK_CHECKOUT= LOCK_ARGV=
  tsv=$(jq -r '[((.pid // "")|tostring),(.pair//""),(.started_at//""),((.started_epoch//0)|tostring),(.checkout//""),((.argv//[])|join(" "))]|@tsv' \
    "$CERT_BUNDLE_LOCK_HOLDER_FILE" 2>/dev/null) || return 1
  IFS=$'\t' read -r LOCK_PID LOCK_PAIR LOCK_STARTED_AT LOCK_STARTED_EPOCH LOCK_CHECKOUT LOCK_ARGV <<<"$tsv"
  case "$LOCK_PID" in ''|*[!0-9]*) return 1 ;; esac
  return 0
}

certbundle_lock_age() { # certbundle_lock_age <started-epoch>
  local started="$1" secs
  # Age is reported, never decided on: staleness is PID liveness (a bundle
  # legitimately runs for over an hour, and a run killed after 30 seconds is
  # just as dead as one killed after two hours).
  case "$started" in ''|0|*[!0-9]*) printf 'age unknown'; return 0 ;; esac
  secs=$(( $(date -u +%s) - started ))
  [ "$secs" -ge 0 ] || secs=0
  printf 'running %dh%02dm' "$((secs / 3600))" "$(((secs % 3600) / 60))"
}

certbundle_lock_refuse() { # certbundle_lock_refuse <headline>
  printf '\n\033[1;31mFAIL: %s\033[0m\n' "$1" >&2
  printf '  lock           : %s\n' "$CERT_BUNDLE_LOCK_DIR" >&2
  printf '  holder pid     : %s (alive)\n' "$LOCK_PID" >&2
  printf '  holder pair    : %s\n' "${LOCK_PAIR:-unknown}" >&2
  printf '  holder started : %s (%s)\n' "${LOCK_STARTED_AT:-unknown}" "$(certbundle_lock_age "$LOCK_STARTED_EPOCH")" >&2
  printf '  holder checkout: %s\n' "${LOCK_CHECKOUT:-unknown}" >&2
  printf '  holder argv    : %s\n' "${LOCK_ARGV:-unknown}" >&2
  printf 'Two bundles cannot share a host: they fight over the pair, the ports, and each\n' >&2
  printf "other's evidence. Wait for the holder instead of displacing it:\n" >&2
  printf '  CERT_BUNDLE_WAIT=1 CERT_BUNDLE_PAIR=%s CERT_BUNDLE_PORT1=%s CERT_BUNDLE_PORT2=%s bash sandbox/tests/certify_reference_bundle.sh\n' \
    "$PAIR" "$PORT1" "$PORT2" >&2
  printf 'Do NOT clear the way with `pkill -f certify_reference_bundle` or any other\n' >&2
  printf 'pattern kill: the pattern matches every agent -- parent, wrapper, and watcher --\n' >&2
  printf 'orphaning the conformance child and destroying its work root (DUO-3382: three\n' >&2
  printf 'runs lost this way on 2026-08-09). Kill only a PID your own launcher recorded.\n' >&2
  printf 'If you believe THIS holder is already dead, prove it with `ps -p %s`: a dead\n' "$LOCK_PID" >&2
  printf 'holder is detected and taken over by the next invocation, with no kill at all.\n' >&2
  exit 1
}

certbundle_lock_take_over_stale() { # certbundle_lock_take_over_stale <dead-pid> <started-epoch>
  local dead_pid="$1" started="$2" claimed claimed_pid claimed_started
  claimed="$CERT_BUNDLE_LOCK_DIR.stale.$$"
  rm -rf -- "$claimed"
  # rename(2) is atomic, so only one of several racing takers claims the
  # corpse. But between our liveness check and this rename another taker may
  # already have taken over AND acquired, in which case what we just claimed
  # is a LIVE holder's lock. Verify by content and put it back: a live
  # holder's lock is never destroyed, which is the whole point of DUO-3382.
  mv "$CERT_BUNDLE_LOCK_DIR" "$claimed" 2>/dev/null || return 1
  claimed_pid=$(jq -r '((.pid // "")|tostring)' "$claimed/holder.json" 2>/dev/null || true)
  claimed_started=$(jq -r '((.started_epoch//0)|tostring)' "$claimed/holder.json" 2>/dev/null || true)
  if [ "$claimed_pid" != "$dead_pid" ] || [ "$claimed_started" != "$started" ]; then
    mv "$claimed" "$CERT_BUNDLE_LOCK_DIR" 2>/dev/null \
      || fail "took over $CERT_BUNDLE_LOCK_DIR believing pid $dead_pid dead, found live holder pid ${claimed_pid:-unknown} instead, and could not put it back; its lock now sits at $claimed -- restore it by hand before any bundle runs"
    return 1
  fi
  rm -rf -- "$claimed"
  return 0
}

certbundle_lock_release() {
  [ "$CERT_BUNDLE_LOCK_OWNED" = 1 ] || return 0
  CERT_BUNDLE_LOCK_OWNED=0
  local holder_pid
  holder_pid=$(jq -r '((.pid // "")|tostring)' "$CERT_BUNDLE_LOCK_HOLDER_FILE" 2>/dev/null || true)
  if [ -n "$holder_pid" ] && [ "$holder_pid" != "$$" ]; then
    # Somebody took this lock over while we still held it (only reachable if
    # our liveness was misread). Releasing here would delete THEIR lock.
    printf 'refusing to release %s: it names pid %s, not this run (%s)\n' \
      "$CERT_BUNDLE_LOCK_DIR" "$holder_pid" "$$" >&2
    return 0
  fi
  rm -rf -- "$CERT_BUNDLE_LOCK_DIR"
  return 0
}

certbundle_lock_acquire() {
  local waited=0 blank_retries=0 absent_retries=0 takeovers=0 mkdir_err=
  if [ "$CERT_BUNDLE_LOCK_DIR" != /tmp/duo-certbundle.lock ]; then
    printf 'NOTE: host certification lock redirected to %s; this run is NOT serialized\n' "$CERT_BUNDLE_LOCK_DIR" >&2
    printf 'against bundles using the default rendezvous /tmp/duo-certbundle.lock\n' >&2
  fi
  while :; do
    if mkdir_err=$(mkdir "$CERT_BUNDLE_LOCK_DIR" 2>&1); then
      jq -n --argjson pid "$$" --arg pair "$PAIR" \
        --arg started_at "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" \
        --argjson started_epoch "$(date -u +%s)" \
        --arg checkout "$REPO_ROOT" \
        --argjson argv "$(printf '%s\n' "${CERT_BUNDLE_ARGV[@]}" | jq -R . | jq -s .)" \
        '{schema_version:1,pid:$pid,pair:$pair,started_at:$started_at,started_epoch:$started_epoch,checkout:$checkout,argv:$argv}' \
        > "$CERT_BUNDLE_LOCK_HOLDER_FILE"
      CERT_BUNDLE_LOCK_OWNED=1
      # Armed here, not after the work root below: a signal in between must
      # not leave a lock nobody releases.
      trap certbundle_lock_release EXIT
      pass "host certification lock acquired: $CERT_BUNDLE_LOCK_DIR (pid $$, pair $PAIR)"
      return 0
    fi

    if [ ! -d "$CERT_BUNDLE_LOCK_DIR" ]; then
      # mkdir failed and yet nothing is there: either the holder released in
      # the instant between the two, or the rendezvous cannot be created at
      # all (an unwritable /tmp). Bounded retries tell those apart instead of
      # spinning forever on a permission error.
      absent_retries=$((absent_retries + 1))
      [ "$absent_retries" -le 20 ] \
        || fail "cannot create the host certification rendezvous $CERT_BUNDLE_LOCK_DIR: ${mkdir_err:-mkdir failed without a message}"
      sleep 0.05
      continue
    fi
    absent_retries=0

    if ! certbundle_lock_read_holder; then
      blank_retries=$((blank_retries + 1))
      if [ "$blank_retries" -le 20 ]; then sleep 0.1; continue; fi
      # mkdir wins a beat before holder.json lands, so a nameless lock is
      # normally a sub-second window. Two seconds on, it is a run killed
      # mid-acquisition. We cannot prove whose it is, so we refuse instead of
      # guessing -- removing an unidentified lock is the pattern-kill mistake
      # wearing a different hat.
      fail "$CERT_BUNDLE_LOCK_DIR exists but names no holder after ${blank_retries} reads; a run was killed mid-acquisition. Confirm no bundle is running (ps -ef | grep -c '[c]ertify_reference_bundle') and then: rm -rf $CERT_BUNDLE_LOCK_DIR"
    fi
    blank_retries=0

    if ! certbundle_pid_alive "$LOCK_PID"; then
      takeovers=$((takeovers + 1))
      [ "$takeovers" -le 5 ] || fail "host certification lock $CERT_BUNDLE_LOCK_DIR keeps changing hands under us ($takeovers stale takeovers); retry once the host settles"
      say "stale host certification lock: holder pid $LOCK_PID (pair ${LOCK_PAIR:-unknown}, started ${LOCK_STARTED_AT:-unknown}) is not alive -- taking it over"
      certbundle_lock_take_over_stale "$LOCK_PID" "$LOCK_STARTED_EPOCH" || true
      continue
    fi

    if [ "$CERT_BUNDLE_WAIT" = 1 ]; then
      if [ "$waited" -ge "$CERT_BUNDLE_WAIT_TIMEOUT" ]; then
        certbundle_lock_refuse "waited ${waited}s for the host certification lock (CERT_BUNDLE_WAIT_TIMEOUT=${CERT_BUNDLE_WAIT_TIMEOUT}s) and it is still held"
      fi
      printf 'waiting for the host certification lock: holder pid %s pair %s (%s); %ss elapsed of %ss budget\n' \
        "$LOCK_PID" "${LOCK_PAIR:-unknown}" "$(certbundle_lock_age "$LOCK_STARTED_EPOCH")" \
        "$waited" "$CERT_BUNDLE_WAIT_TIMEOUT"
      sleep "$CERT_BUNDLE_WAIT_POLL"
      waited=$((waited + CERT_BUNDLE_WAIT_POLL))
      continue
    fi

    certbundle_lock_refuse "refusing to start a second certification bundle on this host"
  done
}

# The offline lock regression (sandbox/tests/regress_certbundle_lock.sh)
# sources this file to drive the functions above against its own rendezvous
# and a stub holder -- it runs the shipped bytes, not a transcription of them.
# Nothing below this point may run in that mode.
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

WORK_ROOT=$(mktemp -d /tmp/duo-certbundle.XXXXXX)
cleanup_run() {
  case "$WORK_ROOT" in
    /tmp/duo-certbundle.*) rm -rf -- "$WORK_ROOT" ;;
    *) printf 'refusing unsafe work cleanup path: %s\n' "$WORK_ROOT" >&2 ;;
  esac
  # DUO-3382: the lock is released LAST, and from the same trap that clears
  # the work root -- the next invocation must never win the lock while this
  # run's work root is still on disk.
  certbundle_lock_release
}
trap cleanup_run EXIT   # replaces the release-only trap armed by certbundle_lock_acquire

ENV_FILE="$WORK_ROOT/environment.json"
MULTISITE_LOG="$WORK_ROOT/multisite-refusal.log"
MATRIX_LOG="$WORK_ROOT/exact-artifact-version-matrix.log"
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
bash -n conformance/run.sh tests/regress_multisite_refusal.sh tests/certify_version_matrix.sh
bash bin/pair.sh list
pass "bundle builder and all invoked harnesses parse; pair load inspected"

overall=0
leg=0
total_legs=$((${#CONFORMANCE_MANIFESTS[@]} + 2))
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

say "materialize the content-addressed machine-readable bundle"
BOUND_INPUTS=$({ git -C "$REPO_ROOT" ls-files \
  agent cli manifests sandbox/bin sandbox/conformance \
  sandbox/tests/certify_reference_bundle.sh \
  sandbox/tests/certify_version_matrix.sh \
  sandbox/tests/regress_multisite_refusal.sh \
  scripts/capability-registry.php docs/compatibility-baseline.json \
  DESIGN.md spec/repo-format.md Makefile .github/workflows/conformance.yml; \
  printf '%s\n' manifests/dispositions.json; } \
  | grep -v '^manifests/capabilities/' | sort -u | jq -R . | jq -s .)
ARTIFACTS=$(jq '[to_entries[] as $slug | $slug.value | to_entries[] | {name:$slug.key,version:.key,url:.value.url,sha256:.value.sha256,role:.value.role}]' conformance/artifacts.lock.json)
TESTS=$(printf '%s\n' "${TEST_FRAGMENTS[@]}" | jq -s .)
CREATED_AT=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
GIT_REVISION=$(git -C "$REPO_ROOT" rev-parse HEAD)
jq -n \
  --arg repo_root "$REPO_ROOT" --arg created_at "$CREATED_AT" --arg git_revision "$GIT_REVISION" \
  --arg environment "$ENV_FILE" --argjson bound_inputs "$BOUND_INPUTS" --argjson artifacts "$ARTIFACTS" \
  --arg ratification "$REPO_ROOT/manifests/dispositions.json" --argjson tests "$TESTS" \
  '{
    repo_root:$repo_root,created_at:$created_at,git_revision:$git_revision,
    harness:{name:"duo-reference-certification",version:3},force_hatches:[],
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

if [ "$overall" -ne 0 ] || [ "$build_rc" -ne 0 ] || [ "$verify_rc" -ne 0 ]; then
  fail "reference certification failed; immutable evidence remains at $BUNDLE"
fi

LIVE_CONTAINERS=$(docker ps --format '{{.Names}}')
if grep -qE "^duo-${PAIR}-" <<<"$LIVE_CONTAINERS"; then
  fail "own pair '$PAIR' is still running after a green reference certification"
fi

pass "reference bundle is valid, content-addressed, input-bound, and immediately re-verified"
printf '\n\033[1;32m✔ CERTIFY_REFERENCE_BUNDLE PASSED (%s)\033[0m\n' "$(basename "$BUNDLE")"
