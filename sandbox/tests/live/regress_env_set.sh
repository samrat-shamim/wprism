#!/usr/bin/env bash
# Regression — issue #3232: env-bound value provisioning. Live, docker-based
# (matches every other Apply.php-touching regression in this repo — none
# of which get a FakeWpdb offline harness; see sandbox/tests/
# regress_env_options_policy.php for the offline complement, Policy.php's
# own validate_env_options()/env_options() wiring in isolation).
#
# Proves, end to end, against the SHIPPED platform/adapter-library/core/manifest.json (not a
# synthetic declaration):
#   1. `wp wprism plan`/`wp wprism status` refuse required options that have live
#      values but no intended-value binding; provisioning all three shipped
#      core options creates an owner-only target-local binding and turns green.
#   2. An out-of-band non-empty replacement is red until `env-set` restores
#      the intended value, proving equality rather than presence-only checking.
#   3. `wp wprism env-set --stdin` writes the value, verified by re-reading it;
#      the response correctly distinguishes "was previously unset" from
#      "replaced an existing value" (Apply::set_env_option()'s own
#      previously_set flag), and a serialized-looking scalar remains the exact
#      string WordPress's option API would preserve through update_option()
#      while plan/status compare its encoded wire representation correctly.
#   4. `wp wprism env-set --stdin`: reads a value from STDIN with terminal
#      echo disabled, piped non-interactively (matching `wprism classify`'s
#      own established pipe-testable convention). Deliberately NOT
#      `--prompt` — that name collides with a wp-cli GLOBAL reserved flag
#      (confirmed live during this task's own development: an
#      isset($assoc['prompt']) check is silently always false, because
#      wp-cli's own dispatcher consumes --prompt before any command ever
#      sees it in $assoc — a real bug this project's design caught before
#      shipping, not a hypothetical).
#   5. Refusal paths, each proven to refuse BEFORE writing anything: an
#      option name not declared class="env" by the loaded policy; both
#      an argv value (even with --stdin); neither --stdin nor a value;
#      an empty value
#      (which env_missing would immediately re-flag as still-missing); an
#      option that declares sub_keys (a structured, plugin-managed blob —
#      pins the `yoast` manifest for this one case, since core.json has no
#      sub_keys-bearing option to exercise it against).
#   6. `wprism doctor <env>`'s new .wprism-env-values.json git-tracked hygiene
#      check: a gitignored, untracked file passes; a force-added, tracked
#      one is a BLOCKING failure (non-zero doctor exit) once git is
#      available. This project's own sandbox image (wordpress:cli-php8.3)
#      verifiably has NO git binary — confirmed live during this task's
#      own development, not assumed — so step 6 proves the ADVISORY
#      degradation path instead (a WARN naming exactly that reason,
#      doctor exit still 0): the check's own git-presence branch is
#      simple, mirrors the already-proven repo-path-check pattern
#      immediately above it in cli/src/Onboarding/Doctor.php, and is not re-proven
#      here by installing git into a shared sandbox image.
#
# Self-contained: own scratch pair (created and destroyed by this
# script), one side only (this task has nothing to prove about
# capture/apply round-tripping between two environments — env values are
# never captured at all).
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

PAIR=asnapenvset
PORT1=8940
PORT2=8941
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" WPRISM_CODEBIND_PLUGIN=""

wp1() { docker compose -p "wprism-$PAIR" -f pair.yml run --rm -T cli1 wp "$@"; }
GIT_1="git -C siterepo/${PAIR}1 -c user.name=wprism-$PAIR -c user.email=$PAIR@example.test"

WPRISM_CLI="$(pwd)/../cli/wprism"
ENVS_FILE="$(pwd)/../.wprism-envs.json"
ENV_NAME="${PAIR}1"

cleanup() {
  rm -f "$ENVS_FILE"
  bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
}
trap cleanup EXIT

say "bring up scratch pair '$PAIR' ($PORT1/$PORT2, headless — no rendering needed for this task)"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless
pass "pair '$PAIR' ready"

cat > "$ENVS_FILE" <<EOF
{
  "envs": {
    "${ENV_NAME}": {"transport": "docker", "compose_file": "sandbox/pair.yml", "service": "cli1", "repo_path": "/siterepo"}
  }
}
EOF
pass ".wprism-envs.json written for cli/wprism status/doctor <$ENV_NAME>"

say "site repo: pin the SHIPPED core manifest (the actual deliverable — admin_email/home/siteurl, all required:true)"
rm -rf "siterepo/${PAIR}1/state" "siterepo/${PAIR}1/site.wprism.json" "siterepo/${PAIR}1/.git"
cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "post_types": ["post", "page"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
$GIT_1 init -q -b main
$GIT_1 add -A
$GIT_1 commit -qm "policy: core"
pass "site repo initialized, pinning the shipped core manifest"

say "(1) fresh install: non-empty required options stay red until their intended values are bound"
wp1 wprism capture --repo=/siterepo >/dev/null
ENV_MISSING_INITIAL=$(wp1 wprism plan --repo=/siterepo --format=json 2>/dev/null | jq -c '.env_missing')
[ "$ENV_MISSING_INITIAL" = '[{"name":"admin_email","required":true},{"name":"home","required":true},{"name":"siteurl","required":true}]' ] \
  || fail "expected all three unbound core values in env_missing, got: $ENV_MISSING_INITIAL"
pass "non-empty but unbound required options remain in env_missing"

STATUS_INITIAL_OUT=$("$WPRISM_CLI" status "$ENV_NAME" 2>&1) && STATUS_INITIAL_EXIT=0 || STATUS_INITIAL_EXIT=$?
grep -q 'ENV_MISSING' <<<"$STATUS_INITIAL_OUT" || fail "wprism status should render unbound required options"
[ "$STATUS_INITIAL_EXIT" -ne 0 ] || fail "wprism status should be red before intended values are bound"
pass "wprism status refuses the unbound fresh environment"

say "(2) bind the shipped required values through env-set"
ADMIN_INITIAL=$(wp1 option get admin_email 2>/dev/null)
HOME_INITIAL=$(wp1 option get home 2>/dev/null)
SITEURL_INITIAL=$(wp1 option get siteurl 2>/dev/null)
printf '%s\n' "$ADMIN_INITIAL" | wp1 wprism env-set --repo=/siterepo --name=admin_email --stdin --format=json >/dev/null
printf '%s\n' "$HOME_INITIAL" | wp1 wprism env-set --repo=/siterepo --name=home --stdin --format=json >/dev/null
printf '%s\n' "$SITEURL_INITIAL" | wp1 wprism env-set --repo=/siterepo --name=siteurl --stdin --format=json >/dev/null
ENV_VALUES_PATH="siterepo/${PAIR}1/.wprism-env-values.json"
jq -e 'keys == ["admin_email", "home", "siteurl"]' "$ENV_VALUES_PATH" >/dev/null \
  || fail "intended-value file does not contain the three provisioned bindings"
if stat -f '%Lp' "$ENV_VALUES_PATH" >/dev/null 2>&1; then
  ENV_VALUES_MODE=$(stat -f '%Lp' "$ENV_VALUES_PATH")
else
  ENV_VALUES_MODE=$(stat -c '%a' "$ENV_VALUES_PATH")
fi
[ "$ENV_VALUES_MODE" = "600" ] || fail "intended-value file mode is $ENV_VALUES_MODE, expected 600"
[ "$(wp1 wprism plan --repo=/siterepo --format=json 2>/dev/null | jq -c '.env_missing')" = "[]" ] \
  || fail "bound core values should make env_missing empty"
"$WPRISM_CLI" status "$ENV_NAME" >/dev/null 2>&1 || fail "wprism status should be green after all required values are bound"
pass "env-set publishes canonical owner-only bindings and the exact live values turn green"

say "(3) an out-of-band non-empty replacement is intended-value drift, not green presence"
wp1 option update admin_email wrong-client@example.test >/dev/null
ENV_MISSING_DRIFT=$(wp1 wprism plan --repo=/siterepo --format=json 2>/dev/null | jq -c '.env_missing')
[ "$ENV_MISSING_DRIFT" = '[{"name":"admin_email","required":true}]' ] \
  || fail "expected mismatched admin_email in env_missing, got: $ENV_MISSING_DRIFT"
STATUS_DRIFT_OUT=$("$WPRISM_CLI" status "$ENV_NAME" 2>&1) && STATUS_DRIFT_EXIT=0 || STATUS_DRIFT_EXIT=$?
[ "$STATUS_DRIFT_EXIT" -ne 0 ] || fail "wprism status should be red for a wrong non-empty value"
printf '%s\n' "$ADMIN_INITIAL" | wp1 wprism env-set --repo=/siterepo --name=admin_email --stdin --format=json >/dev/null
[ "$(wp1 wprism plan --repo=/siterepo --format=json 2>/dev/null | jq -c '.env_missing')" = "[]" ] \
  || fail "restoring admin_email through env-set should clear intended-value drift"
pass "wrong non-empty configuration is red and env-set restores exact intent"

say "(4) manufacture a genuinely-missing required env option"
wp1 option delete admin_email >/dev/null
ENV_MISSING_AFTER_DELETE=$(wp1 wprism plan --repo=/siterepo --format=json 2>/dev/null | jq -c '.env_missing')
[ "$ENV_MISSING_AFTER_DELETE" = '[{"name":"admin_email","required":true}]' ] \
  || fail "expected admin_email required:true in env_missing after delete, got: $ENV_MISSING_AFTER_DELETE"
pass "env_missing correctly reports admin_email (required:true) after it's deleted"

STATUS_MISSING_OUT=$("$WPRISM_CLI" status "$ENV_NAME" 2>&1) && STATUS_MISSING_EXIT=0 || STATUS_MISSING_EXIT=$?
grep -q 'ENV_MISSING' <<<"$STATUS_MISSING_OUT" || fail "wprism status should render the ENV_MISSING block"
grep -q 'admin_email (required)' <<<"$STATUS_MISSING_OUT" || fail "wprism status should list admin_email as required"
[ "$STATUS_MISSING_EXIT" -ne 0 ] || fail "wprism status should exit non-zero while a required env value is missing"
pass "wprism status: ENV_MISSING rendered, exit non-zero ($STATUS_MISSING_EXIT)"

say "(5) wp wprism env-set --stdin provisions it; previously_set:false on first write"
RESULT_1=$(printf 'first@example.test\n' | wp1 wprism env-set --repo=/siterepo --name=admin_email --stdin --format=json 2>/dev/null)
echo "$RESULT_1" | jq -e '.name == "admin_email" and .previously_set == false' >/dev/null \
  || fail "expected {name:admin_email, previously_set:false}, got: $RESULT_1"
[ "$(wp1 option get admin_email 2>/dev/null)" = "first@example.test" ] || fail "admin_email did not actually change in wp_options"
pass "env-set --stdin wrote the value; previously_set:false (was unset)"

RESULT_2=$(printf 'second@example.test\n' | wp1 wprism env-set --repo=/siterepo --name=admin_email --stdin --format=json 2>/dev/null)
echo "$RESULT_2" | jq -e '.previously_set == true' >/dev/null \
  || fail "expected previously_set:true on a second write, got: $RESULT_2"
pass "env-set --stdin on an already-set option reports previously_set:true"

ENV_MISSING_AFTER_SET=$(wp1 wprism plan --repo=/siterepo --format=json 2>/dev/null | jq -c '.env_missing')
[ "$ENV_MISSING_AFTER_SET" = "[]" ] || fail "env_missing should be empty again after env-set, got: $ENV_MISSING_AFTER_SET"
"$WPRISM_CLI" status "$ENV_NAME" >/dev/null 2>&1 && pass "wprism status back to exit 0 after provisioning" \
  || fail "wprism status should be exit 0 again after env-set"

say "(6) wp wprism env-set --stdin: piped value, terminal-echo-disabled read path (never --prompt — see this file's own header)"
printf 'from-stdin@example.test\n' | wp1 wprism env-set --repo=/siterepo --name=admin_email --stdin --format=json > /tmp/wprism_env_set_stdin_result.json 2>/dev/null
jq -e '.name == "admin_email" and .previously_set == true' /tmp/wprism_env_set_stdin_result.json >/dev/null \
  || fail "expected a clean {name,previously_set} result from --stdin, got: $(cat /tmp/wprism_env_set_stdin_result.json)"
rm -f /tmp/wprism_env_set_stdin_result.json
[ "$(wp1 option get admin_email 2>/dev/null)" = "from-stdin@example.test" ] || fail "admin_email did not match the piped --stdin value"
pass "env-set --stdin correctly read and wrote the piped value"

say "(6b) a serialized-looking scalar keeps its exact string type and bytes"
SERIALIZED_LOOKING='a:1:{s:1:"x";s:1:"y";}'
printf '%s\n' "$SERIALIZED_LOOKING" \
  | wp1 wprism env-set --repo=/siterepo --name=admin_email --stdin --format=json >/dev/null
SERIALIZED_READBACK=$(wp1 eval '$v = get_option("admin_email"); echo gettype($v) . ":" . base64_encode(is_string($v) ? $v : serialize($v));' 2>/dev/null)
SERIALIZED_EXPECTED="string:$(printf '%s' "$SERIALIZED_LOOKING" | base64 | tr -d '\n')"
[ "$SERIALIZED_READBACK" = "$SERIALIZED_EXPECTED" ] \
  || fail "serialized-looking env value changed type or bytes: $SERIALIZED_READBACK"
[ "$(wp1 wprism plan --repo=/siterepo --format=json 2>/dev/null | jq -c '.env_missing')" = "[]" ] \
  || fail "serialized-looking env value remained red after its exact env-set write"
"$WPRISM_CLI" status "$ENV_NAME" >/dev/null 2>&1 \
  || fail "wprism status remained red for a serialized-looking env value"
printf 'from-stdin@example.test\n' \
  | wp1 wprism env-set --repo=/siterepo --name=admin_email --stdin --format=json >/dev/null
pass "env-set and plan/status preserve WordPress's double-serialization compatibility for logical strings"

BEFORE_TRUNCATED=$(wp1 option get admin_email 2>/dev/null)
if printf %s 'truncated@example.test' \
  | wp1 wprism env-set --repo=/siterepo --name=admin_email --stdin --format=json \
    >/tmp/wprism_env_set_truncated_result.json 2>/dev/null; then
  fail "env-set --stdin should refuse an unterminated value"
fi
jq -e '.error == "invalid_arguments"' /tmp/wprism_env_set_truncated_result.json >/dev/null \
  || fail "unterminated stdin did not return a structured refusal: $(cat /tmp/wprism_env_set_truncated_result.json)"
rm -f /tmp/wprism_env_set_truncated_result.json
[ "$(wp1 option get admin_email 2>/dev/null)" = "$BEFORE_TRUNCATED" ] \
  || fail "admin_email changed after an unterminated stdin value"
pass "env-set --stdin refuses an incomplete pipe write without changing the option"

say "(7) refusal paths — each must refuse BEFORE writing anything"

BEFORE=$(wp1 option get admin_email 2>/dev/null)
printf 'x\n' | wp1 wprism env-set --repo=/siterepo --name=blogname --stdin >/tmp/wprism_refuse_1.txt 2>&1 && fail "should have refused an undeclared/non-env option name"
grep -q 'not declared class="env"' /tmp/wprism_refuse_1.txt || fail "wrong refusal message for undeclared option name: $(cat /tmp/wprism_refuse_1.txt)"
[ "$(wp1 option get admin_email 2>/dev/null)" = "$BEFORE" ] || fail "admin_email changed despite the refusal (name gate)"
rm -f /tmp/wprism_refuse_1.txt
pass "refuses an option not declared class=\"env\", writes nothing"

printf '\n' | wp1 wprism env-set --repo=/siterepo --name=admin_email --stdin >/tmp/wprism_refuse_2.txt 2>&1 && fail "should have refused an empty value"
grep -q 'refusing to set' /tmp/wprism_refuse_2.txt || fail "wrong refusal message for empty value: $(cat /tmp/wprism_refuse_2.txt)"
[ "$(wp1 option get admin_email 2>/dev/null)" = "$BEFORE" ] || fail "admin_email changed despite the refusal (empty value)"
rm -f /tmp/wprism_refuse_2.txt
pass "refuses an empty value, writes nothing"

wp1 wprism env-set --repo=/siterepo --name=admin_email >/tmp/wprism_refuse_3.txt 2>&1 && fail "should have refused without --stdin"
grep -q -- '--stdin is required' /tmp/wprism_refuse_3.txt || fail "wrong refusal message without --stdin: $(cat /tmp/wprism_refuse_3.txt)"
rm -f /tmp/wprism_refuse_3.txt
pass "refuses when --stdin is not given"

printf 'stdin-x\n' | wp1 wprism env-set --repo=/siterepo --name=admin_email --value=argv-x --stdin >/tmp/wprism_refuse_4.txt 2>&1 && fail "should have refused an argv value"
grep -q 'does not accept --value because command-line arguments are observable' /tmp/wprism_refuse_4.txt || fail "wrong refusal message for argv value: $(cat /tmp/wprism_refuse_4.txt)"
[ "$(wp1 option get admin_email 2>/dev/null)" = "$BEFORE" ] || fail "admin_email changed despite the refusal (both flags)"
rm -f /tmp/wprism_refuse_4.txt
pass "refuses an argv value even when --stdin is also given, writes nothing"

say "(7b) refuse: a sub_keys-bearing env option (pins the yoast manifest for this one case only)"
cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "yoast"],
  "policy": {
    "post_types": ["post", "page"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
printf 'x\n' | wp1 wprism env-set --repo=/siterepo --name=wpseo --stdin >/tmp/wprism_refuse_5.txt 2>&1 && fail "should have refused a sub_keys-bearing env option"
grep -q 'declares sub_keys' /tmp/wprism_refuse_5.txt || fail "wrong refusal message for sub_keys option: $(cat /tmp/wprism_refuse_5.txt)"
rm -f /tmp/wprism_refuse_5.txt
pass "refuses a sub_keys-bearing env option (wpseo)"
# Restore the single-manifest policy for the remaining steps.
cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "post_types": ["post", "page"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF

say "(8) wprism doctor: .wprism-env-values.json hygiene check"
$GIT_1 init -q -b main 2>/dev/null || true
$GIT_1 add -A
$GIT_1 commit -qm "site.wprism.json restored" --allow-empty >/dev/null

DOCTOR_CLEAN_OUT=$("$WPRISM_CLI" doctor "$ENV_NAME" 2>&1) || true
grep -q 'wprism-env-values.json' <<<"$DOCTOR_CLEAN_OUT" || fail "doctor did not report a .wprism-env-values.json line at all"
if grep -q '\[PASS\] .wprism-env-values.json not git-tracked' <<<"$DOCTOR_CLEAN_OUT"; then
  pass "doctor: .wprism-env-values.json check PASSes cleanly (provisioned but untracked, git available)"
elif grep -q '\[WARN\] .wprism-env-values.json not git-tracked — could not verify' <<<"$DOCTOR_CLEAN_OUT"; then
  pass "doctor: .wprism-env-values.json check honestly WARNs 'could not verify' (this sandbox image has no git binary — confirmed, not a bug: see this file's own header)"
else
  fail "unexpected .wprism-env-values.json doctor line: $(echo "$DOCTOR_CLEAN_OUT" | grep 'wprism-env-values.json')"
fi

echo '{"admin_email":"leaked@example.test"}' > "siterepo/${PAIR}1/.wprism-env-values.json"
$GIT_1 add -f .wprism-env-values.json
$GIT_1 commit -qm "oops committed secrets (this script's own negative-path fixture, never a real leak)"
DOCTOR_TRACKED_OUT=$("$WPRISM_CLI" doctor "$ENV_NAME" 2>&1) && DOCTOR_TRACKED_EXIT=0 || DOCTOR_TRACKED_EXIT=$?
if grep -q '\[FAIL\] .wprism-env-values.json not git-tracked — .wprism-env-values.json is committed' <<<"$DOCTOR_TRACKED_OUT"; then
  [ "$DOCTOR_TRACKED_EXIT" -ne 0 ] || fail "doctor exit should be non-zero once .wprism-env-values.json is tracked"
  pass "doctor: BLOCKING failure once .wprism-env-values.json is git-tracked, non-zero exit ($DOCTOR_TRACKED_EXIT)"
elif grep -q "could not verify" <<<"$DOCTOR_TRACKED_OUT"; then
  pass "doctor: still the honest no-git advisory (git unavailable in this environment, so the tracked case genuinely cannot be exercised live here — the check's own git-presence branch mirrors the already-proven repo-path-check pattern immediately above it in cli/src/Onboarding/Doctor.php, not re-proven by this script)"
else
  fail "unexpected .wprism-env-values.json doctor line after tracking: $(echo "$DOCTOR_TRACKED_OUT" | grep 'wprism-env-values.json')"
fi
$GIT_1 rm -q --cached .wprism-env-values.json >/dev/null
$GIT_1 commit -qm "cleanup: untrack .wprism-env-values.json" >/dev/null

echo
printf '\033[1;32m✔ REGRESS_ENV_SET PASSED\033[0m\n'
