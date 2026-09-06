#!/usr/bin/env bash
# Regression — issue #3268: login-keyed authored user-meta sidecars.
# Defaults to historical pair umeta3268; shared-host agents provide their own
# exact pair/ports through USER_META_PAIR/PORT1/PORT2. The caller owns teardown
# so failed evidence remains inspectable until it has been read.
set -euo pipefail
cd "$(dirname "$0")/../.." # -> sandbox/

PAIR=${USER_META_PAIR:-umeta3268}
PORT1=${USER_META_PORT1:-9301}
PORT2=${USER_META_PORT2:-9302}
export WPRISM_PAIR=$PAIR WPRISM_PORT1=$PORT1 WPRISM_PORT2=$PORT2
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml)
SITE1="siterepo/${PAIR}1"
SITE2="siterepo/${PAIR}2"

wp_env() { local side="$1"; shift; "${COMPOSE[@]}" run --rm -T "cli${side}" wp "$@"; }
wp1() { wp_env 1 "$@"; }
wp2() { wp_env 2 "$@"; }
say() { printf '\n== %s ==\n' "$*"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. lib/pair_db.sh
pair_db_select_engine

assert_redacted_json_refusal() {
  local command="$1" output="$2" label="$3" json remediation
  case "$command" in
    apply)
      remediation='inspect apply_in_progress and recovery evidence, then resume or recover according to the recorded phase'
      ;;
    capture)
      remediation='inspect private operator evidence and capture recovery state; classify, correct, or recover the blocker before another attempt'
      ;;
    *)
      fail "redacted JSON refusal helper has no reviewed remediation for $command"
      ;;
  esac
  json=$(tail -n 1 <<<"$output")
  jq -e --arg command "$command" --arg remediation "$remediation" '
    (keys | sort) == ["command", "details_redacted", "diagnostics", "error", "format", "message", "ok", "reason_code", "remediation"]
    and .format == "wprism-command-refusal/v1"
    and .ok == false
    and .command == $command
    and .error == ($command + "_failed")
    and .reason_code == ($command + "_failed")
    and .message == ($command + " refused at an unclassified safety gate")
    and .remediation == $remediation
    and .details_redacted == true
    and (.diagnostics | length == 1)
    and .diagnostics[0].code == ($command + "_failed")
    and .diagnostics[0].message == ($command + " refused at an unclassified safety gate")
    and .diagnostics[0].remediation == $remediation
  ' <<<"$json" >/dev/null \
    || fail "$label did not use the reviewed redacted JSON envelope: $json"
}

assert_typed_json_refusal() {
  local error_code="$1" message="$2" remediation="$3" key="$4" shape_key="$5" shape="$6"
  local diagnostic_message="$7" diagnostic_remediation="$8" output="$9" label="${10}" json
  json=$(tail -n 1 <<<"$output")
  jq -e \
    --arg error_code "$error_code" \
    --arg message "$message" \
    --arg remediation "$remediation" \
    --arg key "$key" \
    --arg shape_key "$shape_key" \
    --arg shape "$shape" \
    --arg diagnostic_message "$diagnostic_message" \
    --arg diagnostic_remediation "$diagnostic_remediation" '
      (keys | sort) == ["command", "diagnostics", "error", "format", "message", "ok", "reason_code", "remediation"]
      and .format == "wprism-command-refusal/v1"
      and .ok == false
      and .command == "capture"
      and .error == $error_code
      and .reason_code == $error_code
      and .message == $message
      and .remediation == $remediation
      and (.diagnostics | length == 1)
      and ((.diagnostics[0] | keys | sort) == (["code", "key", "message", "remediation", "surface", $shape_key] | sort))
      and .diagnostics[0].code == $error_code
      and .diagnostics[0].surface == "user_meta"
      and .diagnostics[0].key == $key
      and .diagnostics[0][$shape_key] == $shape
      and .diagnostics[0].message == $diagnostic_message
      and .diagnostics[0].remediation == $diagnostic_remediation
    ' <<<"$json" >/dev/null \
    || fail "$label did not use the reviewed typed JSON refusal envelope: $json"
}

command -v jq >/dev/null || fail "jq required"

say "reset and converge this test's own pair"
bash bin/pair.sh reset "$PAIR"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless

say "create exact-login users with deliberately divergent numeric ids"
SOURCE_EDITOR=$(wp1 user create agency-editor agency-editor-source@example.test --role=editor --user_pass=test --porcelain)
wp2 user create filler-one filler-one@example.test --role=subscriber --user_pass=test >/dev/null
wp2 user create filler-two filler-two@example.test --role=subscriber --user_pass=test >/dev/null
TARGET_EDITOR=$(wp2 user create agency-editor agency-editor-target@example.test --role=editor --user_pass=test --porcelain)
[ "$SOURCE_EDITOR" != "$TARGET_EDITOR" ] || fail "numeric ids should diverge across environments"

wp1 user meta update "$SOURCE_EDITOR" agency_color blue >/dev/null
wp1 user meta update "$SOURCE_EDITOR" profile_owner "$SOURCE_EDITOR" >/dev/null
wp1 user meta update "$SOURCE_EDITOR" runtime_marker source-runtime >/dev/null
wp2 user meta update "$TARGET_EDITOR" agency_color red >/dev/null
wp2 user meta update "$TARGET_EDITOR" runtime_marker target-runtime >/dev/null

mkdir -p "$SITE1"
cat > "$SITE1/site.wprism.json" <<'JSON'
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"],
    "term_meta": {},
    "user_meta": {
      "agency_color": {"class": "authored", "missing_user": "block"},
      "api_token": {"class": "authored"},
      "contact_email": {"class": "authored"},
      "optional_note": {"class": "authored", "missing_user": "warn"},
      "profile_owner": {"class": "authored", "ref": "user"},
      "runtime_marker": {"class": "runtime"},
      "strict_note": {"class": "authored", "missing_user": "block"}
    }
  },
  "spec_version": 2
}
JSON

sync_repo() {
  rm -rf "$SITE2/state"
  cp "$SITE1/site.wprism.json" "$SITE2/site.wprism.json"
  cp -R "$SITE1/state" "$SITE2/state"
}

say "capture emits one exact-login sidecar and excludes target-local keys"
wp1 wprism capture --repo=/siterepo --format=json >/tmp/wprism-umeta-capture.json
SIDE_FILE=$(find "$SITE1/state/user-meta" -type f -name '*.json')
[ "$(find "$SITE1/state/user-meta" -type f | wc -l | tr -d ' ')" = "1" ] || fail "expected one user-meta sidecar"
jq -e '.login == "agency-editor" and .meta.agency_color == "blue" and .meta.profile_owner == "user:agency-editor" and (.meta.runtime_marker | not)' "$SIDE_FILE" >/dev/null \
  || fail "captured sidecar shape/classification is wrong"
pass "source sidecar is login-keyed, authored-only, and contains no user uuid"

say "apply resolves the same exact login at a different numeric id"
sync_repo
APPLY1=$(wp2 wprism apply --repo=/siterepo --format=json --adopt-by-slug=posts,terms | tail -1)
[ "$(wp2 user meta get "$TARGET_EDITOR" agency_color)" = "blue" ] || fail "authored user meta did not apply"
[ "$(wp2 user meta get "$TARGET_EDITOR" profile_owner)" = "$TARGET_EDITOR" ] || fail "user ref did not resolve by target login"
[ "$(wp2 user meta get "$TARGET_EDITOR" runtime_marker)" = "target-runtime" ] || fail "target runtime user meta was clobbered"
echo "$APPLY1" | jq -e '.verification.result == "pass" and .verification.skipped_user_meta == 0' >/dev/null \
  || fail "canonical verifier did not certify the exact-login apply"
pass "divergent numeric ids converge by exact login; target runtime meta survives"

say "case-divergent and absent logins produce structured block vs warn-and-skip outcomes"
SOURCE_STRICT=$(wp1 user create case.editor case-source@example.test --role=author --user_pass=test --porcelain)
SOURCE_OPTIONAL=$(wp1 user create optional-editor optional-source@example.test --role=author --user_pass=test --porcelain)
TARGET_CASE=$(wp2 user create Case.Editor case-target@example.test --role=author --user_pass=test --porcelain)
[ "$(wp2 db query "SELECT user_login FROM wp_users WHERE ID=$TARGET_CASE" --skip-column-names)" = "Case.Editor" ] \
  || fail "target case-divergent login was not preserved exactly"
wp1 user meta update "$SOURCE_STRICT" strict_note strict-value >/dev/null
wp1 user meta update "$SOURCE_OPTIONAL" optional_note optional-value >/dev/null
wp1 user meta update "$SOURCE_EDITOR" agency_color green >/dev/null
wp1 wprism capture --repo=/siterepo --format=json >/tmp/wprism-umeta-capture-missing.json
sync_repo
PLAN=$(wp2 wprism plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN" | jq -e \
  '([.missing_user[].login] == ["case.editor"]) and ([.skipped_user_meta[].login] == ["optional-editor"])' >/dev/null \
  || fail "plan did not distinguish block from warn-and-skip missing logins"
if APPLY_FAIL=$(wp2 wprism apply --repo=/siterepo --format=json 2>&1); then
  fail "apply should refuse the case-divergent required login"
fi
assert_redacted_json_refusal apply "$APPLY_FAIL" "missing-user refusal"
APPLY_FAIL_JSON=$(tail -n 1 <<<"$APPLY_FAIL")
! grep -Fq 'case.editor' <<<"$APPLY_FAIL_JSON" \
  || fail "redacted missing-user refusal exposed the exact login in JSON: $APPLY_FAIL_JSON"
[ "$(wp2 user meta get "$TARGET_EDITOR" agency_color)" = "blue" ] || fail "required-login refusal happened after mutation"
pass "missing exact login blocks before mutation; JSON refusal is redacted and plan JSON carries the login; warn-only login remains explicitly skipped"

say "once the exact login exists, apply proceeds and reports the warn-only skip"
wp2 user delete "$TARGET_CASE" --yes >/dev/null
TARGET_STRICT=$(wp2 user create case.editor case-exact@example.test --role=author --user_pass=test --porcelain)
APPLY2=$(wp2 wprism apply --repo=/siterepo --format=json | tail -1)
[ "$(wp2 user meta get "$TARGET_EDITOR" agency_color)" = "green" ] || fail "blocked authored update did not land after reconciliation"
[ "$(wp2 user meta get "$TARGET_STRICT" strict_note)" = "strict-value" ] || fail "exact-login sidecar did not land"
echo "$APPLY2" | jq -e '.verification.result == "pass" and .verification.skipped_user_meta == 1' >/dev/null \
  || fail "warn-only missing login was not recorded in verification evidence"
pass "exact login lands; optional missing login is a truthful warn-and-skip success"

say "removing the final authored value retains an empty sidecar and deletes only that owned key"
wp1 user meta delete "$SOURCE_EDITOR" agency_color >/dev/null
wp1 user meta delete "$SOURCE_EDITOR" profile_owner >/dev/null
wp1 wprism capture --repo=/siterepo --format=json >/tmp/wprism-umeta-capture-removal.json
EDITOR_FILE=$(jq -r 'select(.login == "agency-editor") | input_filename' "$SITE1"/state/user-meta/*.json)
[ -n "$EDITOR_FILE" ] || fail "empty sidecar was not retained after final key removal"
jq -e '.meta == {}' "$EDITOR_FILE" >/dev/null || fail "retained sidecar should carry an empty meta map"
sync_repo
wp2 wprism apply --repo=/siterepo --format=json >/tmp/wprism-umeta-apply-removal.json
if wp2 user meta get "$TARGET_EDITOR" agency_color >/dev/null 2>&1; then
  fail "removed authored key still exists on target"
fi
[ "$(wp2 user meta get "$TARGET_EDITOR" runtime_marker)" = "target-runtime" ] || fail "removal touched target runtime meta"
pass "last-key removal is explicit and ownership-exact"

say "removing the sidecar file itself is not deletion authority"
wp2 user meta update "$TARGET_EDITOR" agency_color target-only >/dev/null
"${COMPOSE[@]}" run --rm -T --entrypoint sh cli1 \
  -c 'rm -- "$1"' sh "/siterepo/${EDITOR_FILE#"$SITE1/"}"
sync_repo
wp2 wprism apply --repo=/siterepo --format=json >/tmp/wprism-umeta-apply-file-absence.json
[ "$(wp2 user meta get "$TARGET_EDITOR" agency_color)" = "target-only" ] \
  || fail "sidecar file absence incorrectly deleted target metadata"
pass "sidecar absence leaves target metadata untouched"

say "PII and secret values fail recursively until their exact rule is reviewed"
SOURCE_PII=$(wp1 user create pii-editor pii-source@example.test --role=author --user_pass=test --porcelain)
wp1 user meta update "$SOURCE_PII" contact_email editor@example.test >/dev/null
if PII_FAIL=$(wp1 wprism capture --repo=/siterepo --format=json 2>&1); then
  fail "PII-bearing authored user meta should refuse without allow_pii"
fi
assert_typed_json_refusal \
  personal_data_refused \
  "capture found personal data on an authored user-meta surface" \
  "keep the named field environment-local, or record an explicit reviewed allow-pii decision" \
  contact_email personal_data_shape "email address" \
  "authored state matched a personal-data signature" \
  "keep it environment-local or explicitly review allow-pii for this field" \
  "$PII_FAIL" "PII refusal"
PII_FAIL_JSON=$(tail -n 1 <<<"$PII_FAIL")
! grep -Fq 'pii-editor' <<<"$PII_FAIL_JSON" || fail "PII refusal exposed the exact login"
! grep -Fq 'editor@example.test' <<<"$PII_FAIL_JSON" || fail "PII refusal exposed the raw email value"

tmp_policy=$(mktemp)
jq '.policy.user_meta.contact_email.allow_pii = true' "$SITE1/site.wprism.json" > "$tmp_policy"
chmod 0644 "$tmp_policy"
mv "$tmp_policy" "$SITE1/site.wprism.json"
wp1 user meta update "$SOURCE_PII" api_token ghp_abcdefghijklmnopqrstuvwxyz123456 >/dev/null
if SECRET_FAIL=$(wp1 wprism capture --repo=/siterepo --format=json 2>&1); then
  fail "secret-bearing authored user meta should refuse without allow_secret"
fi
assert_typed_json_refusal \
  secret_state_refused \
  "capture found secret-shaped data on an authored surface" \
  "reclassify the named surface as environment/runtime state, or explicitly review and allow the false positive" \
  api_token secret_shape "github token" \
  "authored state matched a secret or credential-shape signature" \
  "reclassify it or record an explicit reviewed allow-secret decision" \
  "$SECRET_FAIL" "secret refusal"
SECRET_FAIL_JSON=$(tail -n 1 <<<"$SECRET_FAIL")
! grep -Fq 'ghp_abcdefghijklmnopqrstuvwxyz123456' <<<"$SECRET_FAIL_JSON" || fail "secret refusal exposed the raw token"
pass "PII and hard-secret scanning refuse in JSON with reviewed typed envelopes"

printf '\nREGRESS_USER_META PASSED\n'
