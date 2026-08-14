#!/usr/bin/env bash
# Build one independently current manifest/profile certification record.
# The reviewed disposition supplies the required test IDs. Standard tests are
# dispatched by convention; an extension-specific test is an executable
# certification/tests/<test-id>.sh driver, never a central switch statement.
set -euo pipefail
cd "$(dirname "$0")/.." # sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

SUBJECT_KEY="${1:-}"
if [[ "$SUBJECT_KEY" =~ ^(manifests|profiles)\.([a-z][a-z0-9-]*)$ ]]; then
  SECTION="${BASH_REMATCH[1]}"
  NAME="${BASH_REMATCH[2]}"
else
  fail "usage: certify_subject_bundle.sh <manifests.name|profiles.name>"
fi
KIND="${SECTION%s}"
CLAIM=$(jq -ce --arg section "$SECTION" --arg name "$NAME" \
  '.[$section][$name] // error("subject has no reviewed claim")' \
  ../manifests/dispositions.json) || fail "subject '$SUBJECT_KEY' has no reviewed claim"
MANIFEST=$(jq -r --arg kind "$KIND" --arg name "$NAME" \
  'if $kind == "manifest" then $name else .manifest end' <<<"$CLAIM")
[[ "$MANIFEST" =~ ^[a-z][a-z0-9-]*$ ]] || fail "subject '$SUBJECT_KEY' has no canonical manifest"
[ -f "../manifests/$MANIFEST.json" ] || fail "subject manifest '$MANIFEST' is absent"
mapfile -t REQUIRED_TESTS < <(jq -r '.evidence.tests[]?' <<<"$CLAIM")
[ "${#REQUIRED_TESTS[@]}" -gt 0 ] || fail "subject '$SUBJECT_KEY' declares no certification tests"
for TEST_ID in "${REQUIRED_TESTS[@]}"; do
  [[ "$TEST_ID" =~ ^[a-z][a-z0-9-]*$ ]] \
    || fail "subject '$SUBJECT_KEY' declares noncanonical certification test id '$TEST_ID'"
done

PAIR="${CERT_SUBJECT_PAIR:-subjectcert}"
PORT1="${CERT_SUBJECT_PORT1:-8920}"
PORT2="${CERT_SUBJECT_PORT2:-8921}"
OUT_ROOT="${CERT_SUBJECT_OUT:-/tmp/duo-subject-certification-bundles}"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "CERT_SUBJECT_PAIR must use lowercase letters/digits"
[[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ && "$PORT1" -ge 8900 && "$PORT2" -eq $((PORT1 + 1)) ]] \
  || fail "CERT_SUBJECT_PORT1 must be an even port >= 8900 and PORT2 must immediately follow"
(( PORT1 % 2 == 0 )) || fail "CERT_SUBJECT_PORT1 must be even"
case "${DUO_PAIR_BUDGET_OVERRIDE:-}" in
  ''|0|1) ;;
  *) fail "DUO_PAIR_BUDGET_OVERRIDE must be unset, 0, or 1" ;;
esac
command -v jq >/dev/null || fail "jq required"
command -v php >/dev/null || fail "php required"
# shellcheck source=../lib/pair_force_hatch.sh
source lib/pair_force_hatch.sh

REPO_ROOT=$(cd .. && pwd -P)
export DUO_SOURCE_ROOT="$REPO_ROOT"
SOURCE_SHA=$(git -C "$REPO_ROOT" rev-parse --verify HEAD^{commit}) \
  || fail "subject certification requires a Git checkout"
if [ -n "${CERT_SUBJECT_EXPECTED_SHA:-}" ] && [ "$SOURCE_SHA" != "$CERT_SUBJECT_EXPECTED_SHA" ]; then
  fail "subject certification source $SOURCE_SHA does not match batch source $CERT_SUBJECT_EXPECTED_SHA"
fi
assert_exact_source() {
  [ "$(git -C "$REPO_ROOT" rev-parse --verify HEAD^{commit})" = "$SOURCE_SHA" ] \
    || fail "subject certification source HEAD changed during the run"
  [ -z "$(git -C "$REPO_ROOT" status --porcelain=v1 --untracked-files=all)" ] \
    || fail "subject certification requires a clean exact-source checkout"
}
assert_exact_source
WORK_ROOT=$(mktemp -d /tmp/duo-subject-cert-run.XXXXXX)
cleanup() {
  bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  case "$WORK_ROOT" in
    /tmp/duo-subject-cert-run.*) rm -rf -- "$WORK_ROOT" ;;
    *) printf 'WARNING: refusing unexpected work-root cleanup: %s\n' "$WORK_ROOT" >&2 ;;
  esac
}
trap cleanup EXIT
pair_force_hatch_init "$WORK_ROOT/pair-force-hatches.log" \
  || fail "could not initialize the certification force-hatch ledger"

say "subject certification preflight: $SUBJECT_KEY @ $SOURCE_SHA"
php -l bin/subject-certification-bundle.php >/dev/null
bash -n conformance/run.sh tests/certify_version_matrix.sh
bash bin/pair.sh list
mkdir -p -- "$OUT_ROOT"
pass "builder, exact-source checkout, and shared-pair inventory are ready"

normalize_log() {
  local log="$1"
  php -r '$path=$argv[1]; $file=$argv[2]; $bytes=file_get_contents($file); if ($bytes === false || file_put_contents($file, str_replace($path, "<source-root>", $bytes)) === false) exit(1);' "$REPO_ROOT" "$log"
  sed -i.bak -E 's/[[:space:]]+$//' "$log"
  rm -f -- "$log.bak"
}

TEST_SPECS='[]'
for TEST_ID in "${REQUIRED_TESTS[@]}"; do
  LOG="$WORK_ROOT/$TEST_ID.log"
  RESULT="$WORK_ROOT/$TEST_ID.result.json"
  DIFF="$WORK_ROOT/$TEST_ID.diff.json"
  RC=0
  REASON=passed
  say "subject leg: $TEST_ID"

  if [ "$TEST_ID" = "conformance-$NAME" ]; then
    ENTRY_FILE="conformance/entries/$NAME.json"
    set +e
    if [ -f "$ENTRY_FILE" ]; then
      DUO_EXPECTED_SOURCE_SHA="$SOURCE_SHA" CONF_EXPECTED_SOURCE_SHA="$SOURCE_SHA" \
        CONF_PAIR="$PAIR" CONF1_PORT="$PORT1" CONF2_PORT="$PORT2" \
        CONFORMANCE_ENTRY_FILE="$ENTRY_FILE" CONFORMANCE_EVIDENCE_DIR="$WORK_ROOT" \
        bash conformance/run.sh "$NAME" >"$LOG" 2>&1
    else
      DUO_EXPECTED_SOURCE_SHA="$SOURCE_SHA" CONF_EXPECTED_SOURCE_SHA="$SOURCE_SHA" \
        CONF_PAIR="$PAIR" CONF1_PORT="$PORT1" CONF2_PORT="$PORT2" \
        CONFORMANCE_EVIDENCE_DIR="$WORK_ROOT" \
        bash conformance/run.sh "$NAME" >"$LOG" 2>&1
    fi
    RC=$?
    set -e
    [ "$RC" -eq 0 ] \
      && grep -qF "✔ CONFORMANCE PASSED ($NAME)" "$LOG" \
      && jq -e --arg id "$TEST_ID" '.test == $id and .verdict == "pass" and .exit_code == 0' "$RESULT" >/dev/null \
      && jq -e '.status == "clean"' "$DIFF" >/dev/null \
      || REASON=command_failed
  elif [ "$TEST_ID" = exact-artifact-version-matrix ] && [ "$KIND" = manifest ]; then
    MATRIX_DRIVER="certification/version-matrix/$NAME.sh"
    if [ -x "$MATRIX_DRIVER" ]; then
      set +e
      DUO_CERT_TEST_ID="$TEST_ID" DUO_CERT_SUBJECT="$SUBJECT_KEY" DUO_CERT_MANIFEST="$MANIFEST" DUO_CERT_SOURCE_SHA="$SOURCE_SHA" \
        DUO_CERT_PAIR="$PAIR" DUO_CERT_PORT1="$PORT1" DUO_CERT_PORT2="$PORT2" \
        DUO_CERT_RESULT="$RESULT" DUO_CERT_DIFF="$DIFF" \
        bash "$MATRIX_DRIVER" >"$LOG" 2>&1
      RC=$?
      set -e
      [ "$RC" -eq 0 ] \
        && jq -e --arg id "$TEST_ID" '.test == $id and .verdict == "pass" and .exit_code == 0' "$RESULT" >/dev/null \
        && jq -e '.status == "clean"' "$DIFF" >/dev/null \
        || REASON=command_failed
    else
      set +e
      DUO_EXPECTED_SOURCE_SHA="$SOURCE_SHA" \
        VMATRIX_MANIFEST="$NAME" VMATRIX_PAIR="$PAIR" VMATRIX_PORT1="$PORT1" VMATRIX_PORT2="$PORT2" \
        bash tests/certify_version_matrix.sh >"$LOG" 2>&1
      RC=$?
      set -e
      [ "$RC" -eq 0 ] && grep -qF '✔ CERTIFY_VERSION_MATRIX PASSED' "$LOG" || REASON=command_failed
      jq -n --arg test "$TEST_ID" \
        --arg verdict "$([ "$REASON" = passed ] && printf pass || printf fail)" \
        --arg reason "$REASON" --argjson exit_code "$RC" --arg subject "$SUBJECT_KEY" \
        '{schema_version:1,test:$test,verdict:$verdict,exit_code:$exit_code,reason:$reason,subject:$subject,
          assertions:["declared_artifacts_digest_verified","admitted_boundaries_round_trip","below_range_refused"]}' >"$RESULT"
      jq -n --arg status "$([ "$REASON" = passed ] && printf clean || printf unknown)" --arg manifest "$NAME" \
        '{status:$status,manifest:$manifest,diffs:["in-range-recapture"],negative_controls:["below-range-refused"]}' >"$DIFF"
    fi
  elif [ "$TEST_ID" = multisite-refusal ] && [ "$SUBJECT_KEY" = manifests.core ]; then
    set +e
    MULTISITE_PAIR="$PAIR" MULTISITE_PORT1="$PORT1" MULTISITE_PORT2="$PORT2" \
      bash tests/regress_multisite_refusal.sh >"$LOG" 2>&1
    RC=$?
    set -e
    [ "$RC" -eq 0 ] && grep -qF '✔ REGRESS_MULTISITE_REFUSAL PASSED' "$LOG" || REASON=command_failed
    jq -n --arg test "$TEST_ID" \
      --arg verdict "$([ "$REASON" = passed ] && printf pass || printf fail)" \
      --arg reason "$REASON" --argjson exit_code "$RC" \
      '{schema_version:1,test:$test,verdict:$verdict,exit_code:$exit_code,reason:$reason,
        assertions:["wordpress_runtime_reports_multisite","capture_nonzero","no_repository_publication","authored_canary_unchanged"]}' >"$RESULT"
    jq -n --arg status "$([ "$REASON" = passed ] && printf clean || printf unknown)" \
      '{status:$status,outcome:"no_mutation",checked:["site.duo.json","state","capture-staging","capture-backup","wordpress-option-canary"]}' >"$DIFF"
  elif [ -x "certification/tests/$TEST_ID.sh" ]; then
    set +e
    DUO_CERT_TEST_ID="$TEST_ID" DUO_CERT_SUBJECT="$SUBJECT_KEY" DUO_CERT_MANIFEST="$MANIFEST" DUO_CERT_SOURCE_SHA="$SOURCE_SHA" \
      DUO_CERT_PAIR="$PAIR" DUO_CERT_PORT1="$PORT1" DUO_CERT_PORT2="$PORT2" \
      DUO_CERT_RESULT="$RESULT" DUO_CERT_DIFF="$DIFF" \
      bash "certification/tests/$TEST_ID.sh" >"$LOG" 2>&1
    RC=$?
    set -e
    [ "$RC" -eq 0 ] \
      && jq -e --arg id "$TEST_ID" '.test == $id and .verdict == "pass" and .exit_code == 0' "$RESULT" >/dev/null \
      && jq -e '.status == "clean"' "$DIFF" >/dev/null \
      || REASON=command_failed
  else
    fail "subject '$SUBJECT_KEY' declares unsupported test '$TEST_ID'; add certification/tests/$TEST_ID.sh"
  fi

  [ -f "$LOG" ] || : >"$LOG"
  normalize_log "$LOG"
  tail -40 "$LOG"
  bash bin/pair.sh destroy "$PAIR"
  [ "$REASON" = passed ] || fail "subject test '$TEST_ID' failed"
  TEST_SPECS=$(jq -c --arg id "$TEST_ID" --arg result "$RESULT" --arg diff "$DIFF" --arg log "$LOG" \
    '. + [{id:$id,result:$result,diff:$diff,log:$log}]' <<<"$TEST_SPECS")
  pass "$TEST_ID passed and its pair was destroyed"
done

assert_exact_source
BOUND_INPUTS=$(php bin/subject-certification-bundle.php inputs "$KIND" "$NAME" "$REPO_ROOT" | jq -ce '.inputs') \
  || fail "could not derive the canonical subject closure"
FORCE_HATCHES=$(pair_force_hatch_json) \
  || fail "certification force-hatch ledger is missing, malformed, or contains an unreviewed hatch"
SPEC="$WORK_ROOT/$KIND-$NAME.spec.json"
jq -n --arg repo_root "$REPO_ROOT" --arg kind "$KIND" --arg name "$NAME" \
  --arg created_at "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" --arg git_revision "$SOURCE_SHA" \
  --argjson bound_inputs "$BOUND_INPUTS" --argjson force_hatches "$FORCE_HATCHES" --argjson tests "$TEST_SPECS" \
  '{repo_root:$repo_root,subject:{kind:$kind,name:$name},created_at:$created_at,git_revision:$git_revision,
    force_hatches:$force_hatches,bound_inputs:$bound_inputs,tests:$tests}' >"$SPEC"

BUILD=$(php bin/subject-certification-bundle.php build "$SPEC" "$OUT_ROOT") \
  || fail "subject bundle assembly failed"
BUNDLE=$(jq -r '.bundle // empty' <<<"$BUILD")
[ -n "$BUNDLE" ] && [ -d "$BUNDLE" ] || fail "builder did not return a content-addressed subject bundle"
php bin/subject-certification-bundle.php verify "$BUNDLE" "$REPO_ROOT" >/dev/null \
  || fail "new subject bundle did not verify against current bytes"
assert_exact_source
printf '%s\n' "$BUILD"
pass "$SUBJECT_KEY certificate is verified: $BUNDLE"
if [ "$FORCE_HATCHES" = '[]' ]; then
  printf 'To publish only this claim: php scripts/capability-registry.php import-subject-bundle %q\n' "$BUNDLE"
else
  printf 'Forced scoped evidence is verified but intentionally not publishable as a current capability claim.\n'
fi
