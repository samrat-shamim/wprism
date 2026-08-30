#!/usr/bin/env bash
# Regression — round-3 MUP §2.3: `wprism release` answers every failure with
# EXACTLY ONE next action from the closed set
# `resume|reconcile|retry|recover|requalify|escalate`, and never with an
# action from the other closed set.
#
# The two sets are deliberately not interchangeable. A refusal BEFORE the
# authorization plan is frozen is an assessment gap and carries a §2.1 gap
# action (`classify`, `declare in contract`, `qualify in rehearsal`,
# `exclude`, `install adapter`, `certify adapter`, `provision env value`,
# `attest contract`, `nothing — supported` — T6 §3.6 added `certify adapter`
# and stopped EMITTING `qualify in rehearsal`, which stays in the set so an
# older stored projection still validates; the contract attestation signer
# added `attest contract`, which is in the set and emitted by NOTHING because
# the trust root ships empty and attesting is an organizational decision, not
# a next action);
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
# This drives the real `php cli/wprism release` over a `local` transport with a
# fake `wp` on PATH, not the command class in isolation: the verb reaching
# the dispatch match arm, `EnvironmentCommandPreflight::ENVIRONMENT_VERBS`
# admitting it, and `DriverCapabilityReport::requirements()` knowing the
# operation all live outside `ReleaseCommand`, and a suite that constructed
# the command by hand would pass with all three broken (issue #3344).
#
# Offline: no docker, no WordPress, no network, no target.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/wprism-release-next-action.XXXXXX")"
RESPONDER_PID=""
cleanup() {
  if [ -n "$RESPONDER_PID" ]; then
    kill "$RESPONDER_PID" 2>/dev/null || true
    wait "$RESPONDER_PID" 2>/dev/null || true
  fi
  rm -rf "$TMP"
}
trap cleanup EXIT INT TERM

FAILURES=0
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; FAILURES=$((FAILURES + 1)); }
say()  { printf '\n== %s ==\n' "$*"; }

printf '== syntax ==\n'
for file in "$ROOT/cli/wprism" \
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
export WPRISM_FIXTURES="$TMP/site/fixtures"
export WPRISM_SITE_REPO="$SITE"
export WPRISM_CALLS="$TMP/calls.txt"
PATH="$TMP/site/bin:$PATH"
export PATH
php "$ROOT/sandbox/tests/fixtures/release/journey-responder.php" "$TMP/journey-address" \
  >"$TMP/journey-responder.out" 2>"$TMP/journey-responder.err" &
RESPONDER_PID=$!
for _ in $(seq 1 100); do
  [ -s "$TMP/journey-address" ] && break
  sleep 0.01
done
[ -s "$TMP/journey-address" ] || { cat "$TMP/journey-responder.err" >&2; exit 1; }
export WPRISM_JOURNEY_URL="http://$(cat "$TMP/journey-address")/release-ready"

# wprism <stdout-file> [args...] -> exit code
wprism() {
  local out="$1"; shift
  ( cd "$SITE" && php "$ROOT/cli/wprism" --envs-file="$TMP/site/envs.json" "$@" ) \
    > "$out" 2> "$out.err"
}

# The reviewed contract this site releases under, produced through the real
# propose -> review -> accept path so the fixture cannot drift from what
# `wprism contract accept` actually writes.
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
$contract["declarations"]["journeys"] = [[
    "affected_surfaces" => ["plugin:unmanaged-widget", "post_type:page", "post_type:widget_item"],
    "expect_contains" => "release journey ready",
    "expect_status" => 200,
    "id" => "release-ready",
    "url" => getenv("WPRISM_JOURNEY_URL"),
]];
$proposal["contract"] = $contract;
file_put_contents($path, json_encode($proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
' "$SITE/.wprism/contract/fixture/proposed.json"
wprism "$TMP/accept.txt" contract fixture accept \
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
  rm -f "$WPRISM_FIXTURES/plan-calls"
  wprism "$TMP/$name.txt" release fixture "$@"
  local status=$?
  cat "$TMP/$name.txt.err" >> "$TMP/$name.txt"
  return $status
}

# ------------------------------------------------------------ --plan-only
say '--plan-only mutates nothing'
RELEASE_DIR="$SITE/.wprism/releases"
release "planonly" --plan-only
STATUS=$?
[ "$STATUS" = 0 ] && pass '--plan-only exits 0' || { fail "--plan-only exited $STATUS"; sed -n '1,30p' "$TMP/planonly.txt" >&2; }
grep -Fq 'Authorize this release to fixture?' "$TMP/planonly.txt" \
  && pass 'the rendered plan ends in the single authorization question' \
  || fail 'the plan did not end in its question'
[ -d "$RELEASE_DIR" ] && [ -n "$(ls -A "$RELEASE_DIR" 2>/dev/null)" ] \
  && fail '--plan-only wrote into .wprism/releases, which is a mutation of the site repository' \
  || pass '--plan-only writes no frozen plan'

# ------------------------------------------------ public mutation retirement
say 'legacy public mutation is unreachable'
: > "$WPRISM_CALLS"
release "legacy-json" --yes --format=json
STATUS=$?
[ "$STATUS" = 1 ] && pass 'legacy --yes refuses (exit 1)' \
  || fail "legacy --yes exited $STATUS"
grep -Eq '"reason_code": *"release_external_authorization_required"' "$TMP/legacy-json.txt" \
  && pass 'the JSON refusal names the external-authorization requirement' \
  || { fail 'legacy --yes did not emit its typed refusal'; sed -n '1,25p' "$TMP/legacy-json.txt" >&2; }
grep -Fq 'stage-source' "$TMP/legacy-json.txt" \
  && grep -Fq 'release prepare' "$TMP/legacy-json.txt" \
  && grep -Fq 'release execute' "$TMP/legacy-json.txt" \
  && pass 'the refusal gives the complete signed-release remediation' \
  || fail 'the refusal omitted part of stage-source -> prepare -> execute'
if grep -Eq 'promote|promotion-begin|apply-authored-state|code-stage' "$WPRISM_CALLS"; then
  fail 'legacy --yes reached a promotion primitive'
else
  pass 'legacy --yes never calls promote or another mutation primitive'
fi
[ -d "$RELEASE_DIR" ] && [ -n "$(ls -A "$RELEASE_DIR" 2>/dev/null)" ] \
  && fail 'legacy --yes froze an authorization plan before refusing' \
  || pass 'legacy --yes refuses before freezing a plan'

release "legacy-interactive"
STATUS=$?
[ "$STATUS" = 1 ] && pass 'legacy interactive release refuses without prompting (exit 1)' \
  || fail "legacy interactive release exited $STATUS"
grep -Fq 'release_external_authorization_required' "$TMP/legacy-interactive.txt" \
  && pass 'human output names the same typed refusal' \
  || fail 'human output did not name the external-authorization refusal'
grep -Fq 'Authorize this release' "$TMP/legacy-interactive.txt" \
  && fail 'legacy release still exposed an interactive authorization prompt' \
  || pass 'legacy release exposes no local authorization prompt'

# ------------------------------------------------------------- cli/wprism wiring
say 'cli/wprism wiring'
grep -Fq "'release' => cmd_release(\$transport, \$extra)" "$ROOT/cli/wprism" \
  && pass 'release is registered in the dispatch match' \
  || fail 'release is not registered in cli/wprism dispatch'
grep -Fq 'wprism release <env>' "$ROOT/cli/wprism" \
  && pass 'release appears in the public usage text' \
  || fail 'release is missing from wprism_usage()'
grep -Fq 'cmd_promote_authorized($driver, $args, $binding)' "$ROOT/cli/wprism" \
  && grep -Fq 'cmd_promote_internal($driver, $args, $frozen, $binding)' "$ROOT/cli/wprism" \
  && grep -Fq 'cmd_promote_internal($driver, $args, $frozen)' "$ROOT/cli/wprism" \
  && pass 'release carries its authorized tuple into the existing promote state machine' \
  || fail 'cmd_release does not compose the existing promote state machine through its authorized boundary'
for verb in verify recover rehearse; do
  grep -Fq "'$verb' => cmd_$verb(" "$ROOT/cli/wprism" \
    && pass "$verb is registered in the dispatch match" \
    || fail "$verb is not registered in cli/wprism dispatch"
  grep -Fq "wprism $verb" "$ROOT/cli/wprism" \
    && pass "$verb appears in the public usage text" \
    || fail "$verb is missing from wprism_usage()"
done
php -r '
require $argv[1] . "/cli/src/Command/EnvironmentCommandPreflight.php";
require $argv[1] . "/cli/src/Transport/EnvironmentDriver.php";
$verbs = \WPrism\Orchestrator\EnvironmentCommandPreflight::environmentVerbs();
$requirements = new ReflectionMethod(\WPrism\Orchestrator\DriverCapabilityReport::class, "requirements");
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
