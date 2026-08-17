#!/usr/bin/env bash
# Offline contract for duo deploy <env>: compile -> target runtime preflight -> begin target session ->
# optional code stage -> fresh lifecycle retire/activate -> optional code finalize.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DUO="$REPO_ROOT/cli/duo"
FIX="$(mktemp -d)"
BIN="$FIX/bin"
SITE="$FIX/site"
ENVS="$FIX/envs.json"
LOG="$FIX/wp.log"

pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
trap 'rm -rf "$FIX"' EXIT
mkdir -p "$BIN" "$SITE"

cat > "$BIN/wp" <<'FAKE'
#!/usr/bin/env bash
set -e
printf '%s\n' "$*" >> "$FAKE_WP_LOG"
while true; do
  case "${1:-}" in
    --path=*|--exec=*|--skip-plugins|--skip-themes) shift ;;
    *) break ;;
  esac
done
first=$1
second=$2
if [ "$first:$second" = duo:compile ]; then
  [ "$FAKE_COMPILE_FAIL" = 1 ] && exit 6
  hash=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
  if [ "$FAKE_CODE_ENABLED" = 1 ]; then
    summary='{"artifact_hash":"'"$hash"'","code":{"code_revision":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","format":1}}'
  else
    summary='{"artifact_hash":"'"$hash"'"}'
  fi
  for arg in "$@"; do
    case "$arg" in
      --out=*) out=$(printf '%s' "$arg" | sed 's/^--out=//'); printf '%s\n' "$summary" > "$out" ;;
    esac
  done
  printf '%s\n' "$summary"
  exit 0
fi
if [ "$first:$second" = duo:code-preflight ]; then
  if [ "$FAKE_PREFLIGHT_FAIL" = 1 ]; then
    printf '%s\n' '{"format":"duo-command-refusal/v1","ok":false,"command":"code-preflight","error":"code_compilation_failed","diagnostics":[{"code":"code_source_requires_php_incompatible","path":"plugins/inactive/inactive.php","required_version":"99.0","target_version":"8.3.0"}]}'
    exit 14
  fi
  printf '%s\n' '{"format":"duo-code-runtime/v1","enabled":true,"compatible":true,"code_revision":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","target":{"php":"8.3.0","wordpress":"6.8.2","source":"target-control-plane"},"requirements":[],"diagnostics":[]}'
  exit 0
fi
if [ "$first:$second" = duo:promotion-begin ]; then exit 0; fi
if [ "$first:$second" = duo:promotion-abort ]; then exit 0; fi
if [ "$first:$second" = duo:code-stage ]; then [ "$FAKE_STAGE_FAIL" = 1 ] && exit 8; exit 0; fi
if [ "$first:$second" = duo:deploy ]; then
  if [[ "$*" == *"--lifecycle-phase=retire"* && "$FAKE_RETIRE_FAIL" = 1 ]]; then exit 7; fi
  if [[ "$*" == *"--lifecycle-phase=activate"* && "$FAKE_ACTIVATE_FAIL" = 1 ]]; then exit 13; fi
  exit 0
fi
if [ "$first:$second" = duo:code-finalize ]; then [ "$FAKE_FINALIZE_FAIL" = 1 ] && exit 9; exit 0; fi
exit 11
FAKE
chmod +x "$BIN/wp"
cat > "$ENVS" <<EOF
{"envs":{"unit":{"transport":"local","wp_path":"$BIN","repo_path":"$SITE"}}}
EOF
export PATH="$BIN:$PATH" FAKE_WP_LOG="$LOG"

line() { sed -n "$1p" "$LOG"; }
calls() { wc -l < "$LOG" | tr -d ' '; }
arg() { grep -o -- "$2" <<<"$1" || true; }
assert_control_call() {
  local call="$1" label="$2"
  [[ "$call" == *"--exec="* \
    && "$call" == *"DUO_CONTROL_PLANE"* \
    && "$call" == *"DUO_CONTROL_WPMU_PLUGIN_DIR"* \
    && "$call" == *"after_wp_config_load"* \
    && "$call" == *"SUNRISE"* \
    && "$call" == *"--skip-plugins"* \
    && "$call" == *"--skip-themes"* ]] \
    || fail "$label did not use the isolated Duo control-plane bootstrap"
}
assert_runtime_call() {
  local call="$1" label="$2"
  [[ "$call" != *"--exec="* \
    && "$call" != *"--skip-plugins"* \
    && "$call" != *"--skip-themes"* ]] \
    || fail "$label incorrectly skipped the WordPress runtime it must reconcile"
}
invoke() {
  mode=$1
  shift
  : > "$LOG"
  if OUT="$(FAKE_CODE_ENABLED="$mode" FAKE_COMPILE_FAIL=0 FAKE_PREFLIGHT_FAIL=0 FAKE_STAGE_FAIL=0 FAKE_RETIRE_FAIL=0 FAKE_ACTIVATE_FAIL=0 FAKE_FINALIZE_FAIL=0 "$@" "$DUO" --envs-file="$ENVS" deploy unit --force-code-mismatch --force-code-drift 2>&1)"; then
    CODE=0
  else
    CODE=$?
  fi
}

# --exec itself runs before wp-config.php. Prove the control bootstrap defers
# its layout decision until after the config constants exist, and refuses a
# custom MU root before it can even require the protected agent (let alone run
# code-stage against the inert standard directory).
DUO_ROOT="$REPO_ROOT" CONTROL_WP_ROOT="$SITE/control-wp" php <<'PHP'
<?php
final class WP_CLI {
    /** @var array<string,list<callable>> */
    public static array $hooks = [];
    public static function get_runner(): object {
        return (object) ['config' => ['path' => getenv('CONTROL_WP_ROOT')]];
    }
    public static function add_hook(string $name, callable $hook): void {
        self::$hooks[$name][] = $hook;
    }
}

$root = getenv('CONTROL_WP_ROOT');
@mkdir($root . '/wp-content/mu-plugins/duo', 0777, true);
$loaded = $root . '/agent-loaded';
file_put_contents(
    $root . '/wp-content/mu-plugins/duo/duo.php',
    '<?php file_put_contents(' . var_export($loaded, true) . ', "loaded");'
);
require getenv('DUO_ROOT') . '/cli/src/Transport/CodeDeploy.php';
$args = \Duo\Orchestrator\CodeDeploy::controlArgs(['duo', 'code-stage']);
$exec = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--exec=')) {
        $exec = substr($arg, strlen('--exec='));
    }
}
if (!is_string($exec)) {
    throw new RuntimeException('FAIL: control args omitted --exec bootstrap');
}
eval($exec);
define('WP_CONTENT_DIR', $root . '/custom-content');
define('WPMU_PLUGIN_DIR', $root . '/custom-mu');
try {
    foreach (WP_CLI::$hooks['after_wp_config_load'] ?? [] as $hook) {
        $hook();
    }
    throw new RuntimeException('FAIL: custom WPMU_PLUGIN_DIR was accepted');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'requires standard wp-content/mu-plugins')) {
        throw $e;
    }
}
if (file_exists($loaded)) {
    throw new RuntimeException('FAIL: custom-root refusal loaded the agent after its layout gate');
}
if (file_exists($root . '/wp-content/mu-plugins/staged.php')) {
    throw new RuntimeException('FAIL: custom-root refusal wrote the inert standard MU target');
}
PHP
pass "control bootstrap proves wp-config MU layout before agent load or stage writes"

# The accepted bootstrap keeps the target's cron out of the control window:
# spawn_cron() writes the doing_cron transient and POSTs wp-cron.php, which
# runs plugin code — exactly what a control-plane observation must not do.
DUO_ROOT="$REPO_ROOT" CONTROL_WP_ROOT="$SITE/control-wp-cron" php <<'PHP'
<?php
final class WP_CLI {
    /** @var array<string,list<callable>> */
    public static array $hooks = [];
    public static function get_runner(): object {
        return (object) ['config' => ['path' => getenv('CONTROL_WP_ROOT')]];
    }
    public static function add_hook(string $name, callable $hook): void {
        self::$hooks[$name][] = $hook;
    }
}
$root = getenv('CONTROL_WP_ROOT');
@mkdir($root . '/wp-content/mu-plugins/duo', 0777, true);
file_put_contents($root . '/wp-content/mu-plugins/duo/duo.php', '<?php // fixture agent');
require getenv('DUO_ROOT') . '/cli/src/Transport/CodeDeploy.php';
$exec = null;
foreach (\Duo\Orchestrator\CodeDeploy::controlArgs(['duo', 'refresh-export']) as $arg) {
    if (str_starts_with($arg, '--exec=')) {
        $exec = substr($arg, strlen('--exec='));
    }
}
eval($exec);
foreach (WP_CLI::$hooks['after_wp_config_load'] ?? [] as $hook) {
    $hook();
}
if (!defined('DISABLE_WP_CRON') || DISABLE_WP_CRON !== true) {
    throw new RuntimeException('FAIL: the control bootstrap left the target cron spawnable');
}
if (!defined('DUO_CONTROL_PLANE')) {
    throw new RuntimeException('FAIL: the accepted bootstrap did not mark the control plane');
}
PHP
pass "control bootstrap disables the target's cron spawn for the whole control-plane window"

# No descriptor preserves lifecycle-only deploy and never receives code flags.
invoke 0 env
[ "$CODE" -eq 0 ] || fail "legacy deploy failed: $OUT"
[ "$(calls)" = 4 ] || fail "legacy path expected four wp calls"
ONE="$(line 1)"
TWO="$(line 2)"
THREE="$(line 3)"
FOUR="$(line 4)"
[[ "$ONE" == *"duo compile"* && "$TWO" == *"duo promotion-begin"* \
  && "$THREE" == *"duo deploy"*"--lifecycle-phase=retire"* \
  && "$FOUR" == *"duo deploy"*"--lifecycle-phase=activate"* ]] || fail "legacy phase order wrong"
assert_control_call "$ONE" "legacy compile"
assert_control_call "$TWO" "legacy promotion-begin"
assert_runtime_call "$THREE" "legacy retirement"
assert_runtime_call "$FOUR" "legacy activation"
[[ "$THREE" != *"--materializing-code"* && "$THREE" == *"--promotion-hold"* && "$THREE" != *"--state-handoff"* ]] \
  || fail "legacy retirement did not retain only its continuation lease"
[[ "$FOUR" != *"--materializing-code"* && "$FOUR" != *"--promotion-hold"* && "$FOUR" != *"--state-handoff"* ]] \
  || fail "legacy activation retained internal flags after its final phase"
[[ "$THREE" == *"--force-code-mismatch"* && "$THREE" == *"--force-code-drift"* \
  && "$FOUR" == *"--force-code-mismatch"* && "$FOUR" == *"--force-code-drift"* ]] \
  || fail "code force flags were not forwarded"
[ -n "$(arg "$THREE" '--compiled=[^ ]*')" ] || fail "legacy lifecycle lacks artifact"
[ "$(arg "$TWO" '--promotion-owner=[^ ]*')" = "$(arg "$THREE" '--promotion-owner=[^ ]*')" ] || fail "legacy begin/lifecycle owner changed"
[ "$(arg "$TWO" '--artifact-hash=[^ ]*')" = "$(arg "$THREE" '--artifact-hash=[^ ]*')" ] || fail "legacy begin/lifecycle hash changed"
grep -q 'deploy complete: lifecycle-retire -> lifecycle-activate (no code descriptor)' <<<"$OUT" || fail "legacy completion missing"
pass "legacy artifact keeps lifecycle-only deploy"

# Descriptor turns on exactly stage, lifecycle, finalize. All use one artifact
# and owner; only lifecycle gets hold/materializing-code; no DB checkpoint.
invoke 1 env
[ "$CODE" -eq 0 ] || fail "code deploy failed: $OUT"
[ "$(calls)" = 7 ] || fail "code path expected seven wp calls"
ONE="$(line 1)"
TWO="$(line 2)"
THREE="$(line 3)"
FOUR="$(line 4)"
FIVE="$(line 5)"
SIX="$(line 6)"
SEVEN="$(line 7)"
[[ "$ONE" == *"duo compile"* && "$TWO" == *"duo code-preflight"* \
  && "$THREE" == *"duo promotion-begin"* && "$FOUR" == *"duo code-stage"* \
  && "$FIVE" == *"duo deploy"*"--lifecycle-phase=retire"* \
  && "$SIX" == *"duo deploy"*"--lifecycle-phase=activate"* \
  && "$SEVEN" == *"duo code-finalize"* ]] || fail "code phase order wrong"
assert_control_call "$ONE" "code compile"
assert_control_call "$TWO" "code target-runtime preflight"
assert_control_call "$THREE" "code promotion-begin"
assert_control_call "$FOUR" "code stage"
assert_runtime_call "$FIVE" "code retirement"
assert_runtime_call "$SIX" "code activation"
assert_control_call "$SEVEN" "code finalize"
A="$(arg "$FOUR" '--compiled=[^ ]*')"
O="$(arg "$FOUR" '--promotion-owner=[^ ]*')"
H="$(arg "$FOUR" '--artifact-hash=[^ ]*')"
[ -n "$A" ] && [ -n "$O" ] || fail "stage lacks artifact/owner"
[ "$(arg "$TWO" '--compiled=[^ ]*')" = "$A" ] || fail "preflight did not inspect the frozen stage artifact"
[ "$(arg "$TWO" '--artifact-hash=[^ ]*')" = "$H" ] || fail "preflight did not bind the host-observed artifact hash"
[ "$(arg "$FIVE" '--compiled=[^ ]*')" = "$A" ] && [ "$(arg "$SIX" '--compiled=[^ ]*')" = "$A" ] && [ "$(arg "$SEVEN" '--compiled=[^ ]*')" = "$A" ] || fail "artifact changed between code phases"
[ "$(arg "$THREE" '--promotion-owner=[^ ]*')" = "$O" ] && [ "$(arg "$FIVE" '--promotion-owner=[^ ]*')" = "$O" ] && [ "$(arg "$SIX" '--promotion-owner=[^ ]*')" = "$O" ] && [ "$(arg "$SEVEN" '--promotion-owner=[^ ]*')" = "$O" ] || fail "owner changed between code phases"
[ "$(arg "$THREE" '--artifact-hash=[^ ]*')" = "$H" ] && [ "$(arg "$FIVE" '--artifact-hash=[^ ]*')" = "$H" ] && [ "$(arg "$SIX" '--artifact-hash=[^ ]*')" = "$H" ] && [ "$(arg "$SEVEN" '--artifact-hash=[^ ]*')" = "$H" ] || fail "expected artifact hash changed between phases"
[[ "$FOUR" != *"--promotion-hold"* && "$FOUR" != *"--materializing-code"* ]] || fail "stage got lifecycle-only flags"
[[ "$FIVE" == *"--promotion-hold"* && "$FIVE" == *"--materializing-code"* && "$FIVE" != *"--state-handoff"* ]] \
  || fail "standalone retirement flags crossed the promotion-only handoff boundary"
[[ "$SIX" == *"--promotion-hold"* && "$SIX" == *"--materializing-code"* && "$SIX" != *"--state-handoff"* ]] \
  || fail "standalone activation did not retain the code-finalize continuation"
[[ "$SEVEN" != *"--promotion-hold"* && "$SEVEN" != *"--materializing-code"* ]] || fail "standalone finalize retained lifecycle flags"
ART="$(printf '%s' "$A" | sed 's/^--compiled=//')"
[[ "$ART" == "$SITE/.duo/artifacts/deploy-"*.json ]] || fail "artifact outside target .duo/artifacts"
[ -f "$ART" ] || fail "artifact not retained"
grep -q 'deploy complete: code-stage -> lifecycle-retire -> lifecycle-activate -> code-finalize' <<<"$OUT" || fail "code completion missing"
pass "code deploy stages/lifecycle-deploys/finalizes one frozen artifact"

# Stop-on-first-failure boundaries.
invoke 1 env FAKE_STAGE_FAIL=1
[ "$CODE" -eq 8 ] || fail "stage exit not propagated"
[ "$(calls)" = 5 ] || fail "later phases or cleanup were wrong after stage failure"
[[ "$(line 5)" == *"duo promotion-abort"* ]] || fail "stage failure did not clean begun session"
assert_control_call "$(line 5)" "stage-failure promotion-abort"
grep -q 'code-stage failed.*were not run' <<<"$OUT" || fail "stage stop wording missing"
pass "stage failure stops lifecycle/finalize"

invoke 1 env FAKE_RETIRE_FAIL=1
[ "$CODE" -eq 7 ] || fail "lifecycle exit not propagated"
[ "$(calls)" = 6 ] || fail "finalize/cleanup calls wrong after lifecycle failure"
[[ "$(line 6)" == *"duo promotion-abort"* ]] || fail "lifecycle failure did not clean begun session"
assert_control_call "$(line 6)" "retirement-failure promotion-abort"
pass "retirement failure stops activation/finalize"

invoke 1 env FAKE_ACTIVATE_FAIL=1
[ "$CODE" -eq 13 ] || fail "activation exit not propagated"
[ "$(calls)" = 7 ] || fail "finalize/cleanup calls wrong after activation failure"
[[ "$(line 7)" == *"duo promotion-abort"* ]] || fail "activation failure did not clean begun session"
assert_control_call "$(line 7)" "activation-failure promotion-abort"
pass "activation failure stops finalize"

invoke 1 env FAKE_FINALIZE_FAIL=1
[ "$CODE" -eq 9 ] || fail "finalize exit not propagated"
[ "$(calls)" = 8 ] || fail "wrong calls after finalize failure"
[[ "$(line 8)" == *"duo promotion-abort"* ]] || fail "finalize failure did not clean begun session"
assert_control_call "$(line 8)" "finalize-failure promotion-abort"
pass "finalize failure is non-successful"

invoke 1 env FAKE_COMPILE_FAIL=1
[ "$CODE" -eq 6 ] || fail "compile exit not propagated"
[ "$(calls)" = 1 ] || fail "code/lifecycle ran after compile failure"
grep -q 'no lifecycle or code materialization occurred' <<<"$OUT" || fail "compile boundary missing"
pass "compile failure causes no code/lifecycle call"

invoke 1 env FAKE_PREFLIGHT_FAIL=1
[ "$CODE" -eq 14 ] || fail "target-runtime preflight exit not propagated"
[ "$(calls)" = 2 ] || fail "begin/stage/lifecycle ran after target-runtime preflight failure"
[[ "$(line 1)" == *"duo compile"* && "$(line 2)" == *"duo code-preflight"* ]] \
  || fail "target-runtime refusal did not stop at compile -> preflight"
assert_control_call "$(line 2)" "failed code target-runtime preflight"
grep -q 'code_source_requires_php_incompatible' <<<"$OUT" || fail "target-runtime refusal lost its structured diagnostic"
grep -q 'refusing before promotion-begin' <<<"$OUT" || fail "target-runtime refusal did not name its no-lease boundary"
if grep -q 'duo promotion-abort' "$LOG"; then
  fail "pre-begin target-runtime refusal attempted lease cleanup"
fi
pass "target-runtime incompatibility refuses before begin with no cleanup fiction"

# Public deploy owns its repo/artifact/lease flags and exposes only the two
# force flags. A second --repo would otherwise make the lifecycle command's
# target differ from the immutable artifact's source repo.
for bad in --repo=/tmp/forged --compiled=/tmp/fake.json --artifact-hash=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa --promotion-owner=intruder --promotion-hold --materializing-code --state-handoff --lifecycle-phase=activate --force-unresolved-refs --with-deletes; do
  : > "$LOG"
  if FAKE_CODE_ENABLED=1 "$DUO" --envs-file="$ENVS" deploy unit "$bad" >/dev/null 2>&1; then
    fail "deploy accepted $bad"
  fi
  [ "$(calls)" = 0 ] || fail "deploy contacted target after rejecting $bad"
done
pass "deploy rejects caller-owned repo/internal flags before target contact"

printf '\n✔ REGRESS_CODE_DEPLOY_UNIT PASSED\n'
