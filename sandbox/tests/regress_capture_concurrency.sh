#!/usr/bin/env bash
# Live regression — DUO-3223 (concurrency-scenario arm): DUO-3213's capture
# lock (agent/src/Publish.php's flock()-based mutual exclusion) under REAL
# concurrent `wp duo capture` processes, not simulated.
#
# regress_capture_publish.php already proves Publish::lock()'s primitives
# offline (mutual exclusion, clean failure+release, per-destination
# independence, deterministic crash recovery, a real SIGKILL mid-publish) —
# see its own P1-P6. What it CANNOT prove, by its own deliberate design (zero
# WordPress/$wpdb dependency), is that the SAME guarantee holds for the FULL
# `wp duo capture` command dispatch: real docker process isolation, a real
# WordPress bootstrap, and genuinely separate wp-cli invocations racing for
# the same destination — this file's job.
#
# Deterministic by construction, not by luck: getting real processes to
# GENUINELY overlap by chance timing alone is exactly the kind of flaky test
# this project's own discipline refuses to ship. Reuses the SAME idiom
# DUO-3217 established for its own live PromotionLock races
# (sandbox/tests/regress_promotion_lock.sh): a DUO_TEST_MODE-gated,
# env-var-controlled deterministic release gate (agent/src/Capture.php's own
# DUO_TEST_CAPTURE_WAIT_FOR_RELEASE hook, immediately after Publish::lock()
# succeeds) plus a DB-backed (Ledger::kv_set(), cross-process-visible —
# unlike the lock itself, which is a local flock() invisible to another
# process) phase marker a controlling script polls for before launching the
# racing captures. No effect on production capture unless a caller
# explicitly opts into both env vars.
#
#   PART 1 — mutual exclusion, SAME destination: one real `wp duo capture`
#     holds --repo=.'s lock while simultaneous machine- and human-mode
#     contenders race for it. Both are refused immediately and cleanly on
#     their respective contracts; the lock holder completes normally;
#     the published tree reflects exactly what the first alone produced,
#     no leftover staging/backup/lock artifacts, and a completely ordinary
#     THIRD capture afterward proves the environment isn't left degraded.
#   PART 2 — independence, DIFFERENT destinations: the same per-process
#     overlap, but the second capture targets a genuinely different
#     destination on the SAME environment (a different --out=). It must
#     succeed without any interference at all, proving the lock is scoped
#     per-destination under real concurrency, not merely in the offline
#     Publish.php-primitive test.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$REPO_ROOT/sandbox"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
. "$REPO_ROOT/sandbox/conformance/asserts.sh"

PAIR="${CONCURRENCY_PAIR:-}"
PORT1="${CONCURRENCY_PORT1:-}"
PORT2="${CONCURRENCY_PORT2:-}"
[ -n "$PAIR" ] || fail "CONCURRENCY_PAIR is required; choose an unused actor-owned pair name"
[ -n "$PORT1" ] || fail "CONCURRENCY_PORT1 is required; choose an unused even port >= 8900"
[ -n "$PORT2" ] || fail "CONCURRENCY_PORT2 is required; set it to CONCURRENCY_PORT1 + 1"
if [[ ! "$PAIR" =~ ^[a-z][a-z0-9]*$ ]]; then
  fail "invalid CONCURRENCY_PAIR '$PAIR'"
fi
if [[ ! "$PORT1" =~ ^[0-9]+$ ]] || [[ ! "$PORT2" =~ ^[0-9]+$ ]]; then
  fail "capture-concurrency ports must be decimal integers"
fi
PORT1=$((10#$PORT1))
PORT2=$((10#$PORT2))
if (( PORT1 < 8900 || PORT1 > 65534 || PORT1 % 2 != 0 || PORT2 != PORT1 + 1 )); then
  fail "capture-concurrency ports must be an even port >=8900 plus its adjacent successor"
fi

EXPECTED_SHA="${DUO_EXPECTED_SOURCE_SHA:-}"
[ -n "$EXPECTED_SHA" ] || fail "DUO_EXPECTED_SOURCE_SHA is required; refuse to run without an exact candidate gate"
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "DUO_EXPECTED_SOURCE_SHA must be a lowercase 40-character commit SHA"
export DUO_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"
[ -d "$REPO_ROOT/.git" ] \
  || fail "capture-concurrency evidence must run from a standalone clone, not a linked worktree"
SOURCE_SHA=""
assert_exact_source() {
  local source_status=""
  SOURCE_SHA="$(git -C "$REPO_ROOT" --no-optional-locks rev-parse --verify 'HEAD^{commit}')" \
    || fail "capture-concurrency evidence source has no resolvable Git HEAD"
  [ "$EXPECTED_SHA" = "$SOURCE_SHA" ] \
    || fail "DUO_EXPECTED_SOURCE_SHA=$EXPECTED_SHA does not equal this checkout HEAD=$SOURCE_SHA"
  if ! source_status=$(git -C "$REPO_ROOT" --no-optional-locks status \
      --porcelain=v1 --untracked-files=all); then
    fail "could not inspect capture-concurrency source cleanliness at $SOURCE_SHA"
  fi
  [ -z "$source_status" ] \
    || fail "capture-concurrency evidence checkout is dirty; use a clean standalone clone at $SOURCE_SHA"
}
assert_exact_source

HOST_REPO1="$REPO_ROOT/sandbox/siterepo/${PAIR}1"
HOST_REPO2="$REPO_ROOT/sandbox/siterepo/${PAIR}2"
HOST_ORIGIN="$REPO_ROOT/sandbox/siterepo/origin-${PAIR}.git"
LOG_A="/tmp/duo3223-${PAIR}-a.log"
LOG_B_JSON_OUT="/tmp/duo3223-${PAIR}-b-json.out"
LOG_B_JSON_ERR="/tmp/duo3223-${PAIR}-b-json.err"
LOG_B_HUMAN_OUT="/tmp/duo3223-${PAIR}-b-human.out"
LOG_B_HUMAN_ERR="/tmp/duo3223-${PAIR}-b-human.err"
LOG_C="/tmp/duo3223-${PAIR}-c.log"
LOG_D="/tmp/duo3223-${PAIR}-d.log"
PHASE_ERR="/tmp/duo3223-${PAIR}-phase.err"
for path in "$HOST_REPO1" "$HOST_REPO2" "$HOST_ORIGIN"; do
  { [ ! -e "$path" ] && [ ! -L "$path" ]; } \
    || fail "$path already exists; inspect or remove that owned evidence before rerunning"
done
for path in "$LOG_A" "$LOG_B_JSON_OUT" "$LOG_B_JSON_ERR" \
    "$LOG_B_HUMAN_OUT" "$LOG_B_HUMAN_ERR" "$LOG_C" "$LOG_D" "$PHASE_ERR"; do
  { [ ! -e "$path" ] && [ ! -L "$path" ]; } \
    || fail "$path already exists; inspect or remove that owned evidence before rerunning"
done
EXISTING_CONTAINERS=""
if ! EXISTING_CONTAINERS=$(docker ps -a \
    --filter "label=com.docker.compose.project=duo-${PAIR}" --format '{{.ID}}'); then
  fail "could not inspect compose project duo-${PAIR}; refuse to assume its namespace is unused"
fi
if [ -n "$EXISTING_CONTAINERS" ]; then
  fail "compose project duo-${PAIR} already has containers; choose an unused pair"
fi

COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml)
export DUO_PAIR="$PAIR"
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp1_test() { "${COMPOSE[@]}" run --rm -T -e DUO_TEST_MODE=1 -e DUO_TEST_CAPTURE_WAIT_FOR_RELEASE=1 cli1 wp "$@"; }
read_capture_phase() {
  local rc=0
  set +e
  PHASE=$(wp1 eval 'echo (string) \Duo\Ledger::kv_get("capture_test_phase");' 2>"$PHASE_ERR")
  rc=$?
  set -e
  return "$rc"
}
release_capture() {
  wp1 eval '\Duo\Ledger::kv_set("capture_test_phase", "release");' >/dev/null
}
PAIR_OWNED=0
GREEN=0
PID_A=""
PID_B_JSON=""
PID_B_HUMAN=""
PID_C=""

cleanup() {
  local status=$? remaining="" pid=""
  local -a running_ids=()
  trap - EXIT
  for pid in "$PID_A" "$PID_B_JSON" "$PID_B_HUMAN" "$PID_C"; do
    if [[ "$pid" =~ ^[0-9]+$ ]]; then
      if jobs -pr | grep -qx "$pid"; then
        kill "$pid" 2>/dev/null || true
      fi
      wait "$pid" 2>/dev/null || true
    fi
  done
  if [ "$PAIR_OWNED" = 1 ]; then
    if [ "$GREEN" = 1 ] && [ "$status" -eq 0 ]; then
      if ! bash bin/pair.sh destroy "$PAIR"; then
        printf 'FAIL: pair destroy failed for %s; preserving its repository artifacts\n' "$PAIR" >&2
        status=1
      elif ! remaining=$(docker ps -a \
          --filter "label=com.docker.compose.project=duo-${PAIR}" --format '{{.ID}}'); then
        printf 'FAIL: could not prove pair %s destroyed; preserving its repository artifacts\n' "$PAIR" >&2
        status=1
      elif [ -n "$remaining" ]; then
        printf 'FAIL: pair %s still has containers; preserving its repository artifacts\n' "$PAIR" >&2
        status=1
      else
        rm -rf -- "$HOST_REPO1" "$HOST_REPO2" "$HOST_ORIGIN"
        rm -f -- "$LOG_A" "$LOG_B_JSON_OUT" "$LOG_B_JSON_ERR" \
          "$LOG_B_HUMAN_OUT" "$LOG_B_HUMAN_ERR" "$LOG_C" "$LOG_D" "$PHASE_ERR"
      fi
    else
      if ! bash bin/pair.sh stop "$PAIR"; then
        printf 'FAIL: pair stop failed for %s; preserving all reachable evidence\n' "$PAIR" >&2
        status=1
      elif ! remaining=$(docker ps \
          --filter "label=com.docker.compose.project=duo-${PAIR}" --format '{{.ID}}'); then
        printf 'FAIL: could not prove pair %s stopped; preserving all reachable evidence\n' "$PAIR" >&2
        status=1
      elif [ -n "$remaining" ]; then
        read -r -a running_ids <<<"$(tr '\n' ' ' <<<"$remaining")"
        if ! docker stop "${running_ids[@]}" >/dev/null; then
          printf 'FAIL: could not stop pair %s one-off containers; preserving all reachable evidence\n' "$PAIR" >&2
          status=1
        elif ! remaining=$(docker ps \
            --filter "label=com.docker.compose.project=duo-${PAIR}" --format '{{.ID}}'); then
          printf 'FAIL: could not recheck pair %s after stopping one-off containers\n' "$PAIR" >&2
          status=1
        elif [ -n "$remaining" ]; then
          printf 'FAIL: pair %s still has running containers; preserving all reachable evidence\n' "$PAIR" >&2
          status=1
        fi
      fi
      printf 'preserved failed capture-concurrency evidence under:\n  %s\n  /tmp/duo3223-%s-*\n' \
        "$HOST_REPO1" "$PAIR" >&2
      printf 'resume the stopped pair with: bash sandbox/bin/pair.sh start %s\n' "$PAIR" >&2
    fi
  fi
  exit "$status"
}
trap cleanup EXIT

say "fresh actor-owned pair via pair.sh (headless, single side is enough: this arm tests capture's OWN lock, not cross-environment sync)"
PAIR_OWNED=1
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless

say "seed a minimal real site + site.duo.json (deliberately small: the deterministic pause creates the race window, not fixture size)"
BASE_PAGE_ID=$(wp1 post create --post_type=page --post_status=publish --post_title='Concurrency Base' --porcelain)
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "options": {}, "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
git -C "siterepo/${PAIR}1" init -q -b main
pass "environment ready"

# ============================================================================
# PART 1 — mutual exclusion under real concurrent processes, SAME destination
# ============================================================================

say "PART 1 — launch capture A (paused mid-lock via DUO_TEST_MODE) in the background against /siterepo"
wp1_test duo capture --repo=/siterepo --format=json >"$LOG_A" 2>&1 &
PID_A=$!

say "PART 1 — poll for A's cross-process phase marker (Ledger::kv_get) rather than guess a sleep"
PHASE=""
for _ in $(seq 1 80); do
  if read_capture_phase && [ "$PHASE" = "locked" ]; then
    break
  fi
  sleep 0.1
done
[ "$PHASE" = "locked" ] \
  || fail "capture A never reached its deterministic lock-held pause (got phase: '$PHASE'; phase stderr: $(tail -3 "$PHASE_ERR"))"
pass "capture A holds the lock and is deterministically paused"

say "PART 1 — machine and human capture B contenders race concurrently for the SAME destination while A still holds the lock"
wp1 duo capture --repo=/siterepo --format=json >"$LOG_B_JSON_OUT" 2>"$LOG_B_JSON_ERR" &
PID_B_JSON=$!
wp1 duo capture --repo=/siterepo >"$LOG_B_HUMAN_OUT" 2>"$LOG_B_HUMAN_ERR" &
PID_B_HUMAN=$!
set +e
wait "$PID_B_JSON"
CAPTURE_B_RC=$?
PID_B_JSON=""
wait "$PID_B_HUMAN"
CAPTURE_B_HUMAN_RC=$?
PID_B_HUMAN=""
set -e
CAPTURE_B_JSON="$(cat "$LOG_B_JSON_OUT")"
CAPTURE_B_JSON_ERR="$(cat "$LOG_B_JSON_ERR")"
CAPTURE_B_HUMAN="$(cat "$LOG_B_HUMAN_OUT" "$LOG_B_HUMAN_ERR")"
printf '%s\n' "$CAPTURE_B_JSON"
[ "$CAPTURE_B_RC" -eq 1 ] || fail "capture B returned $CAPTURE_B_RC instead of the typed refusal exit 1"
require_duo_answered "capture B JSON refusal" json "$CAPTURE_B_JSON"
jq -e -s '
  length == 1 and .[0] == {
    "format": "duo-command-refusal/v1",
    "ok": false,
    "command": "capture",
    "error": "capture_lock_held",
    "reason_code": "capture_lock_held",
    "message": "capture refused because another publisher holds the destination lock",
    "remediation": "wait for the current publisher to finish, verify its receipt, then start a new capture",
    "diagnostics": [{
      "code": "capture_lock_held",
      "message": "another publisher owns the capture destination",
      "remediation": "wait for the publisher and verify its capture receipt"
    }]
  }
' "$LOG_B_JSON_OUT" >/dev/null \
  || fail "capture B did not return exactly one typed capture_lock_held machine contract: $CAPTURE_B_JSON"
[[ "$CAPTURE_B_JSON" != *"/siterepo/state"* ]] \
  && [[ "$CAPTURE_B_JSON" != *"already publishing"* ]] \
  && [[ "$CAPTURE_B_JSON" != *"lock held:"* ]] \
  && [[ "$CAPTURE_B_JSON" != *"DUO-3213"* ]] \
  || fail "capture B's machine contract leaked operator-only path or lock evidence: $CAPTURE_B_JSON"
! grep -Eqi 'duo-command-refusal/v1|capture_lock_held|(^|[[:space:]])duo:|capture refused|another (capture|publisher)|already publishing|lock held:|DUO-3213|/siterepo/state' \
    <<<"$CAPTURE_B_JSON_ERR" \
  || fail "capture B's machine invocation leaked operator or refusal evidence on stderr: $CAPTURE_B_JSON_ERR"
pass "capture B refused immediately through the typed, path-redacted machine contract"

say "PART 1 — human mode retains operator-only lock and destination evidence"
echo "$CAPTURE_B_HUMAN"
[ "$CAPTURE_B_HUMAN_RC" -eq 1 ] || fail "human-mode capture B returned $CAPTURE_B_HUMAN_RC instead of refusal exit 1"
require_duo_answered "capture B human refusal" human "$CAPTURE_B_HUMAN"
grep -qi "already publishing" <<<"$CAPTURE_B_HUMAN" \
  || fail "human-mode capture B did not name the held capture lock: $CAPTURE_B_HUMAN"
grep -q "lock held: /siterepo/state.capture.lock" <<<"$CAPTURE_B_HUMAN" \
  || fail "human-mode capture B did not name the exact contended destination lock: $CAPTURE_B_HUMAN"
! grep -q 'duo-command-refusal/v1' <<<"$CAPTURE_B_HUMAN" \
  || fail "human-mode capture B emitted the machine refusal envelope: $CAPTURE_B_HUMAN"
pass "human-mode capture B retained the operator-only lock and destination evidence"

read_capture_phase \
  || fail "could not prove capture A still held its lock after both B refusals: $(tail -3 "$PHASE_ERR")"
[ "$PHASE" = "locked" ] \
  || fail "capture A released its lock before both B refusals completed; overlap was not proven (phase: '$PHASE')"
pass "both B refusals completed while capture A still provably held the destination lock"
release_capture || fail "could not release capture A after proving both same-destination refusals"

say "PART 1 — capture A completes normally once the controller releases its test gate"
wait "$PID_A" || { tail -60 "$LOG_A"; fail "capture A itself failed (see log above)"; }
PID_A=""
tail -3 "$LOG_A"
pass "capture A (the true lock holder) completed successfully"
read_capture_phase \
  || fail "could not verify capture A cleared its phase marker: $(tail -3 "$PHASE_ERR")"
[ -z "$PHASE" ] || fail "capture A left a stale '$PHASE' marker that could fake PART 2's overlap"
pass "capture A cleared its deterministic phase marker"

say "PART 1 — acceptance: published tree is exactly what A alone produced; no leftover staging/backup/lock artifacts from B's refused attempt"
[ -d "siterepo/${PAIR}1/state" ] || fail "state/ missing after a successful capture"
[ ! -d "siterepo/${PAIR}1/state.capture-staging" ] || fail "a leftover staging dir survived a successful cycle (B's refused attempt should never have created one — it never got past the lock)"
[ ! -d "siterepo/${PAIR}1/state.capture-backup" ] || fail "a leftover backup dir survived a successful cycle"
BASE_PAGE_UUID=$(wp1 post meta get "$BASE_PAGE_ID" _duo_uuid)
[ -n "$BASE_PAGE_UUID" ] || fail "capture A never minted an identity for the seeded page"
[ -f "siterepo/${PAIR}1/state/posts/page/${BASE_PAGE_UUID}--concurrency-base.md" ] \
  || fail "capture A's own content is missing from the published tree"
pass "published tree is exactly capture A's own content, no contention artifacts left behind"

say "PART 1 — a completely ordinary follow-up capture proves the environment is not left degraded by the contention"
wp1 post create --post_type=page --post_status=publish --post_title='After Contention' >/dev/null
FOLLOWUP_OUT=$(wp1 duo capture --repo=/siterepo --format=json | tail -1)
jq . <<<"$FOLLOWUP_OUT"
jq -e '.counts.post >= 2' <<<"$FOLLOWUP_OUT" >/dev/null \
  || fail "follow-up capture after contention did not run normally: $FOLLOWUP_OUT"
pass "PART 1 complete: capture lock serializes two real concurrent processes with zero interleaving, zero leaked artifacts, environment healthy afterward"

# ============================================================================
# PART 2 — independence under real concurrency, DIFFERENT destinations
# ============================================================================

say "PART 2 — launch a paused capture into /siterepo (the SAME, now-established destination) in the background"
wp1_test duo capture --repo=/siterepo --format=json >"$LOG_C" 2>&1 &
PID_C=$!
PHASE=""
for _ in $(seq 1 80); do
  if read_capture_phase && [ "$PHASE" = "locked" ]; then
    break
  fi
  sleep 0.1
done
[ "$PHASE" = "locked" ] \
  || fail "capture C never reached its deterministic lock-held pause (got phase: '$PHASE'; phase stderr: $(tail -3 "$PHASE_ERR"))"
pass "capture C holds /siterepo's lock and is deterministically paused"

say "PART 2 — a capture into a DIFFERENT destination (--out=) on the SAME environment must proceed unaffected"
wp1 duo capture --repo=/siterepo --out=/siterepo/.tmp-concurrency-other --format=json >"$LOG_D" 2>&1
tail -3 "$LOG_D"
[ -d "siterepo/${PAIR}1/.tmp-concurrency-other" ] || fail "the different-destination capture did not produce output while the other destination's lock was held"
read_capture_phase \
  || fail "could not prove capture C still held its lock after the different-destination capture: $(tail -3 "$PHASE_ERR")"
[ "$PHASE" = "locked" ] \
  || fail "capture C released its lock before the different-destination capture completed; overlap was not proven (phase: '$PHASE')"
pass "a different destination is completely unaffected by /siterepo's own held lock"
release_capture || fail "could not release capture C after proving different-destination independence"

wait "$PID_C" || { tail -60 "$LOG_C"; fail "capture C itself failed (see log above)"; }
PID_C=""
rm -rf "siterepo/${PAIR}1/.tmp-concurrency-other"
pass "PART 2 complete: the capture lock is scoped per-destination under real concurrency, not global — proven live, not just at the offline Publish.php-primitive level"

assert_exact_source
printf '\n\033[1;32m✔ REGRESS CAPTURE CONCURRENCY PASSED (real concurrent wp duo capture processes: same-destination mutual exclusion, cross-destination independence)\033[0m\n'
GREEN=1
