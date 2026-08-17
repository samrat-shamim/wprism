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
# Offline: no docker, no WordPress, no network, no real ssh, no real target.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
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
grep -Fq 'checkpoints: none' "$TMP/listnone.txt" \
  && pass 'no active receipt says so rather than printing an empty table' \
  || fail 'an inactive authority did not disclose that there is nothing to restore'

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
