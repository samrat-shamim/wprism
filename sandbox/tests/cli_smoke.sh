#!/usr/bin/env bash
# CLI smoke test — exercises the standalone `duo` orchestrator (cli/duo)
# against the EXISTING spike-E env pair (e1 :8804 / e2 :8805, compose
# profile "spikee") and its site repo at sandbox/siterepo/{e1,e2}. Never
# touches envs a/b/c or their site repos, never brings any environment up
# or down (assumes the spikee pair is already running — `make spike-e` or
# an equivalent `docker compose --profile spikee up` boots it).
#
# Flow: write a machine-local .duo-envs.json registry (repo root) for
# e1/e2 as docker transports -> `duo envs` -> `duo doctor` both green ->
# `duo status e2` clean -> edit e1's content -> `duo capture e1` -> commit
# + push on the host, pull into e2's checkout -> `duo status e2` shows the
# drift as exactly one update -> `duo apply e2` -> `duo status e2` clean
# again. Every step asserts its exit code, not just its output.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$REPO_ROOT"

DUO="$REPO_ROOT/cli/duo"
COMPOSE="docker compose -f sandbox/docker-compose.yml --profile spikee"
ENVS_FILE="$REPO_ROOT/.duo-envs.json"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

# assert_exit <expected-code> <description> -- <command...>
# Runs the command with output captured (and echoed) in $OUT / exit code in
# $CODE, without letting `set -e` abort the script on a non-matching code —
# so a failing assertion prints a clear fail() line instead of a raw trap.
assert_exit() {
  local expected="$1" desc="$2"; shift 2
  if [ "$1" = "--" ]; then shift; fi
  if OUT="$("$@" 2>&1)"; then CODE=0; else CODE=$?; fi
  echo "$OUT"
  [ "$CODE" -eq "$expected" ] || fail "$desc: expected exit $expected, got $CODE"
  pass "$desc (exit $CODE)"
}

cleanup() { rm -f "$ENVS_FILE"; }
trap cleanup EXIT

say "write .duo-envs.json (repo root, docker transports, compose_file relative to repo root)"
cat > "$ENVS_FILE" <<'EOF'
{
  "envs": {
    "e1": {"transport": "docker", "compose_file": "sandbox/docker-compose.yml", "profile": "spikee", "service": "cli-e1", "repo_path": "/siterepo"},
    "e2": {"transport": "docker", "compose_file": "sandbox/docker-compose.yml", "profile": "spikee", "service": "cli-e2", "repo_path": "/siterepo"}
  }
}
EOF
pass ".duo-envs.json written at $ENVS_FILE"

say "duo envs"
assert_exit 0 "duo envs" -- "$DUO" envs
grep -q '^e1 .*docker' <<<"$OUT" || fail "duo envs: no docker e1 row"
grep -q '^e2 .*docker' <<<"$OUT" || fail "duo envs: no docker e2 row"
pass "duo envs lists both e1 and e2 as docker transports"

say "duo doctor e1 / e2 (retrying briefly — concurrent docker load from other agents' envs is expected)"
for env in e1 e2; do
  ok=0
  for _ in $(seq 1 30); do
    if OUT="$("$DUO" doctor "$env" 2>&1)"; then CODE=0; else CODE=$?; fi
    if [ "$CODE" -eq 0 ]; then ok=1; break; fi
    sleep 2
  done
  echo "$OUT"
  [ "$ok" -eq 1 ] || fail "duo doctor $env never went green (exit $CODE)"
  [ "$(echo "$OUT" | grep -c '\[PASS\]')" -eq 4 ] || fail "duo doctor $env: expected 4 [PASS] lines"
  grep -q '\[FAIL\]' <<<"$OUT" && fail "duo doctor $env: unexpected [FAIL]"
  pass "duo doctor $env: 4/4 checks green"
done

say "duo status e2 (expect clean — e1/e2 start in sync from spike E)"
assert_exit 0 "duo status e2 (before edit)" -- "$DUO" status e2
grep -q ', 0 conflict, 0 collision,' <<<"$OUT" || fail "duo status e2: expected 0 conflict, 0 collision before edit"
pass "duo status e2 is clean before the edit"

say "edit e1's content directly (via the cli-e1 service)"
POST_ID=$($COMPOSE run --rm -T cli-e1 wp post list --post_type=post --name=duo-acf-content --field=ID | tr -d '\r')
[ -n "$POST_ID" ] || fail "could not find e1's duo-acf-content post"
NEW_TITLE="CLI smoke $(date +%s)"
$COMPOSE run --rm -T cli-e1 wp post update "$POST_ID" --post_title="$NEW_TITLE" >/dev/null
pass "e1 post #$POST_ID title set to '$NEW_TITLE'"

say "duo capture e1"
assert_exit 0 "duo capture e1" -- "$DUO" capture e1
grep -qi 'captured' <<<"$OUT" || fail "duo capture e1: no 'captured' summary line"
pass "duo capture e1 succeeded"

say "host-git: commit + push on e1's checkout, pull into e2's checkout"
git -C sandbox/siterepo/e1 add -A
git -C sandbox/siterepo/e1 -c user.name=duo -c user.email=duo@example.test commit -qm "capture: cli smoke title edit ($NEW_TITLE)"
git -C sandbox/siterepo/e1 push -q origin main
git -C sandbox/siterepo/e2 checkout -q main
git -C sandbox/siterepo/e2 pull -q origin main
pass "e1 committed + pushed, e2 pulled"

say "duo status e2 (expect exactly 1 update)"
assert_exit 0 "duo status e2 (after pull, before apply)" -- "$DUO" status e2
grep -q ', 1 update,' <<<"$OUT" || fail "duo status e2: expected '1 update' in the plan summary"
grep -q ', 0 conflict, 0 collision,' <<<"$OUT" || fail "duo status e2: expected no conflicts/collisions"
pass "duo status e2 shows exactly 1 update"

say "cross-check via the raw passthrough + --format=json (forwarded flag, not a duo-native flag)"
assert_exit 0 "duo plan e2 --format=json" -- "$DUO" plan e2 --format=json
UPDATE_COUNT=$(echo "$OUT" | tail -1 | jq '.update | length')
[ "$UPDATE_COUNT" = "1" ] || fail "duo plan e2 --format=json: expected .update to have length 1, got $UPDATE_COUNT"
pass "duo plan e2 --format=json independently confirms 1 update"

say "duo apply e2 --default-author=admin"
assert_exit 0 "duo apply e2 --default-author=admin" -- "$DUO" apply e2 --default-author=admin
grep -qi 'applied' <<<"$OUT" || fail "duo apply e2: no 'applied' summary line"
pass "duo apply e2 succeeded"

say "duo status e2 (expect clean again)"
assert_exit 0 "duo status e2 (after apply)" -- "$DUO" status e2
grep -q ', 0 update,' <<<"$OUT" || fail "duo status e2: expected 0 update after apply"
grep -q ', 0 conflict, 0 collision,' <<<"$OUT" || fail "duo status e2: expected no conflicts/collisions after apply"
pass "duo status e2 is clean again after apply"

say "sanity: e2's applied title actually matches"
GOT_TITLE=$($COMPOSE run --rm -T cli-e2 wp post get "$POST_ID" --field=post_title 2>/dev/null | tr -d '\r')
[ "$GOT_TITLE" = "$NEW_TITLE" ] || fail "e2's post #$POST_ID title is '$GOT_TITLE', expected '$NEW_TITLE'"
pass "e2's post title matches e1's edit ($NEW_TITLE)"

printf '\n\033[1;32m✔ CLI SMOKE PASSED\033[0m\n'
