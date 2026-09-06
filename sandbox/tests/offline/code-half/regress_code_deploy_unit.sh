#!/usr/bin/env bash
# Offline contract for wprism deploy <env>: compile -> target runtime preflight -> begin target session ->
# optional code stage -> fresh lifecycle retire/activate -> optional code finalize.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
WPRISM="$REPO_ROOT/cli/wprism"
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
# db:export and checkpoint-seal are opposite ends of one pipeline and start
# concurrently. Their --exec arguments exceed an atomic append on macOS, so a
# 12-run stress probe reproduced interleaved records. Serialize the complete
# records; assertions still inspect the real control-plane command bytes.
log_lock="${FAKE_WP_LOG}.lock"
while ! mkdir "$log_lock" 2>/dev/null; do
  sleep 0.01
done
trap 'rmdir "$log_lock" 2>/dev/null || true' EXIT HUP INT TERM
printf '%s\n' "$*" >> "$FAKE_WP_LOG"
rmdir "$log_lock"
trap - EXIT HUP INT TERM
while true; do
  case "${1:-}" in
    --path=*|--exec=*|--skip-plugins|--skip-themes) shift ;;
    *) break ;;
  esac
done
first=$1
second=$2
if [ "$first:$second" = wprism:compile ]; then
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
if [ "$first:$second" = wprism:code-preflight ]; then
  if [ "$FAKE_PREFLIGHT_FAIL" = 1 ]; then
    printf '%s\n' '{"format":"wprism-command-refusal/v1","ok":false,"command":"code-preflight","error":"code_compilation_failed","diagnostics":[{"code":"code_source_requires_php_incompatible","path":"plugins/inactive/inactive.php","required_version":"99.0","target_version":"8.3.0"}]}'
    exit 14
  fi
  printf '%s\n' '{"format":"wprism-code-runtime/v1","enabled":true,"change_required":true,"compatible":true,"code_revision":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","target":{"php":"8.3.0","wordpress":"6.8.2","source":"target-control-plane"},"requirements":[],"diagnostics":[]}'
  exit 0
fi
if [ "$first:$second" = wprism:lifecycle-status ]; then
  if [ "${FAKE_BASELINE_DRIFT:-0}" = 1 ]; then
    printf '%s\n' '{"format":"wprism-lifecycle-status/v2","required":false,"reasons":[],"baseline_state":"drift","code_drift":[{"issue":"code_drift","kind":"plugin","plugin":"fixture/fixture.php","installed_version":"2.0.0","recorded_version":"1.0.0","message":"fixture/fixture.php changed from 1.0.0 to 2.0.0 outside WPrism"}],"code_boundary_sha256":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","findings_sha256":"cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc","observation_sha256":"eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee","warnings":[]}'
  else
    printf '%s\n' '{"format":"wprism-lifecycle-status/v2","required":false,"reasons":[],"baseline_state":"exact","code_drift":[],"code_boundary_sha256":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","findings_sha256":"cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc","observation_sha256":"eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee","warnings":[]}'
  fi
  exit 0
fi
if [ "$first:$second" = wprism:code-baseline-accept ]; then
  [[ " $* " == *" --force-code-drift "* ]] || exit 24
  operation_id=''
  artifact_hash=''
  observation_sha256=''
  for arg in "$@"; do
    case "$arg" in
      --operation-id=*) operation_id="${arg#--operation-id=}" ;;
      --artifact-hash=*) artifact_hash="${arg#--artifact-hash=}" ;;
      --expected-observation-sha256=*) observation_sha256="${arg#--expected-observation-sha256=}" ;;
    esac
  done
  printf '{"format":"wprism-code-baseline-acceptance/v2","operation_id":"%s","artifact_hash":"%s","observation_sha256":"%s","outcome":"accepted","replayed":false,"before_baseline_sha256":"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd","baseline_sha256":"ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff","code_drift":[{"issue":"code_drift","kind":"plugin","plugin":"fixture/fixture.php","installed_version":"2.0.0","recorded_version":"1.0.0","message":"fixture/fixture.php changed from 1.0.0 to 2.0.0 outside WPrism"}]}\n' "$operation_id" "$artifact_hash" "$observation_sha256"
  exit 0
fi
if [ "$first:$second" = wprism:checkpoint-target ]; then
  printf '%s\n' '{"database_target_sha256":"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd","format":"wprism-database-target/v1"}'
  exit 0
fi
if [ "$first:$second" = db:export ]; then
  # `wp db export <path> --porcelain`, the one primitive both promote
  # (cli/wprism:2387) and deploy use. Write the file as well as report the call, so
  # the suite can assert the checkpoint LANDED at the path deploy chose and
  # shares a stem with the artifact — not merely that an export was attempted.
  [ "${FAKE_EXPORT_FAIL:-0}" = 1 ] && exit 23
  if [ "$3" = - ]; then
    printf -- '-- fixture dump\n'
  else
    printf -- '-- fixture dump\n' > "$3"
    printf '%s\n' "$3"
  fi
  exit 0
fi
if [ "$first:$second" = wprism:checkpoint-seal ]; then
  out=""
  database_target=""
  for arg in "$@"; do
    case "$arg" in
      --output=*) out="${arg#--output=}" ;;
      --database-target-sha256=*) database_target="${arg#--database-target-sha256=}" ;;
    esac
  done
  [ -n "$out" ] || exit 2
  [ "$database_target" = dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd ] || exit 3
  cat > "$out"
  exit 0
fi
if [ "$first:$second" = wprism:promotion-begin ]; then exit 0; fi
if [ "$first:$second" = wprism:promotion-abort ]; then exit 0; fi
if [ "$first:$second" = wprism:code-stage ]; then [ "$FAKE_STAGE_FAIL" = 1 ] && exit 8; exit 0; fi
if [ "$first:$second" = wprism:deploy ]; then
  if [[ "$*" == *"--lifecycle-phase=retire"* && "$FAKE_RETIRE_FAIL" = 1 ]]; then exit 7; fi
  if [[ "$*" == *"--lifecycle-phase=activate"* && "$FAKE_ACTIVATE_FAIL" = 1 ]]; then exit 13; fi
  exit 0
fi
if [ "$first:$second" = wprism:lifecycle-settle ]; then [ "${FAKE_SETTLE_FAIL:-0}" = 1 ] && exit 15; exit 0; fi
if [ "$first:$second" = wprism:code-finalize ]; then [ "$FAKE_FINALIZE_FAIL" = 1 ] && exit 9; exit 0; fi
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
    && "$call" == *"WPRISM_CONTROL_PLANE"* \
    && "$call" == *"WPRISM_CONTROL_WPMU_PLUGIN_DIR"* \
    && "$call" == *"after_wp_config_load"* \
    && "$call" == *"SUNRISE"* \
    && "$call" == *"--skip-plugins"* \
    && "$call" == *"--skip-themes"* ]] \
    || fail "$label did not use the isolated WPrism control-plane bootstrap"
}
assert_runtime_call() {
  local call="$1" label="$2"
  [[ "$call" != *"--exec="* \
    && "$call" != *"--skip-plugins"* \
    && "$call" != *"--skip-themes"* ]] \
    || fail "$label incorrectly skipped the WordPress runtime it must reconcile"
}
# Extra public deploy flags for the next invoke(); reset by invoke() itself so
# one --no-checkpoint case cannot silently leak into the runs after it.
DEPLOY_EXTRA=""
DEPLOY_FORCES="--force-code-mismatch --force-code-drift"
invoke() {
  mode=$1
  shift
  : > "$LOG"
  if OUT="$(FAKE_CODE_ENABLED="$mode" FAKE_COMPILE_FAIL=0 FAKE_PREFLIGHT_FAIL=0 FAKE_STAGE_FAIL=0 FAKE_RETIRE_FAIL=0 FAKE_ACTIVATE_FAIL=0 FAKE_SETTLE_FAIL=0 FAKE_FINALIZE_FAIL=0 FAKE_EXPORT_FAIL=0 "$@" "$WPRISM" --envs-file="$ENVS" deploy unit $DEPLOY_FORCES $DEPLOY_EXTRA 2>&1)"; then
    CODE=0
  else
    CODE=$?
  fi
  DEPLOY_EXTRA=""
  DEPLOY_FORCES="--force-code-mismatch --force-code-drift"
}

# --exec itself runs before wp-config.php. Prove the control bootstrap defers
# its layout decision until after the config constants exist, and refuses a
# custom MU root before it can even require the protected agent (let alone run
# code-stage against the inert standard directory).
WPRISM_ROOT="$REPO_ROOT" CONTROL_WP_ROOT="$SITE/control-wp" php <<'PHP'
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
@mkdir($root . '/wp-content/mu-plugins/wprism', 0777, true);
$loaded = $root . '/agent-loaded';
file_put_contents(
    $root . '/wp-content/mu-plugins/wprism/wprism.php',
    '<?php file_put_contents(' . var_export($loaded, true) . ', "loaded");'
);
require getenv('WPRISM_ROOT') . '/cli/src/Transport/CodeDeploy.php';
$args = \WPrism\Orchestrator\CodeDeploy::controlArgs(['wprism', 'code-stage']);
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
WPRISM_ROOT="$REPO_ROOT" CONTROL_WP_ROOT="$SITE/control-wp-cron" php <<'PHP'
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
@mkdir($root . '/wp-content/mu-plugins/wprism', 0777, true);
file_put_contents($root . '/wp-content/mu-plugins/wprism/wprism.php', '<?php // fixture agent');
require getenv('WPRISM_ROOT') . '/cli/src/Transport/CodeDeploy.php';
$exec = null;
foreach (\WPrism\Orchestrator\CodeDeploy::controlArgs(['wprism', 'refresh-export']) as $arg) {
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
if (!defined('WPRISM_CONTROL_PLANE')) {
    throw new RuntimeException('FAIL: the accepted bootstrap did not mark the control plane');
}
PHP
pass "control bootstrap disables the target's cron spawn for the whole control-plane window"

# Provider debt is published by an eval program after controlArgs() has loaded
# the protected agent. Adoption deliberately retains agent-namespace
# identity/cipher copies for agent-free recovery; executing the real generated
# program proves that this agent-loaded path does not require those copies and
# fatal on duplicate class declarations before publishing the intent.
WPRISM_ROOT="$REPO_ROOT" CONTROL_WP_ROOT="$SITE/control-wp-provider" PROVIDER_REPO="$SITE/provider-publisher" php <<'PHP'
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

$source = getenv('WPRISM_ROOT');
$wpRoot = getenv('CONTROL_WP_ROOT');
$repo = getenv('PROVIDER_REPO');
foreach ([
    $wpRoot . '/wp-content/mu-plugins/wprism',
    $repo . '/.wprism/artifacts',
    $repo . '/.wprism/checkpoints',
    $repo . '/.wprism/control/recovery-runtime',
] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
        throw new RuntimeException("FAIL: could not create fixture directory '$directory'");
    }
}
$resolvedRepo = realpath($repo);
if ($resolvedRepo === false) {
    throw new RuntimeException('FAIL: could not resolve provider publisher fixture repository');
}
$repo = $resolvedRepo;
$agent = $wpRoot . '/wp-content/mu-plugins/wprism/wprism.php';
file_put_contents($agent, <<<'AGENT'
<?php
namespace WPrism;

final class DatabaseTargetIdentity {
    public static int $assertions = 0;
    public static function assertWordPressConfig(string $expected): void {
        if ($expected !== str_repeat('d', 64)) {
            throw new \RuntimeException('fixture database target changed');
        }
        self::$assertions++;
    }
}

final class RetainedCheckpointCipher {
    public static int $verifications = 0;
    /** @return array{cipher_sha256:string,database_target_sha256:string,format:string} */
    public static function verify(string $repo, string $checkpoint): array {
        self::$verifications++;
        return [
            'cipher_sha256' => str_repeat('c', 64),
            'database_target_sha256' => str_repeat('d', 64),
            'format' => 'wprism-retained-checkpoint-verification/v2',
        ];
    }
}

final class PromotionLock {
    public static int $fences = 0;
    public static function with_existing_lease_fence(
        string $owner,
        string $artifactHash,
        string $phase,
        callable $callback
    ): mixed {
        if ($owner !== 'publisher-owner'
            || $artifactHash !== str_repeat('a', 64)
            || $phase !== 'provider-settlement-publish') {
            throw new \RuntimeException('fixture lease identity changed');
        }
        self::$fences++;
        return $callback();
    }
}
AGENT
);

$runtime = $repo . '/.wprism/control/recovery-runtime';
foreach (['CanonicalJson.php', 'AtomicStore.php', 'ProtocolLock.php', 'ProviderSettlementIntent.php'] as $file) {
    if (!copy($source . '/recovery/' . $file, $runtime . '/' . $file)) {
        throw new RuntimeException("FAIL: could not stage recovery runtime '$file'");
    }
}
// These two files are present on every adopted target for agent-free rollback.
// Requiring them after the fake protected agent recreates the production fatal.
foreach (['DatabaseTargetIdentity.php', 'RetainedCheckpointCipher.php'] as $file) {
    if (!copy($source . '/agent/src/Recovery/' . $file, $runtime . '/' . $file)) {
        throw new RuntimeException("FAIL: could not stage agent-free runtime '$file'");
    }
}
$artifact = $repo . '/.wprism/artifacts/deploy-publisher-owner.json';
$checkpoint = $repo . '/.wprism/checkpoints/deploy-publisher-owner.sql.enc';
file_put_contents($artifact, "{}\n");
file_put_contents($checkpoint, "ciphertext\n");
chmod($artifact, 0600);
chmod($checkpoint, 0600);

require $source . '/cli/src/Transport/CodeDeploy.php';
$args = \WPrism\Orchestrator\CodeDeploy::providerSettlementBeginArgs(
    $repo,
    $artifact,
    $checkpoint,
    'publisher-owner',
    str_repeat('a', 64),
    ['lifecycle-settle']
);
$bootstrap = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--exec=')) {
        $bootstrap = substr($arg, strlen('--exec='));
        break;
    }
}
$evalIndex = array_search('eval', $args, true);
$program = is_int($evalIndex) ? ($args[$evalIndex + 1] ?? null) : null;
if (!is_string($bootstrap) || !is_string($program)) {
    throw new RuntimeException('FAIL: provider publisher omitted its executable control program');
}
eval($bootstrap);
foreach (WP_CLI::$hooks['after_wp_config_load'] ?? [] as $hook) {
    $hook();
}
ob_start();
eval($program);
$output = ob_get_clean();
$summary = json_decode((string) $output, true, 8, JSON_THROW_ON_ERROR);
if (($summary['format'] ?? null) !== 'wprism-provider-settlement-intent/v1'
    || ($summary['phases'] ?? null) !== ['lifecycle-settle']
    || ($summary['resumed'] ?? null) !== false
    || ($summary['cipher_sha256'] ?? null) !== str_repeat('c', 64)) {
    throw new RuntimeException('FAIL: executed provider publisher returned a malformed intent summary');
}
if (\WPrism\PromotionLock::$fences !== 1
    || \WPrism\RetainedCheckpointCipher::$verifications !== 1
    || \WPrism\DatabaseTargetIdentity::$assertions !== 1) {
    throw new RuntimeException('FAIL: provider publisher bypassed its protected-agent fence or checkpoint checks');
}
$agentPath = realpath($agent);
foreach ([\WPrism\DatabaseTargetIdentity::class, \WPrism\RetainedCheckpointCipher::class] as $class) {
    if ((new ReflectionClass($class))->getFileName() !== $agentPath) {
        throw new RuntimeException("FAIL: provider publisher replaced protected-agent class '$class'");
    }
}
if (!is_file($repo . '/.wprism/control/provider-settlement-intent.json')) {
    throw new RuntimeException('FAIL: executed provider publisher did not durably publish its intent');
}
PHP
pass "provider publisher executes after protected-agent bootstrap without loading duplicate recovery classes"

# No descriptor means a code-only deploy has no mutation to perform. It must
# not manufacture activation/deactivation side effects (agency audit #77), so
# it exits after the read-only lifecycle status without a lease, checkpoint,
# or lifecycle mutation flags.
invoke 0 env
[ "$CODE" -eq 0 ] || fail "legacy deploy failed: $OUT"
[ "$(calls)" = 2 ] || fail "descriptor-free path expected compile plus lifecycle status"
ONE="$(line 1)"
[[ "$ONE" == *"wprism compile"* ]] || fail "descriptor-free call was not compile"
[[ "$(line 2)" == *"wprism lifecycle-status"* ]] || fail "descriptor-free path omitted lifecycle status"
assert_control_call "$ONE" "legacy compile"
assert_control_call "$(line 2)" "legacy lifecycle status"
grep -q 'deploy complete: no code descriptor; lifecycle hooks not run' <<<"$OUT" || fail "descriptor-free completion missing"
grep -q 'database checkpoint retained: ' <<<"$OUT" && fail "descriptor-free no-op retained an invented checkpoint"
pass "descriptor-free deploy is a disclosed lifecycle-hook-free no-op"

# Descriptor-free does not mean work-free when the target reports an
# unaccepted installed-version baseline. The v2 preflight refuses before any
# mutation without consent, then selects one isolated baseline phase with it.
DEPLOY_FORCES="--force-code-mismatch"
invoke 0 env FAKE_BASELINE_DRIFT=1
[ "$CODE" -ne 0 ] || fail "descriptor-free drift unexpectedly succeeded without consent: $OUT"
[ "$(calls)" = 2 ] || fail "unforced descriptor-free drift crossed the read-only preflight"
grep -q 'code_drift' <<<"$OUT" || fail "unforced descriptor-free drift omitted its reason"
grep -q -- '--force-code-drift' <<<"$OUT" || fail "unforced descriptor-free drift omitted its remedy"
pass "descriptor-free drift refuses before target mutation without explicit consent"

invoke 0 env FAKE_BASELINE_DRIFT=1
[ "$CODE" -eq 0 ] || fail "descriptor-free baseline acceptance failed: $OUT"
[ "$(calls)" = 3 ] || fail "baseline-only path expected compile, status and acceptance"
ONE="$(line 1)"
TWO="$(line 2)"
THREE="$(line 3)"
[[ "$ONE" == *"wprism compile"* \
  && "$TWO" == *"wprism lifecycle-status"* \
  && "$THREE" == *"wprism code-baseline-accept"* ]] \
  || fail "baseline-only phase order is wrong"
assert_control_call "$ONE" "baseline-only compile"
assert_control_call "$TWO" "baseline-only status"
assert_control_call "$THREE" "baseline-only acceptance"
[[ "$THREE" == *"--force-code-drift"* ]] || fail "baseline acceptance dropped explicit drift consent"
grep -q '^deploy phase: code-baseline-accept$' <<<"$OUT" \
  || fail "baseline-only deploy did not disclose its selected phase"
[ "$(grep -c 'FORCED past code_drift' <<<"$OUT")" = 1 ] \
  || fail "baseline-only deploy did not report exactly one forced finding: $OUT"
grep -q 'deploy complete: code-baseline-accept; no code descriptor' <<<"$OUT" \
  || fail "baseline-only deploy returned the wrong terminal result"
if grep -Eq 'promotion-begin|checkpoint|lifecycle-retire|lifecycle-activate|schema-settle|lifecycle-settle' <<<"$OUT"; then
  fail "baseline-only deploy invented lifecycle/provider/recovery work: $OUT"
fi
pass "descriptor-free forced drift uses one isolated, checkpoint-free baseline phase"

# Descriptor turns on exactly stage, lifecycle, settlement, and finalize. All use one artifact
# and owner; only lifecycle gets hold/materializing-code. The DB checkpoint sits
# under the lease between promotion-begin and code-stage — before it the dump
# would carry no lease row for `wprism recover`'s four steps to re-take
# (cli/wprism:3289-3297), after it the dump would already describe mutated code.
invoke 1 env
[ "$CODE" -eq 0 ] || fail "code deploy failed: $OUT"
[ "$(calls)" = 11 ] || fail "code path expected eleven wp calls"
ONE="$(line 1)"
TWO="$(line 2)"
THREE="$(line 3)"
TARGET="$(line 4)"
PIPE_A="$(line 5)"
PIPE_B="$(line 6)"
if [[ "$PIPE_A" == *"db export -"* && "$PIPE_B" == *"wprism checkpoint-seal"* ]]; then
  EXPORT="$PIPE_A"
  CKPT="$PIPE_B"
elif [[ "$PIPE_B" == *"db export -"* && "$PIPE_A" == *"wprism checkpoint-seal"* ]]; then
  EXPORT="$PIPE_B"
  CKPT="$PIPE_A"
else
  fail "checkpoint pipeline calls were missing or escaped the pre-stage boundary"
fi
FOUR="$(line 7)"
FIVE="$(line 8)"
SIX="$(line 9)"
SEVEN="$(line 10)"
EIGHT="$(line 11)"
[[ "$ONE" == *"wprism compile"* && "$TWO" == *"wprism code-preflight"* \
  && "$THREE" == *"wprism promotion-begin"* && "$TARGET" == *"wprism checkpoint-target"* \
  && "$EXPORT" == *"db export -"* \
  && "$CKPT" == *"wprism checkpoint-seal"* \
  && "$FOUR" == *"wprism code-stage"* \
  && "$FIVE" == *"wprism deploy"*"--lifecycle-phase=retire"* \
  && "$SIX" == *"wprism deploy"*"--lifecycle-phase=activate"* \
  && "$SEVEN" == *"wprism lifecycle-settle"* \
  && "$EIGHT" == *"wprism code-finalize"* ]] || fail "code phase order wrong"
assert_control_call "$ONE" "code compile"
assert_control_call "$TWO" "code target-runtime preflight"
assert_control_call "$THREE" "code promotion-begin"
assert_control_call "$TARGET" "code database-target preflight"
assert_control_call "$CKPT" "code checkpoint seal"
[[ "$EXPORT" == *"DatabaseTargetIdentity::fromWordPressConfig"* \
  && "$EXPORT" == *"require_recovery_intent"* \
  && "$EXPORT" == *"--skip-plugins"* && "$EXPORT" == *"--skip-themes"* ]] \
  || fail "code checkpoint export did not recheck its preflight database target in isolation"
assert_control_call "$FOUR" "code stage"
assert_runtime_call "$FIVE" "code retirement"
assert_runtime_call "$SIX" "code activation"
assert_runtime_call "$SEVEN" "lifecycle settlement"
assert_control_call "$EIGHT" "code finalize"
A="$(arg "$FOUR" '--compiled=[^ ]*')"
O="$(arg "$FOUR" '--promotion-owner=[^ ]*')"
H="$(arg "$FOUR" '--artifact-hash=[^ ]*')"
[ -n "$A" ] && [ -n "$O" ] || fail "stage lacks artifact/owner"
[ "$(arg "$TWO" '--compiled=[^ ]*')" = "$A" ] || fail "preflight did not inspect the frozen stage artifact"
[ "$(arg "$TWO" '--artifact-hash=[^ ]*')" = "$H" ] || fail "preflight did not bind the host-observed artifact hash"
[ "$(arg "$FIVE" '--compiled=[^ ]*')" = "$A" ] && [ "$(arg "$SIX" '--compiled=[^ ]*')" = "$A" ] && [ "$(arg "$SEVEN" '--compiled=[^ ]*')" = "$A" ] && [ "$(arg "$EIGHT" '--compiled=[^ ]*')" = "$A" ] || fail "artifact changed between code phases"
[ "$(arg "$THREE" '--promotion-owner=[^ ]*')" = "$O" ] && [ "$(arg "$FIVE" '--promotion-owner=[^ ]*')" = "$O" ] && [ "$(arg "$SIX" '--promotion-owner=[^ ]*')" = "$O" ] && [ "$(arg "$SEVEN" '--promotion-owner=[^ ]*')" = "$O" ] && [ "$(arg "$EIGHT" '--promotion-owner=[^ ]*')" = "$O" ] || fail "owner changed between code phases"
[ "$(arg "$THREE" '--artifact-hash=[^ ]*')" = "$H" ] && [ "$(arg "$FIVE" '--artifact-hash=[^ ]*')" = "$H" ] && [ "$(arg "$SIX" '--artifact-hash=[^ ]*')" = "$H" ] && [ "$(arg "$SEVEN" '--artifact-hash=[^ ]*')" = "$H" ] && [ "$(arg "$EIGHT" '--artifact-hash=[^ ]*')" = "$H" ] || fail "expected artifact hash changed between phases"
[[ "$FOUR" != *"--promotion-hold"* && "$FOUR" != *"--materializing-code"* ]] || fail "stage got lifecycle-only flags"
[[ "$FIVE" == *"--promotion-hold"* && "$FIVE" == *"--materializing-code"* && "$FIVE" != *"--state-handoff"* ]] \
  || fail "standalone retirement flags crossed the promotion-only handoff boundary"
[[ "$SIX" == *"--promotion-hold"* && "$SIX" == *"--materializing-code"* && "$SIX" != *"--state-handoff"* ]] \
  || fail "standalone activation did not retain the code-finalize continuation"
[[ "$SEVEN" != *"--promotion-hold"* && "$SEVEN" != *"--materializing-code"* ]] || fail "standalone settlement received lifecycle materialization flags"
[[ "$EIGHT" != *"--promotion-hold"* && "$EIGHT" != *"--materializing-code"* ]] || fail "standalone finalize retained lifecycle flags"
ART="$(printf '%s' "$A" | sed 's/^--compiled=//')"
[[ "$ART" == "$SITE/.wprism/artifacts/deploy-"*.json ]] || fail "artifact outside target .wprism/artifacts"
[ -f "$ART" ] || fail "artifact not retained"
grep -q 'deploy complete: code-stage -> lifecycle-retire -> lifecycle-activate -> lifecycle-settle -> code-finalize' <<<"$OUT" || fail "code completion missing"

# The checkpoint's file name is what makes it recoverable: RetainedCheckpoints
# reads the lease identity out of the SIBLING artifacts/<same-stem>.json, so a
# checkpoint whose stem differs from the artifact's lists with an empty
# artifact_hash and then refuses checkpoint_identity_unknown at --restore time.
CKPT_PATH="$(printf '%s' "$CKPT" | tr ' ' '\n' | grep -- "$SITE/.wprism/checkpoints/" || true)"
CKPT_PATH="${CKPT_PATH#--output=}"
[[ "$CKPT_PATH" == "$SITE/.wprism/checkpoints/deploy-"*.sql.enc ]] \
  || fail "checkpoint outside target .wprism/checkpoints, or not named deploy-<owner>.sql.enc: $CKPT_PATH"
[ -s "$CKPT_PATH" ] || fail "checkpoint not retained on the target"
[ "$(basename "$CKPT_PATH" .sql.enc)" = "$(basename "$ART" .json)" ] \
  || fail "checkpoint and artifact do not share a stem, so the lease identity cannot be read back"
grep -q "database checkpoint retained: $CKPT_PATH" <<<"$OUT" \
  || fail "the retained checkpoint line did not accompany the completion line"
pass "code deploy stages/lifecycle-deploys/finalizes one frozen artifact, checkpointed under its lease"

# --no-checkpoint is the pre-change world, byte for byte: today's call counts,
# today's phase order and today's stdout.
DEPLOY_EXTRA="--no-checkpoint"
invoke 1 env
[ "$CODE" -eq 0 ] || fail "--no-checkpoint deploy failed: $OUT"
[ "$(calls)" = 8 ] || fail "--no-checkpoint path expected eight wp calls"
[[ "$(line 1)" == *"wprism compile"* && "$(line 2)" == *"wprism code-preflight"* \
  && "$(line 3)" == *"wprism promotion-begin"* && "$(line 4)" == *"wprism code-stage"* \
  && "$(line 5)" == *"wprism deploy"*"--lifecycle-phase=retire"* \
  && "$(line 6)" == *"wprism deploy"*"--lifecycle-phase=activate"* \
  && "$(line 7)" == *"wprism lifecycle-settle"* \
  && "$(line 8)" == *"wprism code-finalize"* ]] || fail "--no-checkpoint phase order wrong"
grep -q 'db export' "$LOG" && fail "--no-checkpoint still exported the database"
EXPECTED_NO_CKPT="deploy phase: compile
deploy phase: code-preflight
deploy phase: promotion-begin
deploy phase: code-stage
deploy phase: lifecycle-retire
deploy phase: lifecycle-activate
deploy phase: lifecycle-settle
deploy phase: code-finalize
deploy complete: code-stage -> lifecycle-retire -> lifecycle-activate -> lifecycle-settle -> code-finalize"
[ "$OUT" = "$EXPECTED_NO_CKPT" ] || fail "--no-checkpoint output moved:
$OUT"
DEPLOY_EXTRA="--no-checkpoint"
invoke 0 env
[ "$(calls)" = 2 ] || fail "--no-checkpoint descriptor-free path expected compile plus lifecycle status"
grep -q 'db export' "$LOG" && fail "--no-checkpoint legacy path still exported the database"
pass "--no-checkpoint reproduces the pre-change call sequence and output"

# A failed export aborts its lease and starts no code or lifecycle phase.
invoke 1 env FAKE_EXPORT_FAIL=1
[ "$CODE" -eq 23 ] || fail "checkpoint export exit not propagated"
[ "$(calls)" = 7 ] || fail "code/lifecycle ran after a failed checkpoint"
[[ "$(line 4)" == *"wprism checkpoint-target"* ]] \
  || fail "failed checkpoint skipped its database-target preflight"
FAIL_PIPE_A="$(line 5)"
FAIL_PIPE_B="$(line 6)"
if ! { [[ "$FAIL_PIPE_A" == *"db export"* && "$FAIL_PIPE_B" == *"wprism checkpoint-seal"* ]] \
  || [[ "$FAIL_PIPE_B" == *"db export"* && "$FAIL_PIPE_A" == *"wprism checkpoint-seal"* ]]; } \
  || [[ "$(line 7)" != *"wprism promotion-abort"* ]]; then
  fail "a failed checkpoint did not clean the begun session"
fi
assert_control_call "$(line 7)" "checkpoint-failure promotion-abort"
grep -q 'database checkpoint failed; code and lifecycle phases were not started' <<<"$OUT" \
  || fail "the checkpoint failure did not name its boundary"
grep -q 'no usable checkpoint was produced' <<<"$OUT" \
  || fail "a confirmed cleanup did not say there is no usable checkpoint"
pass "a failed checkpoint aborts the lease before any code or lifecycle mutation"

# Stop-on-first-failure boundaries.
invoke 1 env FAKE_STAGE_FAIL=1
[ "$CODE" -eq 8 ] || fail "stage exit not propagated"
[ "$(calls)" = 8 ] || fail "later phases or cleanup were wrong after stage failure"
[[ "$(line 8)" == *"wprism promotion-abort"* ]] || fail "stage failure did not clean begun session"
assert_control_call "$(line 8)" "stage-failure promotion-abort"
grep -q 'code-stage failed.*were not run' <<<"$OUT" || fail "stage stop wording missing"
# A checkpoint an operator is never told how to use is not a recovery story.
# Since issue #3525 that story is ONE verb, not four numbered `wp` instructions:
# print_promotion_recovery() (cli/wprism:3317-3394) now names `wprism recover <env>
# --restore=<id> --writers-excluded --operator-directed`, which drives exactly
# the four steps it used to print (RecoverCommand::ORDERED_STEPS,
# cli/src/Command/RecoverCommand.php:114). Deploy shares that one chokepoint
# with `$verb` substituted, so this asserts the deploy-attributed remedy and
# that the retired raw import is absent — the same property
# regress_mup_leak_audit.sh part (c) gates for the guides.
grep -q 'promotion lease cleanup confirmed' <<<"$OUT" || fail "the abort result was discarded"
grep -q 'this checkpoint contains its temporary promotion lease row' <<<"$OUT" \
  || fail "a post-checkpoint failure printed no recovery guidance"
grep -qE '^wprism: deploy: once that exclusion is in place, recover with: wprism recover unit --restore=deploy-[A-Za-z0-9._-]+ --writers-excluded --operator-directed$' <<<"$OUT" \
  || fail "the deploy-attributed wprism recover remedy is missing: $OUT"
grep -q 'releases the lease row the import reinstates, including when the import' <<<"$OUT" \
  || fail "the recovery guidance dropped the mandatory-final-abort safety fact"
if grep -Fq 'wp db import' <<<"$OUT"; then
  fail "deploy still publishes the retired raw database import recipe"
fi
grep -q 'wprism: deploy: code may be staged or partially finalized' <<<"$OUT" \
  || fail "a stage failure did not announce the code-first ordering"
pass "stage failure stops lifecycle/finalize and guides recovery of its own checkpoint"

invoke 1 env FAKE_RETIRE_FAIL=1
[ "$CODE" -eq 7 ] || fail "lifecycle exit not propagated"
[ "$(calls)" = 9 ] || fail "finalize/cleanup calls wrong after lifecycle failure"
[[ "$(line 9)" == *"wprism promotion-abort"* ]] || fail "lifecycle failure did not clean begun session"
assert_control_call "$(line 9)" "retirement-failure promotion-abort"
pass "retirement failure stops activation/finalize"

invoke 1 env FAKE_ACTIVATE_FAIL=1
[ "$CODE" -eq 13 ] || fail "activation exit not propagated"
[ "$(calls)" = 10 ] || fail "finalize/cleanup calls wrong after activation failure"
[[ "$(line 10)" == *"wprism promotion-abort"* ]] || fail "activation failure did not clean begun session"
assert_control_call "$(line 10)" "activation-failure promotion-abort"
pass "activation failure stops finalize"

invoke 1 env FAKE_SETTLE_FAIL=1
[ "$CODE" -eq 15 ] || fail "settlement exit not propagated"
[ "$(calls)" = 11 ] || fail "finalize/cleanup calls wrong after settlement failure"
[[ "$(line 10)" == *"wprism lifecycle-settle"* && "$(line 11)" == *"wprism promotion-abort"* ]] \
  || fail "settlement failure did not stop finalize and clean begun session"
assert_control_call "$(line 11)" "settlement-failure promotion-abort"
pass "lifecycle settlement failure stops finalize"

invoke 1 env FAKE_FINALIZE_FAIL=1
[ "$CODE" -eq 9 ] || fail "finalize exit not propagated"
[ "$(calls)" = 12 ] || fail "wrong calls after finalize failure"
[[ "$(line 12)" == *"wprism promotion-abort"* ]] || fail "finalize failure did not clean begun session"
assert_control_call "$(line 12)" "finalize-failure promotion-abort"
pass "finalize failure is non-successful"

invoke 1 env FAKE_COMPILE_FAIL=1
[ "$CODE" -eq 6 ] || fail "compile exit not propagated"
[ "$(calls)" = 1 ] || fail "code/lifecycle ran after compile failure"
grep -q 'no lifecycle or code materialization occurred' <<<"$OUT" || fail "compile boundary missing"
pass "compile failure causes no code/lifecycle call"

invoke 1 env FAKE_PREFLIGHT_FAIL=1
[ "$CODE" -eq 14 ] || fail "target-runtime preflight exit not propagated"
[ "$(calls)" = 2 ] || fail "begin/stage/lifecycle ran after target-runtime preflight failure"
[[ "$(line 1)" == *"wprism compile"* && "$(line 2)" == *"wprism code-preflight"* ]] \
  || fail "target-runtime refusal did not stop at compile -> preflight"
assert_control_call "$(line 2)" "failed code target-runtime preflight"
grep -q 'code_source_requires_php_incompatible' <<<"$OUT" || fail "target-runtime refusal lost its structured diagnostic"
grep -q 'refusing before promotion-begin' <<<"$OUT" || fail "target-runtime refusal did not name its no-lease boundary"
if grep -q 'wprism promotion-abort' "$LOG"; then
  fail "pre-begin target-runtime refusal attempted lease cleanup"
fi
pass "target-runtime incompatibility refuses before begin with no cleanup fiction"

# Public deploy owns its repo/artifact/lease flags and exposes only the two
# force flags. A second --repo would otherwise make the lifecycle command's
# target differ from the immutable artifact's source repo.
for bad in --repo=/tmp/forged --compiled=/tmp/fake.json --artifact-hash=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa --promotion-owner=intruder --promotion-hold --materializing-code --state-handoff --lifecycle-phase=activate --force-unresolved-refs --with-deletes --no-checkpoint=false; do
  : > "$LOG"
  if FAKE_CODE_ENABLED=1 "$WPRISM" --envs-file="$ENVS" deploy unit "$bad" >/dev/null 2>&1; then
    fail "deploy accepted $bad"
  fi
  [ "$(calls)" = 0 ] || fail "deploy contacted target after rejecting $bad"
done
pass "deploy rejects caller-owned repo/internal flags before target contact"

printf '\n✔ REGRESS_CODE_DEPLOY_UNIT PASSED\n'
