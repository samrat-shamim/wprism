#!/usr/bin/env bash
# Offline contract for the reference-certification evidence writer boundary.
# Real jq and file I/O prove the exact result, skipped-leg, scoped-result, and
# aggregate-fragment shapes without Docker or a live certification run.
set -euo pipefail
cd "$(dirname "$0")"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

command -v jq >/dev/null || fail "jq required"
FORCE_HATCH_LIB=../lib/pair_force_hatch.sh
SCOPED_WRAPPER=certify_adapter_bundle.sh
bash -n ../lib/certbundle_evidence.sh "$FORCE_HATCH_LIB" certify_reference_bundle.sh "$SCOPED_WRAPPER"

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

if bash ../lib/certbundle_evidence.sh 2>"$TMP/direct.log"; then
  fail "source-only evidence library executed directly"
fi
grep -qF 'certbundle_evidence.sh is a source-only library' "$TMP/direct.log" \
  || fail "direct-execution refusal did not name the source-only contract"
pass "the evidence boundary is source-only"

if bash "$FORCE_HATCH_LIB" 2>"$TMP/force-hatch-direct.log"; then
  fail "source-only pair force-hatch library executed directly"
fi
grep -qF 'pair_force_hatch.sh is a source-only library' "$TMP/force-hatch-direct.log" \
  || fail "force-hatch direct-execution refusal did not name the source-only contract"
# shellcheck source=../lib/pair_force_hatch.sh
source "$FORCE_HATCH_LIB"

say "actual-use force-hatch ledger"
unset DUO_PAIR_FORCE_HATCH_LOG
pair_force_hatch_record DUO_PAIR_BUDGET_OVERRIDE \
  || fail "ordinary non-certification pair use unexpectedly required an evidence ledger"
LEDGER="$TMP/pair-force-hatches.log"
if pair_force_hatch_init relative-ledger; then
  fail "force-hatch ledger accepted a relative path"
fi
pair_force_hatch_init "$LEDGER" || fail "could not initialize the private force-hatch ledger"
DUO_PAIR_BUDGET_OVERRIDE=1
export DUO_PAIR_BUDGET_OVERRIDE
[ "$(pair_force_hatch_json)" = '[]' ] \
  || fail "environment-variable presence was mistaken for actual force-hatch use"
pair_force_hatch_record DUO_PAIR_BUDGET_OVERRIDE \
  || fail "reviewed force-hatch use could not be recorded"
pair_force_hatch_record DUO_PAIR_BUDGET_OVERRIDE \
  || fail "repeated force-hatch use could not be recorded"
[ "$(pair_force_hatch_json)" = '["DUO_PAIR_BUDGET_OVERRIDE"]' ] \
  || fail "actual repeated use did not project to one stable reviewed hatch"
if pair_force_hatch_record UNREVIEWED_HATCH; then
  fail "unreviewed force-hatch name was accepted"
fi
printf '%s\n' UNREVIEWED_HATCH >> "$LEDGER"
if pair_force_hatch_json >"$TMP/unreviewed-force-hatch.json"; then
  fail "tampered force-hatch ledger projected an unreviewed hatch"
fi
rm -f -- "$LEDGER"
if pair_force_hatch_record DUO_PAIR_BUDGET_OVERRIDE; then
  fail "actual hatch use succeeded after the wrapper-owned ledger disappeared"
fi
if pair_force_hatch_json >"$TMP/missing-force-hatch.json"; then
  fail "missing wrapper-owned ledger projected clean evidence"
fi
unset DUO_PAIR_BUDGET_OVERRIDE DUO_PAIR_FORCE_HATCH_LOG
pass "presence stays unforced, actual use deduplicates, and missing/tampered ledgers refuse"

# shellcheck source=../lib/certbundle_evidence.sh
source ../lib/certbundle_evidence.sh
TEST_FRAGMENTS=()

say "pass/fail result envelopes"
certbundle_evidence_write_result pass-leg 0 passed '["receipt","convergence"]' "$TMP/pass.result.json"
jq -e '
  . == {
    schema_version:1,test:"pass-leg",verdict:"pass",exit_code:0,reason:"passed",
    assertions:["receipt","convergence"]
  }
' "$TMP/pass.result.json" >/dev/null \
  || fail "passing result envelope changed"

certbundle_evidence_write_result fail-leg 70 invalid_checker_output '[]' "$TMP/fail.result.json"
jq -e '
  . == {
    schema_version:1,test:"fail-leg",verdict:"fail",exit_code:70,
    reason:"invalid_checker_output",assertions:[]
  }
' "$TMP/fail.result.json" >/dev/null \
  || fail "failing result envelope changed"
pass "result verdicts and assertion arrays retain their machine contract"

say "scoped result extension"
certbundle_evidence_write_scoped_result scoped-leg 0 passed '["public_workflow"]' \
  platform-init '["live_pair"]' "$TMP/scoped.result.json"
jq -e '
  .test == "scoped-leg" and .verdict == "pass" and .exit_code == 0
  and .assertions == ["public_workflow"] and .scope == "platform-init"
  and .exclusions == ["live_pair"]
' "$TMP/scoped.result.json" >/dev/null \
  || fail "scoped result fields changed"
[ ! -e "$TMP/scoped.result.json.tmp" ] || fail "scoped result left its replacement temporary behind"
pass "scoped results extend the base envelope and replace their temporary"

say "blocked leg and aggregate fragment"
certbundle_evidence_write_skipped blocked-leg woocommerce \
  "$TMP/blocked.log" "$TMP/blocked.result.json" "$TMP/blocked.diff.json" "$TMP/blocked.fragment.json"
grep -qxF 'SKIPPED: blocked by an earlier failed reference-certification leg' "$TMP/blocked.log" \
  || fail "skipped-leg log changed"
jq -e '.test == "blocked-leg" and .verdict == "fail" and .exit_code == 99 and .reason == "blocked_by_prior_failure" and .assertions == []' \
  "$TMP/blocked.result.json" >/dev/null || fail "skipped-leg result changed"
jq -e '. == {status:"unknown",manifest:"woocommerce",reason:"blocked_by_prior_failure"}' \
  "$TMP/blocked.diff.json" >/dev/null || fail "skipped-leg diff changed"
jq -e --arg result "$TMP/blocked.result.json" --arg diff "$TMP/blocked.diff.json" '
  . == {id:"blocked-leg",manifest:"woocommerce",result:$result,diff:$diff}
' "$TMP/blocked.fragment.json" >/dev/null || fail "skipped-leg fragment changed"

certbundle_evidence_append_fragment "$TMP/blocked.fragment.json" "$TMP/blocked.log"
[ "${#TEST_FRAGMENTS[@]}" -eq 1 ] || fail "aggregate fragment was not appended exactly once"
jq -e --arg result "$TMP/blocked.result.json" --arg diff "$TMP/blocked.diff.json" --arg log "$TMP/blocked.log" '
  . == {id:"blocked-leg",result:$result,diff:$diff,log:$log} and has("manifest") == false
' <<<"${TEST_FRAGMENTS[0]}" >/dev/null || fail "aggregate fragment changed or retained manifest"
pass "blocked legs and aggregate fragments retain their exact evidence shape"

say "thin wrapper boundary"
leaked=$(grep -Ec '^certbundle_evidence_[a-z0-9_]+\(\)' certify_reference_bundle.sh || true)
[ "$leaked" -eq 0 ] || fail "$leaked certification evidence functions leaked back into the wrapper"
defined=$(grep -Ec '^certbundle_evidence_[a-z0-9_]+\(\)' ../lib/certbundle_evidence.sh || true)
[ "$defined" -eq 5 ] || fail "expected 5 evidence helpers in the sourced boundary, found $defined"
grep -qF 'source lib/certbundle_evidence.sh' certify_reference_bundle.sh \
  || fail "reference wrapper no longer sources the evidence boundary"
for helper in write_result write_scoped_result write_fragment write_skipped append_fragment; do
  if grep -Eq "(^|[^[:alnum:]_])${helper}([[:space:]]*\(\)|[[:space:]])" certify_reference_bundle.sh; then
    fail "legacy ${helper} definition or call leaked back into the wrapper"
  fi
  grep -Eq "(^|[[:space:]])certbundle_evidence_${helper}[[:space:]]" certify_reference_bundle.sh \
    || fail "reference wrapper no longer calls certbundle_evidence_${helper}"
done
pass "the wrapper delegates all five evidence helpers and retains no legacy definitions or calls"

say "thin actual-use handoff"
for wrapper in certify_reference_bundle.sh "$SCOPED_WRAPPER"; do
  grep -qF 'source lib/pair_force_hatch.sh' "$wrapper" \
    || fail "$wrapper no longer sources the actual-use ledger"
  grep -qF 'pair_force_hatch_init "$WORK_ROOT/pair-force-hatches.log"' "$wrapper" \
    || fail "$wrapper no longer initializes a private actual-use ledger"
  grep -qF 'FORCE_HATCHES=$(pair_force_hatch_json)' "$wrapper" \
    || fail "$wrapper no longer projects actual hatch use into its build spec"
  grep -qF 'sandbox/lib/pair_force_hatch.sh' "$wrapper" \
    || fail "$wrapper does not bind the actual-use ledger library into certification evidence"
done
grep -qF 'source "lib/pair_force_hatch.sh"' ../bin/pair.sh \
  || fail "pair launcher no longer loads the actual-use ledger"
grep -qF 'pair_force_hatch_record DUO_PAIR_BUDGET_OVERRIDE' ../bin/pair.sh \
  || fail "pair launcher no longer records the actual override admission branch"
pass "both wrappers initialize/project the ledger and pair.sh owns the actual-use write"

printf '\n\033[1;32m✔ REGRESS_CERTBUNDLE_EVIDENCE PASSED\033[0m\n'
