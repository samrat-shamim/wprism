#!/usr/bin/env bash
# Regression — round-3 MUP §2.6, §3.1, §3.4: the application contract's
# propose -> review -> accept lifecycle through `duo contract <env>`.
#
# Four properties, each of which is a way the contract could stop being a
# reviewed statement about this site:
#
#   1. A generated proposal is NEVER authority. It carries an unreviewed
#      external-effect placeholder that `ApplicationContract::validate()`
#      refuses, so accepting one unread is impossible by construction rather
#      than discouraged in a guide.
#   2. `accept` refuses a STALE proposal instead of reconciling it. The bind
#      is over the site's facts, not the clock: two assessments of an
#      unchanged site differ only by `generated_at`, and treating that as
#      drift would make accept permanently impossible.
#   3. `accept` refuses to overwrite a contract that MOVED under it. The
#      review step is minutes long and `.duo/contract/` is a working tree two
#      people can sit in; a lost reviewed declaration is exactly the kind of
#      unknown §1.6 exists to keep out of production.
#   4. Both documents land as canonical JSON and are STAGED, never committed.
#      The commit is the reviewer's signature.
#
# Offline: no docker, no WordPress, no network, no target.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/duo-contract-accept.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT INT TERM

FAILURES=0
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; FAILURES=$((FAILURES + 1)); }
say()  { printf '\n== %s ==\n' "$*"; }

php "$ROOT/sandbox/tests/fixtures/assess/make-fixture.php" "$TMP/site" >/dev/null \
  || { echo "FAIL: could not build the assess fixture" >&2; exit 1; }

SITE="$TMP/site/repo"
CONTRACT_DIR="$SITE/.duo/contract"
# The proposal is per environment (DUO-3503): `.duo/contract/<env>/`,
# beside the per-site contract.json and projection.json, never in place
# of them. This suite drives the fixture's one env, `fixture`.
PROPOSAL="$CONTRACT_DIR/fixture/proposed.json"
# The boundary every product-initialized site repository carries
# (InitRepositoryBoundary::ensure_gitignore(), sandbox/site-repo.gitignore.template):
# Duo's whole private working area is ignored. accept must still stage the
# two review artifacts through it — the case grind_mup.sh step 4 hit live.
printf '/.tmp*\n/.duo/\n' > "$SITE/.gitignore"
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

# Apply the human review MUP §3.4 requires: decide the lifecycle window,
# decide every unresolved surface, and declare one journey.
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
    "id" => "home", "url" => "/", "expect_status" => 200,
    "expect_contains" => "Sample", "affected_surfaces" => ["post_type:page"],
]];
$proposal["contract"] = $contract;
file_put_contents($path, json_encode($proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
' "$1"
}

# ------------------------------------------------------------------- propose
say 'propose'
duo "$TMP/propose.txt" contract fixture propose
STATUS=$?
[ "$STATUS" = 0 ] && pass 'propose exits 0' || { fail "propose exited $STATUS"; cat "$TMP/propose.txt.err" >&2; }
[ -f "$PROPOSAL" ] && pass 'propose writes .duo/contract/fixture/proposed.json' \
  || fail 'propose wrote no proposal'
[ -f "$CONTRACT_DIR/contract.json" ] \
  && fail 'propose wrote an accepted contract, which is not its job' \
  || pass 'propose writes no contract.json — a proposal is not authority'
grep -Fq 'a proposal is not authority' "$TMP/propose.txt" \
  && pass 'propose says out loud that it granted nothing' \
  || fail 'propose did not state that a proposal is not authority'
grep -Fq 'review required:' "$TMP/propose.txt" \
  && pass 'propose lists what a human must still decide' \
  || fail 'propose listed no review items'

# ------------------------------------------------- accepting unread refuses
say 'an unreviewed proposal cannot become authority'
duo "$TMP/accept-unread.txt" contract fixture accept
STATUS=$?
[ "$STATUS" = 1 ] && pass 'accepting an unreviewed proposal refuses (exit 1)' \
  || fail "accepting an unreviewed proposal exited $STATUS"
grep -Fq 'external_effect_unreviewed' "$TMP/accept-unread.txt.err" \
  && pass 'the refusal names the unreviewed external effect by its own code' \
  || fail 'the unreviewed-effect refusal did not name its reason code'
[ -f "$CONTRACT_DIR/contract.json" ] \
  && fail 'a refused accept still wrote a contract' \
  || pass 'a refused accept leaves the repository untouched'

# ------------------------------------------------------------------- accept
say 'accept'
review_proposal "$PROPOSAL"
duo "$TMP/accept.txt" contract fixture accept
STATUS=$?
[ "$STATUS" = 0 ] && pass 'a reviewed proposal is accepted' \
  || { fail "accept exited $STATUS"; cat "$TMP/accept.txt.err" >&2; }
[ -f "$CONTRACT_DIR/contract.json" ] && pass 'accept writes contract.json' || fail 'accept wrote no contract'
[ -f "$CONTRACT_DIR/projection.json" ] && pass 'accept writes projection.json' || fail 'accept wrote no projection'

php -r '
require $argv[3] . "/agent/src/Kernel/Canon.php";
$fail = static function (string $m): void { fwrite(STDERR, "FAIL: $m\n"); exit(1); };
foreach ([$argv[1], $argv[2]] as $path) {
    $raw = (string) file_get_contents($path);
    $document = json_decode($raw, true);
    if (!is_array($document)) { $fail("$path is not a JSON object"); }
    if ($raw !== \Duo\Canon::encode($document)) { $fail(basename($path) . " is not canonical JSON on disk"); }
    if (str_contains($raw, "\r")) { $fail(basename($path) . " carries a CR"); }
}
$contract = json_decode((string) file_get_contents($argv[1]), true);
if (($contract["attestation"]["state"] ?? null) !== "unsigned") {
    $fail("this profile may only ever write an unsigned attestation");
}
$stated = $contract["contract_digest"];
unset($contract["contract_digest"]);
if ($stated !== "sha256:" . hash("sha256", \Duo\Canon::encode($contract))) {
    $fail("contract_digest does not bind its own bytes");
}
$projection = json_decode((string) file_get_contents($argv[2]), true);
if (($projection["format"] ?? null) !== "duo-site-capability-projection/v1") { $fail("wrong projection format"); }
if (($projection["contract_digest"] ?? null) !== $stated) {
    $fail("the projection does not cite the contract it was generated from");
}
// §3.4: a declaration never grants authority, but a REVIEWED declaration of
// a live effect does convert an unknown into a known bounded one. The
// lifecycle window must therefore read `manage`/`live` now, where the same
// surface read `block`/`unknown` in the assessment that proposed it.
$rows = [];
foreach ($projection["surfaces"] as $row) { $rows[$row["id"]] = $row; }
$managed = $rows["option_group:core:managed"] ?? null;
if ($managed === null) { $fail("the lifecycle window is missing from the projection"); }
if ($managed["handling"] !== "manage") { $fail("a reviewed live declaration did not unblock the lifecycle window"); }
if ($managed["operations"]["release"]["effect_containment"] !== "live") {
    $fail("the declared lifecycle window is not reported as live");
}
if ($managed["operations"]["release"]["effect_recovery_semantics"] !== "provider-state restorable") {
    $fail("the declared lifecycle window states no recovery semantics");
}
// An undeclared, unclassified surface stays blocked no matter what else was
// reviewed: a declaration cannot un-know an unknown.
$unknown = $rows["table:sample_log"] ?? null;
if ($unknown === null || $unknown["handling"] !== "block") { $fail("an unclassified surface stopped blocking"); }
foreach ($projection["surfaces"] as $row) {
    foreach ($row["operations"] as $operation => $p) {
        foreach (["Site-certified", "sandboxed", "compensatable"] as $never) {
            if (in_array($never, [$p["certification_provenance"], $p["effect_containment"],
                                  $p["effect_recovery_semantics"]], true)) {
                $fail("$never was emitted, and this profile can never earn it");
            }
        }
    }
}
echo "ok: both documents are canonical, digest-bound, and consistent with each other\n";
' "$CONTRACT_DIR/contract.json" "$CONTRACT_DIR/projection.json" "$ROOT" \
  || fail 'the accepted documents are wrong'

# ------------------------------------------------------------------ staging
say 'staged, never committed'
STAGED=$( cd "$SITE" && git diff --cached --name-only )
echo "$STAGED" | grep -Fq '.duo/contract/contract.json' \
  && pass 'contract.json is staged for commit' || fail 'contract.json was not staged'
echo "$STAGED" | grep -Fq '.duo/contract/projection.json' \
  && pass 'projection.json is staged for commit' || fail 'projection.json was not staged'
( cd "$SITE" && git check-ignore -q --no-index .duo/contract/contract.json ) \
  && pass 'the site boundary still ignores .duo/ — accept staged through it deliberately, not by loosening it' \
  || fail 'the fixture lost the /.duo/ boundary this case is about'
echo "$STAGED" | grep -Ev '^\.duo/contract/(contract|projection)\.json$' | grep -q . \
  && fail "accept staged more than the two review artifacts: $STAGED" \
  || pass 'nothing else under .duo/ was staged'
COMMITS=$( cd "$SITE" && git rev-list --count --all 2>/dev/null || echo 0 )
[ "$COMMITS" = 0 ] && pass 'accept made no commit — the commit is the reviewer signature' \
  || fail "accept created $COMMITS commit(s)"
grep -Fq 'staged for commit' "$TMP/accept.txt" \
  && pass 'accept says what it staged and what it left to the human' \
  || fail 'accept did not report its staging'

# --------------------------------------------------------------------- show
say 'show reads the repository, not the site'
: > "$DUO_CALLS"
duo "$TMP/show.txt" contract fixture show
STATUS=$?
[ "$STATUS" = 0 ] && pass 'show exits 0' || { fail "show exited $STATUS"; cat "$TMP/show.txt.err" >&2; }
[ -s "$DUO_CALLS" ] \
  && fail 'show contacted the target; committed review artifacts are a question about the repository' \
  || pass 'show contacts the target zero times'
grep -Fq 'attestation: unsigned' "$TMP/show.txt" \
  && pass 'show states the attestation state, which is the round s honesty property' \
  || fail 'show did not print the attestation state'
grep -Fq 'projection:' "$TMP/show.txt" \
  && pass 'show renders the generated projection beside the contract' \
  || fail 'show did not render the projection'

# ------------------------------------------------------------ stale proposal
say 'a stale proposal refuses rather than reconciling'
duo "$TMP/propose2.txt" contract fixture propose
review_proposal "$PROPOSAL"
# The site moves under the review: one plugin version changes. Nothing else,
# including the clock, may make accept refuse — and this must.
php -r '
$path = $argv[1];
$inventory = json_decode((string) file_get_contents($path), true);
$inventory["plugins"][0]["version"] = "10.9.9";
file_put_contents($path, json_encode($inventory, JSON_UNESCAPED_SLASHES));
' "$DUO_FIXTURES/inventory.json"
duo "$TMP/stale.txt" contract fixture accept
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a proposal made against a different site refuses (exit 1)' \
  || fail "a stale proposal exited $STATUS"
grep -Fq 'contract_proposal_stale' "$TMP/stale.txt.err" \
  && pass 'the refusal carries the reason code MUP §2.6 names' \
  || { fail 'the stale refusal did not name contract_proposal_stale'; cat "$TMP/stale.txt.err" >&2; }
grep -Fq 'propose again' "$TMP/stale.txt.err" \
  && pass 'the remedy is to propose again, never to reconcile silently' \
  || fail 'the stale refusal offered no remedy'

# Restore the site, and prove the SAME proposal then accepts — i.e. the bind
# is over the facts and the clock alone never makes a proposal stale.
php -r '
$path = $argv[1];
$inventory = json_decode((string) file_get_contents($path), true);
$inventory["plugins"][0]["version"] = "10.4.2";
file_put_contents($path, json_encode($inventory, JSON_UNESCAPED_SLASHES));
' "$DUO_FIXTURES/inventory.json"
duo "$TMP/reaccept.txt" contract fixture accept
STATUS=$?
[ "$STATUS" = 0 ] && pass 'the same proposal accepts once the site matches again — time alone is not drift' \
  || { fail "re-accept exited $STATUS"; cat "$TMP/reaccept.txt.err" >&2; }

# ------------------------------------------------ a library that moved (3484)
# The other half of DUO-3484. `duo assess` stays answerable while a checkout
# is ahead of a site it has not re-adopted yet (regress_assess_composition.sh
# holds that case); these two verbs are where the skew would be baked into a
# committed review artifact, so these two refuse.
#
# The reason it must be THESE verbs: fromAssessReport() copies the report's
# `evidence` block verbatim into contract.evidence_pins, and that block is
# assembled from both machines — the target's registry_sha256 beside the raw
# file hash of the HOST's dispositions. Under skew the accepted contract would
# record this checkout's provenance next to declarations every one of which
# came out of the target's capability reports.
say 'proposing or accepting across two reviewed libraries refuses'
PROPOSAL_BEFORE=$(cat "$PROPOSAL")
CONTRACT_BEFORE=$(cat "$CONTRACT_DIR/contract.json")

DUO_LIBRARY_SKEW=1 duo "$TMP/propose-skew.txt" contract fixture propose
STATUS=$?
[ "$STATUS" = 1 ] && pass 'propose refuses when the target answers from another reviewed library (exit 1)' \
  || fail "propose under a library mismatch exited $STATUS"
grep -Fq 'dispositions_mismatch' "$TMP/propose-skew.txt.err" \
  && pass 'the refusal names dispositions_mismatch' \
  || { fail 'the propose refusal did not name dispositions_mismatch'; cat "$TMP/propose-skew.txt.err" >&2; }
grep -Fq 're-adopt this environment from this checkout, or check out the revision the site was adopted from' \
  "$TMP/propose-skew.txt.err" \
  && pass 'and names BOTH remedies — a sha256 carries no ordering, so which side is ahead is not derivable' \
  || fail 'the refusal named no remedy, or only one direction of the skew'
[ "$PROPOSAL_BEFORE" = "$(cat "$PROPOSAL")" ] \
  && pass 'the refused propose left the existing proposal untouched' \
  || fail 'a refused propose still rewrote fixture/proposed.json'

DUO_LIBRARY_SKEW=1 duo "$TMP/accept-skew.txt" contract fixture accept
STATUS=$?
[ "$STATUS" = 1 ] && pass 'accept refuses the same way (exit 1)' \
  || fail "accept under a library mismatch exited $STATUS"
grep -Fq 'dispositions_mismatch' "$TMP/accept-skew.txt.err" \
  && pass 'accept names the cause' \
  || { fail 'the accept refusal did not name dispositions_mismatch'; cat "$TMP/accept-skew.txt.err" >&2; }
# The ordering assertion, and the reason the gate sits before the staleness
# bind: the dispositions block is inside the digest, so a moved checkout ALSO
# makes the stored proposal stale. Reporting that would tell the operator the
# site returned something different — about a site that did not move at all.
grep -Fq 'contract_proposal_stale' "$TMP/accept-skew.txt.err" \
  && fail 'accept blamed the site for a checkout that moved' \
  || pass 'accept reports the cause, not the staleness it also produces'
[ "$CONTRACT_BEFORE" = "$(cat "$CONTRACT_DIR/contract.json")" ] \
  && pass 'the refused accept left the accepted contract exactly as it was' \
  || fail 'a refused accept still rewrote contract.json'

# And the same proposal still accepts once the two libraries agree again —
# the gate is about the skew, and it closes when the skew does.
duo "$TMP/accept-agreed.txt" contract fixture accept
STATUS=$?
[ "$STATUS" = 0 ] && pass 'the same proposal accepts once the site answers from this checkout library again' \
  || { fail "re-accept after the skew closed exited $STATUS"; cat "$TMP/accept-agreed.txt.err" >&2; }

# ------------------------------------------------- a contract that moved
say 'a contract that moved under the review refuses'
duo "$TMP/propose3.txt" contract fixture propose
review_proposal "$PROPOSAL"
# Build a VALID but different contract, and arm the fake `wp` to land it
# during the capabilities call — i.e. inside accept's own read-modify-write
# window, which is the only place a compare-and-swap can be reached from a
# command line.
php -r '
require $argv[2] . "/agent/src/Kernel/Canon.php";
$contract = json_decode((string) file_get_contents($argv[1]), true);
$contract["site"]["name"] = "landed-by-another-reviewer";
unset($contract["contract_digest"]);
$contract["contract_digest"] = "sha256:" . hash("sha256", \Duo\Canon::encode($contract));
file_put_contents($argv[3], \Duo\Canon::encode($contract));
' "$CONTRACT_DIR/contract.json" "$ROOT" "$TMP/other-contract.json"
DUO_MUTATE_CONTRACT="$TMP/other-contract.json" duo "$TMP/cas.txt" contract fixture accept
STATUS=$?
[ "$STATUS" = 1 ] && pass 'accept refuses when the stored contract moved under it (exit 1)' \
  || fail "the compare-and-swap did not fire (exit $STATUS)"
grep -Fq 'contract_digest_stale' "$TMP/cas.txt.err" \
  && pass 'the refusal is the compare-and-swap, named' \
  || { fail 'the refusal was not contract_digest_stale'; cat "$TMP/cas.txt.err" >&2; }
grep -Fq 'landed-by-another-reviewer' "$CONTRACT_DIR/contract.json" \
  && pass 'the other reviewer s contract survived intact — nothing was silently discarded' \
  || fail 'the concurrent contract was overwritten'

# ------------------------------------------------------- grammar and wiring
say 'subcommand grammar'
duo "$TMP/nosub.txt" contract fixture
STATUS=$?
[ "$STATUS" = 1 ] && pass 'contract without a subcommand refuses' || fail "bare contract exited $STATUS"
duo "$TMP/badsub.txt" contract fixture demolish
STATUS=$?
[ "$STATUS" = 1 ] && pass 'an unknown subcommand refuses' || fail "an unknown subcommand exited $STATUS"
duo "$TMP/twosub.txt" contract fixture show propose
STATUS=$?
[ "$STATUS" = 1 ] && pass 'two subcommands refuse rather than last-wins' || fail "two subcommands exited $STATUS"
duo "$TMP/badsubjson.json" contract fixture demolish --format=json
STATUS=$?
if [ "$STATUS" = 1 ] && grep -Fq '"format":"duo-command-refusal/v1"' "$TMP/badsubjson.json"; then
  pass 'contract refusals use the common machine envelope under --format=json'
else
  fail 'a contract usage refusal produced no typed JSON envelope'
fi

grep -Fq "'contract' => cmd_contract(\$transport, \$extra)" "$ROOT/cli/duo" \
  && pass 'contract is registered in the dispatch match' \
  || fail 'contract is not registered in cli/duo dispatch'
grep -Fq 'duo contract <env> show|propose|accept' "$ROOT/cli/duo" \
  && pass 'contract appears in the public usage text' \
  || fail 'contract is missing from duo_usage()'

printf '\n'
if [ "$FAILURES" -ne 0 ]; then
  printf 'REGRESS_CONTRACT_ACCEPT FAILED (%d)\n' "$FAILURES" >&2
  exit 1
fi
printf 'REGRESS_CONTRACT_ACCEPT PASSED\n'
