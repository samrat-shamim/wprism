#!/usr/bin/env bash
# Live regression — issue #3223 (concurrency-scenario arm): issue #3213's capture
# lock (agent/src/Publication/Publish.php's flock()-based mutual exclusion) under REAL
# concurrent `wp wprism capture` processes, not simulated.
#
# regress_capture_publish.php already proves Publish::lock()'s primitives
# offline (mutual exclusion, clean failure+release, per-destination
# independence, deterministic crash recovery, a real SIGKILL mid-publish) —
# see its own P1-P6. What it CANNOT prove, by its own deliberate design (zero
# WordPress/$wpdb dependency), is that the SAME guarantee holds for the FULL
# `wp wprism capture` command dispatch: real docker process isolation, a real
# WordPress bootstrap, and genuinely separate wp-cli invocations racing for
# the same destination — this file's job.
#
# Deterministic by construction, not by luck: getting real processes to
# GENUINELY overlap by chance timing alone is exactly the kind of flaky test
# this project's own discipline refuses to ship. Reuses the SAME idiom
# issue #3217 established for its own live PromotionLock races
# (sandbox/tests/live/regress_promotion_lock.sh): a WPRISM_TEST_MODE-gated,
# env-var-controlled deterministic release gate (CapturePublicationWorkflow's own
# WPRISM_TEST_CAPTURE_WAIT_FOR_RELEASE hook, immediately after Publish::lock()
# succeeds) plus a DB-backed (Ledger::kv_set(), cross-process-visible —
# unlike the lock itself, which is a local flock() invisible to another
# process) phase marker a controlling script polls for before launching the
# racing captures. No effect on production capture unless a caller
# explicitly opts into both env vars.
#
#   PART 1 — mutual exclusion, SAME destination: one real `wp wprism capture`
#     holds --repo=.'s lock while simultaneous machine- and human-mode
#     contenders race for it. Both are refused immediately and cleanly on
#     their respective contracts; the lock holder completes normally;
#     the published tree reflects exactly what the first alone produced,
#     no leftover staging/backup/lock artifacts, and a completely ordinary
#     THIRD capture afterward proves the environment isn't left degraded.
#   PART 2 — target serialization, DIFFERENT destinations: a second capture
#     owns an independent filesystem destination but shares the target ledger
#     and embedded identities. It must refuse at the target-writer boundary,
#     publish no candidate artifacts, retain only Publish's documented inert
#     lock file, and succeed through that same lock on retry.
#   PART 3 — capture/apply cross-races: capture-held apply refusal and
#     apply-held capture refusal both preserve the old target. The winning
#     apply performs one content update and one verified rewrite rebuild;
#     fresh-process generated/stored rules agree and a retry is a no-op.
#   PART 4 — deletion contention: two exact product-path delete applies race;
#     one deletes the mapped page once, the other refuses, and a retry is a
#     no-op with no lease or recovery residue.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$REPO_ROOT/sandbox"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
. lib/pair_db.sh
pair_db_select_engine
. "$REPO_ROOT/sandbox/conformance/asserts.sh"

WORDPRESS_OFFLINE="${WPRISM_WORDPRESS_ORG_OFFLINE:-0}"
case "$WORDPRESS_OFFLINE" in
  0|1) ;;
  *) fail "WPRISM_WORDPRESS_ORG_OFFLINE must be 0 or 1" ;;
esac
# shellcheck source=../../bin/fetch-artifact.sh
. "$REPO_ROOT/sandbox/bin/fetch-artifact.sh"
validate_artifact_library \
  || fail "artifact library is malformed; capture-concurrency proof stopped before pair startup"

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

EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:-}"
[ -n "$EXPECTED_SHA" ] || fail "WPRISM_EXPECTED_SOURCE_SHA is required; refuse to run without an exact candidate gate"
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "WPRISM_EXPECTED_SOURCE_SHA must be a lowercase 40-character commit SHA"
export WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"
[ -d "$REPO_ROOT/.git" ] \
  || fail "capture-concurrency evidence must run from a standalone clone, not a linked worktree"
SOURCE_SHA=""
assert_exact_source() {
  local source_status=""
  SOURCE_SHA="$(git -C "$REPO_ROOT" --no-optional-locks rev-parse --verify 'HEAD^{commit}')" \
    || fail "capture-concurrency evidence source has no resolvable Git HEAD"
  [ "$EXPECTED_SHA" = "$SOURCE_SHA" ] \
    || fail "WPRISM_EXPECTED_SOURCE_SHA=$EXPECTED_SHA does not equal this checkout HEAD=$SOURCE_SHA"
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
LOG_A="/tmp/capture-concurrency-${PAIR}-a.log"
LOG_B_JSON_OUT="/tmp/capture-concurrency-${PAIR}-b-json.out"
LOG_B_JSON_ERR="/tmp/capture-concurrency-${PAIR}-b-json.err"
LOG_B_HUMAN_OUT="/tmp/capture-concurrency-${PAIR}-b-human.out"
LOG_B_HUMAN_ERR="/tmp/capture-concurrency-${PAIR}-b-human.err"
LOG_C="/tmp/capture-concurrency-${PAIR}-c.log"
LOG_D_OUT="/tmp/capture-concurrency-${PAIR}-d.out"
LOG_D_ERR="/tmp/capture-concurrency-${PAIR}-d.err"
LOG_CAPTURE_HELD_APPLY="/tmp/capture-concurrency-${PAIR}-capture-held-apply.log"
LOG_APPLY_HOLDER="/tmp/capture-concurrency-${PAIR}-apply-holder.log"
LOG_APPLY_HELD_CAPTURE_OUT="/tmp/capture-concurrency-${PAIR}-apply-held-capture.out"
LOG_APPLY_HELD_CAPTURE_ERR="/tmp/capture-concurrency-${PAIR}-apply-held-capture.err"
LOG_DELETE_HOLDER="/tmp/capture-concurrency-${PAIR}-delete-holder.log"
LOG_DELETE_CONTENDER="/tmp/capture-concurrency-${PAIR}-delete-contender.log"
PHASE_ERR="/tmp/capture-concurrency-${PAIR}-phase.err"
for path in "$HOST_REPO1" "$HOST_REPO2" "$HOST_ORIGIN"; do
  { [ ! -e "$path" ] && [ ! -L "$path" ]; } \
    || fail "$path already exists; inspect or remove that owned evidence before rerunning"
done
for path in "$LOG_A" "$LOG_B_JSON_OUT" "$LOG_B_JSON_ERR" \
    "$LOG_B_HUMAN_OUT" "$LOG_B_HUMAN_ERR" "$LOG_C" "$LOG_D_OUT" "$LOG_D_ERR" \
    "$LOG_CAPTURE_HELD_APPLY" "$LOG_APPLY_HOLDER" "$LOG_APPLY_HELD_CAPTURE_OUT" \
    "$LOG_APPLY_HELD_CAPTURE_ERR" "$LOG_DELETE_HOLDER" "$LOG_DELETE_CONTENDER" "$PHASE_ERR"; do
  { [ ! -e "$path" ] && [ ! -L "$path" ]; } \
    || fail "$path already exists; inspect or remove that owned evidence before rerunning"
done
EXISTING_CONTAINERS=""
if ! EXISTING_CONTAINERS=$(docker ps -a \
    --filter "label=com.docker.compose.project=wprism-${PAIR}" --format '{{.ID}}'); then
  fail "could not inspect compose project wprism-${PAIR}; refuse to assume its namespace is unused"
fi
if [ -n "$EXISTING_CONTAINERS" ]; then
  fail "compose project wprism-${PAIR} already has containers; choose an unused pair"
fi

COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.artifacts.yml)
PAIR_UP_FLAGS=(--headless --artifacts)
if [ "$WORDPRESS_OFFLINE" = 1 ]; then
  COMPOSE+=(-f pair.wordpress-offline.yml)
  PAIR_UP_FLAGS+=(--wordpress-offline)
fi
export WPRISM_PAIR="$PAIR" WPRISM_ARTIFACT_OFFLINE="$WORDPRESS_OFFLINE"
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
wp1_test() { "${COMPOSE[@]}" run --rm -T -e WPRISM_TEST_MODE=1 -e WPRISM_TEST_CAPTURE_WAIT_FOR_RELEASE=1 cli1 wp "$@"; }
wp1_apply_test() { "${COMPOSE[@]}" run --rm -T -e WPRISM_TEST_MODE=1 -e WPRISM_TEST_PROMOTION_PAUSE_MS=10000 cli1 wp "$@"; }
assert_compose_framing_only() {
  local file="$1" what="$2" unexpected=""
  unexpected=$(sed -E \
    -e "/^ Container wprism-${PAIR}-cli[12]-run-[[:alnum:]]+ (Creating|Created) $/d" \
    -e '/^[[:space:]]*$/d' \
    "$file")
  [ -z "$unexpected" ] \
    || fail "$what emitted unexpected stderr beyond one-off Compose framing: $unexpected"
}
share_repo() {
  local service="$1"
  "${COMPOSE[@]}" run --rm -T "$service" sh -c '
    [ ! -e /siterepo/state ] || chmod -R a+rwX /siterepo/state
    [ ! -e /siterepo/media ] || chmod -R a+rwX /siterepo/media
    [ ! -e /siterepo/state.capture.lock ] || chmod a+rw /siterepo/state.capture.lock
  ' >/dev/null
}
sync_state() {
  local source="$1" destination="$2"
  rm -rf -- "$destination/state" "$destination/media"
  cp -R "$source/state" "$destination/state"
  if [ -d "$source/media" ]; then
    cp -R "$source/media" "$destination/media"
  fi
  cp "$source/site.wprism.json" "$destination/site.wprism.json"
  chmod -R a+rwX "$destination"
}
read_capture_phase() {
  local rc=0
  set +e
  PHASE=$(wp1 eval 'echo (string) \WPrism\Ledger::kv_get("capture_test_phase");' 2>"$PHASE_ERR")
  rc=$?
  set -e
  return "$rc"
}
release_capture() {
  wp1 eval '\WPrism\Ledger::kv_set("capture_test_phase", "release");' >/dev/null
}
PAIR_OWNED=0
GREEN=0
PID_A=""
PID_B_JSON=""
PID_B_HUMAN=""
PID_C=""
PID_APPLY=""
PID_DELETE=""

cleanup() {
  local status=$? remaining="" pid=""
  local -a running_ids=()
  trap - EXIT
  for pid in "$PID_A" "$PID_B_JSON" "$PID_B_HUMAN" "$PID_C" "$PID_APPLY" "$PID_DELETE"; do
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
          --filter "label=com.docker.compose.project=wprism-${PAIR}" --format '{{.ID}}'); then
        printf 'FAIL: could not prove pair %s destroyed; preserving its repository artifacts\n' "$PAIR" >&2
        status=1
      elif [ -n "$remaining" ]; then
        printf 'FAIL: pair %s still has containers; preserving its repository artifacts\n' "$PAIR" >&2
        status=1
      else
        rm -rf -- "$HOST_REPO1" "$HOST_REPO2" "$HOST_ORIGIN"
        rm -f -- "$LOG_A" "$LOG_B_JSON_OUT" "$LOG_B_JSON_ERR" \
          "$LOG_B_HUMAN_OUT" "$LOG_B_HUMAN_ERR" "$LOG_C" "$LOG_D_OUT" "$LOG_D_ERR" \
          "$LOG_CAPTURE_HELD_APPLY" "$LOG_APPLY_HOLDER" "$LOG_APPLY_HELD_CAPTURE_OUT" \
          "$LOG_APPLY_HELD_CAPTURE_ERR" "$LOG_DELETE_HOLDER" "$LOG_DELETE_CONTENDER" "$PHASE_ERR"
      fi
    else
      if ! bash bin/pair.sh stop "$PAIR"; then
        printf 'FAIL: pair stop failed for %s; preserving all reachable evidence\n' "$PAIR" >&2
        status=1
      elif ! remaining=$(docker ps \
          --filter "label=com.docker.compose.project=wprism-${PAIR}" --format '{{.ID}}'); then
        printf 'FAIL: could not prove pair %s stopped; preserving all reachable evidence\n' "$PAIR" >&2
        status=1
      elif [ -n "$remaining" ]; then
        read -r -a running_ids <<<"$(tr '\n' ' ' <<<"$remaining")"
        if ! docker stop "${running_ids[@]}" >/dev/null; then
          printf 'FAIL: could not stop pair %s one-off containers; preserving all reachable evidence\n' "$PAIR" >&2
          status=1
        elif ! remaining=$(docker ps \
            --filter "label=com.docker.compose.project=wprism-${PAIR}" --format '{{.ID}}'); then
          printf 'FAIL: could not recheck pair %s after stopping one-off containers\n' "$PAIR" >&2
          status=1
        elif [ -n "$remaining" ]; then
          printf 'FAIL: pair %s still has running containers; preserving all reachable evidence\n' "$PAIR" >&2
          status=1
        fi
      fi
      printf 'preserved failed capture-concurrency evidence under:\n  %s\n  /tmp/capture-concurrency-%s-*\n' \
        "$HOST_REPO1" "$PAIR" >&2
      printf 'resume the stopped pair with: bash sandbox/bin/pair.sh start %s\n' "$PAIR" >&2
    fi
  fi
  exit "$status"
}
trap cleanup EXIT

say "pair-budget preflight"
bash bin/pair.sh list

say "fresh actor-owned pair via pair.sh with exact cached artifacts"
PAIR_OWNED=1
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" "${PAIR_UP_FLAGS[@]}"

say "seed a minimal real site + site.wprism.json (deliberately small: the deterministic pause creates the race window, not fixture size)"
BASE_PAGE_ID=$(wp1 post create --post_type=page --post_status=publish --post_title='Concurrency Base' --porcelain)
cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
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

say "PART 1 — launch capture A (paused mid-lock via WPRISM_TEST_MODE) in the background against /siterepo"
wp1_test wprism capture --repo=/siterepo --format=json >"$LOG_A" 2>&1 &
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
wp1 wprism capture --repo=/siterepo --format=json >"$LOG_B_JSON_OUT" 2>"$LOG_B_JSON_ERR" &
PID_B_JSON=$!
wp1 wprism capture --repo=/siterepo >"$LOG_B_HUMAN_OUT" 2>"$LOG_B_HUMAN_ERR" &
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
require_wprism_answered "capture B JSON refusal" json "$CAPTURE_B_JSON"
jq -e -s '
  length == 1 and .[0] == {
    "format": "wprism-command-refusal/v1",
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
  && [[ "$CAPTURE_B_JSON" != *"issue #3213"* ]] \
  || fail "capture B's machine contract leaked operator-only path or lock evidence: $CAPTURE_B_JSON"
! grep -Eqi 'wprism-command-refusal/v1|capture_lock_held|(^|[[:space:]])wprism:|capture refused|another (capture|publisher)|already publishing|lock held:|issue #3213|/siterepo/state' \
    <<<"$CAPTURE_B_JSON_ERR" \
  || fail "capture B's machine invocation leaked operator or refusal evidence on stderr: $CAPTURE_B_JSON_ERR"
assert_compose_framing_only "$LOG_B_JSON_ERR" "capture B's machine invocation"
pass "capture B refused immediately through the typed, path-redacted machine contract"

say "PART 1 — human mode retains operator-only lock and destination evidence"
echo "$CAPTURE_B_HUMAN"
[ "$CAPTURE_B_HUMAN_RC" -eq 1 ] || fail "human-mode capture B returned $CAPTURE_B_HUMAN_RC instead of refusal exit 1"
require_wprism_answered "capture B human refusal" human "$CAPTURE_B_HUMAN"
grep -qi "already publishing" <<<"$CAPTURE_B_HUMAN" \
  || fail "human-mode capture B did not name the held capture lock: $CAPTURE_B_HUMAN"
grep -q "lock held: /siterepo/state.capture.lock" <<<"$CAPTURE_B_HUMAN" \
  || fail "human-mode capture B did not name the exact contended destination lock: $CAPTURE_B_HUMAN"
! grep -q 'wprism-command-refusal/v1' <<<"$CAPTURE_B_HUMAN" \
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
BASE_PAGE_UUID=$(wp1 post meta get "$BASE_PAGE_ID" _wprism_uuid)
[ -n "$BASE_PAGE_UUID" ] || fail "capture A never minted an identity for the seeded page"
[ -f "siterepo/${PAIR}1/state/posts/page/${BASE_PAGE_UUID}--concurrency-base.md" ] \
  || fail "capture A's own content is missing from the published tree"
pass "published tree is exactly capture A's own content, no contention artifacts left behind"

say "PART 1 — a completely ordinary follow-up capture proves the environment is not left degraded by the contention"
wp1 post create --post_type=page --post_status=publish --post_title='After Contention' >/dev/null
FOLLOWUP_OUT=$(wp1 wprism capture --repo=/siterepo --format=json | tail -1)
jq . <<<"$FOLLOWUP_OUT"
jq -e '.counts.post >= 2' <<<"$FOLLOWUP_OUT" >/dev/null \
  || fail "follow-up capture after contention did not run normally: $FOLLOWUP_OUT"
pass "PART 1 complete: capture lock serializes two real concurrent processes with zero interleaving, zero leaked artifacts, environment healthy afterward"

# Build a second real source environment from the first capture. Its target
# gets the same stable identities through the product apply/adoption path;
# subsequent changes and deletion intents are then produced by ordinary
# WordPress mutation + capture, never hand-authored state bytes.
say "prepare side 2 as an independently mutable exact source environment"
share_repo cli1
sync_state "$HOST_REPO1" "$HOST_REPO2"
SIDE2_BASELINE=$(wp2 wprism apply --repo=/siterepo --default-author=admin \
  --adopt-by-slug=posts,terms,menus --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "side 2 baseline materialization" json "$SIDE2_BASELINE"
jq -e '
  .canary == "clean" and .applied > 0 and
  ([.warnings[]? | select(test("ADOPTED|adopt"; "i") | not)] | length) == 0
' <<<"$SIDE2_BASELINE" >/dev/null \
  || fail "side 2 baseline did not materialize cleanly with only explicit adoption evidence: $SIDE2_BASELINE"
SIDE2_BASE_PAGE_ID=$(wp2 eval "echo (int) \\WPrism\\Ledger::id_for('$BASE_PAGE_UUID', \\WPrism\\Ledger::KIND_POST);")
[ "$SIDE2_BASE_PAGE_ID" -gt 0 ] || fail "side 2 did not materialize the base page's stable identity"
wp2 post update "$SIDE2_BASE_PAGE_ID" --post_title='Capture Apply Desired' >/dev/null
wp2 eval '
global $wp_rewrite;
$wp_rewrite->set_permalink_structure("/concurrent/%postname%/");
$wp_rewrite->flush_rules(false);
' >/dev/null
wp2 wprism capture --repo=/siterepo --format=json >/dev/null
share_repo cli2
grep -Fq '"title": "Capture Apply Desired"' \
  "$HOST_REPO2/state/posts/page/${BASE_PAGE_UUID}--concurrency-base.md" \
  || fail "side 2 capture did not publish the desired page title"
jq -e '.records.permalink_structure.value == "/concurrent/%postname%/"' \
  "$HOST_REPO2/state/options/core.json" >/dev/null \
  || fail "side 2 capture did not publish the desired permalink grammar"
pass "side 2 owns a naturally captured content + rewrite change for the cross-race"

# ============================================================================
# PART 2 — target serialization under DIFFERENT capture destinations
# ============================================================================

share_repo cli1
sync_state "$HOST_REPO2" "$HOST_REPO1"
say "PART 2 — launch a paused capture into /siterepo with a different desired revision present"
wp1_test wprism capture --repo=/siterepo --format=json >"$LOG_C" 2>&1 &
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
pass "capture C holds both /siterepo's publication lock and the target-wide writer fence"

say "PART 2 — a DIFFERENT output destination still shares target ledger/identity state and must refuse"
set +e
wp1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-concurrency-other --format=json \
  >"$LOG_D_OUT" 2>"$LOG_D_ERR"
CAPTURE_D_RC=$?
set -e
CAPTURE_D_JSON=$(cat "$LOG_D_OUT")
printf '%s\n' "$CAPTURE_D_JSON"
[ "$CAPTURE_D_RC" -eq 1 ] || fail "different-destination capture returned $CAPTURE_D_RC instead of refusal exit 1"
require_wprism_answered "different-destination target-writer refusal" json "$CAPTURE_D_JSON"
jq -e -s '
  length == 1 and .[0] == {
    "format": "wprism-command-refusal/v1",
    "ok": false,
    "command": "capture",
    "error": "capture_target_writer_active",
    "reason_code": "capture_target_writer_active",
    "message": "capture refused because another WPrism target writer or promotion session is active",
    "remediation": "wait for the active writer to finish; if none is running, inspect and recover or abort the retained promotion session before retrying capture"
  }
' "$LOG_D_OUT" >/dev/null \
  || fail "different-destination capture did not return the exact target-writer contract: $CAPTURE_D_JSON"
assert_compose_framing_only "$LOG_D_ERR" "different-destination machine refusal"
for artifact in \
  "$HOST_REPO1/.tmp-concurrency-other" \
  "$HOST_REPO1/.tmp-concurrency-other.capture-staging" \
  "$HOST_REPO1/.tmp-concurrency-other.capture-backup"; do
  { [ ! -e "$artifact" ] && [ ! -L "$artifact" ]; } \
    || fail "different-destination refusal leaked publication artifact $artifact"
done
[ -f "$HOST_REPO1/.tmp-concurrency-other.capture.lock" ] \
  && [ ! -L "$HOST_REPO1/.tmp-concurrency-other.capture.lock" ] \
  || fail "different-destination refusal did not retain exactly the documented regular lock file"
read_capture_phase \
  || fail "could not prove capture C still held its fence after the different-destination refusal: $(tail -3 "$PHASE_ERR")"
[ "$PHASE" = "locked" ] \
  || fail "capture C stopped holding before the different-destination refusal completed (phase: '$PHASE')"
pass "different filesystem destinations cannot race the shared target ledger or embedded identities"

say "PART 2 — apply must also refuse while capture owns the common target-writer fence"
set +e
wp1 wprism apply --repo=/siterepo --default-author=admin >"$LOG_CAPTURE_HELD_APPLY" 2>&1
CAPTURE_HELD_APPLY_RC=$?
set -e
[ "$CAPTURE_HELD_APPLY_RC" -ne 0 ] || fail "apply entered while capture held the target-writer fence"
grep -Fq 'promotion lock held by another live target process; concurrent target mutation refused' \
  "$LOG_CAPTURE_HELD_APPLY" \
  || fail "capture-held apply refusal did not name live target-process contention: $(tail -20 "$LOG_CAPTURE_HELD_APPLY")"
[ "$(wp1 post get "$BASE_PAGE_ID" --field=post_title)" = 'Concurrency Base' ] \
  || fail "capture-held apply changed the target page"
[ "$(wp1 option get permalink_structure)" != '/concurrent/%postname%/' ] \
  || fail "capture-held apply changed the target permalink grammar"
read_capture_phase \
  || fail "could not prove capture C remained held after the apply refusal: $(tail -3 "$PHASE_ERR")"
[ "$PHASE" = "locked" ] || fail "apply contender outlived capture C's deterministic hold"
pass "capture-held apply refused before authored or derived target mutation"

release_capture || fail "could not release capture C after proving target-wide contention"
wait "$PID_C" || { tail -60 "$LOG_C"; fail "capture C itself failed (see log above)"; }
PID_C=""
[ "$(wp1 eval 'echo null === \WPrism\Ledger::kv_get("promotion_lock") ? "none" : "held";')" = none ] \
  || fail "capture/apply contention left a durable promotion lock"

say "PART 2 — retry against the previously refused different destination succeeds after release"
wp1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-concurrency-other --format=json >/dev/null
[ -d "$HOST_REPO1/.tmp-concurrency-other" ] \
  || fail "different-destination capture did not succeed after the target fence released"
rm -rf -- "$HOST_REPO1/.tmp-concurrency-other"
pass "PART 2 complete: cross-destination refusal is clean and retryable"

# ============================================================================
# PART 3 — apply-held capture refusal + one convergent native rebuild
# ============================================================================

share_repo cli1
sync_state "$HOST_REPO2" "$HOST_REPO1"
say "PART 3 — pause apply after it owns the promotion row + process fence"
wp1_apply_test wprism apply --repo=/siterepo --default-author=admin --format=json \
  >"$LOG_APPLY_HOLDER" 2>&1 &
PID_APPLY=$!
PHASE=""
for _ in $(seq 1 80); do
  PHASE=$(wp1 eval 'echo (string) ((\WPrism\PromotionLock::current()["phase"] ?? ""));' 2>/dev/null || true)
  [ "$PHASE" = precondition-recheck ] && break
  sleep 0.1
done
[ "$PHASE" = precondition-recheck ] || fail "apply never reached its deterministic locked precondition phase"
[ "$(wp1 post get "$BASE_PAGE_ID" --field=post_title)" = 'Concurrency Base' ] \
  || fail "paused apply mutated the page before its locked recheck"

set +e
wp1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-apply-held-capture --format=json \
  >"$LOG_APPLY_HELD_CAPTURE_OUT" 2>"$LOG_APPLY_HELD_CAPTURE_ERR"
APPLY_HELD_CAPTURE_RC=$?
set -e
APPLY_HELD_CAPTURE_JSON=$(cat "$LOG_APPLY_HELD_CAPTURE_OUT")
[ "$APPLY_HELD_CAPTURE_RC" -eq 1 ] \
  || fail "capture entered while apply held the target writer boundary (rc=$APPLY_HELD_CAPTURE_RC)"
require_wprism_answered "apply-held capture refusal" json "$APPLY_HELD_CAPTURE_JSON"
jq -e '
  .error == "capture_target_writer_active" and
  .reason_code == "capture_target_writer_active" and
  .message == "capture refused because another WPrism target writer or promotion session is active"
' <<<"$APPLY_HELD_CAPTURE_JSON" >/dev/null \
  || fail "apply-held capture did not return the typed target-writer refusal: $APPLY_HELD_CAPTURE_JSON"
assert_compose_framing_only "$LOG_APPLY_HELD_CAPTURE_ERR" "apply-held capture machine refusal"
for artifact in \
  "$HOST_REPO1/.tmp-apply-held-capture" \
  "$HOST_REPO1/.tmp-apply-held-capture.capture-staging" \
  "$HOST_REPO1/.tmp-apply-held-capture.capture-backup"; do
  { [ ! -e "$artifact" ] && [ ! -L "$artifact" ]; } \
    || fail "apply-held capture leaked publication artifact $artifact"
done
[ -f "$HOST_REPO1/.tmp-apply-held-capture.capture.lock" ] \
  && [ ! -L "$HOST_REPO1/.tmp-apply-held-capture.capture.lock" ] \
  || fail "apply-held capture did not retain exactly the documented regular lock file"
pass "apply-held capture refused without candidate publication or target mutation"

wait "$PID_APPLY" || { tail -80 "$LOG_APPLY_HOLDER"; fail "the target-writer apply holder failed"; }
PID_APPLY=""
APPLY_JSON=$(awk 'NF { line=$0 } END { print line }' "$LOG_APPLY_HOLDER")
require_wprism_answered "winning concurrent core apply" json "$APPLY_JSON"
jq -e '
  .canary == "clean" and .applied > 0 and
  ([.actions[]? | select(
    .source == "native:rewrite.flush" and .verified == true and
    .after.rules_hash == .after.runtime_rules_hash
  )] | length) == 1
' <<<"$APPLY_JSON" >/dev/null \
  || fail "winning apply did not perform exactly one verified rewrite rebuild: $APPLY_JSON"
[ "$(wp1 post get "$BASE_PAGE_ID" --field=post_title)" = 'Capture Apply Desired' ] \
  || fail "winning apply did not materialize the desired page title"
[ "$(wp1 option get permalink_structure)" = '/concurrent/%postname%/' ] \
  || fail "winning apply did not materialize the desired permalink grammar"
RULE_PARITY=$(wp1 eval '
global $wp_rewrite;
$stored = get_option("rewrite_rules");
$wp_rewrite->matches = "matches";
$generated = $wp_rewrite->rewrite_rules();
echo wp_json_encode([
  "stored_nonempty" => is_array($stored) && $stored !== [],
  "generated_nonempty" => is_array($generated) && $generated !== [],
  "rules_match" => is_array($stored) && $stored === $generated,
]);
')
jq -e '.stored_nonempty and .generated_nonempty and .rules_match' <<<"$RULE_PARITY" >/dev/null \
  || fail "fresh-process generated/stored rewrite rules diverged after contention: $RULE_PARITY"
[ "$(wp1 eval 'echo null === \WPrism\Ledger::kv_get("promotion_lock") ? "none" : "held";')" = none ] \
  || fail "successful apply retained its promotion lock"
[ "$(wp1 eval 'echo null === \WPrism\Ledger::kv_get("apply_in_progress") ? "none" : "held";')" = none ] \
  || fail "successful apply retained incomplete-apply recovery state"

APPLY_RETRY=$(wp1 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "concurrent core apply idempotent retry" json "$APPLY_RETRY"
jq -e '.canary == "clean" and .applied == 0 and .actions == []' <<<"$APPLY_RETRY" >/dev/null \
  || fail "retry after the winning apply was not a zero-action no-op: $APPLY_RETRY"

wp1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-post-apply-capture --format=json >/dev/null
POST_APPLY_DIGEST=$(wp1 eval 'echo \WPrism\Publish::tree_digest("/siterepo/.tmp-post-apply-capture");')
grep -Fq '"title": "Capture Apply Desired"' \
  "$HOST_REPO1/.tmp-post-apply-capture/posts/page/${BASE_PAGE_UUID}--concurrency-base.md" \
  || fail "post-apply capture did not observe the desired page"
wp1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-post-apply-capture --format=json >/dev/null
POST_APPLY_RETRY_DIGEST=$(wp1 eval 'echo \WPrism\Publish::tree_digest("/siterepo/.tmp-post-apply-capture");')
[ "$POST_APPLY_DIGEST" = "$POST_APPLY_RETRY_DIGEST" ] \
  || fail "repeated post-apply capture changed identical canonical output"
rm -rf -- "$HOST_REPO1/.tmp-post-apply-capture"
pass "PART 3 complete: bidirectional capture/apply contention converges once and retries idempotently"

# ============================================================================
# PART 4 — concurrent deletion executes exactly once
# ============================================================================

say "PART 4 — produce a real deletion intent on side 2, leaving side 1's mapped page live"
wp2 post delete "$SIDE2_BASE_PAGE_ID" --force >/dev/null
wp2 wprism capture --repo=/siterepo --format=json >/dev/null
share_repo cli2
[ -f "$HOST_REPO2/state/deletions/${BASE_PAGE_UUID}.json" ] \
  || fail "side 2 did not capture the mapped page deletion intent"
share_repo cli1
sync_state "$HOST_REPO2" "$HOST_REPO1"
[ "$(wp1 post get "$BASE_PAGE_ID" --field=ID)" = "$BASE_PAGE_ID" ] \
  || fail "side 1 deletion target disappeared before apply"

wp1_apply_test wprism apply --repo=/siterepo --default-author=admin --with-deletes --format=json \
  >"$LOG_DELETE_HOLDER" 2>&1 &
PID_DELETE=$!
PHASE=""
for _ in $(seq 1 80); do
  PHASE=$(wp1 eval 'echo (string) ((\WPrism\PromotionLock::current()["phase"] ?? ""));' 2>/dev/null || true)
  [ "$PHASE" = precondition-recheck ] && break
  sleep 0.1
done
[ "$PHASE" = precondition-recheck ] || fail "delete holder never reached its deterministic locked precondition phase"

set +e
wp1 wprism apply --repo=/siterepo --default-author=admin --with-deletes \
  >"$LOG_DELETE_CONTENDER" 2>&1
DELETE_CONTENDER_RC=$?
set -e
[ "$DELETE_CONTENDER_RC" -ne 0 ] || fail "second delete apply entered the first delete holder's lease"
grep -Fq 'promotion lock held by another live target process; concurrent target mutation refused' \
  "$LOG_DELETE_CONTENDER" \
  || fail "second delete contender did not refuse at the live process fence: $(tail -20 "$LOG_DELETE_CONTENDER")"
[ "$(wp1 post get "$BASE_PAGE_ID" --field=ID)" = "$BASE_PAGE_ID" ] \
  || fail "refused delete contender changed the target before the holder resumed"

wait "$PID_DELETE" || { tail -80 "$LOG_DELETE_HOLDER"; fail "winning delete apply failed"; }
PID_DELETE=""
DELETE_JSON=$(awk 'NF { line=$0 } END { print line }' "$LOG_DELETE_HOLDER")
require_wprism_answered "winning concurrent core delete" json "$DELETE_JSON"
jq -e '.canary == "clean" and .applied > 0' <<<"$DELETE_JSON" >/dev/null \
  || fail "winning delete did not report a clean applied mutation: $DELETE_JSON"
set +e
wp1 post get "$BASE_PAGE_ID" --field=ID >/dev/null 2>&1
DELETED_PAGE_RC=$?
set -e
[ "$DELETED_PAGE_RC" -ne 0 ] || fail "winning delete left the page live"
DELETE_UUID_ROWS=$(wp1 db query \
  "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key='_wprism_uuid' AND meta_value='$BASE_PAGE_UUID'" \
  --skip-column-names | tr -d '[:space:]')
[ "$DELETE_UUID_ROWS" = 0 ] || fail "winning delete left $DELETE_UUID_ROWS embedded identity rows"
[ -z "$(wp1 eval "echo (string) \\WPrism\\Ledger::id_for('$BASE_PAGE_UUID', \\WPrism\\Ledger::KIND_POST);")" ] \
  || fail "winning delete retained the page's ledger identity"
[ "$(wp1 eval 'echo null === \WPrism\Ledger::kv_get("promotion_lock") ? "none" : "held";')" = none ] \
  || fail "winning delete retained its promotion lock"
[ "$(wp1 eval 'echo null === \WPrism\Ledger::kv_get("apply_in_progress") ? "none" : "held";')" = none ] \
  || fail "winning delete retained incomplete-apply recovery state"

DELETE_RETRY=$(wp1 wprism apply --repo=/siterepo --default-author=admin --with-deletes --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "concurrent core delete idempotent retry" json "$DELETE_RETRY"
jq -e '.canary == "clean" and .applied == 0 and .actions == []' <<<"$DELETE_RETRY" >/dev/null \
  || fail "delete retry was not a zero-action no-op: $DELETE_RETRY"
pass "PART 4 complete: competing delete applies execute once and retry without residue"

assert_exact_source
printf '\n\033[1;32m✔ REGRESS CAPTURE CONCURRENCY PASSED (same/different capture destinations, capture/apply cross-races, verified rewrite rebuild, delete-once idempotence)\033[0m\n'
GREEN=1
