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
# WordPress bootstrap, and two genuinely separate wp-cli invocations racing
# for the same destination — this file's job.
#
# Deterministic by construction, not by luck: getting two real processes to
# GENUINELY overlap by chance timing alone is exactly the kind of flaky test
# this project's own discipline refuses to ship. Reuses the SAME idiom
# DUO-3217 established for its own live PromotionLock races
# (sandbox/tests/regress_promotion_lock.sh): a DUO_TEST_MODE-gated,
# env-var-controlled deterministic pause (agent/src/Capture.php's own new
# DUO_TEST_CAPTURE_PAUSE_MS hook, inserted immediately after Publish::lock()
# succeeds) plus a DB-backed (Ledger::kv_set(), cross-process-visible —
# unlike the lock itself, which is a local flock() invisible to another
# process) phase marker a controlling script polls for before launching the
# second, racing capture. No effect on production capture unless a caller
# explicitly opts into both env vars.
#
#   PART 1 — mutual exclusion, SAME destination: two real `wp duo capture`
#     processes race for the same --repo=. The second is refused immediately
#     and cleanly, naming the destination; the first completes normally;
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
cd "$(dirname "$0")/.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

PORT1="${CONCURRENCY_PORT1:-8934}"
PORT2="${CONCURRENCY_PORT2:-8935}"
COMPOSE="docker compose -p duo-concurrency -f pair.yml"
export DUO_PAIR=concurrency
wp1() { $COMPOSE run --rm -T cli1 wp "$@"; }
wp1_test() { $COMPOSE run --rm -T -e DUO_TEST_MODE=1 -e DUO_TEST_CAPTURE_PAUSE_MS="$PAUSE_MS" cli1 wp "$@"; }
PAUSE_MS=4000

say "clean-room via pair.sh (own pair, isolated — headless, single side is enough: this arm tests capture's OWN lock, not cross-environment sync)"
bash bin/pair.sh reset concurrency
bash bin/pair.sh up concurrency "$PORT1" "$PORT2" --headless

say "seed a minimal real site + site.duo.json (deliberately small: the deterministic pause creates the race window, not fixture size)"
BASE_PAGE_ID=$(wp1 post create --post_type=page --post_status=publish --post_title='Concurrency Base' --porcelain)
cat > siterepo/concurrency1/site.duo.json <<'EOF'
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
cp site-repo.gitignore.template siterepo/concurrency1/.gitignore
git -C siterepo/concurrency1 init -q -b main
pass "environment ready"

# ============================================================================
# PART 1 — mutual exclusion under real concurrent processes, SAME destination
# ============================================================================

say "PART 1 — launch capture A (paused mid-lock via DUO_TEST_MODE) in the background against /siterepo"
wp1_test duo capture --repo=/siterepo --format=json >/tmp/duo3223-concurrency-a.log 2>&1 &
PID_A=$!

say "PART 1 — poll for A's cross-process phase marker (Ledger::kv_get) rather than guess a sleep"
PHASE=""
for _ in $(seq 1 80); do
  PHASE="$(wp1 eval 'echo (string) \Duo\Ledger::kv_get("capture_test_phase");' 2>/dev/null || true)"
  [ "$PHASE" = "locked" ] && break
  sleep 0.1
done
[ "$PHASE" = "locked" ] || fail "capture A never reached its deterministic lock-held pause (got phase: '$PHASE')"
pass "capture A holds the lock and is deterministically paused"

say "PART 1 — capture B races for the SAME destination while A still holds the lock"
set +e
CAPTURE_B_ERR=$(wp1 duo capture --repo=/siterepo --format=json 2>&1)
CAPTURE_B_RC=$?
set -e
echo "$CAPTURE_B_ERR"
[ "$CAPTURE_B_RC" -ne 0 ] || fail "capture B succeeded despite A holding the lock — two publishers interleaved"
echo "$CAPTURE_B_ERR" | grep -qi "already publishing" || fail "capture B's refusal did not name the held capture lock: $CAPTURE_B_ERR"
echo "$CAPTURE_B_ERR" | grep -q "/siterepo/state" || fail "capture B's refusal did not name the contended destination: $CAPTURE_B_ERR"
pass "capture B refused immediately and named both the lock and the destination"

say "PART 1 — capture A completes normally once its pause ends"
wait "$PID_A" || { tail -60 /tmp/duo3223-concurrency-a.log; fail "capture A itself failed (see log above)"; }
tail -3 /tmp/duo3223-concurrency-a.log
pass "capture A (the true lock holder) completed successfully"

say "PART 1 — acceptance: published tree is exactly what A alone produced; no leftover staging/backup/lock artifacts from B's refused attempt"
[ -d siterepo/concurrency1/state ] || fail "state/ missing after a successful capture"
[ ! -d siterepo/concurrency1/state.capture-staging ] || fail "a leftover staging dir survived a successful cycle (B's refused attempt should never have created one — it never got past the lock)"
[ ! -d siterepo/concurrency1/state.capture-backup ] || fail "a leftover backup dir survived a successful cycle"
BASE_PAGE_UUID=$(wp1 post meta get "$BASE_PAGE_ID" _duo_uuid)
[ -n "$BASE_PAGE_UUID" ] || fail "capture A never minted an identity for the seeded page"
[ -f "siterepo/concurrency1/state/posts/page/${BASE_PAGE_UUID}--concurrency-base.md" ] \
  || fail "capture A's own content is missing from the published tree"
pass "published tree is exactly capture A's own content, no contention artifacts left behind"

say "PART 1 — a completely ordinary follow-up capture proves the environment is not left degraded by the contention"
wp1 post create --post_type=page --post_status=publish --post_title='After Contention' >/dev/null
FOLLOWUP_OUT=$(wp1 duo capture --repo=/siterepo --format=json | tail -1)
echo "$FOLLOWUP_OUT" | jq .
echo "$FOLLOWUP_OUT" | jq -e '.counts.post >= 2' >/dev/null || fail "follow-up capture after contention did not run normally: $FOLLOWUP_OUT"
pass "PART 1 complete: capture lock serializes two real concurrent processes with zero interleaving, zero leaked artifacts, environment healthy afterward"

# ============================================================================
# PART 2 — independence under real concurrency, DIFFERENT destinations
# ============================================================================

say "PART 2 — launch a paused capture into /siterepo (the SAME, now-established destination) in the background"
wp1_test duo capture --repo=/siterepo --format=json >/tmp/duo3223-concurrency-c.log 2>&1 &
PID_C=$!
PHASE=""
for _ in $(seq 1 80); do
  PHASE="$(wp1 eval 'echo (string) \Duo\Ledger::kv_get("capture_test_phase");' 2>/dev/null || true)"
  [ "$PHASE" = "locked" ] && break
  sleep 0.1
done
[ "$PHASE" = "locked" ] || fail "capture C never reached its deterministic lock-held pause (got phase: '$PHASE')"
pass "capture C holds /siterepo's lock and is deterministically paused"

say "PART 2 — a capture into a DIFFERENT destination (--out=) on the SAME environment must proceed unaffected"
wp1 duo capture --repo=/siterepo --out=/siterepo/.tmp-concurrency-other --format=json >/tmp/duo3223-concurrency-d.log 2>&1
tail -3 /tmp/duo3223-concurrency-d.log
[ -d siterepo/concurrency1/.tmp-concurrency-other ] || fail "the different-destination capture did not produce output while the other destination's lock was held"
pass "a different destination is completely unaffected by /siterepo's own held lock"

wait "$PID_C" || { tail -60 /tmp/duo3223-concurrency-c.log; fail "capture C itself failed (see log above)"; }
rm -rf siterepo/concurrency1/.tmp-concurrency-other
pass "PART 2 complete: the capture lock is scoped per-destination under real concurrency, not global — proven live, not just at the offline Publish.php-primitive level"

printf '\n\033[1;32m✔ REGRESS CAPTURE CONCURRENCY PASSED (real concurrent wp duo capture processes: same-destination mutual exclusion, cross-destination independence)\033[0m\n'

say "cleanup: destroy the concurrency pair (green run — 'destroy-when-green' convention; unreached on any earlier failure)"
bash bin/pair.sh destroy concurrency
pass "concurrency pair destroyed"
