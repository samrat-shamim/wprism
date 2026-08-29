#!/usr/bin/env bash
# Product-path regression for the public inert source stage and read-only
# release preparation contracts. The target's canonical checkout is measured
# before and after both commands; the only stage writes live under its private
# Git directory and every prepare read names the detached staged repository.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/wprism-release-stage-prepare.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT INT TERM

FAILURES=0
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; FAILURES=$((FAILURES + 1)); }

php -l "$ROOT/cli/wprism" >/dev/null || fail 'php -l cli/wprism'
php -l "$ROOT/cli/src/Command/StageSourceCommand.php" >/dev/null || fail 'php -l StageSourceCommand.php'
php -l "$ROOT/cli/src/Release/SourceStageReceipt.php" >/dev/null || fail 'php -l SourceStageReceipt.php'
php -l "$ROOT/cli/src/Release/ReleasePrepare.php" >/dev/null || fail 'php -l ReleasePrepare.php'

php -r '
require $argv[1] . "/cli/src/Command/ReleaseCommand.php";
$method = new ReflectionMethod(\WPrism\Orchestrator\ReleaseCommand::class, "authorizedVerificationOutcome");
$rechecked = ["at" => "2026-08-29T15:00:00Z", "checked" => 0, "conditions" => 0, "format" => "test"];
$digest = "sha256:" . str_repeat("a", 64);
$refused = $method->invoke(null, "fixture", $digest, $rechecked, static function (): array {
    throw new \WPrism\CommandRefusalException(
        "verify_fixture_unavailable",
        "fixture verification is unavailable",
        "preserve the fixture evidence"
    );
});
\WPrism\Orchestrator\ReleaseOutcome::validate($refused);
if ($refused["status"] !== "failed"
    || $refused["failure"]["class"] !== "nothing_safe"
    || $refused["failure"]["next_action"] !== "escalate"
    || $refused["failure"]["reason_code"] !== "verify_fixture_unavailable"
    || $refused["verify"] !== null) exit(1);
$report = ["format" => "wprism-verify-report/v1", "verdict" => "fail"];
$negative = $method->invoke(null, "fixture", $digest, $rechecked, static fn (): array => $report);
\WPrism\Orchestrator\ReleaseOutcome::validate($negative);
if ($negative["status"] !== "failed"
    || $negative["failure"]["reason_code"] !== "release_verification_failed"
    || $negative["verify"] !== $report) exit(1);
' "$ROOT" \
  && pass 'verification refusal and non-pass report become failed post-freeze outcomes' \
  || fail 'verification refusal or non-pass report was represented as successful'

php "$ROOT/sandbox/tests/fixtures/release/make-release-site.php" "$TMP/site" >/dev/null \
  || { echo 'FAIL: could not build release fixture' >&2; exit 1; }

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

# Build the accepted contract through the public review path before selecting
# the source commit: the receipt's source tree therefore binds the contract and
# operation-authority policy that prepare reads from this clean checkout.
wprism "$TMP/propose.txt" contract fixture propose \
  || { fail 'contract proposal failed'; cat "$TMP/propose.txt.err" >&2; }
php -r '
$path = $argv[1];
$proposal = json_decode((string) file_get_contents($path), true);
foreach ($proposal["contract"]["declarations"]["external_effects"] as $i => $effect) {
    if (($effect["decided_by"] ?? null) !== "unresolved") continue;
    $proposal["contract"]["declarations"]["external_effects"][$i]["decided_by"] = "operator";
    $proposal["contract"]["declarations"]["external_effects"][$i]["decided_at"] = "2026-08-29T15:00:00Z";
    $proposal["contract"]["declarations"]["external_effects"][$i]["reason"] = "reviewed for inert staging";
}
foreach ($proposal["contract"]["declarations"]["surfaces"] as $i => $surface) {
    if (($surface["decided_by"] ?? null) !== "unresolved") continue;
    $proposal["contract"]["declarations"]["surfaces"][$i]["decided_by"] = "operator";
    if (str_starts_with((string) ($surface["id"] ?? ""), "plugin:")) {
        $proposal["contract"]["declarations"]["surfaces"][$i]["state_class"] = "runtime";
        $proposal["contract"]["declarations"]["surfaces"][$i]["handling"] = "preserve local";
    }
}
file_put_contents($path, json_encode($proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$SITE/.wprism/contract/fixture/proposed.json"
wprism "$TMP/accept.txt" contract fixture accept \
  || { fail 'contract acceptance failed'; cat "$TMP/accept.txt.err" >&2; }

mkdir -p "$SITE/.wprism/authority"
php -r '
require $argv[1] . "/agent/src/Kernel/Canon.php";
$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$public = sodium_crypto_sign_publickey($pair);
$trust = [
    "format" => "wprism-operation-authorities/v1",
    "keys" => ["fixture-key" => [
        "actor" => "fixture-actor",
        "algorithm" => "ed25519",
        "grants" => ["business_owner", "declared_live_effect", "operator_confirmation"],
        "operations" => ["release"],
        "public_key" => base64_encode($public),
        "status" => "trusted",
    ]],
    "max_clock_skew_seconds" => 30,
    "max_ttl_seconds" => 900,
];
file_put_contents($argv[2], \WPrism\Canon::encode($trust));
file_put_contents($argv[3], base64_encode($secret));
' "$ROOT" "$SITE/.wprism/authority/authorities.json" "$TMP/authority.secret"
git -C "$SITE" add -A
git -C "$SITE" -c user.email=fixture@example.invalid -c user.name=fixture commit -q -m 'staged release input'

SOURCE_COMMIT="$(git -C "$SITE" rev-parse HEAD)"
SOURCE_TREE="$(git -C "$SITE" rev-parse HEAD^{tree})"
BRANCH="$(git -C "$SITE" symbolic-ref --quiet --short HEAD)"
git init --bare --initial-branch="$BRANCH" "$TMP/origin.git" >/dev/null 2>&1
git -C "$SITE" remote add stage-origin "$TMP/origin.git"
git -C "$SITE" push stage-origin "HEAD^:refs/heads/$BRANCH" >/dev/null 2>&1
git clone "$TMP/origin.git" "$TMP/target" >/dev/null 2>&1
git -C "$SITE" branch release-candidate HEAD
git -C "$SITE" push stage-origin release-candidate >/dev/null 2>&1
php -r '
$path = $argv[1]; $registry = json_decode((string) file_get_contents($path), true);
$registry["envs"]["fixture"]["repo_path"] = $argv[2];
file_put_contents($path, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$TMP/site/envs.json" "$TMP/target"

TARGET_HEAD_BEFORE="$(git -C "$TMP/target" rev-parse HEAD)"
TARGET_TREE_BEFORE="$(git -C "$TMP/target" rev-parse HEAD^{tree})"
TARGET_INDEX_BEFORE="$(git -C "$TMP/target" write-tree)"
TARGET_STATUS_BEFORE="$(git -C "$TMP/target" status --porcelain --untracked-files=all)"

wprism "$TMP/legacy-release.json" release fixture --yes --format=json
LEGACY_STATUS=$?
[ "$LEGACY_STATUS" = 1 ] \
  && grep -Fq 'release_external_authorization_required' "$TMP/legacy-release.json" \
  && ! grep -Eq 'wprism (promotion-begin|apply|deploy|code-stage)' "$WPRISM_CALLS" \
  && [ "$(git -C "$TMP/target" rev-parse HEAD)" = "$TARGET_HEAD_BEFORE" ] \
  && pass 'legacy interactive/--yes release refuses before promote; signed execute is the only mutation seam' \
  || fail 'legacy release mutation remained reachable or invoked promote'

wprism "$TMP/receipt.json" stage-source fixture --from=release-candidate \
  --operation=release-stage-prepare-fixture --format=json
STAGE_STATUS=$?
[ "$STAGE_STATUS" = 0 ] && pass 'stage-source exits 0' \
  || { fail "stage-source exited $STAGE_STATUS"; cat "$TMP/receipt.json.err" >&2; }
[ ! -s "$TMP/receipt.json.err" ] && pass 'stage-source success emits no competing stderr document' \
  || fail 'stage-source success emitted unexpected stderr'

php -r '
require $argv[1] . "/cli/src/Release/SourceStageReceipt.php";
$bytes = (string) file_get_contents($argv[2]);
$receipt = \WPrism\Orchestrator\SourceStageReceipt::fromBytes($bytes);
if ($receipt["format"] !== "wprism-source-stage-receipt/v1"
    || $receipt["source"]["commit"] !== $argv[3]
    || $receipt["source"]["tree"] !== $argv[4]
    || !str_starts_with($receipt["target"]["id"], "wprism-target:")) exit(1);
' "$ROOT" "$TMP/receipt.json" "$SOURCE_COMMIT" "$SOURCE_TREE" \
  && pass 'stage-source emits one canonical, digest-valid receipt bound to source and stable target identity' \
  || fail 'stage-source receipt did not validate'

TARGET_HEAD_AFTER="$(git -C "$TMP/target" rev-parse HEAD)"
TARGET_TREE_AFTER="$(git -C "$TMP/target" rev-parse HEAD^{tree})"
TARGET_INDEX_AFTER="$(git -C "$TMP/target" write-tree)"
TARGET_STATUS_AFTER="$(git -C "$TMP/target" status --porcelain --untracked-files=all)"
[ "$TARGET_HEAD_AFTER" = "$TARGET_HEAD_BEFORE" ] \
  && [ "$TARGET_TREE_AFTER" = "$TARGET_TREE_BEFORE" ] \
  && [ "$TARGET_INDEX_AFTER" = "$TARGET_INDEX_BEFORE" ] \
  && [ "$TARGET_STATUS_AFTER" = "$TARGET_STATUS_BEFORE" ] \
  && pass 'stage-source leaves canonical target HEAD, tree, index and worktree unchanged' \
  || fail 'stage-source changed the canonical target checkout'

wprism "$TMP/receipt-retry.json" stage-source fixture --from=release-candidate \
  --operation=release-stage-prepare-fixture --format=json
cmp -s "$TMP/receipt.json" "$TMP/receipt-retry.json" \
  && pass 'an exact stage retry returns the byte-identical durable receipt' \
  || fail 'an exact stage retry changed receipt bytes'

RECEIPT_DIGEST="$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["receipt_sha256"];' "$TMP/receipt.json")"
SITE_STATUS_BEFORE="$(git -C "$SITE" status --porcelain --untracked-files=all)"
sleep 1
wprism "$TMP/prepare.json" release fixture prepare --stage-receipt="$TMP/receipt.json" \
  --expected-stage-receipt-sha256="$RECEIPT_DIGEST" --format=json
PREPARE_STATUS=$?
[ "$PREPARE_STATUS" = 0 ] && pass 'release prepare exits 0' \
  || { fail "release prepare exited $PREPARE_STATUS"; cat "$TMP/prepare.json.err" >&2; }
[ ! -s "$TMP/prepare.json.err" ] && pass 'release prepare success emits exactly one stdout document' \
  || fail 'release prepare success emitted unexpected stderr'

php -r '
require $argv[1] . "/cli/src/Release/ReleasePrepare.php";
$bytes = (string) file_get_contents($argv[2]);
$document = json_decode($bytes, true);
\WPrism\Orchestrator\ReleasePrepare::validate($document);
if (\WPrism\Orchestrator\ReleasePrepare::encode($document) !== $bytes
    || $document["authorization_plan"]["format"] !== "wprism-authorization-plan/v1"
    || $document["request"]["expected_stage_receipt_sha256"] !== $argv[3]
    || $document["stage_receipt"]["source"]["commit"] !== $argv[4]
    || $document["authorization_plan"]["frozen_at"] === $document["stage_receipt"]["created_at"]
    || $document["target_id"] !== $document["stage_receipt"]["target"]["id"]
    || !preg_match("/^sha256:[a-f0-9]{64}$/D", $document["presented_plan_sha256"])
    || !preg_match("/^sha256:[a-f0-9]{64}$/D", $document["subject_sha256"])) exit(1);
' "$ROOT" "$TMP/prepare.json" "$RECEIPT_DIGEST" "$SOURCE_COMMIT" \
  && pass 'prepare emits a canonical complete subject while preserving authorization-plan/v1' \
  || fail 'release prepare document did not validate'

STAGE_REPO="$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["stage"]["repository_path"];' "$TMP/receipt.json")"
grep -Fq -- "wprism plan --repo=$STAGE_REPO" "$TMP/calls.txt" \
  && grep -Fq -- "wprism compile --repo=$STAGE_REPO" "$TMP/calls.txt" \
  && grep -Fq -- "wprism assess-inventory --repo=$STAGE_REPO" "$TMP/calls.txt" \
  && grep -Fq -- "wprism capabilities --repo=$STAGE_REPO" "$TMP/calls.txt" \
  && pass 'plan, compile, inventory and capability claims all read the inert staged repository' \
  || fail 'one or more prepare reads used the canonical target repository'

[ "$(git -C "$TMP/target" rev-parse HEAD)" = "$TARGET_HEAD_BEFORE" ] \
  && [ "$(git -C "$TMP/target" write-tree)" = "$TARGET_INDEX_BEFORE" ] \
  && [ "$(git -C "$TMP/target" status --porcelain --untracked-files=all)" = "$TARGET_STATUS_BEFORE" ] \
  && [ "$(git -C "$SITE" status --porcelain --untracked-files=all)" = "$SITE_STATUS_BEFORE" ] \
  && pass 'release prepare leaves target and local site repository unchanged' \
  || fail 'release prepare mutated the target or local site repository'

sleep 1
wprism "$TMP/prepare-retry.json" release fixture prepare --stage-receipt="$TMP/receipt.json" \
  --expected-stage-receipt-sha256="$RECEIPT_DIGEST" --format=json
php -r '
$first = json_decode((string) file_get_contents($argv[1]), true);
$second = json_decode((string) file_get_contents($argv[2]), true);
if ($first["plan_digest"] !== $second["plan_digest"]
    || $first["authorization_plan"]["frozen_at"] === $second["authorization_plan"]["frozen_at"]
    || $first["subject_sha256"] === $second["subject_sha256"]) exit(1);
' "$TMP/prepare.json" "$TMP/prepare-retry.json" \
  && pass 'a fresh prepare revalidates at current time while preserving the semantic plan identity' \
  || fail 'prepare reused staging time or changed the semantic plan identity'

wprism "$TMP/unexpected.json" release fixture prepare --stage-receipt="$TMP/receipt.json" \
  --expected-stage-receipt-sha256="sha256:$(printf '0%.0s' {1..64})" --format=json
[ "$?" = 1 ] && grep -Fq 'release_stage_receipt_unexpected' "$TMP/unexpected.json" \
  && pass 'an explicitly unexpected receipt digest refuses before preparation' \
  || fail 'an unexpected receipt digest did not fail closed'

printf 'tamper\n' > "$STAGE_REPO/untracked-stage-tamper"
wprism "$TMP/stage-tamper.json" release fixture prepare --stage-receipt="$TMP/receipt.json" \
  --expected-stage-receipt-sha256="$RECEIPT_DIGEST" --format=json
[ "$?" = 1 ] && grep -Fq 'release_stage_changed' "$TMP/stage-tamper.json" \
  && [ "$(git -C "$TMP/target" rev-parse HEAD)" = "$TARGET_HEAD_BEFORE" ] \
  && pass 'staged-worktree tamper refuses before canonical target mutation' \
  || fail 'staged-worktree tamper was not refused safely'
php -r '@unlink($argv[1]);' "$STAGE_REPO/untracked-stage-tamper"

cp "$TMP/receipt.json" "$TMP/receipt-tampered.json"
php -r '$p=$argv[1];$d=json_decode(file_get_contents($p),true);$d["source"]["tree"]=str_repeat("f",40);file_put_contents($p,json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");' "$TMP/receipt-tampered.json"
wprism "$TMP/receipt-tamper.json" release fixture prepare --stage-receipt="$TMP/receipt-tampered.json" \
  --expected-stage-receipt-sha256="$RECEIPT_DIGEST" --format=json
[ "$?" = 1 ] && grep -Fq 'release_stage_receipt_invalid' "$TMP/receipt-tamper.json" \
  && pass 'receipt tamper is rejected by canonical digest validation' \
  || fail 'receipt tamper did not fail closed'

SUBJECT_DIGEST="$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["subject_sha256"];' "$TMP/prepare.json")"
PRESENTATION_DIGEST="$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["presented_plan_sha256"];' "$TMP/prepare.json")"
PLAN_DIGEST="$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["plan_digest"];' "$TMP/prepare.json")"
AUTH_DIGEST="$(php -r '
require $argv[1] . "/agent/src/Kernel/Canon.php";
require $argv[1] . "/cli/src/Authority/OperationAuthorization.php";
$prepare = json_decode((string) file_get_contents($argv[2]), true);
$secret = base64_decode((string) file_get_contents($argv[3]), true);
$issued = time();
$statement = [
    "actor" => "fixture-actor",
    "expires_at" => gmdate("Y-m-d\\TH:i:s\\Z", $issued + 600),
    "issued_at" => gmdate("Y-m-d\\TH:i:s\\Z", $issued),
    "key_id" => "fixture-key",
    "nonce" => "release-stage-prepare-fixture-0001",
    "operation" => "release",
    "operation_id" => $prepare["operation_id"],
    "presentation_digest" => $prepare["presented_plan_sha256"],
    "subject_digest" => $prepare["subject_sha256"],
    "target_id" => $prepare["target_id"],
];
$envelope = \WPrism\Orchestrator\OperationAuthorization::sign($statement, $secret);
file_put_contents($argv[4], \WPrism\Canon::encode($envelope));
echo \WPrism\Orchestrator\OperationAuthorization::envelopeDigest($envelope);
' "$ROOT" "$TMP/prepare.json" "$TMP/authority.secret" "$TMP/authorization.json")"

run_execute() {
  local out="$1"
  local expected_subject="$2"
  wprism "$out" release fixture execute \
    --prepare="$TMP/prepare.json" --authorization="$TMP/authorization.json" \
    --expected-authorization-sha256="$AUTH_DIGEST" \
    --expected-subject-sha256="$expected_subject" \
    --expected-presentation-sha256="$PRESENTATION_DIGEST" \
    --expected-plan-digest="$PLAN_DIGEST" \
    --expected-stage-receipt-sha256="$RECEIPT_DIGEST" --format=json
}

TARGET_GIT_DIR="$(git -C "$TMP/target" rev-parse --absolute-git-dir)"
AUTHORIZATION_ROOT="$TARGET_GIT_DIR/wprism-control/authorizations"
authorization_count() {
  if [ ! -d "$AUTHORIZATION_ROOT" ]; then
    printf '0'
    return
  fi
  find "$AUTHORIZATION_ROOT" -mindepth 1 -maxdepth 1 -type d | wc -l | tr -d ' '
}

ZERO_DIGEST="sha256:$(printf '0%.0s' {1..64})"
run_execute "$TMP/execute-digest-refusal.json" "$ZERO_DIGEST"
[ "$?" = 1 ] && grep -Fq 'release_execute_digest_mismatch' "$TMP/execute-digest-refusal.json" \
  && [ "$(authorization_count)" = 0 ] \
  && pass 'execute refuses an explicit digest mismatch before authorization consumption' \
  || fail 'execute consumed or accepted an unexpected prepared-subject digest'

# The signed path, not legacy --yes, owns the mutation-gate capability
# re-observation. Change the staged-repository answer on execute's first read:
# the prepared condition must refuse before target-side consumption or source
# materialization. AuthorizationPlan's exhaustive moved/appeared/withdrawn/
# uncheckable matrix is covered by regress_authorization_plan.php.
rm -f "$WPRISM_FIXTURES/caps-calls"
export WPRISM_CAPS_AFTER=moved
export WPRISM_CAPS_AFTER_CALL=1
run_execute "$TMP/execute-condition-refusal.json" "$SUBJECT_DIGEST"
CONDITION_STATUS=$?
unset WPRISM_CAPS_AFTER WPRISM_CAPS_AFTER_CALL
[ "$CONDITION_STATUS" = 1 ] \
  && grep -Fq 'plan_changed' "$TMP/execute-condition-refusal.json" \
  && grep -Fq 'conditions_sha256' "$TMP/execute-condition-refusal.json" \
  && [ "$(authorization_count)" = 0 ] \
  && [ "$(git -C "$TMP/target" rev-parse HEAD)" = "$TARGET_HEAD_BEFORE" ] \
  && pass 'signed execute rechecks the condition digest and refuses drift before consumption or mutation' \
  || { fail 'signed execute let condition drift cross the mutation boundary'; cat "$TMP/execute-condition-refusal.json" >&2; }

printf 'tamper\n' > "$STAGE_REPO/untracked-execute-tamper"
run_execute "$TMP/execute-stage-refusal.json" "$SUBJECT_DIGEST"
[ "$?" = 1 ] && grep -Fq 'release_stage_changed' "$TMP/execute-stage-refusal.json" \
  && [ "$(authorization_count)" = 0 ] \
  && [ "$(git -C "$TMP/target" rev-parse HEAD)" = "$TARGET_HEAD_BEFORE" ] \
  && pass 'execute refuses staged-source drift before consumption or canonical mutation' \
  || fail 'execute crossed the mutation boundary with a changed source stage'
php -r '@unlink($argv[1]);' "$STAGE_REPO/untracked-execute-tamper"

git -C "$TMP/target" -c user.email=fixture@example.invalid -c user.name=fixture \
  commit --allow-empty -q -m 'unexpected target head drift'
run_execute "$TMP/execute-head-refusal.json" "$SUBJECT_DIGEST"
[ "$?" = 1 ] && grep -Fq 'release_stage_base_changed' "$TMP/execute-head-refusal.json" \
  && [ "$(authorization_count)" = 0 ] \
  && pass 'execute refuses canonical target HEAD drift before authorization consumption' \
  || fail 'execute consumed authority after canonical target HEAD drift'
git -C "$TMP/target" reset --hard "$TARGET_HEAD_BEFORE" >/dev/null

# Revoke the trusted key after the first signature verification but after all
# other planning reads and local evidence writes. The injected callable is a
# deterministic race seam on ReleaseCommand::run(); production leaves it null.
( cd "$SITE" && php -r '
require $argv[1] . "/cli/src/Environment/Registry.php";
require $argv[1] . "/cli/src/Transport/LocalTransport.php";
require $argv[1] . "/cli/src/Command/ReleaseCommand.php";
$envs = \WPrism\Orchestrator\Registry::load($argv[2], $argv[3]);
$driver = \WPrism\Orchestrator\Transport::make(
    "fixture",
    \WPrism\Orchestrator\Registry::get($envs, "fixture")
);
$extra = [
    "execute",
    "--prepare=" . $argv[4],
    "--authorization=" . $argv[5],
    "--expected-authorization-sha256=" . $argv[6],
    "--expected-subject-sha256=" . $argv[7],
    "--expected-presentation-sha256=" . $argv[8],
    "--expected-plan-digest=" . $argv[9],
    "--expected-stage-receipt-sha256=" . $argv[10],
    "--format=json",
];
$trustPath = $argv[11];
$promoteMarker = $argv[12];
$beforeConsumption = static function () use ($trustPath): void {
    $trust = \WPrism\Canon::decode((string) file_get_contents($trustPath));
    $trust["keys"]["fixture-key"]["status"] = "revoked";
    file_put_contents($trustPath, \WPrism\Canon::encode($trust));
};
$promote = static function () use ($promoteMarker): int {
    file_put_contents($promoteMarker, "called\n");
    return 0;
};
exit(\WPrism\Orchestrator\ReleaseCommand::run(
    $driver,
    $extra,
    $argv[1],
    $promote,
    null,
    null,
    null,
    null,
    $beforeConsumption
));
' "$ROOT" "$TMP/site/envs.json" "$SITE" "$TMP/prepare.json" "$TMP/authorization.json" \
  "$AUTH_DIGEST" "$SUBJECT_DIGEST" "$PRESENTATION_DIGEST" "$PLAN_DIGEST" "$RECEIPT_DIGEST" \
  "$SITE/.wprism/authority/authorities.json" "$TMP/promote-race-called" ) \
  > "$TMP/execute-authority-race.json" 2> "$TMP/execute-authority-race.json.err"
AUTHORITY_RACE_STATUS=$?
[ "$AUTHORITY_RACE_STATUS" = 1 ] \
  && grep -Fq 'authorization_authority_policy_changed' "$TMP/execute-authority-race.json" \
  && [ "$(authorization_count)" = 0 ] \
  && [ ! -e "$TMP/promote-race-called" ] \
  && [ "$(git -C "$TMP/target" rev-parse HEAD)" = "$TARGET_HEAD_BEFORE" ] \
  && pass 'execute re-verifies current authority policy at the last boundary before consumption' \
  || { fail 'authority-policy drift crossed consumption or mutation'; cat "$TMP/execute-authority-race.json" >&2; }

# The refused run wrote only authorized local evidence before its final trust
# check. Restore this disposable source fixture to the exact staged commit so
# the unchanged signed subject can exercise the success/replay path next.
git -C "$SITE" reset --hard "$SOURCE_COMMIT" >/dev/null
php -r '@unlink($argv[1]);' "$SITE/.wprism/releases/${PLAN_DIGEST#sha256:}.json"
[ -z "$(git -C "$SITE" status --porcelain --untracked-files=all)" ] \
  || fail 'authority-policy race cleanup did not restore the exact staged source checkout'

export WPRISM_PLAN_AFTER=plan-converged
export WPRISM_PLAN_AFTER_CALL=2
php -r '@unlink($argv[1]);' "$WPRISM_FIXTURES/plan-calls"
run_execute "$TMP/execute.json" "$SUBJECT_DIGEST"
EXECUTE_STATUS=$?
php -r '
require $argv[1] . "/cli/src/Release/ReleaseOutcome.php";
$bytes = (string) file_get_contents($argv[2]);
$outcome = json_decode($bytes, true);
\WPrism\Orchestrator\ReleaseOutcome::validate($outcome);
if (\WPrism\Orchestrator\ReleaseOutcome::encode($outcome) !== $bytes
    || $outcome["status"] !== "failed"
    || $outcome["failure"]["class"] !== "nothing_safe"
    || $outcome["failure"]["next_action"] !== "escalate"
    || $outcome["failure"]["reason_code"] !== "release_verification_failed"
    || ($outcome["verify"]["verdict"] ?? null) === "pass"
    || $argv[3] !== "1") exit(1);
' "$ROOT" "$TMP/execute.json" \
  "$EXECUTE_STATUS" \
  && pass 'release execute records a non-pass verification as one canonical failed outcome' \
  || { fail "release execute misrepresented verification (exit $EXECUTE_STATUS)"; cat "$TMP/execute.json" >&2; cat "$TMP/execute.json.err" >&2; }

[ "$(git -C "$TMP/target" rev-parse HEAD)" = "$SOURCE_COMMIT" ] \
  && [ "$(git -C "$TMP/target" rev-parse HEAD^{tree})" = "$SOURCE_TREE" ] \
  && [ "$(authorization_count)" = 1 ] \
  && [ -f "$AUTHORIZATION_ROOT/${AUTH_DIGEST#sha256:}/completion/outcome.json" ] \
  && pass 'durable one-time consumption precedes the exact staged source materialization and completion' \
  || fail 'execute did not bind consumption, source materialization and terminal completion'

# A completed replay is a status read. Make both authorization verification
# and stage validation fail if they were attempted, then require the exact
# stored outcome anyway.
php -r '
require $argv[1] . "/agent/src/Kernel/Canon.php";
$path = $argv[2]; $trust = json_decode((string) file_get_contents($path), true);
$trust["keys"]["fixture-key"]["status"] = "revoked";
file_put_contents($path, \WPrism\Canon::encode($trust));
' "$ROOT" "$SITE/.wprism/authority/authorities.json"
printf 'post-completion tamper\n' > "$STAGE_REPO/post-completion-tamper"
run_execute "$TMP/execute-replay.json" "$SUBJECT_DIGEST"
REPLAY_STATUS=$?
[ "$REPLAY_STATUS" = "$EXECUTE_STATUS" ] && cmp -s "$TMP/execute.json" "$TMP/execute-replay.json" \
  && [ "$(authorization_count)" = 1 ] \
  && pass 'completed exact replay returns the stored outcome before expired/revoked authority or stage checks' \
  || fail 'completed replay reverified authority, re-entered mutation, or changed outcome bytes'

COMPLETION_DIR="$AUTHORIZATION_ROOT/${AUTH_DIGEST#sha256:}/completion"
mv "$COMPLETION_DIR" "$COMPLETION_DIR.saved"
run_execute "$TMP/execute-reconciliation.json" "$SUBJECT_DIGEST"
RECONCILIATION_STATUS=$?
[ "$RECONCILIATION_STATUS" = 1 ] \
  && grep -Fq 'release_operation_reconciliation_required' "$TMP/execute-reconciliation.json" \
  && [ "$(authorization_count)" = 1 ] \
  && [ "$(git -C "$TMP/target" rev-parse HEAD)" = "$SOURCE_COMMIT" ] \
  && pass 'consumption without a published completion refuses reconciliation and never retries mutation' \
  || fail 'consumed-without-completion execution did not fail closed'
mv "$COMPLETION_DIR.saved" "$COMPLETION_DIR"

printf '\n'
if [ "$FAILURES" -ne 0 ]; then
  printf 'REGRESS_RELEASE_STAGE_PREPARE FAILED (%d)\n' "$FAILURES" >&2
  exit 1
fi
printf 'REGRESS_RELEASE_STAGE_PREPARE PASSED\n'
