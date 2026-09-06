#!/usr/bin/env bash
# Regression — issue #3231: code_drift detection (Deploy::code_drift()) and the
# DISALLOW_FILE_MODS advisory `wprism doctor` check.
#
# Uses WordPress core's own bundled "Hello Dolly" plugin as the fixture
# (wp-content/plugins/hello.php, ships with every core install) — zero
# network installs needed, deterministic, always present. Its exact
# get_plugins() basename is discovered live rather than hardcoded (a
# single-file plugin's basename is just its filename, but that's an
# assumption worth confirming empirically rather than baking in).
#
# One pair.sh pair (own dedicated pair — "codedrift", headless: no render
# checks, pure wp-cli + `cli/wprism` orchestrator against Deploy/code-half
# logic). Only side 1 is ever touched: this regression is about ONE
# environment's own version-baseline history (capture -> corrupt the
# recorded baseline -> detect -> refuse -> force -> re-baseline), not a
# cross-environment promotion — side 2 is provisioned (pair.sh always
# brings up both) but deliberately left idle, matching every other
# pair.sh-based regression's convention of using a real pair rather than
# inventing single-environment provisioning.
#
# PART 1 (the core mechanism): capture records a baseline; plan/apply/
# deploy are silent while nothing has drifted; corrupting the recorded
# baseline (simulating an out-of-band version bump — see this file's own
# header for why a direct wprism_kv edit is the right simulation, not
# actually swapping plugin files) makes exactly one code_drift finding
# appear, naming the plugin and both versions; apply AND deploy both
# refuse by default and both proceed with --force-code-drift while still
# reporting what was overridden (Architecture Rulings §1); apply's forced
# pass does NOT clear the finding (apply never re-baselines — it doesn't
# own code state); deploy's forced pass DOES clear it through the isolated
# baseline-acceptance phase; and a plain capture (issue #3507) OBSERVES the drift —
# it warns once per row, leaves wprism_kv['code_versions'] byte-identical and
# the finding standing, and records a baseline only when there is nothing
# to accept. Capture is the observe-reality verb; accepting a code change
# is deploy's decision and has deploy's consent gate in front of it.
#
# PART 2: the DISALLOW_FILE_MODS `wprism doctor` check via the REAL cli/wprism
# orchestrator (DockerTransport against this same pair) — advisory only,
# confirmed both by exit code (WARN must not fail doctor) and by the
# printed severity label flipping from WARN to PASS once the constant is
# actually set via `wp config set --type=constant`.
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
. lib/pair_db.sh
pair_db_select_engine

command -v jq >/dev/null || fail "jq required"

PORT1="${CODEDRIFT_PORT1:-8862}"
PORT2="${CODEDRIFT_PORT2:-8863}"
COMPOSE="docker compose -p wprism-codedrift -f pair.yml"
export WPRISM_PAIR=codedrift
wp1() { $COMPOSE run --rm -T cli1 wp "$@"; }
HOST_ENVS_FILE=$(mktemp)
trap 'rm -f "$HOST_ENVS_FILE"' EXIT
ABS_COMPOSE="$(pwd)/pair.yml"
cat > "$HOST_ENVS_FILE" <<EOF
{"envs": {"codedrift1": {"transport": "docker", "compose_file": "$ABS_COMPOSE", "service": "cli1", "repo_path": "/siterepo"}}}
EOF
host1() {
  local verb="$1"
  shift
  COMPOSE_PROJECT_NAME=wprism-codedrift WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" \
    php ../cli/wprism --envs-file="$HOST_ENVS_FILE" "$verb" codedrift1 "$@"
}

say "clean-room via pair.sh (own dedicated pair, headless — no render checks)"
bash bin/pair.sh reset codedrift
bash bin/pair.sh up codedrift "$PORT1" "$PORT2" --headless

say "discover and activate the bundled Hello Dolly plugin (zero network installs)"
HELLO_SLUG=$(wp1 plugin list --format=json | jq -r '.[] | select(.name | test("hello"; "i")) | .name' | head -1)
[ -n "$HELLO_SLUG" ] || fail "no bundled hello-dolly-shaped plugin found on a fresh core install — cannot proceed"
wp1 plugin activate "$HELLO_SLUG" >/dev/null
HELLO_BASENAME=$(wp1 eval "
foreach (get_plugins() as \$file => \$data) {
  if (stripos(\$file, 'hello') !== false) { echo \$file; break; }
}
" 2>&1 | tail -1)
[ -n "$HELLO_BASENAME" ] || fail "activated '$HELLO_SLUG' but could not resolve its get_plugins() basename"
echo "using plugin slug=$HELLO_SLUG basename=$HELLO_BASENAME"

say "site repo scaffold (core manifest only)"
git init -q -b main siterepo/codedrift1
cat > siterepo/codedrift1/site.wprism.json <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template siterepo/codedrift1/.gitignore

say "PART 1 — capture: must record a code_versions baseline through CodeBaselineCapture (nothing to accept yet)"
wp1 wprism capture --repo=/siterepo
git -C siterepo/codedrift1 add -A
git -C siterepo/codedrift1 -c user.name=wprism -c user.email=wprism@example.test commit -qm "baseline capture ($HELLO_BASENAME active)"

say "sanity: plan shows zero code_drift immediately after capture (baseline == live, nothing drifted yet)"
PLAN1=$(wp1 wprism plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN1" | jq -e '.code_drift | length == 0' >/dev/null \
  || fail "expected zero code_drift findings right after capture, got: $(echo "$PLAN1" | jq -c .code_drift)"
pass "clean baseline, no false positives"

REAL_VERSION=$(wp1 eval "echo get_plugins()['$HELLO_BASENAME']['Version'];" 2>&1 | tail -1)
[ -n "$REAL_VERSION" ] || fail "could not read $HELLO_BASENAME's real installed version"
FAKE_BASELINE="0.0.1-fake-baseline"
echo "real installed version: $REAL_VERSION (will simulate a stale recorded baseline of $FAKE_BASELINE)"

say "simulate out-of-band drift: corrupt the RECORDED baseline directly (not the plugin files — see header comment for why this is the right simulation)"
wp1 eval "
\WPrism\Ledger::ensure();
\$v = json_decode(\WPrism\Ledger::kv_get('code_versions'), true) ?: ['plugins' => []];
\$v['plugins']['$HELLO_BASENAME'] = '$FAKE_BASELINE';
\WPrism\Ledger::kv_set('code_versions', wp_json_encode(\$v));
echo 'corrupted';
" >/dev/null

say "PART 1 — plan must now surface exactly one code_drift finding, naming the plugin and both versions"
PLAN2=$(wp1 wprism plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN2" | jq -e '.code_drift | length == 1' >/dev/null \
  || fail "expected exactly one code_drift finding, got: $(echo "$PLAN2" | jq -c .code_drift)"
echo "$PLAN2" | jq -e --arg p "$HELLO_BASENAME" '.code_drift[0].plugin == $p' >/dev/null \
  || fail "code_drift finding does not name $HELLO_BASENAME: $(echo "$PLAN2" | jq -c '.code_drift[0]')"
echo "$PLAN2" | jq -e --arg v "$REAL_VERSION" '.code_drift[0].installed_version == $v' >/dev/null \
  || fail "installed_version mismatch: $(echo "$PLAN2" | jq -c '.code_drift[0]')"
echo "$PLAN2" | jq -e --arg v "$FAKE_BASELINE" '.code_drift[0].recorded_version == $v' >/dev/null \
  || fail "recorded_version mismatch: $(echo "$PLAN2" | jq -c '.code_drift[0]')"
pass "code_drift finding is precise: plugin, installed_version, recorded_version all correct"

say "PART 1 — apply must refuse by default, naming code_drift and the escape hatch"
set +e
APPLY_REFUSE=$(wp1 wprism apply --repo=/siterepo --default-author=admin 2>&1)
APPLY_REFUSE_RC=$?
set -e
echo "$APPLY_REFUSE"
[ "$APPLY_REFUSE_RC" -ne 0 ] || fail "expected wprism apply to refuse on code_drift, got exit 0"
grep -q "code_drift" <<<"$APPLY_REFUSE" || fail "refusal did not mention code_drift"
grep -q -- "--force-code-drift" <<<"$APPLY_REFUSE" || fail "refusal did not name the escape hatch"
pass "apply refuses loudly, names code_drift and --force-code-drift"

say "PART 1 — apply --force-code-drift proceeds, reporting the override (report-not-hide, Architecture Rulings §1)"
APPLY_FORCED=$(wp1 wprism apply --repo=/siterepo --default-author=admin --force-code-drift 2>&1)
echo "$APPLY_FORCED"
grep -qi "success\|applied" <<<"$APPLY_FORCED" || fail "expected --force-code-drift to let apply succeed, got: $APPLY_FORCED"
grep -q "FORCED past code_drift" <<<"$APPLY_FORCED" || fail "forced apply did not report the overridden finding in human output"
pass "forced apply succeeded and reported the overridden finding"

say "PART 1 — apply does NOT own code state: the baseline is still corrupted after a forced apply"
PLAN3=$(wp1 wprism plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN3" | jq -e '.code_drift | length == 1' >/dev/null \
  || fail "expected code_drift to STILL be present after a forced apply (apply must not silently re-baseline), got: $(echo "$PLAN3" | jq -c .code_drift)"
pass "confirmed: apply forcing through a finding does not clear it — apply does not own code state"

say "PART 1 — deploy must ALSO refuse by default on the same drift"
DEPLOY_BASELINE_BEFORE=$(wp1 eval "echo \WPrism\Ledger::kv_get('code_versions');" 2>&1 | tail -1)
set +e
DEPLOY_REFUSE=$(host1 deploy 2>&1)
DEPLOY_REFUSE_RC=$?
set -e
echo "$DEPLOY_REFUSE"
[ "$DEPLOY_REFUSE_RC" -ne 0 ] || fail "expected wprism deploy to refuse on code_drift, got exit 0"
grep -q "code_drift" <<<"$DEPLOY_REFUSE" || fail "deploy refusal did not mention code_drift"
grep -q -- "--force-code-drift" <<<"$DEPLOY_REFUSE" || fail "deploy refusal did not name the escape hatch"
DEPLOY_BASELINE_AFTER=$(wp1 eval "echo \WPrism\Ledger::kv_get('code_versions');" 2>&1 | tail -1)
[ "$DEPLOY_BASELINE_BEFORE" = "$DEPLOY_BASELINE_AFTER" ] \
  || fail "unforced host deploy moved the code baseline"
grep -q '^deploy phase: lifecycle-status$' <<<"$DEPLOY_REFUSE" \
  || fail "host deploy did not acquire read-only lifecycle/baseline evidence"
if grep -Eq '^deploy phase: (promotion-begin|checkpoint|code-baseline-accept|lifecycle-retire|lifecycle-activate|schema-settle|lifecycle-settle)$' <<<"$DEPLOY_REFUSE"; then
  fail "unforced host deploy crossed its read-only refusal boundary: $DEPLOY_REFUSE"
fi
pass "host deploy refuses before mutation, names code_drift and preserves the exact baseline"

say "PART 1 — deploy --force-code-drift proceeds, reports the override, AND re-baselines"
DEPLOY_FORCED=$(host1 deploy --force-code-drift 2>&1)
echo "$DEPLOY_FORCED"
[ "$(grep -c 'FORCED past code_drift' <<<"$DEPLOY_FORCED")" -eq 1 ] \
  || fail "forced host deploy did not report exactly one overridden finding: $DEPLOY_FORCED"
grep -q '^deploy phase: code-baseline-accept$' <<<"$DEPLOY_FORCED" \
  || fail "forced host deploy omitted the dedicated baseline phase: $DEPLOY_FORCED"
grep -q '^deploy complete: code-baseline-accept; no code descriptor$' <<<"$DEPLOY_FORCED" \
  || fail "forced host deploy returned the wrong terminal result: $DEPLOY_FORCED"
if grep -Eq '^deploy phase: (promotion-begin|checkpoint|lifecycle-retire|lifecycle-activate|schema-settle|lifecycle-settle)$' <<<"$DEPLOY_FORCED"; then
  fail "baseline-only host deploy invented lifecycle/provider/checkpoint work: $DEPLOY_FORCED"
fi
pass "forced host deploy reported once and used the isolated baseline-only phase"

PLAN4=$(wp1 wprism plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN4" | jq -e '.code_drift | length == 0' >/dev/null \
  || fail "expected code_drift to be CLEARED after a forced deploy (re-baseline), got: $(echo "$PLAN4" | jq -c .code_drift)"
pass "confirmed: deploy re-baselines unconditionally — the drift it just forced past is gone on the next plan"

say "PART 1 — an active plugin missing from an existing baseline uses the same explicit host acceptance path"
wp1 eval "
\$v = json_decode(\WPrism\Ledger::kv_get('code_versions'), true) ?: ['plugins' => []];
unset(\$v['plugins']['$HELLO_BASENAME']);
\WPrism\Ledger::kv_set('code_versions', wp_json_encode(\$v));
" >/dev/null
MISSING_PLAN=$(wp1 wprism plan --repo=/siterepo --format=json | tail -1)
echo "$MISSING_PLAN" | jq -e --arg p "$HELLO_BASENAME" '
  (.code_drift | length) == 1 and
  .code_drift[0].issue == "code_baseline_missing" and
  .code_drift[0].plugin == $p and
  .code_drift[0].recorded_version == ""
' >/dev/null || fail "active plugin missing-baseline finding is malformed: $MISSING_PLAN"
MISSING_BEFORE=$(wp1 eval "echo \WPrism\Ledger::kv_get('code_versions');" 2>&1 | tail -1)
set +e
MISSING_REFUSE=$(host1 deploy 2>&1)
MISSING_REFUSE_RC=$?
set -e
[ "$MISSING_REFUSE_RC" -ne 0 ] \
  && grep -q 'absent from the existing WPrism code-version baseline' <<<"$MISSING_REFUSE" \
  || fail "host deploy did not refuse the missing baseline precisely: $MISSING_REFUSE"
[ "$MISSING_BEFORE" = "$(wp1 eval "echo \WPrism\Ledger::kv_get('code_versions');" 2>&1 | tail -1)" ] \
  || fail "missing-baseline refusal mutated the ledger"
MISSING_ACCEPT=$(host1 deploy --force-code-drift 2>&1) \
  || fail "forced missing-baseline acceptance failed: $MISSING_ACCEPT"
[ "$(grep -c 'FORCED past code_drift' <<<"$MISSING_ACCEPT")" -eq 1 ] \
  && grep -q '^deploy phase: code-baseline-accept$' <<<"$MISSING_ACCEPT" \
  || fail "missing-baseline acceptance did not use/report one baseline phase: $MISSING_ACCEPT"
MISSING_AFTER=$(wp1 wprism plan --repo=/siterepo --format=json | tail -1)
echo "$MISSING_AFTER" | jq -e '.code_drift == []' >/dev/null \
  || fail "missing-baseline acceptance did not converge: $MISSING_AFTER"
pass "code_baseline_missing refuses byte-identically, then converges through the same explicit phase"

say "PART 1 — a standalone theme is one baseline identity even though WordPress stores it in two slots"
THEME_STYLESHEET=$(wp1 option get stylesheet)
THEME_TEMPLATE=$(wp1 option get template)
[ -n "$THEME_STYLESHEET" ] && [ "$THEME_STYLESHEET" = "$THEME_TEMPLATE" ] \
  || fail "fresh fixture does not expose one standalone theme identity: template=$THEME_TEMPLATE stylesheet=$THEME_STYLESHEET"
THEME_INSTALLED=$(wp1 theme get "$THEME_STYLESHEET" --field=version)
wp1 eval "
\$v = json_decode(\WPrism\Ledger::kv_get('code_versions'), true) ?: ['plugins' => []];
\$v['template'] = '$THEME_TEMPLATE';
\$v['stylesheet'] = '$THEME_STYLESHEET';
\$v['template_version'] = '0.0-wprism-prior';
\$v['stylesheet_version'] = '0.0-wprism-prior';
\WPrism\Ledger::kv_set('code_versions', wp_json_encode(\$v));
" >/dev/null
THEME_PLAN=$(wp1 wprism plan --repo=/siterepo --format=json | tail -1)
echo "$THEME_PLAN" | jq -e --arg t "$THEME_STYLESHEET" --arg v "$THEME_INSTALLED" '
  (.code_drift | length) == 1 and
  .code_drift[0].issue == "code_drift" and
  .code_drift[0].kind == "theme" and
  .code_drift[0].theme == $t and
  .code_drift[0].installed_version == $v
' >/dev/null || fail "standalone theme emitted duplicate or malformed baseline evidence: $THEME_PLAN"
THEME_BEFORE=$(wp1 eval "echo \WPrism\Ledger::kv_get('code_versions');" 2>&1 | tail -1)
set +e
THEME_REFUSE=$(host1 deploy 2>&1)
THEME_REFUSE_RC=$?
set -e
[ "$THEME_REFUSE_RC" -ne 0 ] && grep -q "$THEME_STYLESHEET theme" <<<"$THEME_REFUSE" \
  || fail "host did not refuse the one standalone-theme finding: $THEME_REFUSE"
[ "$THEME_BEFORE" = "$(wp1 eval "echo \WPrism\Ledger::kv_get('code_versions');" 2>&1 | tail -1)" ] \
  || fail "standalone-theme refusal moved the baseline"
THEME_ACCEPT=$(host1 deploy --force-code-drift 2>&1) \
  || fail "standalone-theme baseline acceptance failed: $THEME_ACCEPT"
[ "$(grep -c 'FORCED past code_drift' <<<"$THEME_ACCEPT")" -eq 1 ] \
  && grep -q '^deploy phase: code-baseline-accept$' <<<"$THEME_ACCEPT" \
  || fail "standalone-theme acceptance was duplicated or used the wrong phase: $THEME_ACCEPT"
THEME_AFTER=$(wp1 wprism plan --repo=/siterepo --format=json | tail -1)
echo "$THEME_AFTER" | jq -e '.code_drift == []' >/dev/null \
  || fail "standalone-theme acceptance did not converge: $THEME_AFTER"
pass "standalone theme drift crosses the host wire once and converges through baseline acceptance"

say "PART 1 — issue #3507: a plain capture OBSERVES the drift and refuses to accept it as the new baseline"
wp1 eval "
\$v = json_decode(\WPrism\Ledger::kv_get('code_versions'), true) ?: ['plugins' => []];
\$v['plugins']['$HELLO_BASENAME'] = '$FAKE_BASELINE';
\WPrism\Ledger::kv_set('code_versions', wp_json_encode(\$v));
" >/dev/null
PLAN5=$(wp1 wprism plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN5" | jq -e '.code_drift | length == 1' >/dev/null || fail "re-corruption before the capture observation check did not take"
KV_BEFORE=$(wp1 eval "echo \WPrism\Ledger::kv_get('code_versions');" 2>&1 | tail -1)
CAPTURE_OBSERVED=$(wp1 wprism capture --repo=/siterepo 2>&1)
git -C siterepo/codedrift1 checkout -q -- state 2>/dev/null || true
echo "$CAPTURE_OBSERVED"
grep -q "did NOT accept it as the new baseline" <<<"$CAPTURE_OBSERVED" \
  || fail "expected a plain 'wprism capture' to WARN that it only observed the drift, got: $CAPTURE_OBSERVED"
grep -q "$HELLO_BASENAME" <<<"$CAPTURE_OBSERVED" \
  || fail "the capture warning does not name the drifted plugin: $CAPTURE_OBSERVED"
KV_AFTER=$(wp1 eval "echo \WPrism\Ledger::kv_get('code_versions');" 2>&1 | tail -1)
[ "$KV_BEFORE" = "$KV_AFTER" ] \
  || fail "capture moved the recorded baseline across an unaccepted drift: '$KV_BEFORE' -> '$KV_AFTER'"
PLAN6=$(wp1 wprism plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN6" | jq -e '.code_drift | length == 1' >/dev/null \
  || fail "expected the code_drift finding to SURVIVE a plain 'wprism capture', got: $(echo "$PLAN6" | jq -c .code_drift)"
pass "capture reports the drift, leaves wprism_kv['code_versions'] byte-identical, and the finding survives — accepting code is deploy's decision, not a side effect of observing"

say "PART 1 — with nothing to accept, capture still records the baseline (the ordinary path is unchanged)"
wp1 eval "\WPrism\Ledger::kv_delete('code_versions');" >/dev/null
CAPTURE_CLEAN=$(wp1 wprism capture --repo=/siterepo 2>&1)
git -C siterepo/codedrift1 checkout -q -- state 2>/dev/null || true
echo "$CAPTURE_CLEAN"
if grep -q "did NOT accept it as the new baseline" <<<"$CAPTURE_CLEAN"; then
  fail "a capture with no baseline to compare against must not warn about code_drift: $CAPTURE_CLEAN"
fi
KV_RECORDED=$(wp1 eval "echo \WPrism\Ledger::kv_get('code_versions');" 2>&1 | tail -1)
jq -e --arg p "$HELLO_BASENAME" --arg v "$REAL_VERSION" '.plugins[$p] == $v' >/dev/null <<<"$KV_RECORDED" \
  || fail "expected capture to record the live version when there is nothing to accept, got: $KV_RECORDED"
PLAN7=$(wp1 wprism plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN7" | jq -e '.code_drift | length == 0' >/dev/null \
  || fail "expected zero code_drift after capture recorded a fresh baseline, got: $(echo "$PLAN7" | jq -c .code_drift)"
pass "capture still maintains the baseline whenever there is no unaccepted finding standing in the way"

say "PART 2 — DISALLOW_FILE_MODS advisory doctor check, via the real cli/wprism orchestrator"
DOCTOR_OUT1=$(WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" COMPOSE_PROJECT_NAME=wprism-codedrift php ../cli/wprism doctor codedrift1 --envs-file="$HOST_ENVS_FILE" 2>&1)
DOCTOR_RC1=$?
echo "$DOCTOR_OUT1"
[ "$DOCTOR_RC1" -eq 0 ] || fail "expected wprism doctor to exit 0 (advisory finding must not fail it), got $DOCTOR_RC1"
grep -q '\[WARN\] DISALLOW_FILE_MODS set' <<<"$DOCTOR_OUT1" \
  || fail "expected a [WARN] (not [FAIL]) DISALLOW_FILE_MODS line on a fresh install with the constant unset"
pass "doctor exits 0 and labels the missing constant WARN, not FAIL — advisory confirmed both ways"

say "PART 2 — setting DISALLOW_FILE_MODS flips the check to PASS"
wp1 config set DISALLOW_FILE_MODS true --raw --type=constant >/dev/null
DOCTOR_OUT2=$(WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" COMPOSE_PROJECT_NAME=wprism-codedrift php ../cli/wprism doctor codedrift1 --envs-file="$HOST_ENVS_FILE" 2>&1)
echo "$DOCTOR_OUT2"
grep -q '\[PASS\] DISALLOW_FILE_MODS set' <<<"$DOCTOR_OUT2" \
  || fail "expected [PASS] DISALLOW_FILE_MODS set once the constant is actually defined true"
pass "doctor correctly reflects DISALLOW_FILE_MODS once set"

printf '\n\033[1;32m✔ issue #3231 regression passed: code_drift detection/refusal/re-baseline + DISALLOW_FILE_MODS advisory check\033[0m\n'

say "cleanup: destroy the codedrift pair (green run)"
bash bin/pair.sh destroy codedrift
pass "codedrift pair destroyed"
