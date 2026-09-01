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
php -l "$ROOT/cli/src/Release/ReleaseOperationStatus.php" >/dev/null || fail 'php -l ReleaseOperationStatus.php'

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

php -r '
require $argv[1] . "/cli/src/Command/ReleaseCommand.php";
$scope = new ReflectionMethod(\WPrism\Orchestrator\ReleaseCommand::class, "scope");
$rows = array_map(static fn(string $id): array => ["id" => $id], [
    "option_group:core:authored",
    "option_group:woocommerce:runtime",
    "post_type:page",
    "post_type:product",
    "post_type:shop_order",
]);
$plan = [
    "affected_surfaces" => ["option_group:core:authored", "post_type:product"],
    "create" => [["kind" => "post"]],
    "update" => [["kind" => "options"]],
];
$exact = $scope->invoke(null, $plan, $rows);
if (($exact["surfaces"] ?? null) !== ["option_group:core:authored", "post_type:product"]) exit(1);
$plan["affected_surfaces"] = ["post_type:product", "option_group:core:authored"];
try {
    $scope->invoke(null, $plan, $rows);
    exit(1);
} catch (\WPrism\CommandRefusalException $error) {
    if ($error->reasonCode !== "release_plan_scope_invalid") exit(1);
}
' "$ROOT" \
  && pass 'release scope consumes the exact agent projection and refuses malformed narrowing' \
  || fail 'release scope widened exact product/option changes or accepted malformed narrowing'

php "$ROOT/sandbox/tests/fixtures/release/make-release-site.php" "$TMP/site" >/dev/null \
  || { echo 'FAIL: could not build release fixture' >&2; exit 1; }

SITE="$TMP/site/repo"
export WPRISM_FIXTURES="$TMP/site/fixtures"
export WPRISM_SITE_REPO="$SITE"
export WPRISM_CALLS="$TMP/calls.txt"
export WPRISM_RELEASE_PRODUCT_ROOT="$ROOT"
export WPRISM_ENFORCE_RELEASE_BINDING=1
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
# The release fixture predates adoption's generated ignore file. Model the
# same target-local exclusion an adopted repository carries without changing
# either the base or candidate tree exercised below.
printf '%s\n' '/.wprism-env-values.json' >> "$TMP/target/.git/info/exclude"
printf '%s\n' '{"fixture_binding":"initial-stage-secret"}' > "$TMP/target/.wprism-env-values.json"
chmod 0600 "$TMP/target/.wprism-env-values.json"
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

STAGE_REPO="$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["stage"]["repository_path"];' "$TMP/receipt.json")"
if stat -f '%Lp' "$STAGE_REPO/.wprism-env-values.json" >/dev/null 2>&1; then
  STAGE_ENV_MODE="$(stat -f '%Lp' "$STAGE_REPO/.wprism-env-values.json")"
else
  STAGE_ENV_MODE="$(stat -c '%a' "$STAGE_REPO/.wprism-env-values.json")"
fi
cmp -s "$TMP/target/.wprism-env-values.json" "$STAGE_REPO/.wprism-env-values.json" \
  && [ "$STAGE_ENV_MODE" = 600 ] \
  && ! grep -Fq 'initial-stage-secret' "$TMP/receipt.json" \
  && pass 'stage-source mirrors the mode-0600 target binding authority without exposing it in the receipt' \
  || fail 'stage-source omitted, weakened or exposed the target binding authority'

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

printf '%s\n' '{"fixture_binding":"rotated-stage-secret"}' > "$TMP/target/.wprism-env-values.json"
chmod 0600 "$TMP/target/.wprism-env-values.json"
wprism "$TMP/prepare-stale-bindings.json" release fixture prepare --stage-receipt="$TMP/receipt.json" \
  --expected-stage-receipt-sha256="$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["receipt_sha256"];' "$TMP/receipt.json")" \
  --format=json
[ "$?" = 1 ] \
  && grep -Fq 'release_stage_environment_values_changed' "$TMP/prepare-stale-bindings.json" \
  && ! grep -Fq 'rotated-stage-secret' "$TMP/prepare-stale-bindings.json" \
  && pass 'read-only prepare refuses a stale environment-binding mirror without exposing its bytes' \
  || fail 'release prepare accepted or exposed stale staged environment bindings'

wprism "$TMP/receipt-binding-refresh.json" stage-source fixture --from=release-candidate \
  --operation=release-stage-prepare-fixture --format=json
cmp -s "$TMP/receipt.json" "$TMP/receipt-binding-refresh.json" \
  && cmp -s "$TMP/target/.wprism-env-values.json" "$STAGE_REPO/.wprism-env-values.json" \
  && pass 'an exact stage retry refreshes target bindings without changing receipt identity' \
  || fail 'stage retry did not refresh bindings independently of the immutable receipt'

RECEIPT_DIGEST="$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["receipt_sha256"];' "$TMP/receipt.json")"
TARGET_GIT_DIR="$(git -C "$TMP/target" rev-parse --absolute-git-dir)"
control_snapshot() {
  tar -C "$TARGET_GIT_DIR" -cf - wprism-control 2>/dev/null | shasum -a 256 | awk '{print $1}'
}
CONTROL_BEFORE_ENROLLMENT="$(control_snapshot)"
wprism "$TMP/authority-status-missing.json" authority-policy fixture status --format=json
[ "$?" = 1 ] \
  && grep -Fq 'target_authority_policy_unavailable' "$TMP/authority-status-missing.json" \
  && [ "$(control_snapshot)" = "$CONTROL_BEFORE_ENROLLMENT" ] \
  && pass 'read-only authority status refuses missing enrollment without creating a target byte' \
  || fail 'authority status created target control state while reporting missing enrollment'

wprism "$TMP/prepare-unenrolled.json" release fixture prepare --stage-receipt="$TMP/receipt.json" \
  --expected-stage-receipt-sha256="$RECEIPT_DIGEST" --format=json
[ "$?" = 1 ] \
  && grep -Fq 'target_authority_policy_unavailable' "$TMP/prepare-unenrolled.json" \
  && [ "$(control_snapshot)" = "$CONTROL_BEFORE_ENROLLMENT" ] \
  && pass 'read-only prepare refuses an unenrolled target without creating a policy lock or byte' \
  || fail 'unenrolled prepare mutated target control storage or crossed its trust boundary'

wprism "$TMP/authority-sync.json" authority-policy fixture sync \
  --policy="$SITE/.wprism/authority/authorities.json" --expected-current=absent --format=json
AUTHORITY_POLICY_DIGEST="$(php -r '
$d=json_decode((string) file_get_contents($argv[1]), true);
if (($d["format"] ?? null) !== "wprism-target-authority-policy-sync/v1"
    || ($d["replayed"] ?? null) !== false
    || !preg_match("/^sha256:[a-f0-9]{64}$/D", (string) ($d["policy_digest"] ?? ""))) exit(1);
echo $d["policy_digest"];
' "$TMP/authority-sync.json")"
[ "$?" = 0 ] && [ -n "$AUTHORITY_POLICY_DIGEST" ] \
  && pass 'explicit CAS enrollment durably installs the reviewed target authority policy' \
  || { fail 'target authority-policy enrollment failed'; cat "$TMP/authority-sync.json" >&2; }

wprism "$TMP/authority-sync-retry.json" authority-policy fixture sync \
  --policy="$SITE/.wprism/authority/authorities.json" --expected-current=absent --format=json
wprism "$TMP/authority-status.json" authority-policy fixture status --format=json
php -r '
$sync=json_decode((string) file_get_contents($argv[1]), true);
$status=json_decode((string) file_get_contents($argv[2]), true);
if (($sync["replayed"] ?? null) !== true
    || ($sync["policy_digest"] ?? null) !== $argv[3]
    || ($status["format"] ?? null) !== "wprism-target-authority-policy-status/v1"
    || ($status["policy_digest"] ?? null) !== $argv[3]
    || ($status["target_id"] ?? null) !== ($sync["target_id"] ?? null)) exit(1);
' "$TMP/authority-sync-retry.json" "$TMP/authority-status.json" "$AUTHORITY_POLICY_DIGEST" \
  && pass 'target policy sync retries exactly and read-only status reports the enrolled identity' \
  || fail 'target authority-policy sync replay/status contract changed'

php -r '
require $argv[1] . "/cli/src/Command/ReleaseCommand.php";
final class ReleaseRepositoryLockDriver implements \WPrism\Orchestrator\EnvironmentDriver {
    public function __construct(private string $repo) {}
    public function name(): string { return "fixture"; }
    public function driverId(): string { return "repository-lock-swap"; }
    public function repoPath(): string { return $this->repo; }
    public function describe(): string { return "repository lock swap fixture"; }
    public function captureRaw(string $script): array {
        $process=proc_open(["/bin/sh","-c",$script],[0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]],$pipes);
        if(!is_resource($process)) throw new RuntimeException("could not execute repository lock fixture");
        fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        return ["exit"=>proc_close($process),"stdout"=>(string)$stdout,"stderr"=>(string)$stderr];
    }
    public function captureWp(array $wpArgs): array { throw new LogicException("WP is outside this fixture"); }
    public function streamWp(array $wpArgs): int { throw new LogicException("WP is outside this fixture"); }
    public function wpInstruction(array $wpArgs): string { return "wp"; }
    public function capabilityReport(string $operation): \WPrism\Orchestrator\DriverCapabilityReport {
        throw new LogicException("capabilities are outside this fixture");
    }
}
$repo=$argv[2];$git=trim((string)shell_exec("git -C ".escapeshellarg($repo)." rev-parse --absolute-git-dir"));
$lock=$git."/wprism-control/repository.lock";$held=$lock.".held";
$critical="mv ".escapeshellarg($lock)." ".escapeshellarg($held)."; : > ".escapeshellarg($lock)."; printf __SWAPPED__";
$method=new ReflectionMethod(\WPrism\Orchestrator\ReleaseCommand::class,"runRepositoryLocked");
$result=$method->invoke(null,new ReleaseRepositoryLockDriver($repo),$repo,$critical,true);
@unlink($lock);@rename($held,$lock);
if(($result["exit"]??null)!==94||!str_contains((string)($result["stderr"]??""),"repository-lock-replaced"))exit(1);
' "$ROOT" "$TMP/target" \
  && pass 'controller Git critical sections refuse a repository-lock inode replacement' \
  || fail 'controller Git critical section accepted a replacement repository lock'

php -r '
require $argv[1] . "/agent/src/Promotion/AuthorizedReleaseRepository.php";
$repo=$argv[2];$git=trim((string)shell_exec("git -C ".escapeshellarg($repo)." rev-parse --absolute-git-dir"));
$head=trim((string)shell_exec("git -C ".escapeshellarg($repo)." rev-parse HEAD"));
$tree=trim((string)shell_exec("git -C ".escapeshellarg($repo)." rev-parse HEAD^{tree}"));
$operation="release:repository-lock-rebind";
$owner="authorized-release-".hash("sha256",$operation."\0".$head."\0".$tree);
$lock=$git."/wprism-control/repository.lock";$held=$lock.".held";
$binding=\WPrism\AuthorizedReleaseRepository::acquire($repo,$operation,$head,$tree,$owner);
if(!@rename($lock,$held)||file_put_contents($lock,"")===false){unset($binding);exit(2);}
$reason="";
try{$binding->assertBound();}catch(\WPrism\CommandRefusalException $error){$reason=$error->reasonCode;}
unset($binding);@unlink($lock);@rename($held,$lock);
$cli=(string)file_get_contents($argv[1]."/agent/src/Command/Cli.php");
if($reason!=="promotion_repository_binding_changed"||!str_contains($cli,"\$repositoryBinding->assertBound();"))exit(1);
' "$ROOT" "$TMP/target" \
  && pass 'target promotion refuses a replaced repository lock immediately before lease election' \
  || fail 'target promotion could cross a replacement repository lock at lease election'

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
    || $document["authority_policy_digest"] !== $argv[5]
    || !preg_match("/^sha256:[a-f0-9]{64}$/D", $document["presented_plan_sha256"])
    || !preg_match("/^sha256:[a-f0-9]{64}$/D", $document["subject_sha256"])) exit(1);
' "$ROOT" "$TMP/prepare.json" "$RECEIPT_DIGEST" "$SOURCE_COMMIT" "$AUTHORITY_POLICY_DIGEST" \
  && pass 'prepare emits a canonical complete subject while preserving authorization-plan/v1' \
  || fail 'release prepare document did not validate'

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

prepare_race_subject() {
  local label="$1"
  local operation="$2"
  local receipt="$TMP/receipt-$label.json"
  local prepare="$TMP/prepare-$label.json"

  wprism "$receipt" stage-source fixture --from=release-candidate \
    --operation="$operation" --format=json \
    || { fail "could not stage the $label race subject"; return 1; }
  local receipt_digest
  receipt_digest="$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["receipt_sha256"];' "$receipt")"
  wprism "$prepare" release fixture prepare --stage-receipt="$receipt" \
    --expected-stage-receipt-sha256="$receipt_digest" --format=json \
    || { fail "could not prepare the $label race subject"; return 1; }
}

json_field() {
  php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d[$argv[2]];' "$1" "$2"
}

sign_race_authorization() {
  local prepare="$1"
  local nonce="$2"
  local output="$3"
  php -r '
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
    "nonce" => $argv[5],
    "operation" => "release",
    "operation_id" => $prepare["operation_id"],
    "presentation_digest" => $prepare["presented_plan_sha256"],
    "subject_digest" => $prepare["subject_sha256"],
    "target_id" => $prepare["target_id"],
];
$envelope = \WPrism\Orchestrator\OperationAuthorization::sign($statement, $secret);
file_put_contents($argv[4], \WPrism\Canon::encode($envelope));
echo \WPrism\Orchestrator\OperationAuthorization::envelopeDigest($envelope);
' "$ROOT" "$prepare" "$TMP/authority.secret" "$output" "$nonce"
}

# Every post-consumption race is a distinct operator operation. Reusing one
# frozen operation with fresh signatures would itself be the bypass this suite
# must prove the target tuple election refuses.
prepare_race_subject materialization release-stage-materialization-race-0002
prepare_race_subject handoff release-stage-handoff-race-0003
prepare_race_subject compile release-stage-compile-race-0004
prepare_race_subject lease release-stage-lease-race-0005

MATERIALIZATION_PREPARE="$TMP/prepare-materialization.json"
MATERIALIZATION_RECEIPT_DIGEST="$(json_field "$TMP/receipt-materialization.json" receipt_sha256)"
MATERIALIZATION_SUBJECT_DIGEST="$(json_field "$MATERIALIZATION_PREPARE" subject_sha256)"
MATERIALIZATION_PRESENTATION_DIGEST="$(json_field "$MATERIALIZATION_PREPARE" presented_plan_sha256)"
MATERIALIZATION_PLAN_DIGEST="$(json_field "$MATERIALIZATION_PREPARE" plan_digest)"
MATERIALIZATION_AUTH_DIGEST="$(sign_race_authorization "$MATERIALIZATION_PREPARE" \
  release-stage-prepare-materialization-race-0002 "$TMP/authorization-materialization-race.json")"

HANDOFF_PREPARE="$TMP/prepare-handoff.json"
HANDOFF_RECEIPT_DIGEST="$(json_field "$TMP/receipt-handoff.json" receipt_sha256)"
HANDOFF_SUBJECT_DIGEST="$(json_field "$HANDOFF_PREPARE" subject_sha256)"
HANDOFF_PRESENTATION_DIGEST="$(json_field "$HANDOFF_PREPARE" presented_plan_sha256)"
HANDOFF_PLAN_DIGEST="$(json_field "$HANDOFF_PREPARE" plan_digest)"
HANDOFF_AUTH_DIGEST="$(sign_race_authorization "$HANDOFF_PREPARE" \
  release-stage-prepare-handoff-race-0003 "$TMP/authorization-handoff-race.json")"

COMPILE_RACE_PREPARE="$TMP/prepare-compile.json"
COMPILE_RACE_RECEIPT_DIGEST="$(json_field "$TMP/receipt-compile.json" receipt_sha256)"
COMPILE_RACE_SUBJECT_DIGEST="$(json_field "$COMPILE_RACE_PREPARE" subject_sha256)"
COMPILE_RACE_PRESENTATION_DIGEST="$(json_field "$COMPILE_RACE_PREPARE" presented_plan_sha256)"
COMPILE_RACE_PLAN_DIGEST="$(json_field "$COMPILE_RACE_PREPARE" plan_digest)"
COMPILE_RACE_AUTH_DIGEST="$(sign_race_authorization "$COMPILE_RACE_PREPARE" \
  release-stage-prepare-compile-race-0004 "$TMP/authorization-compile-race.json")"

LEASE_RACE_PREPARE="$TMP/prepare-lease.json"
LEASE_RACE_RECEIPT_DIGEST="$(json_field "$TMP/receipt-lease.json" receipt_sha256)"
LEASE_RACE_SUBJECT_DIGEST="$(json_field "$LEASE_RACE_PREPARE" subject_sha256)"
LEASE_RACE_PRESENTATION_DIGEST="$(json_field "$LEASE_RACE_PREPARE" presented_plan_sha256)"
LEASE_RACE_PLAN_DIGEST="$(json_field "$LEASE_RACE_PREPARE" plan_digest)"
LEASE_RACE_AUTH_DIGEST="$(sign_race_authorization "$LEASE_RACE_PREPARE" \
  release-stage-prepare-lease-race-0005 "$TMP/authorization-lease-race.json")"

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

AUTHORIZATION_ROOT="$TARGET_GIT_DIR/wprism-control/authorizations"
authorization_count() {
  if [ ! -d "$AUTHORIZATION_ROOT" ]; then
    printf '0'
    return
  fi
  php -r '
$count = 0;
foreach (glob($argv[1] . "/*") ?: [] as $path) {
    if (is_dir($path) && preg_match("/^[a-f0-9]{64}$/D", basename($path)) === 1) ++$count;
}
echo $count;
' "$AUTHORIZATION_ROOT"
}

run_status() {
  local out="$1"
  local expected_subject="$2"
  wprism "$out" release fixture status --prepare="$TMP/prepare.json" \
    --expected-subject-sha256="$expected_subject" --format=json
}

CONTROL_BEFORE_RELEASE_STATUS="$(control_snapshot)"
run_status "$TMP/status-prepared.json" "$SUBJECT_DIGEST"
PREPARED_STATUS=$?
php -r '
require $argv[1] . "/cli/src/Release/ReleaseOperationStatus.php";
$bytes = (string) file_get_contents($argv[2]);
$status = json_decode($bytes, true);
\WPrism\Orchestrator\ReleaseOperationStatus::validate($status);
if (\WPrism\Orchestrator\ReleaseOperationStatus::encode($status) !== $bytes
    || ($status["state"] ?? null) !== "prepared"
    || ($status["sequence"] ?? null) !== 0
    || ($status["terminal"] ?? null) !== false
    || ($status["reconciliation_required"] ?? null) !== false
    || ($status["authorization_digest"] ?? "sentinel") !== null) exit(1);
' "$ROOT" "$TMP/status-prepared.json"
[ "$PREPARED_STATUS" = 1 ] \
  && [ ! -s "$TMP/status-prepared.json.err" ] \
  && [ "$(control_snapshot)" = "$CONTROL_BEFORE_RELEASE_STATUS" ] \
  && pass 'release status reports prepared sequence 0 without creating target control bytes' \
  || fail 'prepared release status mutated the target, changed sequence, or returned green'

php -r '
require $argv[1] . "/cli/src/Release/ReleaseOperationStatus.php";
$prepare = \WPrism\Orchestrator\ReleasePrepare::read($argv[2]);
$status = \WPrism\Orchestrator\ReleaseOperationStatus::build($prepare, [
    "authorization_digest" => "sha256:" . str_repeat("e", 64),
    "completion" => null,
    "consumption" => null,
]);
if ($status["state"] !== "elected" || $status["sequence"] !== 1
    || $status["terminal"] !== false || $status["reconciliation_required"] !== true) exit(1);
' "$ROOT" "$TMP/prepare.json" \
  && pass 'a durable election without consumption is explicit reconciliation sequence 1' \
  || fail 'partial election was hidden or represented as executable status'

ZERO_DIGEST="sha256:$(printf '0%.0s' {1..64})"
run_status "$TMP/status-digest-refusal.json" "$ZERO_DIGEST"
[ "$?" = 1 ] && grep -Fq 'release_status_digest_mismatch' "$TMP/status-digest-refusal.json" \
  && [ "$(authorization_count)" = 0 ] \
  && pass 'release status binds the caller-selected prepare to its explicit subject digest' \
  || fail 'release status accepted the wrong prepared subject'

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

# Revoke the target-authoritative policy after the controller's final verify,
# in the exact window immediately before target-side election. Sync and
# consume serialize on authority.lock; whichever target operation wins is the
# authority fact consumption must observe.
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
$trust = \WPrism\Canon::decode((string) file_get_contents($argv[11]));
$trustedDigest = \WPrism\Orchestrator\OperationAuthorization::trustDigest($trust);
$revoked = $trust;
$revoked["keys"]["fixture-key"]["status"] = "revoked";
$revokedDigest = \WPrism\Orchestrator\OperationAuthorization::trustDigest($revoked);
$afterControllerVerify = static function () use (
    $driver,
    $revoked,
    $trustedDigest,
    $revokedDigest,
    $argv
): void {
    \WPrism\Orchestrator\TargetOperationStore::syncAuthorityPolicy(
        $driver,
        $revoked,
        $trustedDigest
    );
    file_put_contents($argv[12], $revokedDigest);
};
$promote = static function () use ($argv): int {
    file_put_contents($argv[13], "called\n");
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
    null,
    $afterControllerVerify
));
' "$ROOT" "$TMP/site/envs.json" "$SITE" "$TMP/prepare.json" "$TMP/authorization.json" \
  "$AUTH_DIGEST" "$SUBJECT_DIGEST" "$PRESENTATION_DIGEST" "$PLAN_DIGEST" "$RECEIPT_DIGEST" \
  "$SITE/.wprism/authority/authorities.json" "$TMP/revoked-policy-digest" \
  "$TMP/promote-target-authority-race-called" ) \
  > "$TMP/execute-target-authority-race.json" 2> "$TMP/execute-target-authority-race.json.err"
TARGET_AUTHORITY_RACE_STATUS=$?
[ "$TARGET_AUTHORITY_RACE_STATUS" = 1 ] \
  && grep -Fq 'authorization_authority_policy_changed' "$TMP/execute-target-authority-race.json" \
  && [ "$(authorization_count)" = 0 ] \
  && [ ! -e "$TMP/promote-target-authority-race-called" ] \
  && [ "$(git -C "$TMP/target" rev-parse HEAD)" = "$TARGET_HEAD_BEFORE" ] \
  && pass 'target-side election observes revocation after final controller verification' \
  || { fail 'post-verification target revocation crossed consumption or mutation'; cat "$TMP/execute-target-authority-race.json" >&2; }

REVOKED_POLICY_DIGEST="$(cat "$TMP/revoked-policy-digest" 2>/dev/null || true)"
wprism "$TMP/authority-restore.json" authority-policy fixture sync \
  --policy="$SITE/.wprism/authority/authorities.json" \
  --expected-current="$REVOKED_POLICY_DIGEST" --format=json
[ "$?" = 0 ] \
  && grep -Fq "$AUTHORITY_POLICY_DIGEST" "$TMP/authority-restore.json" \
  && pass 'explicit CAS sync restores trusted target policy after the revocation regression' \
  || fail 'target authority policy was not restored explicitly after revocation'

git -C "$SITE" reset --hard "$SOURCE_COMMIT" >/dev/null
php -r '@unlink($argv[1]);' "$SITE/.wprism/releases/${PLAN_DIGEST#sha256:}.json"
[ -z "$(git -C "$SITE" status --porcelain --untracked-files=all)" ] \
  || fail 'target authority-policy race cleanup did not restore the staged source checkout'

run_repository_race() {
  local mode="$1"
  local prepare="$2"
  local authorization="$3"
  local authorization_digest="$4"
  local subject_digest="$5"
  local presentation_digest="$6"
  local plan_digest="$7"
  local receipt_digest="$8"
  local output="$9"
  local evidence="${10}"
  local promote_marker="${11}"
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
$target = $argv[11];
$mode = $argv[12];
$race = static function () use ($target, $mode, $argv): void {
    $path = $target . "/operator-race.txt";
    file_put_contents($path, "operator committed " . $mode . "\n");
    $prefix = "git -C " . escapeshellarg($target) . " ";
    exec($prefix . "add -- operator-race.txt 2>&1", $addOutput, $addExit);
    exec(
        $prefix . "-c user.email=fixture@example.invalid -c user.name=fixture "
        . "commit -q -m " . escapeshellarg("operator " . $mode . " race") . " 2>&1",
        $commitOutput,
        $commitExit
    );
    if ($addExit !== 0 || $commitExit !== 0) {
        throw new RuntimeException("could not create deterministic target Git race");
    }
    $head = trim((string) shell_exec($prefix . "rev-parse HEAD"));
    file_put_contents($path, "operator uncommitted " . $mode . "\n");
    file_put_contents($argv[13], $head . "\n");
};
$beforeMaterialization = $mode === "before-materialization" ? $race : null;
$afterMaterialization = $mode === "after-materialization" ? $race : null;
$promote = static function () use ($argv): int {
    file_put_contents($argv[14], "called\n");
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
    null,
    null,
    $beforeMaterialization,
    $afterMaterialization
));
' "$ROOT" "$TMP/site/envs.json" "$SITE" "$prepare" "$authorization" \
    "$authorization_digest" "$subject_digest" "$presentation_digest" "$plan_digest" \
    "$receipt_digest" "$TMP/target" "$mode" "$evidence" "$promote_marker" ) \
    > "$output" 2> "$output.err"
}

run_repository_race before-materialization "$MATERIALIZATION_PREPARE" \
  "$TMP/authorization-materialization-race.json" "$MATERIALIZATION_AUTH_DIGEST" \
  "$MATERIALIZATION_SUBJECT_DIGEST" "$MATERIALIZATION_PRESENTATION_DIGEST" \
  "$MATERIALIZATION_PLAN_DIGEST" "$MATERIALIZATION_RECEIPT_DIGEST" \
  "$TMP/execute-materialization-race.json" \
  "$TMP/materialization-race-head" "$TMP/promote-materialization-race-called"
MATERIALIZATION_RACE_STATUS=$?
MATERIALIZATION_RACE_HEAD="$(cat "$TMP/materialization-race-head" 2>/dev/null || true)"
[ "$MATERIALIZATION_RACE_STATUS" = 1 ] \
  && grep -Fq 'release_operation_reconciliation_required' "$TMP/execute-materialization-race.json" \
  && [ "$(authorization_count)" = 1 ] \
  && [ ! -e "$TMP/promote-materialization-race-called" ] \
  && [ "$(git -C "$TMP/target" rev-parse HEAD)" = "$MATERIALIZATION_RACE_HEAD" ] \
  && [ "$(cat "$TMP/target/operator-race.txt")" = 'operator uncommitted before-materialization' ] \
  && pass 'a commit/worktree race immediately before materialization is preserved and refuses before promote' \
  || { fail 'pre-materialization race lost unrelated target Git/worktree bytes'; cat "$TMP/execute-materialization-race.json" >&2; }

git -C "$TMP/target" reset --hard "$TARGET_HEAD_BEFORE" >/dev/null
git -C "$TMP/target" clean -fd >/dev/null
git -C "$SITE" reset --hard "$SOURCE_COMMIT" >/dev/null
php -r '@unlink($argv[1]);' "$SITE/.wprism/releases/${MATERIALIZATION_PLAN_DIGEST#sha256:}.json"

run_repository_race after-materialization "$HANDOFF_PREPARE" \
  "$TMP/authorization-handoff-race.json" "$HANDOFF_AUTH_DIGEST" \
  "$HANDOFF_SUBJECT_DIGEST" "$HANDOFF_PRESENTATION_DIGEST" "$HANDOFF_PLAN_DIGEST" \
  "$HANDOFF_RECEIPT_DIGEST" "$TMP/execute-handoff-race.json" \
  "$TMP/handoff-race-head" "$TMP/promote-handoff-race-called"
HANDOFF_RACE_STATUS=$?
HANDOFF_RACE_HEAD="$(cat "$TMP/handoff-race-head" 2>/dev/null || true)"
[ "$HANDOFF_RACE_STATUS" = 1 ] \
  && grep -Fq 'release_operation_reconciliation_required' "$TMP/execute-handoff-race.json" \
  && [ "$(authorization_count)" = 2 ] \
  && [ ! -e "$TMP/promote-handoff-race-called" ] \
  && [ "$(git -C "$TMP/target" rev-parse HEAD)" = "$HANDOFF_RACE_HEAD" ] \
  && [ "$(cat "$TMP/target/operator-race.txt")" = 'operator uncommitted after-materialization' ] \
  && pass 'a commit/worktree race after materialization is preserved and refused at the promotion handoff' \
  || { fail 'materialization-to-promote race crossed the exact source binding'; cat "$TMP/execute-handoff-race.json" >&2; }

git -C "$TMP/target" reset --hard "$TARGET_HEAD_BEFORE" >/dev/null
git -C "$TMP/target" clean -fd >/dev/null
git -C "$SITE" reset --hard "$SOURCE_COMMIT" >/dev/null
php -r '@unlink($argv[1]);' "$SITE/.wprism/releases/${HANDOFF_PLAN_DIGEST#sha256:}.json"
[ -z "$(git -C "$TMP/target" status --porcelain --untracked-files=all)" ] \
  && [ -z "$(git -C "$SITE" status --porcelain --untracked-files=all)" ] \
  || fail 'repository race cleanup did not restore clean source and target fixtures'

BEGIN_CALLS_BEFORE_COMPILE_RACE="$(grep -Fc 'wprism promotion-begin' "$WPRISM_CALLS" 2>/dev/null || true)"
export WPRISM_COMPILE_RACE_REPO="$TMP/target"
export WPRISM_COMPILE_RACE_EVIDENCE="$TMP/compile-race-head"
wprism "$TMP/execute-compile-race.json" release fixture execute \
  --prepare="$COMPILE_RACE_PREPARE" --authorization="$TMP/authorization-compile-race.json" \
  --expected-authorization-sha256="$COMPILE_RACE_AUTH_DIGEST" \
  --expected-subject-sha256="$COMPILE_RACE_SUBJECT_DIGEST" \
  --expected-presentation-sha256="$COMPILE_RACE_PRESENTATION_DIGEST" \
  --expected-plan-digest="$COMPILE_RACE_PLAN_DIGEST" \
  --expected-stage-receipt-sha256="$COMPILE_RACE_RECEIPT_DIGEST" --format=json
COMPILE_RACE_STATUS=$?
unset WPRISM_COMPILE_RACE_REPO WPRISM_COMPILE_RACE_EVIDENCE
COMPILE_RACE_HEAD="$(cat "$TMP/compile-race-head" 2>/dev/null || true)"
BEGIN_CALLS_AFTER_COMPILE_RACE="$(grep -Fc 'wprism promotion-begin' "$WPRISM_CALLS" 2>/dev/null || true)"
[ "$COMPILE_RACE_STATUS" = 1 ] \
  && grep -Fq 'wprism-release-outcome/v1' "$TMP/execute-compile-race.json" \
  && [ "$BEGIN_CALLS_AFTER_COMPILE_RACE" = "$BEGIN_CALLS_BEFORE_COMPILE_RACE" ] \
  && [ "$(authorization_count)" = 3 ] \
  && [ "$(git -C "$TMP/target" rev-parse HEAD)" = "$COMPILE_RACE_HEAD" ] \
  && [ "$(cat "$TMP/target/operator-compile-race.txt")" = 'operator uncommitted compile-race' ] \
  && pass 'compile-to-lease race preserves target bytes and reaches no promotion-begin despite the same artifact digest' \
  || { fail 'a changed/mixed compile snapshot reached target promotion mutation'; cat "$TMP/execute-compile-race.json" >&2; cat "$TMP/execute-compile-race.json.err" >&2; }

git -C "$TMP/target" reset --hard "$TARGET_HEAD_BEFORE" >/dev/null
git -C "$TMP/target" clean -fd >/dev/null
git -C "$SITE" reset --hard "$SOURCE_COMMIT" >/dev/null
php -r '@unlink($argv[1]);' "$SITE/.wprism/releases/${COMPILE_RACE_PLAN_DIGEST#sha256:}.json"

APPLY_CALLS_BEFORE_LEASE_RACE="$(grep -Fc 'wprism apply' "$WPRISM_CALLS" 2>/dev/null || true)"
export WPRISM_BEGIN_REPOSITORY_RACE_REPO="$TMP/target"
export WPRISM_BEGIN_REPOSITORY_RACE_EVIDENCE="$TMP/promotion-begin-race-head"
export WPRISM_BEGIN_BINDING_EVIDENCE="$TMP/promotion-begin-race-bound"
wprism "$TMP/execute-lease-race.json" release fixture execute \
  --prepare="$LEASE_RACE_PREPARE" --authorization="$TMP/authorization-lease-race.json" \
  --expected-authorization-sha256="$LEASE_RACE_AUTH_DIGEST" \
  --expected-subject-sha256="$LEASE_RACE_SUBJECT_DIGEST" \
  --expected-presentation-sha256="$LEASE_RACE_PRESENTATION_DIGEST" \
  --expected-plan-digest="$LEASE_RACE_PLAN_DIGEST" \
  --expected-stage-receipt-sha256="$LEASE_RACE_RECEIPT_DIGEST" --format=json
LEASE_RACE_STATUS=$?
unset WPRISM_BEGIN_REPOSITORY_RACE_REPO WPRISM_BEGIN_REPOSITORY_RACE_EVIDENCE \
  WPRISM_BEGIN_BINDING_EVIDENCE
LEASE_RACE_HEAD="$(cat "$TMP/promotion-begin-race-head" 2>/dev/null || true)"
APPLY_CALLS_AFTER_LEASE_RACE="$(grep -Fc 'wprism apply' "$WPRISM_CALLS" 2>/dev/null || true)"
[ "$LEASE_RACE_STATUS" = 1 ] \
  && grep -Fq 'wprism-release-outcome/v1' "$TMP/execute-lease-race.json" \
  && [ "$APPLY_CALLS_AFTER_LEASE_RACE" = "$APPLY_CALLS_BEFORE_LEASE_RACE" ] \
  && [ "$(authorization_count)" = 4 ] \
  && [ ! -e "$TMP/promotion-begin-race-bound" ] \
  && [ "$(git -C "$TMP/target" rev-parse HEAD)" = "$LEASE_RACE_HEAD" ] \
  && [ "$(cat "$TMP/target/operator-begin-race.txt")" = 'operator uncommitted promotion-begin-race' ] \
  && pass 'target promotion-begin rechecks the exact source under its repository lock before site mutation' \
  || { fail 'a post-controller-check repository race reached target site mutation'; cat "$TMP/execute-lease-race.json" >&2; cat "$TMP/execute-lease-race.json.err" >&2; }

git -C "$TMP/target" reset --hard "$TARGET_HEAD_BEFORE" >/dev/null
git -C "$TMP/target" clean -fd >/dev/null
git -C "$SITE" reset --hard "$SOURCE_COMMIT" >/dev/null
php -r '@unlink($argv[1]);' "$SITE/.wprism/releases/${LEASE_RACE_PLAN_DIGEST#sha256:}.json"

export WPRISM_PLAN_AFTER=plan-converged
export WPRISM_PLAN_AFTER_CALL=2
php -r '@unlink($argv[1]);' "$WPRISM_FIXTURES/plan-calls"
export WPRISM_BEGIN_BINDING_EVIDENCE="$TMP/promotion-begin-bound"
run_execute "$TMP/execute.json" "$SUBJECT_DIGEST"
EXECUTE_STATUS=$?
unset WPRISM_BEGIN_BINDING_EVIDENCE
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

php -r '
$projection = json_decode((string) file_get_contents($argv[1]), true);
if (($projection["format"] ?? null) !== "wprism-site-capability-projection/v1"
    || !is_string($projection["contract_digest"] ?? null)
    || !is_array($projection["surfaces"] ?? null)
    || array_is_list($projection)) exit(1);
' "$SITE/.wprism/contract/projection.json" \
  && pass 'signed execute persists the complete site capability projection document' \
  || fail 'signed execute replaced projection.json with release-scoped authorization rows'

[ "$(git -C "$TMP/target" rev-parse HEAD)" = "$SOURCE_COMMIT" ] \
  && [ "$(git -C "$TMP/target" rev-parse HEAD^{tree})" = "$SOURCE_TREE" ] \
  && [ "$(authorization_count)" = 5 ] \
  && [ -f "$AUTHORIZATION_ROOT/${AUTH_DIGEST#sha256:}/completion/outcome.json" ] \
  && [ "$(cat "$TMP/promotion-begin-bound")" = 'lease-under-repository-lock' ] \
  && grep -Fq -- "--repo=$TMP/target" "$WPRISM_CALLS" \
  && grep -Fq -- '--release-operation-id=release-stage-prepare-fixture' "$WPRISM_CALLS" \
  && grep -Fq -- "--expected-source-commit=$SOURCE_COMMIT" "$WPRISM_CALLS" \
  && grep -Fq -- "--expected-source-tree=$SOURCE_TREE" "$WPRISM_CALLS" \
  && pass 'durable one-time consumption precedes the exact staged source materialization and completion' \
  || fail 'execute did not bind consumption and the exact source tuple through target promotion-begin'

run_status "$TMP/status-completed.json" "$SUBJECT_DIGEST"
COMPLETED_STATUS=$?
php -r '
require $argv[1] . "/cli/src/Release/ReleaseOperationStatus.php";
$statusBytes = (string) file_get_contents($argv[2]);
$outcomeBytes = (string) file_get_contents($argv[3]);
$status = json_decode($statusBytes, true);
$outcome = json_decode($outcomeBytes, true);
\WPrism\Orchestrator\ReleaseOperationStatus::validate($status);
if (\WPrism\Orchestrator\ReleaseOperationStatus::encode($status) !== $statusBytes
    || ($status["state"] ?? null) !== "completed"
    || ($status["sequence"] ?? null) !== 3
    || ($status["terminal"] ?? null) !== true
    || ($status["reconciliation_required"] ?? null) !== false
    || ($status["authorization_digest"] ?? null) !== $argv[4]
    || ($status["outcome"] ?? null) !== $outcome) exit(1);
' "$ROOT" "$TMP/status-completed.json" "$TMP/execute.json" "$AUTH_DIGEST"
[ "$COMPLETED_STATUS" = "$EXECUTE_STATUS" ] \
  && [ ! -s "$TMP/status-completed.json.err" ] \
  && pass 'release status returns completed sequence 3 with the exact durable outcome and exit status' \
  || fail 'completed release status lost its outcome, lineage, or terminal exit status'

# A completed replay is a status read. Make both authorization verification
# and stage validation fail if they were attempted, then require the exact
# stored outcome anyway.
TARGET_ID_PATH="$TARGET_GIT_DIR/wprism-control/target-id"
cp "$TARGET_ID_PATH" "$TMP/target-id.before-replay-drift"
printf 'wprism-target:%064d\n' 0 > "$TARGET_ID_PATH"
run_execute "$TMP/execute-replay-target-drift.json" "$SUBJECT_DIGEST"
TARGET_DRIFT_STATUS=$?
[ "$TARGET_DRIFT_STATUS" = 1 ] \
  && grep -Fq 'authorized_operation_status_invalid' "$TMP/execute-replay-target-drift.json" \
  && [ "$(authorization_count)" = 5 ] \
  && pass 'completed release replay refuses when canonical target identity no longer names its stored outcome' \
  || fail 'completed release replay accepted target-A outcome after target-id drift'
mv "$TMP/target-id.before-replay-drift" "$TARGET_ID_PATH"
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
  && [ "$(authorization_count)" = 5 ] \
  && pass 'completed exact replay returns the stored outcome before expired/revoked authority or stage checks' \
  || fail 'completed replay reverified authority, re-entered mutation, or changed outcome bytes'

COMPLETION_DIR="$AUTHORIZATION_ROOT/${AUTH_DIGEST#sha256:}/completion"
mv "$COMPLETION_DIR" "$COMPLETION_DIR.saved"
run_status "$TMP/status-consumed.json" "$SUBJECT_DIGEST"
CONSUMED_STATUS=$?
php -r '
require $argv[1] . "/cli/src/Release/ReleaseOperationStatus.php";
$bytes = (string) file_get_contents($argv[2]);
$status = json_decode($bytes, true);
\WPrism\Orchestrator\ReleaseOperationStatus::validate($status);
if (\WPrism\Orchestrator\ReleaseOperationStatus::encode($status) !== $bytes
    || ($status["state"] ?? null) !== "consumed"
    || ($status["sequence"] ?? null) !== 2
    || ($status["terminal"] ?? null) !== false
    || ($status["reconciliation_required"] ?? null) !== true
    || ($status["authorization_digest"] ?? null) !== $argv[3]
    || ($status["outcome"] ?? "sentinel") !== null) exit(1);
' "$ROOT" "$TMP/status-consumed.json" "$AUTH_DIGEST"
[ "$CONSUMED_STATUS" = 1 ] \
  && [ ! -s "$TMP/status-consumed.json.err" ] \
  && [ "$(authorization_count)" = 5 ] \
  && [ "$(git -C "$TMP/target" rev-parse HEAD)" = "$SOURCE_COMMIT" ] \
  && pass 'release status exposes consumed sequence 2 for fail-closed reconciliation without mutation' \
  || fail 'consumed release status retried mutation or hid its nonterminal evidence'
run_execute "$TMP/execute-reconciliation.json" "$SUBJECT_DIGEST"
RECONCILIATION_STATUS=$?
[ "$RECONCILIATION_STATUS" = 1 ] \
  && grep -Fq 'release_operation_reconciliation_required' "$TMP/execute-reconciliation.json" \
  && [ "$(authorization_count)" = 5 ] \
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
