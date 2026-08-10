#!/usr/bin/env bash
# CLI status-truth test (DUO-3221) — proves `duo status`'s exit code is
# fail-closed. Before this fix, cli/src/PlanSummary.php's BUCKETS omitted
# code_mismatch entirely and render() never read $plan['code_mismatch'] or
# $plan['warnings']; `ok` was computed as `conflict===0 && collision===0`
# only. That meant `duo status <env>` exited 0 while: a canonically-active
# plugin was missing from the environment or outside its version_range
# (code_mismatch), the environment had drifted since the last capture/apply
# (capture-first), or a delete was blocked by a referential guard — every
# one of those is a condition `wp duo apply` itself either refuses on
# outright, or (drift) a condition that means this plan's own comparison is
# already stale. The agent-side human output (agent/src/Cli.php's plan())
# already rendered code_mismatch/drift correctly; only the orchestrator's
# own summary — and therefore its exit code — lied by omission.
#
# Proves, against a live throwaway environment: (a) a clean env -> exit 0;
# (b) an injected code_mismatch (a plugin declared active in state/options/
# core.json that doesn't exist in this environment's wp-content/plugins/)
# -> exit non-zero, naming the missing plugin; (c) drift (a post edited
# directly on the environment, bypassing the repo) -> exit non-zero with
# the "capture-first" hint, mirroring agent/src/Cli.php's own wording; (d)
# malformed JSON from the agent still fails `duo status` (regression check
# — this path was already correct and must stay that way), tested in
# complete isolation via a fake `wp` binary, no docker/live env needed.
#
# Owns a dedicated, throwaway sandbox/bin/pair.sh pair (task #74's
# redesign), brought up and destroyed entirely within this script — unlike
# the long-lived e1/e2 (cli_smoke.sh) or r3a/r3b/r3e (grind_*.sh) fixtures,
# this test's whole point is a single environment's `duo status`
# truthfulness, so there is no cross-environment state worth keeping
# around afterward (the "destroy-when-green" convention, docs/sandbox.md).
# --headless: nothing below ever curls the site, only wp-cli through the
# pair's own cli1 container, so publishing a host port would just be one
# more thing to collide with a concurrent session over.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$REPO_ROOT"

DUO="$REPO_ROOT/cli/duo"
PAIR=w1b
PORT1=8852
PORT2=8853
ENVS_FILE="$REPO_ROOT/.duo-envs.json"
SITEREPO="$REPO_ROOT/sandbox/siterepo/${PAIR}1"
PAIR_COMPOSE=(docker compose -p "duo-${PAIR}" -f sandbox/pair.yml)

# Exported (not just prefixed onto one command): sandbox/pair.yml resolves
# ${DUO_PAIR} at compose-invocation time for its own project name/db name/
# bind path, and every `duo doctor|capture|status` call below shells out to
# `docker compose` as a NEW subprocess (Transport::runCapturing()'s
# proc_open) that only sees DUO_PAIR if it's in THIS script's own exported
# environment -- pair.sh's internal `export DUO_PAIR=...` (cmd_up) lives and
# dies inside that one child process and never propagates back here.
export DUO_PAIR="$PAIR"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

# assert_exit <expected-code> <description> -- <command...>
# Same helper as cli_smoke.sh/cli_triage_smoke.sh: captures output+exit
# without letting `set -e` abort on a mismatch, so a failing assertion
# prints fail() instead of a raw trap.
assert_exit() {
  local expected="$1" desc="$2"; shift 2
  if [ "$1" = "--" ]; then shift; fi
  if OUT="$("$@" 2>&1)"; then CODE=0; else CODE=$?; fi
  echo "$OUT"
  [ "$CODE" -eq "$expected" ] || fail "$desc: expected exit $expected, got $CODE"
  pass "$desc (exit $CODE)"
}

# Best-effort cleanup on ANY exit, including a failed assertion — a broken
# run must never leave the throwaway pair, its site-repo directories, or
# the machine-local envs overlay behind for the next one.
cleanup() {
  rm -f "$ENVS_FILE"
  bash sandbox/bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  rm -rf "sandbox/siterepo/${PAIR}1" "sandbox/siterepo/${PAIR}2"
}
trap cleanup EXIT

say "boot throwaway pair '$PAIR' (headless -- no HTTP surface is exercised anywhere below)"
bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless
pass "pair '$PAIR' up"

say "write .duo-envs.json (repo root, docker transport -> sandbox/pair.yml's cli1 service)"
cat > "$ENVS_FILE" <<EOF
{
  "envs": {
    "${PAIR}1": {"transport": "docker", "compose_file": "sandbox/pair.yml", "service": "cli1", "repo_path": "/siterepo"}
  }
}
EOF
pass ".duo-envs.json written"

say "minimal site.duo.json (core manifest only -- this is a bare install, no plugins)"
mkdir -p "$SITEREPO"
cat > "$SITEREPO/site.duo.json" <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
pass "site.duo.json written"

say "doctor: transport/WP/agent/repo all green"
assert_exit 0 "duo doctor ${PAIR}1" -- "$DUO" doctor "${PAIR}1"

say "initial capture (establishes ledger base hashes -- Capture::run() calls Ledger::set_state_hash for every entity, which is what lets a later direct env edit register as drift instead of first_sync-update)"
assert_exit 0 "duo capture ${PAIR}1" -- "$DUO" capture "${PAIR}1"

say "(a) clean environment: duo status exits 0"
assert_exit 0 "duo status ${PAIR}1 (clean)" -- "$DUO" status "${PAIR}1"
grep -q '0 code_mismatch' <<<"$OUT" || fail "expected '0 code_mismatch' in a clean plan summary"
grep -q ', 0 conflict, 0 collision,' <<<"$OUT" || fail "expected 0 conflict, 0 collision in a clean plan summary"
pass "clean plan summary, 0 code_mismatch"

say "(b) inject code_mismatch: declare a plugin active in state/options/core.json that doesn't exist in this environment's code"
CORE_JSON="$SITEREPO/state/options/core.json"
[ -f "$CORE_JSON" ] || fail "expected $CORE_JSON to exist after capture"
jq '.records.active_plugins.value = ["fake-plugin/fake-plugin.php"]' "$CORE_JSON" > "$CORE_JSON.tmp" && mv "$CORE_JSON.tmp" "$CORE_JSON"
pass "state/options/core.json: active_plugins now declares fake-plugin/fake-plugin.php"

assert_exit 1 "duo status ${PAIR}1 (code_mismatch)" -- "$DUO" status "${PAIR}1"
grep -q 'fake-plugin/fake-plugin.php' <<<"$OUT" || fail "duo status did not name the missing plugin"
grep -qi 'CODE_MISMATCH' <<<"$OUT" || fail "duo status did not surface a CODE_MISMATCH block"
grep -q ', 1 code_mismatch' <<<"$OUT" || fail "duo status did not count 1 code_mismatch"
pass "duo status exits non-zero and names the missing plugin"

say "revert the code_mismatch injection; confirm code_mismatch clears"
jq '.records.active_plugins.value = []' "$CORE_JSON" > "$CORE_JSON.tmp" && mv "$CORE_JSON.tmp" "$CORE_JSON"
assert_exit 0 "duo status ${PAIR}1 (reverted)" -- "$DUO" status "${PAIR}1"
grep -q '0 code_mismatch' <<<"$OUT" || fail "expected 0 code_mismatch after reverting the injected plugin"
pass "code_mismatch clears after reverting; exit 0 again"

say "(c) create drift: edit a post directly on the environment, bypassing the repo entirely"
POST_ID=$("${PAIR_COMPOSE[@]}" run --rm -T cli1 wp post list --post_type=post --field=ID | tr -d '\r' | head -1)
[ -n "$POST_ID" ] || fail "could not find a default post to drift"
"${PAIR_COMPOSE[@]}" run --rm -T cli1 wp post update "$POST_ID" --post_title="drifted directly on the env" >/dev/null
pass "post #$POST_ID title changed directly on the environment (repo's captured file untouched)"

assert_exit 1 "duo status ${PAIR}1 (drift)" -- "$DUO" status "${PAIR}1"
grep -q ', 1 drift,' <<<"$OUT" || fail "duo status did not count 1 drift"
grep -qi 'capture-first' <<<"$OUT" || fail "duo status did not render the capture-first hint"
pass "duo status exits non-zero with the capture-first hint on drift"

say "(d) malformed JSON from the agent still fails duo status (regression check -- isolated, no docker/live env needed)"
FAKEBIN=$(mktemp -d)
cat > "$FAKEBIN/wp" <<'EOF'
#!/usr/bin/env bash
# Stands in for wp-cli for exactly one purpose: exit 0 but print something
# that isn't valid JSON when asked to `duo plan`, so cli/duo's own
# json_decode()-failure branch (cli/duo, cmd_status()) gets exercised
# without needing a real agent to ever misbehave.
for a in "$@"; do
  if [ "$a" = "plan" ]; then
    printf '%s\n' "${FAKE_PLAN_JSON:-not valid json}"
    exit 0
  fi
done
exit 1
EOF
chmod +x "$FAKEBIN/wp"
FAKE_ENVS="$FAKEBIN/envs.json"
cat > "$FAKE_ENVS" <<EOF
{
  "envs": {
    "malformed": {"transport": "local", "wp_path": "$FAKEBIN", "repo_path": "$FAKEBIN"}
  }
}
EOF
if OUT=$(PATH="$FAKEBIN:$PATH" "$DUO" --envs-file="$FAKE_ENVS" status malformed 2>&1); then CODE=0; else CODE=$?; fi
echo "$OUT"
[ "$CODE" -ne 0 ] || fail "duo status malformed: expected non-zero exit on malformed JSON, got 0"
grep -qi 'could not parse plan JSON' <<<"$OUT" || fail "expected the malformed-JSON error message"
pass "malformed JSON from the agent still fails duo status (exit $CODE)"

say "(e) valid JSON without the complete plan contract fails closed"
if OUT=$(FAKE_PLAN_JSON='{}' PATH="$FAKEBIN:$PATH" "$DUO" --envs-file="$FAKE_ENVS" status malformed 2>&1); then CODE=0; else CODE=$?; fi
echo "$OUT"
rm -rf "$FAKEBIN"
[ "$CODE" -ne 0 ] || fail "duo status empty object: expected non-zero exit, got 0"
grep -qi 'incomplete plan contract' <<<"$OUT" || fail "expected the incomplete-plan-contract diagnostic"
pass "schema-empty plan JSON cannot become a clean status (exit $CODE)"

printf '\n\033[1;32m✔ CLI STATUS TRUTH PASSED\033[0m\n'
