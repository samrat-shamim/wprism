#!/usr/bin/env bash
# Regression — DUO-3503: the contract proposal is PER ENVIRONMENT, and accept
# says so when it is handed one that is not.
#
# `.duo/contract/proposed.json` used to be one slot per repository holding a
# document that is environment-specific in two independent ways: it stamps
# `environment` from the report (ContractProposal.php:185) and binds an
# `assess_digest` that covers `env` plus `target.home`/`target.siteurl`
# (AssessReport.php:103, ContractProposal.php:113). `AssessCommand::
# writeLocalArtifacts()` writes it unconditionally on every `duo assess <env>`,
# so assessing a second environment silently overwrote a reviewed-but-unaccepted
# proposal for the first — unrecoverably, because `/.duo/` is inside the site
# repo's own ignore (InitRepositoryBoundary.php:247) so git holds no copy — and
# the next accept blamed the SITE with `contract_proposal_stale`: "the proposal
# was generated from a different assessment than the site returns now", about a
# site that had not moved.
#
# Four properties, each of which is a way that can come back:
#
#   1. The proposal lands under `.duo/contract/<env>/`, and the retired shared
#      slot is not written at all. The two per-site documents (`contract.json`,
#      `projection.json`) stay where they were: the contract is ONE reviewed
#      statement about this repository, and `environment_bindings.required[]`
#      is how it says what varies per environment.
#   2. Assessing a second environment leaves the first environment's reviewed
#      proposal BYTE-identical. This is the defect itself; the assertion fails
#      against the prior code because the file was overwritten.
#   3. A review that survived a foreign assess still accepts. Staleness is a
#      claim about the site, and the clobber used to make that claim false.
#   4. A proposal whose stamped environment is not the one being accepted is
#      refused by its own reason code — before the target is contacted, and
#      before the staleness comparison that would otherwise report the
#      consequence instead of the cause. Per-environment paths make this the
#      residual case only (a moved, copied or hand-edited file), which is
#      exactly the case where accepting a contract reviewed against another
#      environment is the harm.
#
# Plus the path-segment guard: the environment becomes a directory name, so
# `ContractStore` refuses one that is not a legal segment
# (`contract_environment_invalid`) rather than joining it into a path.
#
# Offline: no docker, no WordPress, no network, no target.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/duo-contract-multi-env.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT INT TERM

FAILURES=0
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; FAILURES=$((FAILURES + 1)); }
say()  { printf '\n== %s ==\n' "$*"; }

php "$ROOT/sandbox/tests/fixtures/assess/make-fixture.php" "$TMP/site" >/dev/null \
  || { echo "FAIL: could not build the assess fixture" >&2; exit 1; }

SITE="$TMP/site/repo"
CONTRACT_DIR="$SITE/.duo/contract"
# InitRepositoryBoundary::ensure_gitignore() / sandbox/site-repo.gitignore.template:
# Duo's whole private working area is ignored, which is why an overwritten
# proposal is unrecoverable and why accept must force-add the two per-site
# review artifacts through it.
printf '/.tmp*\n/.duo/\n' > "$SITE/.gitignore"
export DUO_FIXTURES="$TMP/site/fixtures"
export DUO_SITE_REPO="$SITE"
export DUO_CALLS="$TMP/calls.txt"
PATH="$TMP/site/bin:$PATH"
export PATH

# The fixture ships exactly one environment, `fixture`
# (sandbox/tests/fixtures/assess/make-fixture.php:96-105) — which is precisely
# why no existing suite could see this defect. Register a second one against
# the same target: both report the same site facts, so `env` is the ONLY thing
# that separates the two proposals, and any clobber is unambiguous.
php -r '
$path = $argv[1];
$registry = json_decode((string) file_get_contents($path), true);
$registry["envs"]["fixture2"] = $registry["envs"]["fixture"];
file_put_contents($path, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$TMP/site/envs.json" || { echo "FAIL: could not register the second environment" >&2; exit 1; }

# duo <stdout-file> [args...] -> exit code
duo() {
  local out="$1"; shift
  ( cd "$SITE" && php "$ROOT/cli/duo" --envs-file="$TMP/site/envs.json" "$@" ) \
    > "$out" 2> "$out.err"
}

# The human review MUP §3.4 requires, performed as the edit it consists of:
# the generated `code-lifecycle-window` entry arrives `decided_by: unresolved`
# and ApplicationContract::validate() refuses to accept it that way. Identical
# to regress_contract_accept.sh's, so a proposal reviewed here is accept-ready
# in every respect except the environment it is stamped for.
review_proposal() {
  php -r '
$path = $argv[1];
$proposal = json_decode((string) file_get_contents($path), true);
$contract = $proposal["contract"];
foreach ($contract["declarations"]["external_effects"] as $index => $effect) {
    if (($effect["decided_by"] ?? null) !== "unresolved") { continue; }
    $contract["declarations"]["external_effects"][$index]["decided_by"] = "operator";
    $contract["declarations"]["external_effects"][$index]["reason"] =
        "reviewed 2026-08-17: nothing this site activates sends mail, calls a payment API, or fires a webhook";
    $contract["declarations"]["external_effects"][$index]["decided_at"] = "2026-08-17T09:02:11Z";
}
foreach ($contract["declarations"]["surfaces"] as $index => $surface) {
    if (($surface["decided_by"] ?? null) !== "unresolved") { continue; }
    $contract["declarations"]["surfaces"][$index]["decided_by"] = "operator";
    if (strpos((string) ($surface["id"] ?? ""), "plugin:") === 0) {
        $contract["declarations"]["surfaces"][$index]["state_class"] = "runtime";
        $contract["declarations"]["surfaces"][$index]["handling"] = "preserve local";
    }
}
$contract["declarations"]["journeys"] = [[
    "id" => "home", "url" => "/", "expect_status" => 200,
    "expect_contains" => "Sample", "affected_surfaces" => ["post_type:page"],
]];
$proposal["contract"] = $contract;
file_put_contents($path, json_encode($proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
' "$1"
}

# jq is not a dependency of the offline corpus; read one scalar with php.
field() { php -r '
$d = json_decode((string) file_get_contents($argv[1]), true);
foreach (explode(".", $argv[2]) as $k) { $d = $d[$k] ?? null; }
echo is_scalar($d) ? (string) $d : "";
' "$1" "$2"; }

PROPOSAL_A="$CONTRACT_DIR/fixture/proposed.json"
PROPOSAL_B="$CONTRACT_DIR/fixture2/proposed.json"

# ------------------------------------------------- 1. the path carries the env
say 'the proposal is written per environment'
duo "$TMP/assess-a.txt" assess fixture
STATUS=$?
[ "$STATUS" = 3 ] && pass 'assess fixture completes with its readiness-gap exit' \
  || { fail "assess fixture exited $STATUS"; cat "$TMP/assess-a.txt.err" >&2; }
[ -f "$PROPOSAL_A" ] && pass 'assess writes .duo/contract/fixture/proposed.json' \
  || fail 'assess wrote no proposal under .duo/contract/fixture/'
[ -e "$CONTRACT_DIR/proposed.json" ] \
  && fail 'the retired per-repository slot .duo/contract/proposed.json was written' \
  || pass 'the retired per-repository slot .duo/contract/proposed.json is not written'
grep -Fq 'proposed contract written: .duo/contract/fixture/proposed.json' "$TMP/assess-a.txt" \
  && pass 'and the path assess PRINTS is the path assess WROTE' \
  || { fail 'assess printed a proposal path other than the one it wrote'; cat "$TMP/assess-a.txt" >&2; }
[ "$(field "$PROPOSAL_A" environment)" = fixture ] \
  && pass 'the document stamps the environment its directory names' \
  || fail "the proposal under fixture/ is stamped '$(field "$PROPOSAL_A" environment)'"

# ------------------------------------------ 2. a foreign assess does not clobber
say 'assessing another environment leaves the review in flight alone'
review_proposal "$PROPOSAL_A"
REVIEWED_A="$(cat "$PROPOSAL_A" 2>/dev/null)"
# Asserted, not assumed: an empty capture would make the byte-comparison below
# pass vacuously against a build that never wrote this path at all.
[ -n "$REVIEWED_A" ] && pass 'the reviewed proposal has content to be clobbered' \
  || fail 'there is no reviewed proposal to compare — the rest of this section proves nothing'

duo "$TMP/assess-b.txt" assess fixture2
STATUS=$?
[ "$STATUS" = 3 ] && pass 'assess fixture2 completes with its readiness-gap exit' \
  || { fail "assess fixture2 exited $STATUS"; cat "$TMP/assess-b.txt.err" >&2; }
[ -f "$PROPOSAL_B" ] && pass 'assess fixture2 writes its OWN proposal' \
  || fail 'assess fixture2 wrote no proposal under .duo/contract/fixture2/'
# The defect, stated as an assertion. Against the prior code this file was the
# same file, so the reviewed bytes were gone.
[ "$REVIEWED_A" = "$(cat "$PROPOSAL_A")" ] \
  && pass "assess fixture2 left fixture's reviewed proposal byte-identical" \
  || fail "assess fixture2 overwrote fixture's reviewed proposal"
[ "$(field "$PROPOSAL_B" environment)" = fixture2 ] \
  && pass 'the second proposal is stamped for its own environment' \
  || fail "the proposal under fixture2/ is stamped '$(field "$PROPOSAL_B" environment)'"
# Two environments, two digests: `env` is inside the digest, which is why one
# slot could never have held both honestly.
[ "$(field "$PROPOSAL_A" assess_digest)" != "$(field "$PROPOSAL_B" assess_digest)" ] \
  && pass 'the two proposals bind different assess digests — env is inside the digest' \
  || fail 'the two environments produced the same assess digest'

# ---------------------------------------------- 3. the surviving review accepts
say 'the reviewed proposal still accepts after the other environment was assessed'
duo "$TMP/accept-a.txt" contract fixture accept
STATUS=$?
[ "$STATUS" = 0 ] && pass 'accept fixture exits 0' \
  || { fail "accept fixture exited $STATUS"; cat "$TMP/accept-a.txt.err" >&2; }
[ -f "$CONTRACT_DIR/contract.json" ] \
  && pass 'accept writes the per-site contract.json' || fail 'accept wrote no contract.json'
[ -f "$CONTRACT_DIR/projection.json" ] \
  && pass 'accept writes the per-site projection.json' || fail 'accept wrote no projection.json'
# The two per-site documents did NOT move into an environment directory: the
# contract is one reviewed statement about this repository, and
# `environment_bindings.required[]` is how it names what varies per environment
# (ApplicationContract.php:428-441).
[ -e "$CONTRACT_DIR/fixture/contract.json" ] \
  && fail 'contract.json was written per environment, which is not the tier it belongs to' \
  || pass 'contract.json stays per site, beside the environment directories'
[ -e "$CONTRACT_DIR/fixture/projection.json" ] \
  && fail 'projection.json was written per environment' \
  || pass 'projection.json stays per site'
grep -Fq 'contract_proposal_stale' "$TMP/accept-a.txt.err" \
  && fail 'accept blamed the site for staleness after an unrelated environment was assessed' \
  || pass 'accept never reported the site as moved — it had not'
DIGEST_ACCEPTED="$(field "$CONTRACT_DIR/contract.json" contract_digest)"
[ -n "$DIGEST_ACCEPTED" ] && pass 'the accepted contract carries a digest' \
  || fail 'the accepted contract has no readable digest'

# -------------------------------------------- 4. the environment bind at accept
say 'a proposal stamped for another environment is refused by its own code'
# The residual case per-environment paths cannot rule out: the file was moved,
# copied or hand-edited. Review fixture2's proposal first, so the ONLY thing
# wrong with it at fixture's path is the environment it was generated for —
# otherwise the refusal could be the unreviewed-effect one instead.
review_proposal "$PROPOSAL_B"
cp "$PROPOSAL_B" "$PROPOSAL_A"
: > "$DUO_CALLS"
duo "$TMP/accept-mismatch.txt" contract fixture accept
STATUS=$?
[ "$STATUS" = 1 ] && pass 'accept refuses a proposal from another environment (exit 1)' \
  || { fail "the mismatched accept exited $STATUS"; cat "$TMP/accept-mismatch.txt.err" >&2; }
grep -Fq 'contract_proposal_environment_mismatch' "$TMP/accept-mismatch.txt.err" \
  && pass 'the refusal names contract_proposal_environment_mismatch' \
  || { fail 'the mismatched accept did not name its own reason code'
       cat "$TMP/accept-mismatch.txt.err" >&2; }
# The cause, not the consequence: the same file is ALSO stale (its digest was
# taken against fixture2), and reporting that would send the operator to
# re-assess a site that is fine.
grep -Fq 'contract_proposal_stale' "$TMP/accept-mismatch.txt.err" \
  && fail 'the mismatched accept reported staleness — the consequence, not the cause' \
  || pass 'the mismatched accept reports the environment, not the staleness it also produces'
grep -Fq 'proposed_for=fixture2' "$TMP/accept-mismatch.txt.err" \
  && grep -Fq 'accepting=fixture' "$TMP/accept-mismatch.txt.err" \
  && pass 'the refusal names both environments' \
  || { fail 'the refusal did not name the two environments'
       cat "$TMP/accept-mismatch.txt.err" >&2; }
# Refused before the target is contacted, like the worktree check beside it:
# this is answerable from two strings already in hand.
[ ! -s "$DUO_CALLS" ] \
  && pass 'the refusal happens before the target is contacted — no wp call was made' \
  || { fail 'the mismatched accept contacted the target before refusing'
       cat "$DUO_CALLS" >&2; }
[ "$(field "$CONTRACT_DIR/contract.json" contract_digest)" = "$DIGEST_ACCEPTED" ] \
  && pass 'the accepted contract is exactly as it was — nothing was overwritten' \
  || fail 'the refused accept still moved the accepted contract'

# ------------------------------------------- the environment as a path segment
say 'an environment name that is not a legal path segment is refused'
# Two independent gates, and this suite asserts both. The registry refuses an
# illegal name at load (Registry.php:103), so the CLI never reaches the store
# with one; the store repeats the check because that is where the value stops
# being a name and becomes a directory.
php -r '
require $argv[1] . "/cli/src/Contract/ContractStore.php";
$store = new Duo\Orchestrator\ContractStore($argv[2]);
$failures = 0;
foreach (["../../escape", "../x", ".", "..", "", "has space", "-leading", "a/b"] as $illegal) {
    try {
        $store->proposalPath($illegal);
        fwrite(STDERR, "FAIL: proposalPath() accepted the illegal segment " . var_export($illegal, true) . "\n");
        $failures++;
    } catch (Duo\CommandRefusalException $e) {
        if ($e->reasonCode !== "contract_environment_invalid") {
            fwrite(STDERR, "FAIL: " . var_export($illegal, true) . " refused as " . $e->reasonCode . "\n");
            $failures++;
        }
    }
}
// The guidance names the charset the operator must rename to, because a
// refusal that does not say what a legal name looks like is a puzzle.
try {
    $store->proposalPath("../escape");
} catch (Duo\CommandRefusalException $e) {
    if (strpos($e->remediation, "[A-Za-z0-9][A-Za-z0-9._-]{0,63}") === false) {
        fwrite(STDERR, "FAIL: the remediation does not name the legal charset\n");
        $failures++;
    }
    if ($e->publicMessage !== "the environment name is not a legal path segment") {
        fwrite(STDERR, "FAIL: unexpected public message: " . $e->publicMessage . "\n");
        $failures++;
    }
}
// And the legal ones still resolve, so the guard is a boundary and not a wall.
foreach (["fixture", "prod-1", "a.b_c", "1", str_repeat("x", 64)] as $legal) {
    $store->proposalPath($legal);
}
exit($failures === 0 ? 0 : 1);
' "$ROOT" "$SITE" \
  && pass 'ContractStore refuses every illegal segment with contract_environment_invalid' \
  || fail 'ContractStore accepted an illegal environment path segment'

php -r '
$path = $argv[1];
$registry = json_decode((string) file_get_contents($path), true);
$registry["envs"]["../escape"] = $registry["envs"]["fixture"];
file_put_contents($argv[2], json_encode($registry, JSON_UNESCAPED_SLASHES));
' "$TMP/site/envs.json" "$TMP/illegal-envs.json"
( cd "$SITE" && php "$ROOT/cli/duo" --envs-file="$TMP/illegal-envs.json" assess fixture ) \
  > "$TMP/illegal.txt" 2> "$TMP/illegal.txt.err"
STATUS=$?
[ "$STATUS" != 0 ] \
  && pass 'and the registry refuses an illegal environment name upstream of the store' \
  || fail 'a registry carrying an illegal environment name was accepted'
grep -Fq 'A-Za-z0-9' "$TMP/illegal.txt.err" \
  && pass 'the registry refusal names the same charset' \
  || { fail 'the registry refusal did not name the legal charset'; cat "$TMP/illegal.txt.err" >&2; }

# -------------------------------------------------------------------- summary
printf '\n'
if [ "$FAILURES" = 0 ]; then
  printf 'REGRESS_CONTRACT_MULTI_ENV PASSED\n'
  exit 0
fi
printf 'REGRESS_CONTRACT_MULTI_ENV FAILED (%s)\n' "$FAILURES" >&2
exit 1
