#!/usr/bin/env bash
# CLI triage smoke test — exercises `wprism pending`/`wprism classify` (cli/wprism)
# against the EXISTING spike-E env pair, e1 only (e1 :8804, compose profile
# "spikee", site repo sandbox/siterepo/e1). Companion to cli_smoke.sh, which
# covers capture/plan/apply/status across e1+e2; this one is scoped to the
# review-queue triage surface added on top (task #14) and stays on e1 alone.
# Never touches envs a/b/c/f/fx or their site repos, never brings any
# environment up or down (assumes the spikee pair is already running —
# `make spike-e` or an equivalent `docker compose --profile spikee up`).
#
# Flow: assert e1's site repo starts git-clean -> doctor-check e1 -> assert
# its review queue starts empty -> fabricate an unclassified post-meta
# probe -> `wprism pending e1` shows it (gate evidence only, no proposal --
# journal is off on this pair) -> `wprism classify e1` interactively via a
# scripted stdin pipe, choosing runtime -> assert the policy landed in
# site.wprism.json, `wp wprism capture` succeeds and touches nothing else, and the
# queue is empty again -> `wprism classify e1 --accept-proposals` on the
# now-empty queue exits 0 with the empty-queue message -> full cleanup
# (probe meta removed, site.wprism.json reverted) -- `git -C
# sandbox/siterepo/e1 diff` (and `status`) must be empty at the end. Every
# step asserts its exit code, not just its output.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$REPO_ROOT"

WPRISM="$REPO_ROOT/cli/wprism"
COMPOSE="docker compose -f sandbox/docker-compose.yml --profile spikee"
ENVS_FILE="$REPO_ROOT/.wprism-envs.json"
SITE_JSON="$REPO_ROOT/sandbox/siterepo/e1/site.wprism.json"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

# assert_exit <expected-code> <description> -- <command...>
# Same helper as cli_smoke.sh: captures output+exit without letting `set -e`
# abort on a mismatch, so a failing assertion prints fail() instead of a raw
# trap.
assert_exit() {
  local expected="$1" desc="$2"; shift 2
  if [ "$1" = "--" ]; then shift; fi
  if OUT="$("$@" 2>&1)"; then CODE=0; else CODE=$?; fi
  echo "$OUT"
  [ "$CODE" -eq "$expected" ] || fail "$desc: expected exit $expected, got $CODE"
  pass "$desc (exit $CODE)"
}

# Best-effort cleanup on ANY exit (including a failed assertion) so a broken
# run never leaves the probe meta or a policy edit behind for the next one.
# The real assertions (git diff/status empty, queue empty) still run
# explicitly in the success path below -- this is just the safety net.
cleanup() {
  rm -f "$ENVS_FILE"
  if [ -n "${POST_ID:-}" ]; then
    $COMPOSE run --rm -T cli-e1 wp post meta delete "$POST_ID" wprism_triage_probe >/dev/null 2>&1 || true
  fi
  git -C sandbox/siterepo/e1 checkout -- site.wprism.json >/dev/null 2>&1 || true
}
trap cleanup EXIT

say "precondition: sandbox/siterepo/e1 starts git-clean"
PRECHECK="$(git -C sandbox/siterepo/e1 status --porcelain)"
[ -z "$PRECHECK" ] || fail "sandbox/siterepo/e1 is not clean before this test even starts -- resolve it first:\n$PRECHECK"
pass "sandbox/siterepo/e1 is clean"

say "write .wprism-envs.json (repo root, e1 only -- this pair is triage-smoke's during validation)"
cat > "$ENVS_FILE" <<'EOF'
{
  "envs": {
    "e1": {"transport": "docker", "compose_file": "sandbox/docker-compose.yml", "profile": "spikee", "service": "cli-e1", "repo_path": "/siterepo"}
  }
}
EOF
pass ".wprism-envs.json written at $ENVS_FILE"

say "wprism doctor e1 (retrying briefly -- concurrent docker load from other agents' envs is expected)"
ok=0
for _ in $(seq 1 30); do
  if OUT="$("$WPRISM" doctor e1 2>&1)"; then CODE=0; else CODE=$?; fi
  if [ "$CODE" -eq 0 ]; then ok=1; break; fi
  sleep 2
done
echo "$OUT"
[ "$ok" -eq 1 ] || fail "wprism doctor e1 never went green (exit $CODE)"
pass "wprism doctor e1: all required checks green"

say "baseline: e1's review queue starts empty"
assert_exit 0 "wprism pending e1 (baseline)" -- "$WPRISM" pending e1
grep -qi 'review queue is empty' <<<"$OUT" || fail "wprism pending e1 (baseline): expected an empty queue -- clean up e1's env before running this smoke test"
pass "e1's review queue is empty before the probe"

say "fabricate an unclassified probe (post_meta on the spike-E fixture post)"
POST_ID=$($COMPOSE run --rm -T cli-e1 wp post list --post_type=post --name=wprism-acf-content --field=ID | tr -d '\r')
[ -n "$POST_ID" ] || fail "could not find e1's wprism-acf-content post"
$COMPOSE run --rm -T cli-e1 wp post meta add "$POST_ID" wprism_triage_probe x >/dev/null
pass "post #$POST_ID: wprism_triage_probe meta added"

say "wprism pending e1 --format=json: the probe shows gate evidence, no proposal, no journal"
assert_exit 0 "wprism pending e1 --format=json (probe present)" -- "$WPRISM" pending e1 --format=json
PENDING_JSON=$(echo "$OUT" | tail -1)
echo "$PENDING_JSON" | jq -e '. | length >= 1' >/dev/null || fail "wprism pending e1 --format=json: expected at least one item"
PROBE=$(echo "$PENDING_JSON" | jq -c '.[] | select(.section == "post_meta" and .key == "wprism_triage_probe")')
[ -n "$PROBE" ] || fail "wprism pending e1 --format=json: probe key not in the queue"
echo "$PROBE" | jq -e '.proposal == null' >/dev/null || fail "probe: expected no proposal (journal is off on the e-pair), got: $(echo "$PROBE" | jq -c .proposal)"
echo "$PROBE" | jq -e '(.evidence.entities // 0) >= 1' >/dev/null || fail "probe: expected gate evidence.entities >= 1, got: $(echo "$PROBE" | jq -c .evidence)"
echo "$PROBE" | jq -e '.evidence.journal == null' >/dev/null || fail "probe: expected no journal evidence on this pair, got: $(echo "$PROBE" | jq -c .evidence)"
pass "probe listed with gate evidence only, no proposal, no journal"

say "wprism pending e1 (human table): sanity-check the rendered view too"
assert_exit 0 "wprism pending e1 (table)" -- "$WPRISM" pending e1
grep -q 'post_meta:wprism_triage_probe' <<<"$OUT" || fail "wprism pending e1: probe row missing from the rendered table"
pass "rendered table lists the probe row"

say "wprism classify e1 -- interactive triage, choosing runtime for the probe"
INPUT=$'r\n'
if OUT=$(printf '%s' "$INPUT" | "$WPRISM" classify e1 2>&1); then CODE=0; else CODE=$?; fi
echo "$OUT"
[ "$CODE" -eq 0 ] || fail "wprism classify e1: expected exit 0, got $CODE"
grep -q '1 classified, 0 skipped\.' <<<"$OUT" || fail "wprism classify e1: expected the '1 classified, 0 skipped.' summary line"
pass "wprism classify e1 accepted 'runtime' for the probe (exit $CODE)"

say "the policy entry landed in site.wprism.json"
jq -e '.policy.post_meta.wprism_triage_probe.class == "runtime"' "$SITE_JSON" >/dev/null \
  || fail "site.wprism.json: expected policy.post_meta.wprism_triage_probe.class == \"runtime\", got: $(jq -c '.policy.post_meta.wprism_triage_probe // "missing"' "$SITE_JSON")"
pass 'site.wprism.json: policy.post_meta.wprism_triage_probe.class == "runtime"'

say "wp wprism capture e1 succeeds now that the probe key is classified"
assert_exit 0 "wprism capture e1 (post-classify)" -- "$WPRISM" capture e1
grep -qi 'captured' <<<"$OUT" || fail "wprism capture e1: no 'captured' summary line"
pass "wprism capture e1 succeeded (no more loud-and-blocking abort on the probe key)"

say "wprism pending e1 is empty again"
assert_exit 0 "wprism pending e1 (after classify)" -- "$WPRISM" pending e1
grep -qi 'review queue is empty' <<<"$OUT" || fail "wprism pending e1: expected the queue to be empty after classifying the only item"
pass "e1's review queue is empty again"

say "wprism classify e1 --accept-proposals on an empty queue exits 0 with the empty-queue message"
assert_exit 0 "wprism classify e1 --accept-proposals (empty queue)" -- "$WPRISM" classify e1 --accept-proposals
grep -qi 'review queue is empty' <<<"$OUT" || fail "wprism classify e1 --accept-proposals: expected the empty-queue message"
pass "wprism classify e1 --accept-proposals is a clean no-op on an empty queue"

say "confirm the ONLY tracked change so far is site.wprism.json (capture was a true no-op on state/)"
CHANGED="$(git -C sandbox/siterepo/e1 diff --name-only)"
[ "$CHANGED" = "site.wprism.json" ] || fail "sandbox/siterepo/e1: expected only site.wprism.json to have changed, got:\n$CHANGED"
pass "only site.wprism.json changed -- runtime-classified meta never reaches captured state"

say "full cleanup: remove the probe meta and revert site.wprism.json"
$COMPOSE run --rm -T cli-e1 wp post meta delete "$POST_ID" wprism_triage_probe >/dev/null
git -C sandbox/siterepo/e1 checkout -- site.wprism.json
DIFF="$(git -C sandbox/siterepo/e1 diff)"
[ -z "$DIFF" ] || fail "sandbox/siterepo/e1: expected an empty git diff after cleanup, got:\n$DIFF"
STATUS="$(git -C sandbox/siterepo/e1 status --porcelain)"
[ -z "$STATUS" ] || fail "sandbox/siterepo/e1: expected a fully clean git status after cleanup, got:\n$STATUS"
pass "sandbox/siterepo/e1: git diff and git status are both empty after cleanup"

printf '\n\033[1;32m✔ CLI TRIAGE SMOKE PASSED\033[0m\n'
