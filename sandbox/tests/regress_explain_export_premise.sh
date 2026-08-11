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

# DUO-3424: the durable mutation-tooth is removed immediately after its
# after-export bytes are captured, before the non-empty premise can abort. A
# separate EXIT cleanup covers a command that returns non-zero after writing.
TOOTH_CAPTURE_LINE=$(grep -n 'EXPLAIN_TOOTH_AFTER_SQL=$(wp_conf2 db export' "$CORE" | cut -d: -f1)
TOOTH_DELETE_LINE=$(awk -v capture="$TOOTH_CAPTURE_LINE" 'NR > capture && /wp_conf2 option delete duo_explain_mutation_tooth/ { print NR; exit }' "$CORE")
TOOTH_PREMISE_LINE=$(grep -n 'require_observed_nonempty "conf2 db export (mutation-tooth after)"' "$CORE" | cut -d: -f1)
[ -n "$TOOTH_CAPTURE_LINE" ] && [ -n "$TOOTH_DELETE_LINE" ] && [ -n "$TOOTH_PREMISE_LINE" ] \
  || fail "DUO-3424 strict-explain tooth cleanup/premise anchors are missing"
[ "$TOOTH_CAPTURE_LINE" -lt "$TOOTH_DELETE_LINE" ] && [ "$TOOTH_DELETE_LINE" -lt "$TOOTH_PREMISE_LINE" ] \
  || fail "DUO-3424 tooth option is not removed between after-export capture and its premise guard"
grep -Fq 'EXPLAIN_TOOTH_OPTION_MAY_EXIST=1' "$CORE" \
  || fail "DUO-3424 does not mark the durable tooth as cleanup-owned before its write"
grep -Fq 'EXPLAIN_TOOTH_DELETE_OUT=$(wp_conf2 option delete duo_explain_mutation_tooth' "$CORE" \
  || fail "DUO-3424 does not capture a non-empty answer from tooth cleanup before clearing ownership"
grep -Fq 'cleanup_strict_explain()' "$CORE" \
  || fail "DUO-3424 does not retain a best-effort EXIT cleanup for a partial tooth write"
grep -Fq 'EXPLAIN_MU_MAY_EXIST=1' "$CORE" \
  || fail "DUO-3424 does not mark the persistent MU files as cleanup-owned before installation"
MU_FLAG_LINE=$(grep -n 'EXPLAIN_MU_MAY_EXIST=1' "$CORE" | head -n1 | cut -d: -f1)
MU_INSTALL_LINE=$(grep -n 'DUO_EXPLAIN_OFFLOAD_HOOK_WAS_INVOKED' "$CORE" | head -n1 | cut -d: -f1)
[ -n "$MU_FLAG_LINE" ] && [ -n "$MU_INSTALL_LINE" ] && [ "$MU_FLAG_LINE" -lt "$MU_INSTALL_LINE" ] \
  || fail "DUO-3424 does not mark MU cleanup ownership before the first offload-guard write"
grep -Fq '$COMPOSE run --rm -T --no-deps --user root wp2' "$CORE" \
  || fail "DUO-3424 has no dependency-free root fallback for MU cleanup when exec cannot answer"
grep -Fq 'pair destroy (not pair reset)' "$CORE" \
  || fail "DUO-3424 MU cleanup rationale still misstates pair reset's webroot-volume behavior"
pass "DUO-3424 removes the tooth before premise failure and retains an EXIT cleanup for partial writes"

echo "REGRESS_EXPLAIN_EXPORT_PREMISE PASSED"
