#!/usr/bin/env bash
# Regression — round-3 MUP §2.3: `duo release` answers every failure with
# EXACTLY ONE next action from the closed set
# `resume|reconcile|retry|recover|requalify|escalate`, and never with an
# action from the other closed set.
#
# The two sets are deliberately not interchangeable. A refusal BEFORE the
# authorization plan is frozen is an assessment gap and carries a §2.1 gap
# action (`classify`, `declare in contract`, `qualify in rehearsal`,
# `exclude`, `install adapter`, `certify adapter`, `provision env value`,
# `nothing — supported` — T6 §3.6 added the sixth and stopped EMITTING the
# third, which stays in the set so an older stored projection still validates);
# only a failure AFTER the freeze carries a release next action. Mixing them
# would tell an operator to `retry` a site that needs a contract declaration,
# or to `declare in contract` a target that is mid-rollback.
#
# Two rows of the mapping are load-bearing and are asserted by name:
#   - an interrupted code lifecycle window is ALWAYS `recover`;
#   - an ambiguous commitment is ALWAYS `reconcile` and NEVER `retry`,
#     because retry reuses the same operation identity and is safe only after
#     durable receipt reconciliation proves the prior attempt wrote nothing.
#
# This drives the real `php cli/duo release` over a `local` transport with a
# fake `wp` on PATH, not the command class in isolation: the verb reaching
# the dispatch match arm, `EnvironmentCommandPreflight::ENVIRONMENT_VERBS`
# admitting it, and `DriverCapabilityReport::requirements()` knowing the
# operation all live outside `ReleaseCommand`, and a suite that constructed
# the command by hand would pass with all three broken (DUO-3344).
#
# Offline: no docker, no WordPress, no network, no target.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/duo-release-next-action.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT INT TERM

FAILURES=0
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; FAILURES=$((FAILURES + 1)); }
say()  { printf '\n== %s ==\n' "$*"; }

printf '== syntax ==\n'
for file in "$ROOT/cli/duo" \
  "$ROOT/cli/src/Command/ReleaseCommand.php" \
  "$ROOT/cli/src/Command/VerifyCommand.php" \
  "$ROOT/cli/src/Release/NextAction.php" \
  "$ROOT/cli/src/Release/ReleaseOutcome.php" \
  "$ROOT/sandbox/tests/fixtures/release/make-release-site.php"; do
  php -l "$file" >/dev/null || fail "php -l $file"
done
pass 'PHP syntax'

# ------------------------------------------------- the closed mapping itself
say 'the closed next-action set'
php "$ROOT/sandbox/tests/fixtures/release/next-action-checks.php" "$ROOT" \
  || fail 'the closed failure-class -> next-action mapping is wrong'

# ---------------------------------------------------------------- the fixture
php "$ROOT/sandbox/tests/fixtures/release/make-release-site.php" "$TMP/site" >/dev/null \
  || { echo "FAIL: could not build the release fixture" >&2; exit 1; }

SITE="$TMP/site/repo"
export DUO_FIXTURES="$TMP/site/fixtures"
export DUO_SITE_REPO="$SITE"
export DUO_CALLS="$TMP/calls.txt"
PATH="$TMP/site/bin:$PATH"
export PATH

# duo <stdout-file> [args...] -> exit code
duo() {
  local out="$1"; shift
  ( cd "$SITE" && php "$ROOT/cli/duo" --envs-file="$TMP/site/envs.json" "$@" ) \
    > "$out" 2> "$out.err"
}

# The reviewed contract this site releases under, produced through the real
# propose -> review -> accept path so the fixture cannot drift from what
# `duo contract accept` actually writes.
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
    // T6 SS3.6: an unmanaged plugin gets the ORDINARY decision an operator
    // makes for one -- runtime / preserve local, which projects Unsupported,
    // prints a meaning line and no next action, and sits outside every
    // release gate. Without it the row is unclassified/block and release
    // correctly refuses, which is the product working and not a fixture this
    // suite is about.
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

# assert_action <stdout+stderr file> <expected action> <forbidden action> <message>
assert_action() {
  local file="$1" want="$2" forbidden="$3" message="$4"
  if grep -Eq -- "next.action\"?:? *\"?$want" "$file"; then
    if [ -n "$forbidden" ] && grep -Eq -- "next.action\"?:? *\"?$forbidden" "$file"; then
      fail "$message (it also offered '$forbidden')"
    else
      pass "$message"
    fi
  else
    fail "$message (expected '$want')"
    sed -n '1,25p' "$file" >&2
  fi
}

# release <name> [args...] -> writes $TMP/<name>.txt and .err
release() {
  local name="$1"; shift
  rm -f "$DUO_FIXTURES/plan-calls"
  duo "$TMP/$name.txt" release fixture "$@"
  local status=$?
  cat "$TMP/$name.txt.err" >> "$TMP/$name.txt"
  return $status
}

# --------------------------------------------------------- pre-freeze refusal
say 'a pre-authorization refusal carries a gap action, never a next action'
RELEASE_DIR="$SITE/.duo/releases"
DUO_PLAN=plan-deletes release "deletes" --yes
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a plan with deletions and no --with-deletes refuses (exit 1)' \
  || fail "a plan with deletions exited $STATUS"
grep -Fq 'release_deletes_not_authorized' "$TMP/deletes.txt" \
  && pass 'the refusal names the deletion authorization gate by its own reason code' \
  || fail 'the deletion refusal did not name its reason code'
for action in resume reconcile retry recover requalify escalate; do
  if grep -Fq -- "next action: $action" "$TMP/deletes.txt"; then
    fail "a pre-authorization refusal offered the release next action '$action'"
  fi
done
pass 'a pre-authorization refusal offers no release next action at all'
[ -d "$RELEASE_DIR" ] && [ -n "$(ls -A "$RELEASE_DIR" 2>/dev/null)" ] \
  && fail 'a refused release still froze an authorization plan' \
  || pass 'a refused release freezes nothing'

# ---------------------------------------------- the §2.7 surface label line
say 'an unclean target names the WordPress surface, not a bucket'
DUO_PLAN=plan-drift release "unclean" --yes
STATUS=$?
[ "$STATUS" = 1 ] && pass 'an unclean target refuses before anything is authorized (exit 1)' \
  || fail "an unclean target exited $STATUS"
grep -Fq 'release_target_not_clean' "$TMP/unclean.txt" \
  && pass 'the refusal names release_target_not_clean' \
  || fail 'the unclean-target refusal did not name itself'
grep -Eq '^ +surface: ' "$TMP/unclean.txt" \
  && pass 'the drifted row carries the contract-resolved `surface:` line (MUP §2.7)' \
  || { fail 'no surface: line was rendered for the drifted row'; sed -n '1,20p' "$TMP/unclean.txt" >&2; }
grep -Fq 'surface: post_type:page' "$TMP/unclean.txt" \
  && pass 'the surface is resolved from the row path through the contract surface_labels map' \
  || fail 'the surface line did not resolve the row to its declared surface'

# ------------------------------------------------------------ --plan-only
say '--plan-only mutates nothing'
release "planonly" --plan-only
STATUS=$?
[ "$STATUS" = 0 ] && pass '--plan-only exits 0' || { fail "--plan-only exited $STATUS"; sed -n '1,30p' "$TMP/planonly.txt" >&2; }
grep -Fq 'Authorize this release to fixture?' "$TMP/planonly.txt" \
  && pass 'the rendered plan ends in the single authorization question' \
  || fail 'the plan did not end in its question'
[ -d "$RELEASE_DIR" ] && [ -n "$(ls -A "$RELEASE_DIR" 2>/dev/null)" ] \
  && fail '--plan-only wrote into .duo/releases, which is a mutation of the site repository' \
  || pass '--plan-only writes no frozen plan'

# ------------------------------------------------------ post-freeze failures
say 'every observed post-freeze failure maps to exactly one next action'

# incomplete_lifecycle -> recover. The lifecycle phase fails, and the target's
# own re-read then reports the interrupted window.
DUO_LIFECYCLE_EXIT=7 DUO_PLAN_AFTER=plan-incomplete-lifecycle DUO_PLAN_AFTER_CALL=3 \
  release "lifecycle" --yes --format=json
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a failed release exits 1' || fail "a failed release exited $STATUS"
assert_action "$TMP/lifecycle.txt" recover retry \
  'an incomplete lifecycle receipt is always `recover`'
grep -Eq '"status": *"failed"' "$TMP/lifecycle.txt" \
  && pass 'the outcome is recorded as failed, with its plan digest' \
  || fail 'the failure outcome did not use the failed vocabulary'

# incomplete_apply -> recover.
DUO_APPLY_EXIT=9 DUO_PLAN_AFTER=plan-incomplete-apply DUO_PLAN_AFTER_CALL=3 \
  release "apply" --yes --format=json
assert_action "$TMP/apply.txt" recover retry \
  'an interrupted authored-state transaction is `recover`'

# drift_detected -> reconcile, never retry.
DUO_APPLY_EXIT=9 DUO_PLAN_AFTER=plan-drift DUO_PLAN_AFTER_CALL=3 \
  release "drift" --yes --format=json
assert_action "$TMP/drift.txt" reconcile retry \
  'a target that changed outside Duo is `reconcile`, never `retry`'

# nothing_safe -> escalate. The promotion failed and the target reports a
# state this command cannot positively classify.
DUO_APPLY_EXIT=9 release "unclassified" --yes --format=json
assert_action "$TMP/unclassified.txt" escalate retry \
  'a failure that cannot be positively classified is `escalate`, never an automated action'

# plan_changed -> retry, and only because nothing was written. Call 2 is the
# re-verification `ReleaseCommand` performs immediately before the mutating
# call: call 1 is the plan the authorization was built from.
DUO_PLAN_AFTER=plan-drift DUO_PLAN_AFTER_CALL=2 release "changed" --yes --format=json
assert_action "$TMP/changed.txt" retry recover \
  'a target that moved between freeze and confirmation is `retry`; nothing was written'
grep -Fq 'plan_changed' "$TMP/changed.txt" \
  && pass 'the plan_changed refusal names itself' \
  || fail 'the re-verification refusal did not name plan_changed'

# ------------------------------------------------------- the successful path
say 'a successful release still runs promote, byte for byte, and then verifies'
DUO_PLAN_AFTER=plan-converged DUO_PLAN_AFTER_CALL=3 release "released" --yes
STATUS=$?
[ "$STATUS" = 0 ] && pass 'a clean release exits 0' \
  || { fail "a clean release exited $STATUS"; sed -n '1,40p' "$TMP/released.txt" >&2; }
# promote's own output bytes: release composes it, so these lines are promote's
# and must be unchanged. A release that printed its own version of them would
# mean the state machine had been forked.
PHASES=$(grep -E '^promote (phase|complete):' "$TMP/released.txt" | sed -e 's/^promote phase: //' -e 's/^promote complete: .*/complete/' | tr '\n' ' ')
[ "$PHASES" = "compile promotion-begin checkpoint lifecycle-retire lifecycle-activate apply complete " ] \
  && pass 'the existing promote phase sequence and its output bytes are unchanged' \
  || fail "promote's phase output changed: $PHASES"
grep -Fq 'database checkpoint retained:' "$TMP/released.txt" \
  && pass "promote's own checkpoint-retained line still prints" \
  || fail "promote's checkpoint-retained line is missing"
grep -Fq 'verify fixture: pass' "$TMP/released.txt" \
  && pass 'the release verifies afterwards and records the verdict (MUP §2.3 step 5)' \
  || fail 'a successful release did not run the verification step'
grep -Fq 'released to fixture' "$TMP/released.txt" \
  && pass 'the outcome uses the released vocabulary' \
  || fail 'the success outcome was not recorded'
[ -n "$(ls -A "$RELEASE_DIR" 2>/dev/null)" ] \
  && pass 'the authorization plan is durably written under .duo/releases' \
  || fail 'a released release froze no plan'

# ------------------------------------------------------------- cli/duo wiring
say 'cli/duo wiring'
grep -Fq "'release' => cmd_release(\$transport, \$extra)" "$ROOT/cli/duo" \
  && pass 'release is registered in the dispatch match' \
  || fail 'release is not registered in cli/duo dispatch'
grep -Fq 'duo release <env>' "$ROOT/cli/duo" \
  && pass 'release appears in the public usage text' \
  || fail 'release is missing from duo_usage()'
grep -Fq 'cmd_promote($driver, $args)' "$ROOT/cli/duo" \
  && pass 'release composes the EXISTING promote entry point rather than forking it' \
  || fail 'cmd_release does not inject cmd_promote'
for verb in verify recover rehearse; do
  grep -Fq "'$verb' => cmd_$verb(" "$ROOT/cli/duo" \
    && pass "$verb is registered in the dispatch match" \
    || fail "$verb is not registered in cli/duo dispatch"
  grep -Fq "duo $verb" "$ROOT/cli/duo" \
    && pass "$verb appears in the public usage text" \
    || fail "$verb is missing from duo_usage()"
done
php -r '
require $argv[1] . "/cli/src/Command/EnvironmentCommandPreflight.php";
require $argv[1] . "/cli/src/Transport/EnvironmentDriver.php";
$verbs = \Duo\Orchestrator\EnvironmentCommandPreflight::environmentVerbs();
$requirements = new ReflectionMethod(\Duo\Orchestrator\DriverCapabilityReport::class, "requirements");
foreach (["release", "verify", "recover", "rehearse"] as $verb) {
    if (!in_array($verb, $verbs, true)) { fwrite(STDERR, "FAIL: $verb is not an environment verb\n"); exit(1); }
    $requirements->invoke(null, $verb);
}
// Release composes promote, so it must demand exactly what promote demands:
// more would refuse a target promote can already reach, less would let it
// reach the mutation gate without the checkpoint the claim promises.
if ($requirements->invoke(null, "release") !== $requirements->invoke(null, "promote")) {
    fwrite(STDERR, "FAIL: release must demand exactly what promote demands\n"); exit(1);
}
// Verify is read-only over WP-CLI, like every other read-only passthrough.
if ($requirements->invoke(null, "verify") !== $requirements->invoke(null, "coverage")) {
    fwrite(STDERR, "FAIL: verify must demand exactly what the read-only passthroughs demand\n"); exit(1);
}
echo "ok: all four verbs are environment-bound and resolve through requirements()\n";
' "$ROOT" || fail 'the four new verbs are not wired through the preflight and driver tables'

printf '\n'
if [ "$FAILURES" -ne 0 ]; then
  printf 'REGRESS_RELEASE_NEXT_ACTION FAILED (%d)\n' "$FAILURES" >&2
  exit 1
fi
printf 'REGRESS_RELEASE_NEXT_ACTION PASSED\n'
