#!/usr/bin/env bash
# Regression — the mutation gate re-checks the CONDITIONS the frozen plan
# names, not only the three facts it used to re-probe.
#
# The product spec is one sentence (docs/product-spec.md:302-303):
# "execution is permitted only when every named, machine-checkable condition
# is satisfied and rechecked at the mutation gate. An unmet or uncheckable
# condition blocks."
#
# A gate did exist — `ReleaseCommand::execute()` re-verifies "against the
# target AS IT IS NOW, immediately before the mutating call and after the
# operator's confirmation" — but it was partly self-satisfying. It re-read the
# agent plan envelope, the target HEAD and the target artifact hash, and then
# handed `AuthorizationPlan::currentFacts()` the frozen `$prepared['inputs']`
# for everything else. `capabilities` — the per-surface capability and
# condition rows — was COPIED, so `plugin_version_mismatch` and
# `plugin_not_active` re-hashed to their frozen values by construction and a
# plugin deactivated or downgraded inside the confirmation window authorized a
# production mutation anyway.
#
# Every case below RELEASES against that build. Each drives the real
# `php cli/duo release` end to end over a `local` transport with a fake `wp` on
# PATH, and the assertion that matters most is not the reason code: it is that
# `$DUO_CALLS` records no `duo promotion-begin`, `duo deploy` or `duo apply`.
# That proves the refusal is PRE-mutation rather than merely early in the
# source — the difference between a gate and a comment.
#
# Offline: no docker, no WordPress, no network, no target.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/duo-release-condition-gate.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT INT TERM

FAILURES=0
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; FAILURES=$((FAILURES + 1)); }
say()  { printf '\n== %s ==\n' "$*"; }

printf '== syntax ==\n'
for file in "$ROOT/cli/duo" \
  "$ROOT/cli/src/Command/ReleaseCommand.php" \
  "$ROOT/cli/src/Command/AssessCommand.php" \
  "$ROOT/cli/src/Assess/SurfaceCatalog.php" \
  "$ROOT/cli/src/Release/AuthorizationPlan.php" \
  "$ROOT/cli/src/Release/ReleaseOutcome.php" \
  "$ROOT/agent/src/Adapter/AdapterRegistry.php" \
  "$ROOT/sandbox/tests/fixtures/assess/make-fixture.php" \
  "$ROOT/sandbox/tests/fixtures/release/make-release-site.php"; do
  php -l "$file" >/dev/null || fail "php -l $file"
done
pass 'PHP syntax'

# ---------------------------------------------------------------- the fixture
php "$ROOT/sandbox/tests/fixtures/release/make-release-site.php" "$TMP/site" >/dev/null \
  || { echo "FAIL: could not build the release fixture" >&2; exit 1; }

SITE="$TMP/site/repo"
export DUO_FIXTURES="$TMP/site/fixtures"
export DUO_SITE_REPO="$SITE"
export DUO_CALLS="$TMP/calls.txt"
PATH="$TMP/site/bin:$PATH"
export PATH

duo() {
  local out="$1"; shift
  ( cd "$SITE" && php "$ROOT/cli/duo" --envs-file="$TMP/site/envs.json" "$@" ) \
    > "$out" 2> "$out.err"
}

# The reviewed contract, through the real propose -> review -> accept path.
duo "$TMP/propose.txt" contract fixture propose \
  || { fail 'contract propose failed'; cat "$TMP/propose.txt.err" >&2; }
php -r '
$path = $argv[1];
$proposal = json_decode((string) file_get_contents($path), true);
$contract = $proposal["contract"];
foreach ($contract["declarations"]["external_effects"] as $index => $effect) {
    if (($effect["decided_by"] ?? null) !== "unresolved") { continue; }
    $contract["declarations"]["external_effects"][$index]["decided_by"] = "operator";
    $contract["declarations"]["external_effects"][$index]["decided_at"] = "2026-08-17T09:02:11Z";
    $contract["declarations"]["external_effects"][$index]["reason"] =
        "reviewed 2026-08-17: nothing this site activates sends mail, calls a payment API, or fires a webhook";
}
foreach ($contract["declarations"]["surfaces"] as $index => $surface) {
    if (($surface["decided_by"] ?? null) !== "unresolved") { continue; }
    $contract["declarations"]["surfaces"][$index]["decided_by"] = "operator";
    if (strpos((string) ($surface["id"] ?? ""), "plugin:") === 0) {
        $contract["declarations"]["surfaces"][$index]["state_class"] = "runtime";
        $contract["declarations"]["surfaces"][$index]["handling"] = "preserve local";
    }
}
$proposal["contract"] = $contract;
file_put_contents($path, json_encode($proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
' "$SITE/.duo/contract/fixture/proposed.json"
duo "$TMP/accept.txt" contract fixture accept \
  || { fail 'contract accept failed'; cat "$TMP/accept.txt.err" >&2; }

# release <name> [args...]: resets both fixture call counters and the recorded
# call log, so `caps-calls` and the mutation assertion are about THIS run.
release() {
  local name="$1"; shift
  rm -f "$DUO_FIXTURES/plan-calls" "$DUO_FIXTURES/caps-calls" "$DUO_CALLS"
  duo "$TMP/$name.txt" release fixture "$@"
  local status=$?
  cat "$TMP/$name.txt.err" >> "$TMP/$name.txt"
  return $status
}

# The target was NOT touched: promotion never began, no lifecycle phase ran,
# no apply ran. Read off the fake wp's own call log, not off the source.
#
# `duo compile` is deliberately not in this list. It is a read — the content
# address `prepare()` binds the plan to, and the gate re-reads to compare —
# and it runs before the freeze on every release, `--plan-only` included. The
# three verbs below are the ones that begin a production-visible mutation.
assert_untouched() {
  local what="$1"
  if grep -Eq 'duo (promotion-begin|deploy|apply) ' "$DUO_CALLS"; then
    fail "$what refused, but the target was already being mutated"
    grep -Eo 'duo (promotion-begin|deploy|apply) ' "$DUO_CALLS" | sort -u >&2
  else
    pass "$what refuses BEFORE the first production-visible call: the target is untouched"
  fi
}

# assert_refusal <name> <reason code> <human description>
assert_refusal() {
  local name="$1" code="$2" what="$3"
  grep -Fq "[$code]" "$TMP/$name.txt" \
    && pass "$what names $code" \
    || { fail "$what did not name $code"; sed -n '1,40p' "$TMP/$name.txt" >&2; }
  grep -Fq '"class": "capability_expired"' "$TMP/$name.txt" \
    && pass "$what is the failure class capability_expired" \
    || fail "$what did not carry the capability_expired failure class"
  grep -Fq '"next_action": "requalify"' "$TMP/$name.txt" \
    && pass "$what answers requalify" \
    || fail "$what did not answer requalify"
  grep -Eq '"next_action": "(retry|recover|resume)"' "$TMP/$name.txt" \
    && fail "$what offered an automated action for a condition that no longer holds" \
    || pass "$what never offers retry, recover or resume"
}

# ------------------------------------------------ the frozen plan's own rows
say 'the frozen plan carries machine-checkable condition rows, not prose'
release "planonly" --plan-only --format=json
grep -Eq 'condition: plugin_version_mismatch — sample-adapter in 10\.0\.0-11\.0\.0 — 10\.4\.2 — mutation gate' \
  "$TMP/planonly.txt" \
  && pass 'the authorization page prints the four-part condition line docs/guides/release.md:148 documents' \
  || { fail 'the condition line is not the documented row'; grep -n 'condition:' "$TMP/planonly.txt" >&2; }

# ----------------------------------------------------------------- the drift
# `DUO_CAPS_AFTER_CALL=2`: call 1 is the freeze-time capability read inside
# `prepare()`, call 2 is the mutation gate's own re-probe. The target changes
# in between, which is exactly the operator's confirmation window.
say 'the plugin is DOWNGRADED between freeze and gate'
DUO_CAPS_AFTER=moved DUO_CAPS_AFTER_CALL=2 release "moved" --yes --format=json
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a moved condition refuses (exit 1)' || fail "a moved condition exited $STATUS"
assert_refusal moved release_condition_changed 'a downgraded plugin'
grep -Fq '"state": "moved"' "$TMP/moved.txt" \
  && pass 'the diagnostics say what happened to the condition: it moved' \
  || fail 'the refusal does not say the observation moved'
grep -Fq '"subject": "sample-adapter"' "$TMP/moved.txt" \
  && pass 'the diagnostics name the SUBJECT the condition is anchored to' \
  || fail 'the refusal does not name the condition subject'
grep -Fq '9.9.0' "$TMP/moved.txt" \
  && fail 'the refusal leaked the observed version value into a public envelope' \
  || pass 'the refusal names code, subject, manifest and state — and never a version value'
assert_untouched 'a downgraded plugin'

say 'the plugin is DEACTIVATED between freeze and gate'
DUO_CAPS_AFTER=inactive DUO_CAPS_AFTER_CALL=2 release "inactive" --yes --format=json
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a deactivated plugin refuses (exit 1)' || fail "a deactivated plugin exited $STATUS"
assert_refusal inactive release_condition_changed 'a deactivated plugin'
grep -Fq '"code": "plugin_not_active"' "$TMP/inactive.txt" \
  && pass 'the refusal names the condition code that APPEARED, not the one that was already there' \
  || fail 'the refusal does not name plugin_not_active'
grep -Fq '"state": "appeared"' "$TMP/inactive.txt" \
  && pass 'a condition that appeared during the window is reported as appeared' \
  || fail 'the appearing condition was not classified as appeared'
assert_untouched 'a deactivated plugin'

say 'a condition is WITHDRAWN between freeze and gate'
DUO_CAPS_AFTER=withdrawn DUO_CAPS_AFTER_CALL=2 release "withdrawn" --yes --format=json
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a withdrawn condition refuses (exit 1)' || fail "a withdrawn condition exited $STATUS"
# Deliberate and conservative: the operator authorized a plan whose printed
# readiness word was `Ready with conditions`, and that word is no longer the
# true one. "Any plan change invalidates that authorization" — not "any change
# for the worse".
assert_refusal withdrawn release_condition_changed 'a withdrawn condition'
grep -Fq '"state": "withdrawn"' "$TMP/withdrawn.txt" \
  && pass 'a condition that disappeared is reported as withdrawn, not silently accepted' \
  || fail 'the withdrawn condition was not classified as withdrawn'
assert_untouched 'a withdrawn condition'

# ------------------------------------------------------------ uncheckability
say 'the manifest is ABSENT from the gate-time report'
DUO_CAPS_AFTER=gone DUO_CAPS_AFTER_CALL=2 release "gone" --yes --format=json
STATUS=$?
[ "$STATUS" = 1 ] && pass 'an absent claim refuses (exit 1)' || fail "an absent claim exited $STATUS"
assert_refusal gone release_condition_uncheckable 'an absent manifest'
grep -Fq '"state": "absent_from_report"' "$TMP/gone.txt" \
  && pass 'the diagnostics say the claim is gone rather than pretending it still holds' \
  || fail 'the refusal does not say the manifest is absent'
assert_untouched 'an absent manifest'

say 'the gate-time report carries prose with no subject to re-probe'
DUO_CAPS_AFTER=blind DUO_CAPS_AFTER_CALL=2 release "blind" --yes --format=json
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a subject-less condition refuses (exit 1)' || fail "a subject-less condition exited $STATUS"
assert_refusal blind release_condition_uncheckable 'a condition with no subject'
grep -Fq '"state": "no_subject"' "$TMP/blind.txt" \
  && pass 'an UNCHECKABLE condition blocks (docs/product-spec.md:302-303), it is never skipped' \
  || fail 'the subject-less condition was not classified as uncheckable'
assert_untouched 'a condition with no subject'

# ------------------------------------------------------ the reviewed library
say 'the reviewed disposition library moves between freeze and gate'
DUO_CAPS_AFTER=skew DUO_CAPS_AFTER_CALL=2 release "skew" --yes --format=json
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a moved reviewed library refuses (exit 1)' || fail "a moved library exited $STATUS"
grep -Fq '[release_evidence_not_current]' "$TMP/skew.txt" \
  && pass 'a library that moved inside the window names release_evidence_not_current' \
  || { fail 'the refusal did not name release_evidence_not_current'; sed -n '1,40p' "$TMP/skew.txt" >&2; }
grep -Fq '"class": "evidence_not_current"' "$TMP/skew.txt" \
  && pass 'the failure class is evidence_not_current — the second class nothing could reach before' \
  || fail 'the moved library did not carry the evidence_not_current failure class'
grep -Fq '"next_action": "requalify"' "$TMP/skew.txt" \
  && pass 'a moved reviewed library answers requalify' \
  || fail 'the moved library did not answer requalify'
assert_untouched 'a moved reviewed library'

# --------------------------------------------------------------- happy path
say 'nothing changed during the window'
DUO_PLAN_AFTER=plan-converged DUO_PLAN_AFTER_CALL=3 release "clean" --yes --format=json
STATUS=$?
[ "$STATUS" = 0 ] && pass 'an unchanged target releases (exit 0)' \
  || { fail "a clean release exited $STATUS"; sed -n '1,40p' "$TMP/clean.txt" >&2; }
CAPS=$(grep -c 'duo capabilities .*--operation=promote' "$DUO_CALLS" || true)
[ "$CAPS" = 2 ] \
  && pass 'exactly TWO capability reads: one at freeze, one at the mutation gate' \
  || fail "expected 2 duo capabilities --operation=promote calls, saw $CAPS"
# Identical argv on both reads, or the gate would be comparing the answers to
# two different questions (AssessCommand::capabilityReport()).
UNIQUE=$(grep 'duo capabilities .*--operation=promote' "$DUO_CALLS" | sort -u | wc -l | tr -d ' ')
[ "$UNIQUE" = 1 ] \
  && pass 'freeze and gate observe through IDENTICAL argv, --adoption-preview included' \
  || { fail 'the freeze-time and gate-time capability reads used different arguments'
       grep 'duo capabilities' "$DUO_CALLS" | sort -u >&2; }
grep -Fq '"conditions_rechecked"' "$TMP/clean.txt" \
  && pass 'the released outcome records the recheck' \
  || fail 'the released outcome carries no conditions_rechecked block'
python3 - "$TMP/clean.txt" <<'PY' || fail 'the conditions_rechecked block is not the documented record'
import json, re, sys
text = open(sys.argv[1]).read()
start = text.rindex('{\n    "conditions_rechecked"')
doc = json.loads(text[start:text.rindex('}') + 1])
record = doc['conditions_rechecked']
assert doc['status'] == 'released', doc['status']
assert sorted(record) == ['at', 'checked', 'conditions', 'manifests'], sorted(record)
assert re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ', record['at']), record['at']
assert record['conditions'] == 1, record['conditions']
assert record['manifests'] == ['core', 'sample-adapter'], record['manifests']
print('ok: the recheck record names its instant, its count and the claims it re-observed')
PY

say 'the released human view stays bounded'
DUO_PLAN_AFTER=plan-converged DUO_PLAN_AFTER_CALL=3 release "cleanhuman" --yes
LINES=$(grep -c 'conditions rechecked at' "$TMP/cleanhuman.txt" || true)
[ "$LINES" = 1 ] \
  && pass 'the human view gains EXACTLY one recheck line' \
  || fail "expected exactly one 'conditions rechecked at' line, saw $LINES"
# Scoped to the OUTCOME, which is everything from `released to fixture`
# onward. The authorization page above it legitimately names the claim beside
# each in-scope surface — that list is bounded by the release's own scope,
# which is the §4.6 test. The outcome's recheck line is bounded by the site's
# whole adapter set, so it prints a count.
# `release()` appends stderr, so the driver's own transport WARN is dropped
# here: it is not part of the outcome projection.
sed -n '/^released to fixture/,$p' "$TMP/cleanhuman.txt" | grep -v '^WARN ' | grep -v '^$' \
  > "$TMP/cleanhuman.outcome"
grep -Fq 'sample-adapter' "$TMP/cleanhuman.outcome" \
  && fail 'the released view enumerated the adapter claims: MUP §4.6 forbids a listing bounded by the site' \
  || pass 'it is a COUNT, never an enumeration bounded by the site adapter set'
[ "$(wc -l < "$TMP/cleanhuman.outcome" | tr -d ' ')" = 2 ] \
  && pass 'the released outcome is still two lines: the release word and the bounded recheck count' \
  || { fail 'the released outcome grew beyond the one bounded line this change adds'
       cat "$TMP/cleanhuman.outcome" >&2; }

printf '\n'
if [ "$FAILURES" -eq 0 ]; then
  printf 'PASS: regress_release_condition_gate\n'
  exit 0
fi
printf 'FAIL: regress_release_condition_gate (%s failure(s))\n' "$FAILURES" >&2
exit 1
