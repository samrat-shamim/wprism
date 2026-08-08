#!/usr/bin/env bash
# Regression — DUO-3231: code_drift detection (Deploy::code_drift()) and the
# DISALLOW_FILE_MODS advisory `duo doctor` check.
#
# Uses WordPress core's own bundled "Hello Dolly" plugin as the fixture
# (wp-content/plugins/hello.php, ships with every core install) — zero
# network installs needed, deterministic, always present. Its exact
# get_plugins() basename is discovered live rather than hardcoded (a
# single-file plugin's basename is just its filename, but that's an
# assumption worth confirming empirically rather than baking in).
#
# One pair.sh pair (own dedicated pair — "codedrift", headless: no render
# checks, pure wp-cli + `cli/duo` orchestrator against Deploy/code-half
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
# header for why a direct duo_kv edit is the right simulation, not
# actually swapping plugin files) makes exactly one code_drift finding
# appear, naming the plugin and both versions; apply AND deploy both
# refuse by default and both proceed with --force-code-drift while still
# reporting what was overridden (Architecture Rulings §1); apply's forced
# pass does NOT clear the finding (apply never re-baselines — it doesn't
# own code state); deploy's forced pass DOES clear it (re-baselines
# unconditionally); capture alone (no force flag involved at all) also
# re-baselines.
#
# PART 2: the DISALLOW_FILE_MODS `duo doctor` check via the REAL cli/duo
# orchestrator (DockerTransport against this same pair) — advisory only,
# confirmed both by exit code (WARN must not fail doctor) and by the
# printed severity label flipping from WARN to PASS once the constant is
# actually set via `wp config set --type=constant`.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

PORT1="${CODEDRIFT_PORT1:-8862}"
PORT2="${CODEDRIFT_PORT2:-8863}"
COMPOSE="docker compose -p duo-codedrift -f pair.yml"
export DUO_PAIR=codedrift
wp1() { $COMPOSE run --rm -T cli1 wp "$@"; }

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
cat > siterepo/codedrift1/site.duo.json <<'EOF'
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

say "PART 1 — capture: must record a code_versions baseline (Deploy::record_code_versions())"
wp1 duo capture --repo=/siterepo
git -C siterepo/codedrift1 add -A
git -C siterepo/codedrift1 -c user.name=duo -c user.email=duo@example.test commit -qm "baseline capture ($HELLO_BASENAME active)"

say "sanity: plan shows zero code_drift immediately after capture (baseline == live, nothing drifted yet)"
PLAN1=$(wp1 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN1" | jq -e '.code_drift | length == 0' >/dev/null \
  || fail "expected zero code_drift findings right after capture, got: $(echo "$PLAN1" | jq -c .code_drift)"
pass "clean baseline, no false positives"

REAL_VERSION=$(wp1 eval "echo get_plugins()['$HELLO_BASENAME']['Version'];" 2>&1 | tail -1)
[ -n "$REAL_VERSION" ] || fail "could not read $HELLO_BASENAME's real installed version"
FAKE_BASELINE="0.0.1-fake-baseline"
echo "real installed version: $REAL_VERSION (will simulate a stale recorded baseline of $FAKE_BASELINE)"

say "simulate out-of-band drift: corrupt the RECORDED baseline directly (not the plugin files — see header comment for why this is the right simulation)"
wp1 eval "
\Duo\Ledger::ensure();
\$v = json_decode(\Duo\Ledger::kv_get('code_versions'), true) ?: ['plugins' => []];
\$v['plugins']['$HELLO_BASENAME'] = '$FAKE_BASELINE';
\Duo\Ledger::kv_set('code_versions', wp_json_encode(\$v));
echo 'corrupted';
" >/dev/null

say "PART 1 — plan must now surface exactly one code_drift finding, naming the plugin and both versions"
PLAN2=$(wp1 duo plan --repo=/siterepo --format=json | tail -1)
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
APPLY_REFUSE=$(wp1 duo apply --repo=/siterepo --default-author=admin 2>&1)
APPLY_REFUSE_RC=$?
set -e
echo "$APPLY_REFUSE"
[ "$APPLY_REFUSE_RC" -ne 0 ] || fail "expected duo apply to refuse on code_drift, got exit 0"
grep -q "code_drift" <<<"$APPLY_REFUSE" || fail "refusal did not mention code_drift"
grep -q -- "--force-code-drift" <<<"$APPLY_REFUSE" || fail "refusal did not name the escape hatch"
pass "apply refuses loudly, names code_drift and --force-code-drift"

say "PART 1 — apply --force-code-drift proceeds, reporting the override (report-not-hide, Architecture Rulings §1)"
APPLY_FORCED=$(wp1 duo apply --repo=/siterepo --default-author=admin --force-code-drift 2>&1)
echo "$APPLY_FORCED"
grep -qi "success\|applied" <<<"$APPLY_FORCED" || fail "expected --force-code-drift to let apply succeed, got: $APPLY_FORCED"
grep -q "FORCED past code_drift" <<<"$APPLY_FORCED" || fail "forced apply did not report the overridden finding in human output"
pass "forced apply succeeded and reported the overridden finding"

say "PART 1 — apply does NOT own code state: the baseline is still corrupted after a forced apply"
PLAN3=$(wp1 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN3" | jq -e '.code_drift | length == 1' >/dev/null \
  || fail "expected code_drift to STILL be present after a forced apply (apply must not silently re-baseline), got: $(echo "$PLAN3" | jq -c .code_drift)"
pass "confirmed: apply forcing through a finding does not clear it — only deploy/capture legitimately observe code"

say "PART 1 — deploy must ALSO refuse by default on the same drift"
set +e
DEPLOY_REFUSE=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_REFUSE_RC=$?
set -e
echo "$DEPLOY_REFUSE"
[ "$DEPLOY_REFUSE_RC" -ne 0 ] || fail "expected duo deploy to refuse on code_drift, got exit 0"
grep -q "code_drift" <<<"$DEPLOY_REFUSE" || fail "deploy refusal did not mention code_drift"
grep -q -- "--force-code-drift" <<<"$DEPLOY_REFUSE" || fail "deploy refusal did not name the escape hatch"
pass "deploy refuses loudly too, names code_drift and --force-code-drift"

say "PART 1 — deploy --force-code-drift proceeds, reports the override, AND re-baselines"
DEPLOY_FORCED=$(wp1 duo deploy --repo=/siterepo --force-code-drift 2>&1)
echo "$DEPLOY_FORCED"
grep -q "FORCED past code_drift" <<<"$DEPLOY_FORCED" || fail "forced deploy did not report the overridden finding in human output"
pass "forced deploy succeeded and reported the overridden finding"

PLAN4=$(wp1 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN4" | jq -e '.code_drift | length == 0' >/dev/null \
  || fail "expected code_drift to be CLEARED after a forced deploy (re-baseline), got: $(echo "$PLAN4" | jq -c .code_drift)"
pass "confirmed: deploy re-baselines unconditionally — the drift it just forced past is gone on the next plan"

say "PART 1 — capture alone (no force flag involved) also re-baselines"
wp1 eval "
\$v = json_decode(\Duo\Ledger::kv_get('code_versions'), true) ?: ['plugins' => []];
\$v['plugins']['$HELLO_BASENAME'] = '$FAKE_BASELINE';
\Duo\Ledger::kv_set('code_versions', wp_json_encode(\$v));
" >/dev/null
PLAN5=$(wp1 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN5" | jq -e '.code_drift | length == 1' >/dev/null || fail "re-corruption before the capture re-baseline check did not take"
wp1 duo capture --repo=/siterepo >/dev/null
git -C siterepo/codedrift1 checkout -q -- state 2>/dev/null || true
PLAN6=$(wp1 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN6" | jq -e '.code_drift | length == 0' >/dev/null \
  || fail "expected a plain 'duo capture' to also re-baseline, got: $(echo "$PLAN6" | jq -c .code_drift)"
pass "capture re-baselines too — either capture or deploy counts as 'Duo legitimately observed this environment's code'"

say "PART 2 — DISALLOW_FILE_MODS advisory doctor check, via the real cli/duo orchestrator"
ABS_COMPOSE="$(pwd)/pair.yml"
DOCTOR_ENVS_FILE=$(mktemp)
cat > "$DOCTOR_ENVS_FILE" <<EOF
{"envs": {"codedrift1": {"transport": "docker", "compose_file": "$ABS_COMPOSE", "service": "cli1", "repo_path": "/siterepo"}}}
EOF
trap 'rm -f "$DOCTOR_ENVS_FILE"' EXIT

DOCTOR_OUT1=$(DUO_PORT1="$PORT1" DUO_PORT2="$PORT2" php ../cli/duo doctor codedrift1 --envs-file="$DOCTOR_ENVS_FILE" 2>&1)
DOCTOR_RC1=$?
echo "$DOCTOR_OUT1"
[ "$DOCTOR_RC1" -eq 0 ] || fail "expected duo doctor to exit 0 (advisory finding must not fail it), got $DOCTOR_RC1"
grep -q '\[WARN\] DISALLOW_FILE_MODS set' <<<"$DOCTOR_OUT1" \
  || fail "expected a [WARN] (not [FAIL]) DISALLOW_FILE_MODS line on a fresh install with the constant unset"
pass "doctor exits 0 and labels the missing constant WARN, not FAIL — advisory confirmed both ways"

say "PART 2 — setting DISALLOW_FILE_MODS flips the check to PASS"
wp1 config set DISALLOW_FILE_MODS true --raw --type=constant >/dev/null
DOCTOR_OUT2=$(DUO_PORT1="$PORT1" DUO_PORT2="$PORT2" php ../cli/duo doctor codedrift1 --envs-file="$DOCTOR_ENVS_FILE" 2>&1)
echo "$DOCTOR_OUT2"
grep -q '\[PASS\] DISALLOW_FILE_MODS set' <<<"$DOCTOR_OUT2" \
  || fail "expected [PASS] DISALLOW_FILE_MODS set once the constant is actually defined true"
pass "doctor correctly reflects DISALLOW_FILE_MODS once set"

printf '\n\033[1;32m✔ DUO-3231 regression passed: code_drift detection/refusal/re-baseline + DISALLOW_FILE_MODS advisory check\033[0m\n'

say "cleanup: destroy the codedrift pair (green run)"
bash bin/pair.sh destroy codedrift
pass "codedrift pair destroyed"
