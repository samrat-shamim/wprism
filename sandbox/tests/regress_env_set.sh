#!/usr/bin/env bash
# Regression — DUO-3232: env-bound value provisioning. Live, docker-based
# (matches every other Apply.php-touching regression in this repo — none
# of which get a FakeWpdb offline harness; see sandbox/tests/
# regress_env_options_policy.sh for the offline complement, Policy.php's
# own validate_env_options()/env_options() wiring in isolation).
#
# Proves, end to end, against the SHIPPED manifests/core.json (not a
# synthetic declaration):
#   1. `wp duo plan`/`wp duo status`'s env_missing bucket: absent on a
#      fresh install (admin_email/home/siteurl are always already set by
#      `wp core install`), then correctly appears once a value is deleted
#      to manufacture a genuinely-missing required option, tagged
#      required:true, and flips `duo status`'s exit code non-zero.
#   2. `wp duo env-set --value=...`: writes the value, verified by
#      re-reading it back; `duo status` returns to exit 0 once written;
#      the response correctly distinguishes "was previously unset" from
#      "replaced an existing value" (Apply::set_env_option()'s own
#      previously_set flag).
#   3. `wp duo env-set --stdin`: reads a value from STDIN with terminal
#      echo disabled, piped non-interactively (matching `duo classify`'s
#      own established pipe-testable convention). Deliberately NOT
#      `--prompt` — that name collides with a wp-cli GLOBAL reserved flag
#      (confirmed live during this task's own development: an
#      isset($assoc['prompt']) check is silently always false, because
#      wp-cli's own dispatcher consumes --prompt before any command ever
#      sees it in $assoc — a real bug this project's design caught before
#      shipping, not a hypothetical).
#   4. Refusal paths, each proven to refuse BEFORE writing anything: an
#      option name not declared class="env" by the loaded policy; both
#      --value and --stdin given together; neither given; an empty value
#      (which env_missing would immediately re-flag as still-missing); an
#      option that declares sub_keys (a structured, plugin-managed blob —
#      pins the `yoast` manifest for this one case, since core.json has no
#      sub_keys-bearing option to exercise it against).
#   5. `duo doctor <env>`'s new .duo-env-values.json git-tracked hygiene
#      check: a gitignored, untracked file passes; a force-added, tracked
#      one is a BLOCKING failure (non-zero doctor exit) once git is
#      available. This project's own sandbox image (wordpress:cli-php8.3)
#      verifiably has NO git binary — confirmed live during this task's
#      own development, not assumed — so step 5 proves the ADVISORY
#      degradation path instead (a WARN naming exactly that reason,
#      doctor exit still 0): the check's own git-presence branch is
#      simple, mirrors the already-proven repo-path-check pattern
#      immediately above it in cli/src/Doctor.php, and is not re-proven
#      here by installing git into a shared sandbox image.
#
# Self-contained: own scratch pair (created and destroyed by this
# script), one side only (this task has nothing to prove about
# capture/apply round-tripping between two environments — env values are
# never captured at all).
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

PAIR=asnapenvset
PORT1=8940
PORT2=8941
export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2" DUO_CODEBIND_PLUGIN=""

wp1() { docker compose -p "duo-$PAIR" -f pair.yml run --rm -T cli1 wp "$@"; }
GIT_1="git -C siterepo/${PAIR}1 -c user.name=duo-$PAIR -c user.email=$PAIR@example.test"

DUO_CLI="$(pwd)/../cli/duo"
ENVS_FILE="$(pwd)/../.duo-envs.json"
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
pass ".duo-envs.json written for cli/duo status/doctor <$ENV_NAME>"

say "site repo: pin the SHIPPED core manifest (the actual deliverable — admin_email/home/siteurl, all required:true)"
rm -rf "siterepo/${PAIR}1/state" "siterepo/${PAIR}1/site.duo.json" "siterepo/${PAIR}1/.git"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
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

say "(1) fresh install: env_missing is empty (wp core install already set admin_email/home/siteurl)"
wp1 duo capture --repo=/siterepo >/dev/null
ENV_MISSING_INITIAL=$(wp1 duo plan --repo=/siterepo --format=json 2>/dev/null | jq -c '.env_missing')
[ "$ENV_MISSING_INITIAL" = "[]" ] || fail "expected env_missing empty on a fresh install, got: $ENV_MISSING_INITIAL"
pass "env_missing empty on a fresh install"

STATUS_INITIAL_OUT=$("$DUO_CLI" status "$ENV_NAME" 2>&1) && STATUS_INITIAL_EXIT=0 || STATUS_INITIAL_EXIT=$?
echo "$STATUS_INITIAL_OUT" | grep -q '0 env_missing' || fail "duo status should report 0 env_missing initially"
[ "$STATUS_INITIAL_EXIT" -eq 0 ] || fail "duo status should exit 0 on a clean fresh install, got $STATUS_INITIAL_EXIT"
pass "duo status: 0 env_missing, exit 0"

say "(2) manufacture a genuinely-missing required env option"
wp1 option delete admin_email >/dev/null
ENV_MISSING_AFTER_DELETE=$(wp1 duo plan --repo=/siterepo --format=json 2>/dev/null | jq -c '.env_missing')
[ "$ENV_MISSING_AFTER_DELETE" = '[{"name":"admin_email","required":true}]' ] \
  || fail "expected admin_email required:true in env_missing after delete, got: $ENV_MISSING_AFTER_DELETE"
pass "env_missing correctly reports admin_email (required:true) after it's deleted"

STATUS_MISSING_OUT=$("$DUO_CLI" status "$ENV_NAME" 2>&1) && STATUS_MISSING_EXIT=0 || STATUS_MISSING_EXIT=$?
echo "$STATUS_MISSING_OUT" | grep -q 'ENV_MISSING' || fail "duo status should render the ENV_MISSING block"
echo "$STATUS_MISSING_OUT" | grep -q 'admin_email (required)' || fail "duo status should list admin_email as required"
[ "$STATUS_MISSING_EXIT" -ne 0 ] || fail "duo status should exit non-zero while a required env value is missing"
pass "duo status: ENV_MISSING rendered, exit non-zero ($STATUS_MISSING_EXIT)"

say "(3) wp duo env-set --value=... provisions it; previously_set:false on first write"
RESULT_1=$(wp1 duo env-set --repo=/siterepo --name=admin_email --value=first@example.test --format=json 2>/dev/null)
echo "$RESULT_1" | jq -e '.name == "admin_email" and .previously_set == false' >/dev/null \
  || fail "expected {name:admin_email, previously_set:false}, got: $RESULT_1"
[ "$(wp1 option get admin_email 2>/dev/null)" = "first@example.test" ] || fail "admin_email did not actually change in wp_options"
pass "env-set --value wrote the value; previously_set:false (was unset)"

RESULT_2=$(wp1 duo env-set --repo=/siterepo --name=admin_email --value=second@example.test --format=json 2>/dev/null)
echo "$RESULT_2" | jq -e '.previously_set == true' >/dev/null \
  || fail "expected previously_set:true on a second write, got: $RESULT_2"
pass "env-set --value on an already-set option reports previously_set:true"

ENV_MISSING_AFTER_SET=$(wp1 duo plan --repo=/siterepo --format=json 2>/dev/null | jq -c '.env_missing')
[ "$ENV_MISSING_AFTER_SET" = "[]" ] || fail "env_missing should be empty again after env-set, got: $ENV_MISSING_AFTER_SET"
"$DUO_CLI" status "$ENV_NAME" >/dev/null 2>&1 && pass "duo status back to exit 0 after provisioning" \
  || fail "duo status should be exit 0 again after env-set"

say "(4) wp duo env-set --stdin: piped value, terminal-echo-disabled read path (never --prompt — see this file's own header)"
printf 'from-stdin@example.test\n' | wp1 duo env-set --repo=/siterepo --name=admin_email --stdin --format=json > /tmp/duo_env_set_stdin_result.json 2>/dev/null
jq -e '.name == "admin_email" and .previously_set == true' /tmp/duo_env_set_stdin_result.json >/dev/null \
  || fail "expected a clean {name,previously_set} result from --stdin, got: $(cat /tmp/duo_env_set_stdin_result.json)"
rm -f /tmp/duo_env_set_stdin_result.json
[ "$(wp1 option get admin_email 2>/dev/null)" = "from-stdin@example.test" ] || fail "admin_email did not match the piped --stdin value"
pass "env-set --stdin correctly read and wrote the piped value"

say "(5) refusal paths — each must refuse BEFORE writing anything"

BEFORE=$(wp1 option get admin_email 2>/dev/null)
wp1 duo env-set --repo=/siterepo --name=blogname --value=x >/tmp/duo_refuse_1.txt 2>&1 && fail "should have refused an undeclared/non-env option name"
grep -q 'not declared class="env"' /tmp/duo_refuse_1.txt || fail "wrong refusal message for undeclared option name: $(cat /tmp/duo_refuse_1.txt)"
[ "$(wp1 option get admin_email 2>/dev/null)" = "$BEFORE" ] || fail "admin_email changed despite the refusal (name gate)"
rm -f /tmp/duo_refuse_1.txt
pass "refuses an option not declared class=\"env\", writes nothing"

wp1 duo env-set --repo=/siterepo --name=admin_email --value= >/tmp/duo_refuse_2.txt 2>&1 && fail "should have refused an empty value"
grep -q 'refusing to set' /tmp/duo_refuse_2.txt || fail "wrong refusal message for empty value: $(cat /tmp/duo_refuse_2.txt)"
[ "$(wp1 option get admin_email 2>/dev/null)" = "$BEFORE" ] || fail "admin_email changed despite the refusal (empty value)"
rm -f /tmp/duo_refuse_2.txt
pass "refuses an empty value, writes nothing"

wp1 duo env-set --repo=/siterepo --name=admin_email >/tmp/duo_refuse_3.txt 2>&1 && fail "should have refused with neither --value nor --stdin"
grep -q 'one of --value=<value> or --stdin is required' /tmp/duo_refuse_3.txt || fail "wrong refusal message for neither flag: $(cat /tmp/duo_refuse_3.txt)"
rm -f /tmp/duo_refuse_3.txt
pass "refuses when neither --value nor --stdin is given"

wp1 duo env-set --repo=/siterepo --name=admin_email --value=x --stdin < /dev/null >/tmp/duo_refuse_4.txt 2>&1 && fail "should have refused with both --value and --stdin"
grep -q 'pass exactly one of --value or --stdin, not both' /tmp/duo_refuse_4.txt || fail "wrong refusal message for both flags: $(cat /tmp/duo_refuse_4.txt)"
[ "$(wp1 option get admin_email 2>/dev/null)" = "$BEFORE" ] || fail "admin_email changed despite the refusal (both flags)"
rm -f /tmp/duo_refuse_4.txt
pass "refuses when both --value and --stdin are given, writes nothing"

say "(5b) refuse: a sub_keys-bearing env option (pins the yoast manifest for this one case only)"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "yoast"],
  "policy": {
    "post_types": ["post", "page"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
wp1 duo env-set --repo=/siterepo --name=wpseo --value=x >/tmp/duo_refuse_5.txt 2>&1 && fail "should have refused a sub_keys-bearing env option"
grep -q 'declares sub_keys' /tmp/duo_refuse_5.txt || fail "wrong refusal message for sub_keys option: $(cat /tmp/duo_refuse_5.txt)"
rm -f /tmp/duo_refuse_5.txt
pass "refuses a sub_keys-bearing env option (wpseo)"
# Restore the single-manifest policy for the remaining steps.
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "post_types": ["post", "page"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF

say "(6) duo doctor: .duo-env-values.json hygiene check"
$GIT_1 init -q -b main 2>/dev/null || true
$GIT_1 add -A
$GIT_1 commit -qm "site.duo.json restored" --allow-empty >/dev/null

DOCTOR_CLEAN_OUT=$("$DUO_CLI" doctor "$ENV_NAME" 2>&1) || true
echo "$DOCTOR_CLEAN_OUT" | grep -q 'duo-env-values.json' || fail "doctor did not report a .duo-env-values.json line at all"
if echo "$DOCTOR_CLEAN_OUT" | grep -q '\[PASS\] .duo-env-values.json not git-tracked'; then
  pass "doctor: .duo-env-values.json check PASSes cleanly (no such file yet, git available)"
elif echo "$DOCTOR_CLEAN_OUT" | grep -q '\[WARN\] .duo-env-values.json not git-tracked — could not verify'; then
  pass "doctor: .duo-env-values.json check honestly WARNs 'could not verify' (this sandbox image has no git binary — confirmed, not a bug: see this file's own header)"
else
  fail "unexpected .duo-env-values.json doctor line: $(echo "$DOCTOR_CLEAN_OUT" | grep 'duo-env-values.json')"
fi

echo '{"admin_email":"leaked@example.test"}' > "siterepo/${PAIR}1/.duo-env-values.json"
$GIT_1 add -f .duo-env-values.json
$GIT_1 commit -qm "oops committed secrets (this script's own negative-path fixture, never a real leak)"
DOCTOR_TRACKED_OUT=$("$DUO_CLI" doctor "$ENV_NAME" 2>&1) && DOCTOR_TRACKED_EXIT=0 || DOCTOR_TRACKED_EXIT=$?
if echo "$DOCTOR_TRACKED_OUT" | grep -q '\[FAIL\] .duo-env-values.json not git-tracked — .duo-env-values.json is committed'; then
  [ "$DOCTOR_TRACKED_EXIT" -ne 0 ] || fail "doctor exit should be non-zero once .duo-env-values.json is tracked"
  pass "doctor: BLOCKING failure once .duo-env-values.json is git-tracked, non-zero exit ($DOCTOR_TRACKED_EXIT)"
elif echo "$DOCTOR_TRACKED_OUT" | grep -q "could not verify"; then
  pass "doctor: still the honest no-git advisory (git unavailable in this environment, so the tracked case genuinely cannot be exercised live here — the check's own git-presence branch mirrors the already-proven repo-path-check pattern immediately above it in cli/src/Doctor.php, not re-proven by this script)"
else
  fail "unexpected .duo-env-values.json doctor line after tracking: $(echo "$DOCTOR_TRACKED_OUT" | grep 'duo-env-values.json')"
fi
$GIT_1 rm -q --cached .duo-env-values.json >/dev/null
$GIT_1 commit -qm "cleanup: untrack .duo-env-values.json" >/dev/null

echo
printf '\033[1;32m✔ REGRESS_ENV_SET PASSED\033[0m\n'
