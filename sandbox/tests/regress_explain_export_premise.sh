#!/usr/bin/env bash
# Regression — DUO-3413: the core sweep's strict-explain post-conditions must
# name infrastructure, not the engine, when a load-starved `docker compose run`
# returns EMPTY at exit 0. An empty `wp db export` hashes to the empty-string
# digest e3b0c442… and the before/after equality check then reports "strict
# explain changed the target database" with neither hash nor diff — an engine
# accusation for a compose-layer death (reproduced live, PR #190). Proves:
# require_observed_nonempty raises the infrastructure domain on an empty stream
# (never an engine accusation) and passes a non-empty one; and core.sh routes
# BOTH db exports through the premise and pastes evidence into the mutation and
# refusal accusations. Offline: sources the shared fragment, no docker, no pair.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

FRAG=conformance/asserts.sh
CORE=conformance/checks/core.sh
[ -f "$FRAG" ] || fail "shared fragment $FRAG is missing"
[ -f "$CORE" ] || fail "$CORE is missing"

# --- 1. defined in the shared fragment + exported for the hook subprocesses ---
grep -qE '^require_observed_nonempty\(\) \{' "$FRAG" \
  || fail "require_observed_nonempty is not defined in $FRAG (both harnesses must see it)"
grep -qE 'require_observed_nonempty' conformance/run.sh \
  || fail "run.sh does not export require_observed_nonempty for the hook subprocesses (core.sh runs as one)"

# --- 2. behavior: empty -> infrastructure failure; non-empty -> silent pass ---
# asserts.sh refuses to source without a caller-defined fail() (its own guard),
# so each probe defines one, sources the fragment, and calls the helper in an
# isolated bash -c whose exit the parent captures.
probe() { # probe <value> ; prints combined stdout+stderr then "rc=<code>"
  local out rc
  out=$(bash -c '
    fail() { printf "FAIL: %s\n" "$*"; exit 1; }
    . conformance/asserts.sh
    require_observed_nonempty "conf2 db export (probe)" "$1" && printf "OK_NONEMPTY\n"
  ' _ "$1" 2>&1) && rc=0 || rc=$?
  printf '%s\nrc=%s\n' "$out" "$rc"
}

EMPTY=$(probe "")
grep -q 'infrastructure failure:' <<<"$EMPTY" \
  || fail "empty stream did not raise the infrastructure-failure domain: $EMPTY"
grep -qE 'rc=[1-9]' <<<"$EMPTY" \
  || fail "empty stream did not stop the hook (expected non-zero exit): $EMPTY"
grep -q 'OK_NONEMPTY' <<<"$EMPTY" \
  && fail "empty stream wrongly passed the premise: $EMPTY"
pass "require_observed_nonempty on an empty stream raises the infrastructure domain and stops the hook (not an engine accusation)"

NONEMPTY=$(probe "SELECT 1;")
grep -q 'OK_NONEMPTY' <<<"$NONEMPTY" \
  || fail "require_observed_nonempty rejected a non-empty stream: $NONEMPTY"
grep -qE 'rc=0' <<<"$NONEMPTY" \
  || fail "require_observed_nonempty on a non-empty stream did not exit 0: $NONEMPTY"
pass "require_observed_nonempty passes a non-empty observation silently"

# --- 3. static call-site guards on core.sh (pin the fix in place) ------------
grep -qE 'require_observed_nonempty "conf2 db export \(before' "$CORE" \
  || fail "$CORE no longer premise-guards the BEFORE strict-explain db export"
grep -qE 'require_observed_nonempty "conf2 db export \(after' "$CORE" \
  || fail "$CORE no longer premise-guards the AFTER strict-explain db export"
grep -qE 'strict explain changed the target database: before=.*after=' "$CORE" \
  || fail "$CORE's db-mutation accusation pastes no digests (undiagnosable)"
grep -qE 'refused a valid current selector \(rc=' "$CORE" \
  || fail "$CORE's explain-refusal accusation pastes no rc/stdout/stderr evidence"
grep -qE '2>"\$EXPLAIN_REGISTRY_DIR/json\.stderr"' "$CORE" \
  || fail "$CORE still drops the json explain invocation's stderr to /dev/null"
if grep -qE 'EXPLAIN_DB_(BEFORE|AFTER)=\$\(wp_conf2 db export .*shasum' "$CORE"; then
  fail "$CORE still hashes an export inline (empty -> e3b0c442) without the non-empty premise"
fi
pass "core.sh premise-guards both exports and pastes evidence into the strict-explain post-condition accusations"

echo "REGRESS_EXPLAIN_EXPORT_PREMISE PASSED"
