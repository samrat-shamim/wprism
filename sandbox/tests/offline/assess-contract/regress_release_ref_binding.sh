#!/usr/bin/env bash
# Regression — `--from <ref>` is a read-only binding in plan-only mode. Source
# delivery is now exclusively stage-source -> prepare -> signed execute; the
# old public fast-forward branch must remain unreachable.
#
# Release resolves the ref in the local site repository, reads the TARGET
# repository's own HEAD through the driver, and refuses a mismatch with the
# next action `reconcile`. The refusal is not a retry: a retry asserts the
# same ref against the same target and gets the same answer.
#
# Plan-only mutates nothing. Inert source delivery, target identity and exact
# commit binding are exercised by regress_release_stage_prepare.sh.
#
# Offline: no docker, no WordPress, no network, no target.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/wprism-release-ref-binding.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT INT TERM

FAILURES=0
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; FAILURES=$((FAILURES + 1)); }
say()  { printf '\n== %s ==\n' "$*"; }

printf '== syntax ==\n'
php -l "$ROOT/cli/wprism" >/dev/null || fail 'php -l cli/wprism'
php -l "$ROOT/cli/src/Command/ReleaseCommand.php" >/dev/null || fail 'php -l ReleaseCommand.php'
pass 'PHP syntax'

php "$ROOT/sandbox/tests/fixtures/release/make-release-site.php" "$TMP/site" >/dev/null \
  || { echo "FAIL: could not build the release fixture" >&2; exit 1; }

SITE="$TMP/site/repo"
export WPRISM_FIXTURES="$TMP/site/fixtures"
export WPRISM_SITE_REPO="$SITE"
export WPRISM_CALLS="$TMP/calls.txt"
PATH="$TMP/site/bin:$PATH"
export PATH

# A `git` shim on PATH ahead of the real one, recording every invocation the
# host makes and then delegating. The assertion below reads THIS file, so it
# is about what the command actually ran, not about what its source looks
# like it runs.
REAL_GIT="$(command -v git)"
mkdir -p "$TMP/site/bin"
cat > "$TMP/site/bin/git" <<SHIM
#!/usr/bin/env bash
printf '%s\n' "\$*" >> "$TMP/git-calls.txt"
exec "$REAL_GIT" "\$@"
SHIM
chmod +x "$TMP/site/bin/git"

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
' "$SITE/.wprism/contract/fixture/proposed.json"
wprism "$TMP/accept.txt" contract fixture accept \
  || { fail 'contract accept failed'; cat "$TMP/accept.txt.err" >&2; }

RELEASE_DIR="$SITE/.wprism/releases"
: > "$TMP/git-calls.txt"

# --------------------------------------------------------------- the mismatch
say '--from on a ref the target is not on'
wprism "$TMP/mismatch.txt" release fixture --from=other --plan-only --format=json
STATUS=$?
cat "$TMP/mismatch.txt.err" >> "$TMP/mismatch.txt"
[ "$STATUS" = 1 ] && pass 'a ref mismatch refuses (exit 1)' || fail "a ref mismatch exited $STATUS"
grep -Fq 'release_ref_mismatch' "$TMP/mismatch.txt" \
  && pass 'the refusal names release_ref_mismatch' \
  || { fail 'the refusal did not name release_ref_mismatch'; sed -n '1,25p' "$TMP/mismatch.txt" >&2; }
grep -Eq 'next.action"?:? *"?reconcile' "$TMP/mismatch.txt" \
  && pass 'the next action is reconcile' \
  || fail 'the ref-mismatch refusal did not offer reconcile'
grep -Eq 'next.action"?:? *"?retry' "$TMP/mismatch.txt" \
  && fail 'the ref-mismatch refusal offered retry, which asserts the same ref again' \
  || pass 'the ref-mismatch refusal never offers retry'
grep -Fq 'stage-source' "$TMP/mismatch.txt" \
  && grep -Fq 'release execute' "$TMP/mismatch.txt" \
  && pass 'the remediation names the externally authorized delivery path' \
  || fail 'the remediation does not explain the signed source-delivery path'
[ -d "$RELEASE_DIR" ] && [ -n "$(ls -A "$RELEASE_DIR" 2>/dev/null)" ] \
  && fail 'a ref mismatch still froze an authorization plan' \
  || pass 'a ref mismatch freezes nothing'

# ------------------------------------------------------------ no git transport
say 'plan-only performs no git transport'
FORBIDDEN=0
while IFS= read -r line; do
  case " $line " in
    *" push "*|*" fetch "*|*" checkout "*|*" reset "*|*" pull "*|*" clone "*|*" merge "*|*" switch "*|*" cherry-pick "*)
      fail "release issued a mutating/transferring git command: git $line"
      FORBIDDEN=1 ;;
  esac
done < "$TMP/git-calls.txt"
[ "$FORBIDDEN" = 0 ] && pass 'plan-only issued no push/fetch/pull/clone/checkout/reset/merge'
REV_PARSE=$(grep -c 'rev-parse' "$TMP/git-calls.txt" || true)
[ "$REV_PARSE" -ge 2 ] \
  && pass "the binding is read-only: $REV_PARSE git rev-parse calls and nothing else that touches refs" \
  || fail "expected at least two git rev-parse calls, saw $REV_PARSE"

# The target side is read through the DRIVER, with the target's own repo path.
grep -Fq "rev-parse HEAD" "$TMP/calls.txt" 2>/dev/null && true
grep -q "rev-parse HEAD" "$TMP/git-calls.txt" \
  && pass "the target's own HEAD is read rather than assumed" \
  || fail "release never read the target repository HEAD"

# ------------------------------------------------------------- the match case
say '--from on the ref the target is actually on'
: > "$TMP/git-calls.txt"
HEAD_REF="$(git -C "$SITE" rev-parse HEAD)"

# issue #3510: `--plan-only` is documented three times (docs/guides/release.md:
# 122-124, docs/guides/daily-workflow.md:330-331, cli/README.md:502-503) as
# exiting "having mutated nothing at all — not the target, not the site
# repository." ReleaseCommand::prepare() used to call $store->writeProjection()
# unconditionally at step 3, before run()'s plan-only return at :215, so every
# plan-only release rewrote .wprism/contract/projection.json in the site repo.
# Capture the file's state (present-and-hashed, or absent) before the run
# below so the "unchanged" assertion after it is meaningful either way.
PROJECTION="$SITE/.wprism/contract/projection.json"
if [ -f "$PROJECTION" ]; then
  PROJECTION_BEFORE="$(sha256sum "$PROJECTION" | awk '{print $1}')"
else
  PROJECTION_BEFORE=""
fi

wprism "$TMP/match.txt" release fixture --from="$HEAD_REF" --plan-only
STATUS=$?
cat "$TMP/match.txt.err" >> "$TMP/match.txt"
[ "$STATUS" = 0 ] && pass 'a matching ref binds and the plan renders (exit 0)' \
  || { fail "a matching ref exited $STATUS"; sed -n '1,30p' "$TMP/match.txt" >&2; }
grep -Fq "releasing code revision $HEAD_REF" "$TMP/match.txt" \
  && pass 'the frozen plan names the exact revision it was bound to' \
  || fail 'the plan does not name the bound revision'

if [ -n "$PROJECTION_BEFORE" ]; then
  PROJECTION_AFTER="$(sha256sum "$PROJECTION" | awk '{print $1}')"
  [ "$PROJECTION_AFTER" = "$PROJECTION_BEFORE" ] \
    && pass 'plan-only leaves .wprism/contract/projection.json bytes unchanged' \
    || fail 'plan-only rewrote .wprism/contract/projection.json, contradicting the "mutated nothing" promise'
else
  [ ! -f "$PROJECTION" ] \
    && pass 'plan-only writes no .wprism/contract/projection.json into a site repository that had none' \
    || fail 'plan-only WROTE .wprism/contract/projection.json into a site repository that had none, contradicting the "mutated nothing… not the target, not the site repository" promise (docs/guides/release.md:122-124)'
fi

# ------------------------------------------------ public mutation retirement
say 'legacy executing --from is disabled'
: > "$TMP/git-calls.txt"
: > "$WPRISM_CALLS"
wprism "$TMP/legacy.txt" release fixture --from="$HEAD_REF" --yes --format=json
STATUS=$?
cat "$TMP/legacy.txt.err" >> "$TMP/legacy.txt"
[ "$STATUS" = 1 ] && grep -Fq 'release_external_authorization_required' "$TMP/legacy.txt" \
  && pass 'legacy --from --yes refuses with the external-authorization requirement' \
  || { fail "legacy --from --yes was not retired (exit $STATUS)"; sed -n '1,25p' "$TMP/legacy.txt" >&2; }
if grep -Eq ' fetch | reset | checkout | merge | pull | push ' "$TMP/git-calls.txt" \
  || grep -Eq 'wprism (promotion-begin|deploy|apply|code-stage)' "$WPRISM_CALLS"; then
  fail 'legacy --from --yes reached transport or promotion'
else
  pass 'legacy --from --yes performs no transport or promotion'
fi

# ----------------------------------------------------- an unresolvable ref
say 'a ref that does not exist locally'
: > "$TMP/git-calls.txt"
wprism "$TMP/unknown.txt" release fixture --from=no-such-ref --plan-only --format=json
STATUS=$?
cat "$TMP/unknown.txt.err" >> "$TMP/unknown.txt"
[ "$STATUS" = 1 ] && pass 'an unresolvable ref refuses (exit 1)' || fail "an unresolvable ref exited $STATUS"
grep -Fq 'release_ref_unresolvable' "$TMP/unknown.txt" \
  && pass 'an unresolvable ref refuses by its own reason code rather than being fetched' \
  || fail 'an unresolvable ref did not refuse with release_ref_unresolvable'
while IFS= read -r line; do
  case " $line " in
    *" fetch "*|*" clone "*) fail "an unresolvable ref triggered a git transport: git $line" ;;
  esac
done < "$TMP/git-calls.txt"
pass 'an unresolvable ref is never resolved by fetching it'

printf '\n'
if [ "$FAILURES" -ne 0 ]; then
  printf 'REGRESS_RELEASE_REF_BINDING FAILED (%d)\n' "$FAILURES" >&2
  exit 1
fi
printf 'REGRESS_RELEASE_REF_BINDING PASSED\n'
