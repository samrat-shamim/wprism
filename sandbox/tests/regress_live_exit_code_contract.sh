#!/usr/bin/env bash
# Regression — DUO-3400: live regressions that assert a Duo refusal must not
# turn a compose-layer death into a green engine accusation. These three
# suites intentionally do not source conformance/asserts.sh: they are older
# live-regress harnesses with their own pair lifecycle. Their safe boundary is
# therefore the smaller, explicit contract: capture the complete invocation
# output, retain its real status, require the reviewed refusal status (1), and
# include both status and captured output if the transport dies differently.
#
# This is an offline source contract. The mutation probes below change each
# exact refusal comparison to `-ne 0`; the contract must reject that weaker
# shape, proving the regression would fail if a future edit reintroduced the
# DUO-3391 misroute.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

SCOPE=tests/live/regress_scope_gate.sh
COMPILER=tests/live/regress_repository_compiler_integration.sh
AUTH=tests/live/regress_repository_authorization.sh
for file in "$SCOPE" "$COMPILER" "$AUTH"; do
  [ -f "$file" ] || fail "live regression source is missing: $file"
done

assert_literal() {
  local file="$1" literal="$2"
  grep -Fq -- "$literal" "$file" \
    || fail "$file no longer contains the required refusal-contract source: $literal"
}

# The scope-gate capture must preserve the whole transport stream and its
# exact refusal status, and its failure path must print both diagnostics.
assert_literal "$SCOPE" 'BLOCKED=$(wp1 --require="$REPO/register.php" duo capture --repo="$REPO" --out="$OUT" 2>&1) || RC=$?'
assert_literal "$SCOPE" '[ "$RC" -eq 1 ] || fail "unscoped public surfaces must block capture (rc=$RC): $BLOCKED"'
pass "scope gate captures the real status and complete refusal output"

# The compiler integration has two refusal assertions; both must retain their
# independent statuses and captured compose/Duo output.
assert_literal "$COMPILER" 'RAW=$(wp2 duo plan --repo="$REPO" --format=json 2>&1) || RAW_RC=$?'
assert_literal "$COMPILER" '[ "$RAW_RC" -eq 1 ] || fail "raw plan must reject the post-compile mutation (rc=$RAW_RC): $RAW"'
assert_literal "$COMPILER" 'RAW2=$(wp2 duo apply --repo="$REPO" --format=json 2>&1) || RAW2_RC=$?'
assert_literal "$COMPILER" '[ "$RAW2_RC" -eq 1 ] || fail "raw apply must reject the changed tree (rc=$RAW2_RC): $RAW2"'
pass "compiler integration retains exact plan/apply refusal statuses and output"

# The authorization helper covers plan/apply/deploy through one capture path;
# keep its exact status check and diagnostic echo intact.
assert_literal "$AUTH" 'out=$(wp1 duo "$verb" --repo="$REPO" --format=json 2>&1) || rc=$?'
assert_literal "$AUTH" '[ "$rc" -eq 1 ] || fail "duo $verb must exit 1 on unauthorized repository state (got $rc)"'
assert_literal "$AUTH" "printf '%s\\n' \"\$out\" >&2"
assert_literal "$AUTH" 'HUMAN=$(wp1 duo apply --repo="$REPO" 2>&1) || HUMAN_RC=$?'
assert_literal "$AUTH" '[ "$HUMAN_RC" -eq 1 ] || fail "human apply output must exit 1 (rc=$HUMAN_RC): $HUMAN"'
pass "authorization helper retains exact refusal status and captured output"

assert_contract() {
  local file="$1" identity="${2:-$1}"
  case "$identity" in
    *regress_scope_gate.sh)
      assert_literal "$file" 'BLOCKED=$(wp1 --require="$REPO/register.php" duo capture --repo="$REPO" --out="$OUT" 2>&1) || RC=$?'
      assert_literal "$file" '[ "$RC" -eq 1 ] || fail "unscoped public surfaces must block capture (rc=$RC): $BLOCKED"'
      ;;
    *regress_repository_compiler_integration.sh)
      assert_literal "$file" 'RAW=$(wp2 duo plan --repo="$REPO" --format=json 2>&1) || RAW_RC=$?'
      assert_literal "$file" '[ "$RAW_RC" -eq 1 ] || fail "raw plan must reject the post-compile mutation (rc=$RAW_RC): $RAW"'
      assert_literal "$file" 'RAW2=$(wp2 duo apply --repo="$REPO" --format=json 2>&1) || RAW2_RC=$?'
      assert_literal "$file" '[ "$RAW2_RC" -eq 1 ] || fail "raw apply must reject the changed tree (rc=$RAW2_RC): $RAW2"'
      ;;
    *regress_repository_authorization.sh)
      assert_literal "$file" 'out=$(wp1 duo "$verb" --repo="$REPO" --format=json 2>&1) || rc=$?'
      assert_literal "$file" '[ "$rc" -eq 1 ] || fail "duo $verb must exit 1 on unauthorized repository state (got $rc)"'
      assert_literal "$file" "printf '%s\\n' \"\$out\" >&2"
      assert_literal "$file" 'HUMAN=$(wp1 duo apply --repo="$REPO" 2>&1) || HUMAN_RC=$?'
      assert_literal "$file" '[ "$HUMAN_RC" -eq 1 ] || fail "human apply output must exit 1 (rc=$HUMAN_RC): $HUMAN"'
      ;;
    *) fail "unknown live regression source: $file" ;;
  esac
}

probe_mutation() {
  local source="$1" needle="$2" replacement="$3" tmp contents mutated
  tmp=$(mktemp)
  contents=$(<"$source")
  [[ "$contents" == *"$needle"* ]] || fail "mutation probe did not find its source needle in $source"
  mutated=${contents/"$needle"/"$replacement"}
  printf '%s' "$mutated" > "$tmp"
  if (assert_contract "$tmp" "$source") 2>/dev/null; then
    rm -f "$tmp"
    fail "$source contract probe accepted the weakened refusal comparison"
  fi
  rm -f "$tmp"
}

# Keep this deliberately source-local rather than executing the Docker
# harnesses: the preceding literals are the actual contract, while these
# probes make the old `-ne 0` shape visibly fail the same offline gate.
probe_mutation "$SCOPE" '[ "$RC" -eq 1 ]' '[ "$RC" -ne 0 ]'
probe_mutation "$COMPILER" '[ "$RAW_RC" -eq 1 ]' '[ "$RAW_RC" -ne 0 ]'
probe_mutation "$COMPILER" '[ "$RAW2_RC" -eq 1 ]' '[ "$RAW2_RC" -ne 0 ]'
probe_mutation "$AUTH" '[ "$rc" -eq 1 ]' '[ "$rc" -ne 0 ]'
probe_mutation "$AUTH" '[ "$HUMAN_RC" -eq 1 ]' '[ "$HUMAN_RC" -ne 0 ]'
pass "DUO-3391 compose-death regression: all three live suites require exact rc=1 and retain transport output"

printf '\033[1;32m✔ REGRESS_LIVE_EXIT_CODE_CONTRACT PASSED\033[0m\n'
