#!/usr/bin/env bash
# Regression — the public release surface cannot bypass the externally
# authorized mutation gate. Legacy release remains a read-only plan renderer;
# signed execute owns the gate-time condition recheck exercised by
# regress_release_stage_prepare.sh. AuthorizationPlan's complete condition
# matrix remains covered by regress_authorization_plan.php.
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
# This suite drives the public CLI over a local transport and proves a legacy
# `--yes` invocation cannot reach even the first planning read, much less
# `promotion-begin`, `deploy`, or `apply`.
#
# Offline: no docker, no WordPress, no network, no target.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/wprism-release-condition-gate.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT INT TERM

FAILURES=0
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; FAILURES=$((FAILURES + 1)); }
say()  { printf '\n== %s ==\n' "$*"; }

printf '== syntax ==\n'
for file in "$ROOT/cli/wprism" \
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
export WPRISM_FIXTURES="$TMP/site/fixtures"
export WPRISM_SITE_REPO="$SITE"
export WPRISM_CALLS="$TMP/calls.txt"
PATH="$TMP/site/bin:$PATH"
export PATH

wprism() {
  local out="$1"; shift
  ( cd "$SITE" && php "$ROOT/cli/wprism" --envs-file="$TMP/site/envs.json" "$@" ) \
    > "$out" 2> "$out.err"
}

# The reviewed contract, through the real propose -> review -> accept path.
wprism "$TMP/propose.txt" contract fixture propose \
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
' "$SITE/.wprism/contract/fixture/proposed.json"
wprism "$TMP/accept.txt" contract fixture accept \
  || { fail 'contract accept failed'; cat "$TMP/accept.txt.err" >&2; }

# release <name> [args...]: resets both fixture call counters and the recorded
# call log, so `caps-calls` and the mutation assertion are about THIS run.
release() {
  local name="$1"; shift
  rm -f "$WPRISM_FIXTURES/plan-calls" "$WPRISM_FIXTURES/caps-calls" "$WPRISM_CALLS"
  wprism "$TMP/$name.txt" release fixture "$@"
  local status=$?
  cat "$TMP/$name.txt.err" >> "$TMP/$name.txt"
  return $status
}

# The target was NOT touched: promotion never began, no lifecycle phase ran,
# no apply ran. Read off the fake wp's own call log, not off the source.
#
# `wprism compile` is deliberately not in this list. It is a read — the content
# address `prepare()` binds the plan to, and the gate re-reads to compare —
# and it runs before the freeze on every release, `--plan-only` included. The
# three verbs below are the ones that begin a production-visible mutation.
assert_untouched() {
  local what="$1"
  if [ -f "$WPRISM_CALLS" ] && grep -Eq 'wprism (promotion-begin|deploy|apply) ' "$WPRISM_CALLS"; then
    fail "$what refused, but the target was already being mutated"
    grep -Eo 'wprism (promotion-begin|deploy|apply) ' "$WPRISM_CALLS" | sort -u >&2
  else
    pass "$what refuses BEFORE the first production-visible call: the target is untouched"
  fi
}

# ------------------------------------------------ the frozen plan's own rows
say 'the frozen plan carries machine-checkable condition rows, not prose'
release "planonly" --plan-only --format=json
grep -Eq 'condition: plugin_version_mismatch — sample-adapter in 10\.0\.0-11\.0\.0 — 10\.4\.2 — mutation gate' \
  "$TMP/planonly.txt" \
  && pass 'the authorization page prints the four-part condition line docs/guides/release.md:148 documents' \
  || { fail 'the condition line is not the documented row'; grep -n 'condition:' "$TMP/planonly.txt" >&2; }

# ------------------------------------------------ public mutation retirement
say 'legacy --yes cannot enter the mutation gate'
WPRISM_CAPS_AFTER=moved WPRISM_CAPS_AFTER_CALL=1 release "legacy" --yes --format=json
STATUS=$?
[ "$STATUS" = 1 ] && pass 'legacy --yes refuses (exit 1)' || fail "legacy --yes exited $STATUS"
grep -Fq 'release_external_authorization_required' "$TMP/legacy.txt" \
  && pass 'the refusal requires an externally signed release execute' \
  || { fail 'legacy --yes did not name the external-authorization boundary'; sed -n '1,30p' "$TMP/legacy.txt" >&2; }
[ ! -e "$WPRISM_FIXTURES/caps-calls" ] \
  && pass 'legacy --yes refuses before even a capability-policy read' \
  || fail 'legacy --yes entered the old freeze/confirmation condition window'
assert_untouched 'legacy --yes'

printf '\n'
if [ "$FAILURES" -eq 0 ]; then
  printf 'PASS: regress_release_condition_gate\n'
  exit 0
fi
printf 'FAIL: regress_release_condition_gate (%s failure(s))\n' "$FAILURES" >&2
exit 1
