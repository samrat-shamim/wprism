#!/usr/bin/env bash
# Regression — round-3 MUP §2.5: `duo recover` drives the recovery runtime in
# an order that cannot be reordered by a caller, and refuses to start at all
# until the one thing a database lock cannot provide has been asserted.
#
# Three properties, each of which has a specific failure mode this suite
# reproduces rather than describes:
#
#   1. `--writers-excluded` is REQUIRED. The checkpoint contains its own
#      temporary promotion lease row (`cli/duo`'s own recovery guidance says
#      so), so a lock inside the database being imported cannot protect the
#      recovery window. Without the assertion, nothing runs.
#   2. CODE FIRST. A checkpoint taken around a code phase refuses a database
#      import until code is reconciled to the pre-release revision, and names
#      it. A database that describes one code revision underneath another is
#      the state nobody can reason about afterwards.
#   3. The FINAL ABORT IS MANDATORY, including when the import fails. Step 4
#      releases the lease row the imported dump reinstated; skipping it on a
#      failed import leaves the target holding a lease no process owns.
#
# Plus the property that makes the claim checkable at all: the recovery claim
# printed before acting is byte-identical to the one in the frozen
# authorization plan for that checkpoint.
#
# And, round-3 T5: the RETAINED release checkpoints. Every operator-directed
# promotion keeps `.duo/checkpoints/promote-<owner>.sql` beside its compiled
# artifact, on every transport; `duo recover` lists them and restores them
# through the SAME four ordered steps, on a transport that carries no rollback
# authority runtime at all — so the operator-directed claim a frozen plan
# prints on a local/docker target is a claim this verb honours (grind_mup.sh
# step 11).
#
# A standalone `duo deploy` is the second writer, at
# `.duo/checkpoints/deploy-<owner>.sql`. The property this suite adds is that
# the file-name prefix is the ONLY difference that reaches `duo recover`: the
# row lists under the same kind, --writers-excluded is required for it just the
# same, the four ordered steps are the same steps under the lease identity read
# from its own sibling artifact, and an absent one refuses the way an absent
# promote checkpoint does.
#
# Offline: no docker, no WordPress, no network, no real ssh, no real target.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/duo-recover-ordering.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT INT TERM

FAILURES=0
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; FAILURES=$((FAILURES + 1)); }
say()  { printf '\n== %s ==\n' "$*"; }

printf '== syntax ==\n'
for file in "$ROOT/cli/duo" \
  "$ROOT/cli/src/Command/RecoverCommand.php" \
  "$ROOT/cli/src/Recovery/CheckpointCatalog.php" \
  "$ROOT/cli/src/Recovery/RecoveryClaim.php" \
  "$ROOT/sandbox/tests/fixtures/release/make-recover-site.php"; do
  php -l "$file" >/dev/null || fail "php -l $file"
done
pass 'PHP syntax'

php "$ROOT/sandbox/tests/fixtures/release/make-recover-site.php" "$TMP/f" >/dev/null \
  || { echo "FAIL: could not build the recover fixture" >&2; exit 1; }

SITE="$TMP/f/site"
export DUO_RECOVERY_RUNTIME_SOURCE="$ROOT/recovery/rollback-control.php"
export DUO_WP_CALLS="$TMP/wp-calls.txt"
PATH="$TMP/f/bin:$PATH"
export PATH

# recover <name> [args...] -> exit code; stdout+stderr land in $TMP/<name>.txt
recover() {
  local name="$1"; shift
  : > "$DUO_WP_CALLS"
  ( cd "$SITE" && php "$ROOT/cli/duo" --envs-file="$TMP/f/envs.json" recover fixture "$@" ) \
    > "$TMP/$name.txt" 2> "$TMP/$name.err"
  local status=$?
  cat "$TMP/$name.err" >> "$TMP/$name.txt"
  return $status
}
# recover_plain <name> [args...] -> the same, against the `local` environment
# that carries no rollback authority runtime.
recover_plain() {
  local name="$1"; shift
  : > "$DUO_WP_CALLS"
  ( cd "$SITE" && php "$ROOT/cli/duo" --envs-file="$TMP/f/envs.json" recover plain "$@" ) \
    > "$TMP/$name.txt" 2> "$TMP/$name.err"
  local status=$?
  cat "$TMP/$name.err" >> "$TMP/$name.txt"
  return $status
}

# wp_steps -> the recovery steps the target actually received, in order
wp_steps() {
  sed -e 's/.*duo promotion-abort.*/abort/' \
      -e 's/.*duo promotion-begin.*/begin/' \
      -e 's/.*db import.*/import/' "$DUO_WP_CALLS" \
    | grep -E '^(abort|begin|import)$' || true
}

# ------------------------------------------------------------------- the list
say '--list'
DUO_RECOVER_STATUS="$TMP/f/status/code.json" recover "list" --list
STATUS=$?
[ "$STATUS" = 0 ] && pass '--list exits 0' || { fail "--list exited $STATUS"; sed -n '1,20p' "$TMP/list.txt" >&2; }
grep -Fq 'receipt-recover-fixture' "$TMP/list.txt" \
  && pass '--list prints the receipt id --restore consumes' \
  || fail '--list did not print the receipt id'
grep -Fq 'covers: database checkpoint, code release' "$TMP/list.txt" \
  && pass '--list prints the covered inventory, derived from the receipt evidence' \
  || fail '--list did not print the covered inventory'
[ -s "$DUO_WP_CALLS" ] && fail '--list ran a recovery step' || pass '--list runs no recovery step at all'

DUO_RECOVER_STATUS="$TMP/f/status/none.json" recover "listnone" --list
grep -Fq 'the rollback authority is available and holds no active receipt' "$TMP/listnone.txt" \
  && pass 'no active receipt says so rather than leaving the signed source implied' \
  || fail 'an inactive authority did not disclose that it holds nothing'
grep -Fq 'promote-recover-fixture-owner  retained  retained-release-checkpoint' "$TMP/listnone.txt" \
  && pass 'the retained release checkpoint is still listed when the authority holds nothing' \
  || { fail 'the retained checkpoint was not listed beside an inactive authority'; sed -n '1,12p' "$TMP/listnone.txt" >&2; }
grep -Fq 'receipt-recover-fixture' "$TMP/listnone.txt" \
  && fail 'an inactive authority still printed a receipt row' \
  || pass 'an inactive authority contributes no receipt row'

# ------------------------------------------------- writer exclusion is required
say '--writers-excluded is required'
DUO_RECOVER_STATUS="$TMP/f/status/database-only.json" \
  recover "noexclusion" --restore=receipt-recover-fixture
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a restore without --writers-excluded refuses (exit 1)' \
  || fail "a restore without --writers-excluded exited $STATUS"
grep -Fq 'writer_exclusion_required' "$TMP/noexclusion.txt" \
  && pass 'the refusal names writer_exclusion_required' \
  || fail 'the refusal did not name writer_exclusion_required'
grep -Fq 'lease row' "$TMP/noexclusion.txt" \
  && pass 'the refusal explains that the checkpoint contains its own lease row' \
  || fail 'the refusal does not explain why an internal lock cannot protect the window'
[ -s "$DUO_WP_CALLS" ] \
  && fail 'a refused restore still touched the target' \
  || pass 'a refused restore runs nothing at all — not even step 1'

# --------------------------------------------------------------- code first
say 'code-first ordering is enforced, not advised'
DUO_RECOVER_STATUS="$TMP/f/status/code.json" \
  recover "codefirst" --restore=receipt-recover-fixture --writers-excluded
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a code-covering checkpoint refuses a database import (exit 1)' \
  || fail "a code-covering checkpoint exited $STATUS"
grep -Fq 'recover_code_not_reconciled' "$TMP/codefirst.txt" \
  && pass 'the refusal names recover_code_not_reconciled' \
  || { fail 'the code-first refusal did not name itself'; sed -n '1,25p' "$TMP/codefirst.txt" >&2; }
grep -Eq 'code_revision_expected|reconcile or restore the target code to' "$TMP/codefirst.txt" \
  && pass 'the refusal names the exact revision code must be reconciled to' \
  || fail 'the code-first refusal did not name a revision'
STEPS="$(wp_steps | tr '\n' ' ')"
[ -z "${STEPS// /}" ] \
  && pass 'the code-first refusal happens before step 1, so no lease is disturbed' \
  || fail "the code-first refusal still ran steps: $STEPS"

# The claim is printed BEFORE acting, and it is the frozen plan's own.
grep -Fq 'recovery profile: operator-directed' "$TMP/codefirst.txt" \
  && pass 'the recovery claim is printed before the refusal, while the operator can still act on it' \
  || fail 'the recovery claim was not printed before acting'
php -r '
$out = (string) file_get_contents($argv[1]);
$claim = json_decode((string) file_get_contents($argv[2]), true);
$missing = [];
foreach ($claim["does_not_restore"] as $line) {
    if (!str_contains($out, $line)) { $missing[] = $line; }
}
if ($missing !== []) {
    fwrite(STDERR, "FAIL: the printed claim dropped: " . implode(" | ", $missing) . "\n");
    exit(1);
}
echo "ok: every does-not-restore line of the frozen plan claim is printed verbatim\n";
' "$TMP/codefirst.txt" "$TMP/f/claim.json" || fail 'the printed claim is not the frozen plan claim'

# ------------------------------------------------- the ordered path, and step 4
say 'the four ordered steps, and the mandatory final abort'
DUO_RECOVER_STATUS="$TMP/f/status/database-only.json" \
  recover "ordered" --restore=receipt-recover-fixture --writers-excluded
STATUS=$?
[ "$STATUS" = 0 ] && pass 'a database-only checkpoint recovers (exit 0)' \
  || { fail "the ordered recovery exited $STATUS"; sed -n '1,25p' "$TMP/ordered.txt" >&2; }
ORDER="$(wp_steps | tr '\n' ' ')"
[ "$ORDER" = "abort begin import abort " ] \
  && pass 'the target received exactly abort -> begin -> import -> final abort' \
  || fail "the ordered path ran: $ORDER"

# The one that matters: the final abort runs even when the import failed.
DUO_RECOVER_STATUS="$TMP/f/status/database-only.json" DUO_IMPORT_EXIT=3 \
  recover "importfail" --restore=receipt-recover-fixture --writers-excluded
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a failed import is reported as not recovered (exit 1)' \
  || fail "a failed import exited $STATUS"
ORDER="$(wp_steps | tr '\n' ' ')"
[ "$ORDER" = "abort begin import abort " ] \
  && pass 'the final abort ran even though the import failed' \
  || fail "a failed import ran: $ORDER"
grep -Fq 'final-abort: ok' "$TMP/importfail.txt" \
  && pass 'the outcome records the final abort as completed' \
  || fail 'the outcome did not record the mandatory final abort'
grep -Fq 'the claim this recovery was performed under, unchanged' "$TMP/importfail.txt" \
  && pass 'the claim is printed again in the outcome, unchanged' \
  || fail 'the outcome did not reprint the claim'

# A failed step 1 stops before step 2: expiry lets a DIFFERENT promotion owner
# recover the target; it does not authorize this restore.
DUO_RECOVER_STATUS="$TMP/f/status/database-only.json" DUO_ABORT_EXIT=5 \
  recover "leasefail" --restore=receipt-recover-fixture --writers-excluded
ORDER="$(wp_steps | tr '\n' ' ')"
[ "$ORDER" = "abort " ] \
  && pass 'a lease cleanup that did not succeed stops before begin/import' \
  || fail "a failed lease cleanup ran: $ORDER"
# A failure the target did not classify keeps the constant sentence, so the
# code below is a real signal rather than decoration on every failure.
grep -Fq 'abort: FAILED — the target refused or failed this step; inspect private operator evidence' \
  "$TMP/leasefail.txt" \
  && pass 'an unclassified step failure keeps its constant detail' \
  || { fail 'an unclassified step failure lost the constant detail'; sed -n '1,25p' "$TMP/leasefail.txt" >&2; }
grep -Eq '^ +(reason|remedy): ' "$TMP/leasefail.txt" \
  && fail 'an unclassified failure invented a reason code' \
  || pass 'an unclassified failure claims no reason code it was not given'

# ------------------------------------------- the target's own refusal, DUO-3506
say 'a step-1 refusal the target classified'

# The lease steps are asked in MACHINE mode so the target can answer with a
# reason code at all. `CodeDeploy::abortArgs()`/`beginArgs()` deliberately stay
# human for promote/deploy's compensating cleanup, which renders the target's
# raw streams to a waiting operator (cli/duo:3094-3103); the recovery-only
# variants are what carry --format=json.
php -r '
require_once $argv[1] . "/cli/src/Transport/CodeDeploy.php";
$cd = "Duo\\Orchestrator\\CodeDeploy";
$owner = "promote-recover-fixture-owner";
$hash = str_repeat("ab", 32);
$fail = [];
foreach (["recoveryAbortArgs", "recoveryBeginArgs"] as $recovery) {
    if (!in_array("--format=json", $cd::$recovery($owner, $hash), true)) {
        $fail[] = "$recovery() does not ask for JSON";
    }
}
foreach (["abortArgs", "beginArgs"] as $shared) {
    if (in_array("--format=json", $cd::$shared($owner, $hash), true)) {
        $fail[] = "$shared() gained --format=json; promote/deploy cleanup output would move (rule 8)";
    }
}
// Same command, same identity, same order -- only the reply format differs.
if ($cd::recoveryAbortArgs($owner, $hash)
    !== array_merge($cd::abortArgs($owner, $hash), ["--format=json"])) {
    $fail[] = "recoveryAbortArgs() is not abortArgs() plus the format flag";
}
if ($fail !== []) {
    fwrite(STDERR, "FAIL: " . implode(" | ", $fail) . "\n");
    exit(1);
}
echo "ok: only the recovery-path lease steps ask the target in machine mode\n";
' "$ROOT" || fail 'the recovery lease steps do not ask in machine mode'

# Exactly what the agent puts on stdout when a --format=json abort is refused:
# one `duo-command-refusal/v1` object, non-zero exit, nothing on stderr
# (agent/src/Command/Cli.php:145-146). The reason code and the two reviewed
# public fields are `PromotionLease::assert_abort_session()`'s own
# (agent/src/Promotion/PromotionLease.php), which
# offline/recovery/regress_promotion_abort_reason.php pins at the source.
ENVELOPE="$TMP/superseded-envelope.json"
cat > "$ENVELOPE" <<'JSON'
{"format":"duo-command-refusal/v1","ok":false,"command":"promotion-abort","error":"promotion_abort_session_superseded","reason_code":"promotion_abort_session_superseded","message":"promotion abort refused: a newer promotion session superseded the one this abort names","remediation":"restore or recover the release that owns the latest begun promotion session; an obsolete checkpoint is not a safe recovery source, so recover this target through the provider that owns its backups instead"}
JSON

DUO_RECOVER_STATUS="$TMP/f/status/database-only.json" DUO_ABORT_EXIT=1 \
  DUO_ABORT_ENVELOPE="$ENVELOPE" \
  recover "superseded" --restore=receipt-recover-fixture --writers-excluded
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a refused step 1 is reported as not recovered (exit 1)' \
  || fail "a refused step 1 exited $STATUS"
ORDER="$(wp_steps | tr '\n' ' ')"
[ "$ORDER" = "abort " ] \
  && pass 'a refused step 1 still stops before begin/import' \
  || fail "a refused step 1 ran: $ORDER"
grep -Fq 'abort: FAILED — promotion abort refused: a newer promotion session superseded the one this abort names' \
  "$TMP/superseded.txt" \
  && pass "the failed step carries the target's own public message, not the constant sentence" \
  || { fail 'the failed step still reported the constant sentence'; sed -n '1,25p' "$TMP/superseded.txt" >&2; }
grep -Fq '    reason: promotion_abort_session_superseded' "$TMP/superseded.txt" \
  && pass 'the human view names promotion_abort_session_superseded' \
  || fail 'the human view did not name the reason code'
grep -Fq '    remedy: restore or recover the release that owns the latest begun promotion session' \
  "$TMP/superseded.txt" \
  && pass 'the human view carries the remedy, which is the only next action there is' \
  || fail 'the human view did not carry the remedy'
# §5.2: the target's operator sentence names the superseding lease owner and a
# 64-hex artifact hash. Neither is consumed by any documented command, so the
# host publishes the reviewed fields and never the raw streams.
grep -Eq '[0-9a-f]{64}' "$TMP/superseded.txt" \
  && fail 'a 64-hex identifier reached the recover human view' \
  || pass 'no artifact hash reaches the human view'

# The same three facts, additively, in the machine document.
DUO_RECOVER_STATUS="$TMP/f/status/database-only.json" DUO_ABORT_EXIT=1 \
  DUO_ABORT_ENVELOPE="$ENVELOPE" \
  recover "supersededjson" --restore=receipt-recover-fixture --writers-excluded --format=json
php -r '
$doc = json_decode((string) file_get_contents($argv[1]), true);
if (!is_array($doc)) {
    fwrite(STDERR, "FAIL: recover --format=json produced no document\n");
    exit(1);
}
$fail = [];
if (($doc["format"] ?? null) !== "duo-recovery-outcome/v1") {
    $fail[] = "the outcome format moved";
}
$step = $doc["steps"][0] ?? [];
if (($step["step"] ?? null) !== "abort" || ($step["ok"] ?? null) !== false) {
    $fail[] = "step 1 is not the failed abort";
}
if (($step["reason_code"] ?? null) !== "promotion_abort_session_superseded") {
    $fail[] = "the failed step carries no reason_code";
}
if (!str_contains((string) ($step["remediation"] ?? ""), "provider that owns its backups")) {
    $fail[] = "the failed step carries no remediation";
}
if (($step["detail"] ?? "") === "the target refused or failed this step; inspect private operator evidence") {
    $fail[] = "the machine detail is still the constant sentence";
}
// The additive fields are exactly that: nothing the document already
// published moved.
foreach (["checkpoint", "checkpoint_at", "checkpoint_source", "claim", "environment", "recovered", "steps"] as $key) {
    if (!array_key_exists($key, $doc)) { $fail[] = "the outcome lost $key"; }
}
if ($fail !== []) {
    fwrite(STDERR, "FAIL: " . implode(" | ", $fail) . "\n");
    exit(1);
}
echo "ok: duo-recovery-outcome/v1 carries the reason code and remedy on the failed step\n";
' "$TMP/supersededjson.txt" || fail 'the machine outcome did not carry the classified refusal'

# ------------------------------------------------------ an absent checkpoint
say 'an absent checkpoint'
rm -f "$TMP/f/target/.duo/checkpoints/promote-recover-fixture-owner.sql"
DUO_RECOVER_STATUS="$TMP/f/status/database-only.json" \
  recover "nockpt" --restore=receipt-recover-fixture --writers-excluded
STATUS=$?
[ "$STATUS" = 1 ] && pass 'an absent checkpoint refuses (exit 1)' || fail "an absent checkpoint exited $STATUS"
grep -Fq 'checkpoint_unavailable' "$TMP/nockpt.txt" \
  && pass 'an absent or truncated checkpoint refuses rather than importing nothing' \
  || fail 'an absent checkpoint did not refuse with checkpoint_unavailable'
ORDER="$(wp_steps | tr '\n' ' ')"
[ "$ORDER" = "abort " ] || [ -z "${ORDER// /}" ] \
  && pass 'an absent checkpoint never reaches the import' \
  || fail "an absent checkpoint ran: $ORDER"

# ---------------------------------------------- retained release checkpoints
say 'retained release checkpoints on a transport with no rollback authority'
# The fixture deleted the receipt checkpoint above; put it back for this part.
printf -- '-- fixture checkpoint\n' > "$TMP/f/target/.duo/checkpoints/promote-recover-fixture-owner.sql"

recover_plain "plainlist" --list
STATUS=$?
[ "$STATUS" = 0 ] && pass 'a local transport lists (exit 0) instead of refusing' \
  || { fail "a local transport --list exited $STATUS"; sed -n '1,20p' "$TMP/plainlist.txt" >&2; }
grep -Fq 'checkpoints: 3' "$TMP/plainlist.txt" \
  && pass 'every retained checkpoint is counted, promote- and deploy- alike' \
  || { fail 'the retained checkpoints were not counted'; sed -n '1,12p' "$TMP/plainlist.txt" >&2; }
grep -Fq 'deploy-recover-fixture-owner  retained  retained-release-checkpoint' "$TMP/plainlist.txt" \
  && pass 'a deploy checkpoint lists under the same kind a promote checkpoint does' \
  || { fail 'the deploy checkpoint was not listed'; sed -n '1,16p' "$TMP/plainlist.txt" >&2; }
grep -Fq 'this transport carries no rollback authority runtime, so only the database checkpoints its releases retained are listed' "$TMP/plainlist.txt" \
  && pass 'the listing says which source it could not read' \
  || fail 'the listing did not disclose the missing authority source'
grep -Fq 'retained release checkpoints are the plain database checkpoints promote and deploy kept under .duo/checkpoints' "$TMP/plainlist.txt" \
  && pass 'the listing says what a retained checkpoint is and how it is restored' \
  || fail 'the listing did not disclose what a retained checkpoint is'
# DUO-3506: the listing discloses the refusal an older checkpoint can meet at
# step 1, WITHOUT claiming to know which row it applies to. `promotion_session`
# is target-side state no host verb reads, and an older checkpoint is still
# restorable when no later session was begun, so a per-row "not restorable"
# marker would be a fabrication and would take away a restore the target allows.
grep -Fq "a retained checkpoint older than the target's latest begun promotion session is refused at step 1 with promotion_abort_session_superseded" \
  "$TMP/plainlist.txt" \
  && pass 'the listing names the supersession refusal an older checkpoint can meet' \
  || { fail 'the listing did not disclose the supersession refusal'; sed -n '1,16p' "$TMP/plainlist.txt" >&2; }
grep -Fq 'provider that owns the target' "$TMP/plainlist.txt" \
  && pass 'the listing carries the remedy beside that refusal' \
  || fail 'the listing named the refusal without its remedy'
# The disclosure is a note; no ROW may carry a supersession verdict. Row lines
# are the ones that are not `note: ` lines and are not the `covers:` detail.
grep -v '^note: ' "$TMP/plainlist.txt" | grep -Fiq 'supersede' \
  && fail 'a listing row carries a supersession verdict the host cannot reach' \
  || pass 'no row is marked superseded — step 1 stays the authority'
grep -Fq 'receipt-recover-fixture' "$TMP/plainlist.txt" \
  && fail 'a local transport printed a signed receipt it cannot have read' \
  || pass 'no signed receipt is invented on a transport without an authority runtime'
grep -Fq 'generation' "$TMP/plainlist.txt" \
  && fail 'a retained checkpoint printed a signed generation it does not have' \
  || pass 'a retained checkpoint prints no generation'
[ -s "$DUO_WP_CALLS" ] && fail '--list on a local transport ran a recovery step' || pass '--list on a local transport runs no recovery step'
# Newest first: the code-phase checkpoint is dated 2023, the receipt one now.
FIRST_ID="$(grep -E '^  promote-' "$TMP/plainlist.txt" | head -1 | awk '{print $1}')"
[ "$FIRST_ID" = 'promote-recover-fixture-owner' ] \
  && pass 'retained checkpoints list newest first' \
  || fail "the first retained row was '$FIRST_ID', not the newest checkpoint"

# The restore: the same four ordered steps, under the lease identity the
# release used — the owner from the file name, the artifact hash from the
# retained compiled artifact.
recover_plain "plainrestore" --restore=promote-recover-fixture-owner --writers-excluded
STATUS=$?
[ "$STATUS" = 0 ] && pass 'a retained checkpoint restores on a local transport (exit 0)' \
  || { fail "the retained restore exited $STATUS"; sed -n '1,25p' "$TMP/plainrestore.txt" >&2; }
ORDER="$(wp_steps | tr '\n' ' ')"
[ "$ORDER" = "abort begin import abort " ] \
  && pass 'a retained checkpoint is restored through exactly abort -> begin -> import -> final abort' \
  || fail "the retained restore ran: $ORDER"
grep -Fq -- '--promotion-owner=recover-fixture-owner' "$DUO_WP_CALLS" \
  && pass 'the recovery lease names the owner promote used (from the checkpoint file name)' \
  || fail 'the recovery lease did not carry the promote owner'
grep -Fq -- '--artifact-hash=a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1' "$DUO_WP_CALLS" \
  && pass 'the recovery lease names the artifact hash promote used (from the retained compiled artifact)' \
  || fail 'the recovery lease did not carry the retained artifact hash'
grep -Fq 'promote-recover-fixture-owner.sql' "$DUO_WP_CALLS" \
  && pass 'the import reads exactly the retained checkpoint file' \
  || fail 'the import did not name the retained checkpoint file'
grep -Fq 'recovery profile: operator-directed' "$TMP/plainrestore.txt" \
  && pass 'the retained restore prints the operator-directed claim before acting' \
  || fail 'the retained restore did not print the claim'
grep -Fq 'checkpoint at: ' "$TMP/plainrestore.txt" \
  && pass 'the checkpoint instant is printed beside the claim' \
  || fail 'no checkpoint instant was printed'
php -r '
$out = (string) file_get_contents($argv[1]);
$claim = json_decode((string) file_get_contents($argv[2]), true);
$missing = [];
foreach (array_merge($claim["restores"], $claim["does_not_restore"]) as $line) {
    if (!str_contains($out, $line)) { $missing[] = $line; }
}
if ($missing !== []) {
    fwrite(STDERR, "FAIL: the retained restore dropped: " . implode(" | ", $missing) . "\n");
    exit(1);
}
echo "ok: the retained restore prints the frozen plan claim for that artifact verbatim, restores and does-not-restore\n";
' "$TMP/plainrestore.txt" "$TMP/f/claim.json" || fail 'the retained restore claim is not the frozen plan claim'

# The deploy checkpoint: the same four ordered steps, under the lease identity
# read from ITS OWN sibling artifact. Only the file-name prefix differs.
recover_plain "plaindeploynoexcl" --restore=deploy-recover-fixture-owner
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a deploy checkpoint restore without --writers-excluded refuses (exit 1)' \
  || fail "a deploy checkpoint restore without --writers-excluded exited $STATUS"
grep -Fq 'writer_exclusion_required' "$TMP/plaindeploynoexcl.txt" \
  && pass 'the deploy checkpoint refusal names writer_exclusion_required too' \
  || fail 'the deploy checkpoint restore did not require writer exclusion'
[ -s "$DUO_WP_CALLS" ] \
  && fail 'a refused deploy checkpoint restore still touched the target' \
  || pass 'a refused deploy checkpoint restore runs nothing at all'

recover_plain "plaindeploy" --restore=deploy-recover-fixture-owner --writers-excluded
STATUS=$?
[ "$STATUS" = 0 ] && pass 'a retained deploy checkpoint restores on a local transport (exit 0)' \
  || { fail "the deploy checkpoint restore exited $STATUS"; sed -n '1,25p' "$TMP/plaindeploy.txt" >&2; }
ORDER="$(wp_steps | tr '\n' ' ')"
[ "$ORDER" = "abort begin import abort " ] \
  && pass 'a deploy checkpoint is restored through exactly abort -> begin -> import -> final abort' \
  || fail "the deploy checkpoint restore ran: $ORDER"
grep -Fq 'deploy-recover-fixture-owner.sql' "$DUO_WP_CALLS" \
  && pass 'the import reads exactly the file duo deploy wrote' \
  || fail 'the import did not name the deploy checkpoint file'
grep -Fq -- '--artifact-hash=a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1' "$DUO_WP_CALLS" \
  && pass 'the deploy recovery lease names the hash from the sibling deploy-<owner>.json' \
  || fail 'the deploy recovery lease did not carry its own artifact hash'
grep -Fq 'recovery profile: operator-directed' "$TMP/plaindeploy.txt" \
  && pass 'the deploy checkpoint restore prints the operator-directed claim before acting' \
  || fail 'the deploy checkpoint restore did not print the claim'

# The final abort is mandatory for a deploy checkpoint as well.
DUO_IMPORT_EXIT=3 recover_plain "plaindeployimportfail" --restore=deploy-recover-fixture-owner --writers-excluded
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a failed deploy-checkpoint import is reported as not recovered (exit 1)' \
  || fail "a failed deploy-checkpoint import exited $STATUS"
ORDER="$(wp_steps | tr '\n' ' ')"
[ "$ORDER" = "abort begin import abort " ] \
  && pass 'the final abort ran for the deploy checkpoint even though the import failed' \
  || fail "a failed deploy-checkpoint import ran: $ORDER"

# An absent deploy checkpoint refuses, and refuses for the right reason. A
# RETAINED row IS its file (RetainedCheckpoints::script()'s `[ -s "$f" ]` skips
# a missing or zero-byte one), so the id stops existing rather than becoming an
# unrestorable row — which is why this is checkpoint_unknown and not the
# checkpoint_unavailable a signed receipt gets above at the same emptiness. A
# retained checkpoint has no source of truth other than the file.
mv "$TMP/f/target/.duo/checkpoints/deploy-recover-fixture-owner.sql" "$TMP/deploy-checkpoint.hold"
recover_plain "plaindeploygone" --restore=deploy-recover-fixture-owner --writers-excluded
STATUS=$?
[ "$STATUS" = 1 ] && pass 'an absent deploy checkpoint refuses (exit 1)' \
  || fail "an absent deploy checkpoint exited $STATUS"
grep -Fq 'checkpoint_unknown' "$TMP/plaindeploygone.txt" \
  && pass 'an absent deploy checkpoint refuses by name rather than importing nothing' \
  || { fail 'an absent deploy checkpoint did not refuse with checkpoint_unknown'; sed -n '1,12p' "$TMP/plaindeploygone.txt" >&2; }
STEPS="$(wp_steps | tr '\n' ' ')"
[ -z "${STEPS// /}" ] \
  && pass 'an absent deploy checkpoint never reaches step 1' \
  || fail "an absent deploy checkpoint ran steps: $STEPS"
mv "$TMP/deploy-checkpoint.hold" "$TMP/f/target/.duo/checkpoints/deploy-recover-fixture-owner.sql"

# Code first holds for a retained checkpoint too: the checkpoint file carries
# no code evidence, so the question is asked of the frozen plan for that
# artifact, which entered the code lifecycle window.
recover_plain "plaincode" --restore=promote-recover-fixture-code --writers-excluded
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a retained checkpoint of a code-phase release refuses a database import (exit 1)' \
  || { fail "the code-phase retained restore exited $STATUS"; sed -n '1,25p' "$TMP/plaincode.txt" >&2; }
grep -Fq 'recover_code_not_reconciled' "$TMP/plaincode.txt" \
  && pass 'the retained code-first refusal names recover_code_not_reconciled' \
  || fail 'the retained code-first refusal did not name itself'
grep -Fq 'e1e1e1e1e1e1' "$TMP/plaincode.txt" \
  && pass 'the retained code-first refusal names the pre-release revision from the frozen plan' \
  || fail 'the retained code-first refusal did not name the frozen plan revision'
STEPS="$(wp_steps | tr '\n' ' ')"
[ -z "${STEPS// /}" ] \
  && pass 'the retained code-first refusal happens before step 1' \
  || fail "the retained code-first refusal still ran steps: $STEPS"

# The final abort is mandatory here as well.
DUO_IMPORT_EXIT=3 recover_plain "plainimportfail" --restore=promote-recover-fixture-owner --writers-excluded
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a failed retained import is reported as not recovered (exit 1)' \
  || fail "a failed retained import exited $STATUS"
ORDER="$(wp_steps | tr '\n' ' ')"
[ "$ORDER" = "abort begin import abort " ] \
  && pass 'the final abort ran for the retained checkpoint even though the import failed' \
  || fail "a failed retained import ran: $ORDER"

# No identity, no lease: a retained checkpoint whose artifact is gone is
# listed (the absence is printed) and refuses to restore before step 1.
rm -f "$TMP/f/target/.duo/artifacts/promote-recover-fixture-owner.json"
recover_plain "plainnoid" --list
grep -Fq 'has no lease identity and cannot be restored by this command' "$TMP/plainnoid.txt" \
  && pass 'a retained checkpoint without its artifact is listed with the no-identity disclosure' \
  || fail 'the no-identity disclosure was not printed'
recover_plain "plainnoidrestore" --restore=promote-recover-fixture-owner --writers-excluded
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a retained checkpoint without its artifact refuses to restore (exit 1)' \
  || fail "a no-identity restore exited $STATUS"
grep -Fq 'checkpoint_identity_unknown' "$TMP/plainnoidrestore.txt" \
  && pass 'the refusal names checkpoint_identity_unknown' \
  || fail 'the no-identity refusal did not name itself'
STEPS="$(wp_steps | tr '\n' ' ')"
[ -z "${STEPS// /}" ] \
  && pass 'a lease this command cannot name is a lease it never takes: no step ran' \
  || fail "the no-identity restore ran steps: $STEPS"

# The one thing a non-SSH target genuinely cannot do keeps its typed refusal.
grep -Fq "'recovery_authority_unavailable'" "$ROOT/cli/src/Command/RecoverCommand.php" \
  && pass 'the signed rollback still refuses with recovery_authority_unavailable off SSH' \
  || fail 'recovery_authority_unavailable disappeared from RecoverCommand'

# ------------------------------------------------------------- cli/duo wiring
say 'cli/duo wiring'
grep -Fq "'recover' => cmd_recover(\$transport, \$extra)" "$ROOT/cli/duo" \
  && pass 'recover is registered in the dispatch match' \
  || fail 'recover is not registered in cli/duo dispatch'
grep -Fq 'duo recover <env>' "$ROOT/cli/duo" \
  && pass 'recover appears in the public usage text' \
  || fail 'recover is missing from duo_usage()'

printf '\n'
if [ "$FAILURES" -ne 0 ]; then
  printf 'REGRESS_RECOVER_ORDERING FAILED (%d)\n' "$FAILURES" >&2
  exit 1
fi
printf 'REGRESS_RECOVER_ORDERING PASSED\n'
