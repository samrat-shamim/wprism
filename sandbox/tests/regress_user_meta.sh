#!/usr/bin/env bash
# Regression — DUO-3268: login-keyed authored user-meta sidecars.
# Owns only pair umeta3268 and deliberately leaves it running for inspection.
set -euo pipefail
cd "$(dirname "$0")/.." # -> sandbox/

PAIR=umeta3268
export DUO_PAIR=$PAIR DUO_PORT1=9301 DUO_PORT2=9302
COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml)
SITE1="siterepo/${PAIR}1"
SITE2="siterepo/${PAIR}2"

wp_env() { local side="$1"; shift; "${COMPOSE[@]}" run --rm -T "cli${side}" wp "$@"; }
wp1() { wp_env 1 "$@"; }
wp2() { wp_env 2 "$@"; }
say() { printf '\n== %s ==\n' "$*"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

command -v jq >/dev/null || fail "jq required"

say "reset and converge this test's own pair"
bash bin/pair.sh reset "$PAIR"
bash bin/pair.sh up "$PAIR" 9301 9302 --headless

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
cat > "$SITE1/site.duo.json" <<'JSON'
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
  cp "$SITE1/site.duo.json" "$SITE2/site.duo.json"
  cp -R "$SITE1/state" "$SITE2/state"
}

say "capture emits one exact-login sidecar and excludes target-local keys"
wp1 duo capture --repo=/siterepo --format=json >/tmp/duo-umeta-capture.json
SIDE_FILE=$(find "$SITE1/state/user-meta" -type f -name '*.json')
[ "$(find "$SITE1/state/user-meta" -type f | wc -l | tr -d ' ')" = "1" ] || fail "expected one user-meta sidecar"
jq -e '.login == "agency-editor" and .meta.agency_color == "blue" and .meta.profile_owner == "user:agency-editor" and (.meta.runtime_marker | not)' "$SIDE_FILE" >/dev/null \
  || fail "captured sidecar shape/classification is wrong"
pass "source sidecar is login-keyed, authored-only, and contains no user uuid"

say "apply resolves the same exact login at a different numeric id"
sync_repo
APPLY1=$(wp2 duo apply --repo=/siterepo --format=json --adopt-by-slug=posts,terms | tail -1)
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
wp1 duo capture --repo=/siterepo --format=json >/tmp/duo-umeta-capture-missing.json
sync_repo
PLAN=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN" | jq -e \
  '([.missing_user[].login] == ["case.editor"]) and ([.skipped_user_meta[].login] == ["optional-editor"])' >/dev/null \
  || fail "plan did not distinguish block from warn-and-skip missing logins"
if APPLY_FAIL=$(wp2 duo apply --repo=/siterepo --format=json 2>&1); then
  fail "apply should refuse the case-divergent required login"
fi
APPLY_FAIL_JSON=$(tail -n 1 <<<"$APPLY_FAIL")
jq -e '
  (keys | sort) == ["command", "details_redacted", "diagnostics", "error", "format", "message", "ok", "reason_code", "remediation"]
  and .format == "duo-command-refusal/v1"
  and .ok == false
  and .command == "apply"
  and .error == "apply_failed"
  and .reason_code == "apply_failed"
  and .message == "apply refused at an unclassified safety gate"
  and .remediation == "inspect apply_in_progress and recovery evidence, then resume or recover according to the recorded phase"
  and .details_redacted == true
  and (.diagnostics | length == 1)
  and .diagnostics[0].code == "apply_failed"
  and .diagnostics[0].message == "apply refused at an unclassified safety gate"
  and .diagnostics[0].remediation == "inspect apply_in_progress and recovery evidence, then resume or recover according to the recorded phase"
' <<<"$APPLY_FAIL_JSON" >/dev/null \
  || fail "missing-user refusal did not use the reviewed redacted JSON envelope: $APPLY_FAIL_JSON"
! grep -Fq 'case.editor' <<<"$APPLY_FAIL_JSON" \
  || fail "redacted missing-user refusal exposed the exact login in JSON: $APPLY_FAIL_JSON"
[ "$(wp2 user meta get "$TARGET_EDITOR" agency_color)" = "blue" ] || fail "required-login refusal happened after mutation"
pass "missing exact login blocks before mutation; JSON refusal is redacted and plan JSON carries the login; warn-only login remains explicitly skipped"

say "once the exact login exists, apply proceeds and reports the warn-only skip"
wp2 user delete "$TARGET_CASE" --yes >/dev/null
TARGET_STRICT=$(wp2 user create case.editor case-exact@example.test --role=author --user_pass=test --porcelain)
APPLY2=$(wp2 duo apply --repo=/siterepo --format=json | tail -1)
[ "$(wp2 user meta get "$TARGET_EDITOR" agency_color)" = "green" ] || fail "blocked authored update did not land after reconciliation"
[ "$(wp2 user meta get "$TARGET_STRICT" strict_note)" = "strict-value" ] || fail "exact-login sidecar did not land"
echo "$APPLY2" | jq -e '.verification.result == "pass" and .verification.skipped_user_meta == 1' >/dev/null \
  || fail "warn-only missing login was not recorded in verification evidence"
pass "exact login lands; optional missing login is a truthful warn-and-skip success"

say "removing the final authored value retains an empty sidecar and deletes only that owned key"
wp1 user meta delete "$SOURCE_EDITOR" agency_color >/dev/null
wp1 user meta delete "$SOURCE_EDITOR" profile_owner >/dev/null
wp1 duo capture --repo=/siterepo --format=json >/tmp/duo-umeta-capture-removal.json
EDITOR_FILE=$(jq -r 'select(.login == "agency-editor") | input_filename' "$SITE1"/state/user-meta/*.json)
[ -n "$EDITOR_FILE" ] || fail "empty sidecar was not retained after final key removal"
jq -e '.meta == {}' "$EDITOR_FILE" >/dev/null || fail "retained sidecar should carry an empty meta map"
sync_repo
wp2 duo apply --repo=/siterepo --format=json >/tmp/duo-umeta-apply-removal.json
if wp2 user meta get "$TARGET_EDITOR" agency_color >/dev/null 2>&1; then
  fail "removed authored key still exists on target"
fi
[ "$(wp2 user meta get "$TARGET_EDITOR" runtime_marker)" = "target-runtime" ] || fail "removal touched target runtime meta"
pass "last-key removal is explicit and ownership-exact"

say "removing the sidecar file itself is not deletion authority"
wp2 user meta update "$TARGET_EDITOR" agency_color target-only >/dev/null
rm "$EDITOR_FILE"
sync_repo
wp2 duo apply --repo=/siterepo --format=json >/tmp/duo-umeta-apply-file-absence.json
[ "$(wp2 user meta get "$TARGET_EDITOR" agency_color)" = "target-only" ] \
  || fail "sidecar file absence incorrectly deleted target metadata"
pass "sidecar absence leaves target metadata untouched"

say "PII and secret values fail recursively until their exact rule is reviewed"
SOURCE_PII=$(wp1 user create pii-editor pii-source@example.test --role=author --user_pass=test --porcelain)
wp1 user meta update "$SOURCE_PII" contact_email editor@example.test >/dev/null
if PII_FAIL=$(wp1 duo capture --repo=/siterepo --format=json 2>&1); then
  fail "PII-bearing authored user meta should refuse without allow_pii"
fi
grep -q "PII guard tripped" <<<"$PII_FAIL" || fail "PII refusal did not identify the gate"

tmp_policy=$(mktemp)
jq '.policy.user_meta.contact_email.allow_pii = true' "$SITE1/site.duo.json" > "$tmp_policy"
mv "$tmp_policy" "$SITE1/site.duo.json"
wp1 user meta update "$SOURCE_PII" api_token ghp_abcdefghijklmnopqrstuvwxyz123456 >/dev/null
if SECRET_FAIL=$(wp1 duo capture --repo=/siterepo --format=json 2>&1); then
  fail "secret-bearing authored user meta should refuse without allow_secret"
fi
grep -q "secret guard tripped" <<<"$SECRET_FAIL" || fail "secret refusal did not identify the gate"
pass "PII and hard-secret scanning are both active on user-meta values"

printf '\nREGRESS_USER_META PASSED\n'
