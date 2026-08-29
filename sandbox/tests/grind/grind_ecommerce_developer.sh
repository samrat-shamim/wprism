#!/usr/bin/env bash
# Ecommerce developer grind — clean-room, two-sided pair with HTTP probes.
#
# This scenario uses WooCommerce 11.0.0 from the shared digest-checked
# artifact cache, seeds a synthetic catalog on pair side 1, then materializes
# that state and a vendored in-house extension/theme onto side 2 through
# public WPrism operations. Synthetic runtime customer/order/event rows are
# deliberately authored on each side only to prove they remain site-local;
# production data and secrets are never used.
#
# The custom extension's v1 -> v2 migration is real: v1 stores a scalar
# setting and a two-column runtime table; v2 converts the setting to an
# object and adds a context column. The broken v2 attempt is deliberately
# made inactive -> active so CodeDeploy's lifecycle activation hook is
# exercised even though its control bootstrap skips ordinary plugins.
set -euo pipefail
cd "$(dirname "$0")/../.."

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || fail "required command is missing: $1"; }
require id
require mktemp

REPO_ROOT="$(cd .. && pwd)"
# shellcheck source=../../bin/artifact-library.sh
. "$REPO_ROOT/sandbox/bin/artifact-library.sh"
WPRISM="$REPO_ROOT/cli/wprism"
CODE_DEPLOY="$REPO_ROOT/cli/src/Transport/CodeDeploy.php"
FIXTURE="$REPO_ROOT/sandbox/fixtures/wprism-ecommerce-developer-grind"
PAIR="${ECOMMERCE_PAIR:-ecomgrind${BASHPID}${RANDOM}}"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] \
  || fail "pair name '$PAIR' invalid — lowercase letters/digits only, starting with a letter"
case "$PAIR" in
  db|sandbox) fail "pair name '$PAIR' is reserved" ;;
esac
PORT1="${ECOMMERCE_PORT1:-8898}"
PORT2="${ECOMMERCE_PORT2:-8899}"
ECOMMERCE_HOST_UID="$(id -u)"
ECOMMERCE_HOST_GID="$(id -g)"
SITE="siterepo/${PAIR}1"
OTHER_SITE="siterepo/${PAIR}2"
ORIGIN="siterepo/origin-${PAIR}.git"
V1_CHECKPOINT_TARGET="$OTHER_SITE/.tmp-ecommerce-v1.sql"
for pair_path in "$SITE" "$OTHER_SITE" "$ORIGIN"; do
  if [ -e "$pair_path" ] || [ -L "$pair_path" ]; then
    fail "refusing to reuse pre-existing pair path: $pair_path"
  fi
done

# The pair bind-mounts the checkout's agent, adapter packages, and platform
# library while the scenario mutates
# disposable repositories below sandbox/.  A linked worktree has a separate
# worktree git-dir but shares the primary checkout's common git-dir; starting
# a live pair from it can therefore bind a checkout that disappears while the
# pair is still running.  A dirty checkout has the same stale-source hazard:
# the pair would be testing bytes that no exact-HEAD clone can reproduce.
# Keep the invalid-name and pre-existing-root probes above first so they remain
# cheap, side-effect-free diagnostics even from a dirty agent worktree.
assert_clean_live_checkout() {
  local checkout_root git_dir common_dir common_root env_file
  local expected_agent expected_packages expected_platform
  local mounted_agent mounted_packages mounted_platform
  command -v git >/dev/null 2>&1 || fail 'required command is missing: git'
  checkout_root="$(cd "$REPO_ROOT" && pwd -P)"
  git_dir="$(git -C "$REPO_ROOT" rev-parse --path-format=absolute --git-dir 2>/dev/null)" \
    || fail "refusing live mutation: cannot resolve git-dir for checkout $checkout_root"
  common_dir="$(git -C "$REPO_ROOT" rev-parse --path-format=absolute --git-common-dir 2>/dev/null)" \
    || fail "refusing live mutation: cannot resolve git-common-dir for checkout $checkout_root"
  common_root="$(dirname "$common_dir")"
  if [ ! -d "$git_dir" ] || [ "$git_dir" != "$common_dir" ] || [ "$common_root" != "$checkout_root" ] || [ -f "$REPO_ROOT/.git" ]; then
    fail "refusing live mutation from a linked worktree or stale canonical mount; use a clean primary or standalone exact-HEAD clone"
  fi
  if [ -n "$(git -C "$REPO_ROOT" status --porcelain=v1 --untracked-files=all)" ]; then
    fail "refusing live mutation from a dirty checkout; use a clean primary or standalone exact-HEAD clone"
  fi
  expected_agent="$checkout_root/agent"
  expected_packages="$checkout_root/adapter-packages"
  expected_platform="$checkout_root/platform"
  env_file="$checkout_root/sandbox/.env"
  if [ -e "$env_file" ]; then
    mounted_agent="$(sed -n 's/^WPRISM_AGENT_SRC=//p' "$env_file" | head -1)"
    mounted_packages="$(sed -n 's/^WPRISM_ADAPTER_PACKAGES_SRC=//p' "$env_file" | head -1)"
    mounted_platform="$(sed -n 's/^WPRISM_PLATFORM_SRC=//p' "$env_file" | head -1)"
    if [ "$mounted_agent" != "$expected_agent" ] \
        || [ "$mounted_packages" != "$expected_packages" ] \
        || [ "$mounted_platform" != "$expected_platform" ]; then
      fail "refusing live mutation with stale canonical mount registry $env_file; remove it or refresh pair.sh from the clean checkout"
    fi
  fi
  [ -d "$expected_agent" ] || fail "refusing live mutation: canonical agent mount source is absent: $expected_agent"
  [ -d "$expected_packages" ] || fail "refusing live mutation: canonical adapter-package mount source is absent: $expected_packages"
  [ -d "$expected_platform" ] || fail "refusing live mutation: canonical platform mount source is absent: $expected_platform"
}
ENVS_FILE="$(mktemp "${TMPDIR:-/tmp}/wprism-ecommerce-envs.XXXXXX")"
V1_INPUTS="$(mktemp -d "${TMPDIR:-/tmp}/wprism-ecommerce-v1.XXXXXX")"
V1_DB_DUMP="$(mktemp "${TMPDIR:-/tmp}/wprism-ecommerce-db.XXXXXX")"
V1_DB_DUMP_SHA256=""
SOURCE_RUNTIME_EVENT_V2_BASELINE=""
SOURCE_RUNTIME_IDENTITY_BASELINE=""
TARGET_RUNTIME_IDENTITY_BASELINE=""
TARGET_ORDER_ITEM_IDS=""
PAIR_UP=0
PAIR_PATHS_OWNED=0
PAIR_COMPOSE=()
ROLLBACK_MAINTENANCE_HELD=0
ROLLBACK_PROMOTION_SUCCEEDED=0

WOO_SLUG="woocommerce"
WOO_VERSION="11.0.0"
WOO_DOWNGRADE_VERSION="10.9.4"
WOO_BASENAME="woocommerce/woocommerce.php"
ACF_SLUG="advanced-custom-fields"
ACF_VERSION="6.8.7"
ACF_BASENAME="advanced-custom-fields/acf.php"
EXT_SLUG="wprism-commerce-extension"
EXT_FILE="wprism-commerce-extension.php"
EXT_BASENAME="$EXT_SLUG/$EXT_FILE"
REPLACEMENT_SLUG="wprism-commerce-replacement"
REPLACEMENT_FILE="wprism-commerce-replacement.php"
REPLACEMENT_BASENAME="$REPLACEMENT_SLUG/$REPLACEMENT_FILE"
NATIVE_ACTIVE_PLUGINS_JSON="[\"$ACF_BASENAME\",\"$EXT_BASENAME\",\"$WOO_BASENAME\"]"
AUTHORED_ACTIVE_PLUGINS_JSON="[\"$WOO_BASENAME\",\"$ACF_BASENAME\",\"$EXT_BASENAME\"]"
EXTENSION_INACTIVE_ACTIVE_PLUGINS_JSON="[\"$WOO_BASENAME\",\"$ACF_BASENAME\"]"
REPLACEMENT_ACTIVE_PLUGINS_JSON="[\"$WOO_BASENAME\",\"$ACF_BASENAME\",\"$REPLACEMENT_BASENAME\"]"
PARENT_THEME="wprism-commerce-parent"
CHILD_THEME="wprism-commerce-child"
CONTENT="/var/www/html/wp-content"
EXT_TARGET="$CONTENT/plugins/$EXT_SLUG/$EXT_FILE"
REPLACEMENT_TARGET="$CONTENT/plugins/$REPLACEMENT_SLUG/$REPLACEMENT_FILE"
CHILD_TARGET="$CONTENT/themes/$CHILD_THEME"
WOO_TARGET="$CONTENT/plugins/$WOO_SLUG/woocommerce.php"
STATE="$SITE/state/options/core.json"
CODE_REVISION_KEY="code_revision"

cleanup() {
  local status=$?
  local pair_containers pair_volumes pair_networks remaining_dbs teardown_verified=1
  trap - EXIT INT TERM
  set +e
  if [ -n "${TARGET_CRON_FREEZE_FILE:-}" ] && [ -n "${TARGET_CRON_FREEZE_SHA:-}" ] && [ "$PAIR_UP" = 1 ]; then
    if ! remove_target_cron_freeze; then
      printf 'FAIL: ecommerce cron freeze cleanup failed for %s\n' "$PAIR" >&2
      status=1
      teardown_verified=0
    fi
  fi
  # Maintenance is deliberately fail-closed while an exact rollback is
  # incomplete.  Release it only after the public v1 promote completed; if
  # that promote/import failed, pair destruction removes the disposable
  # target, and an uncertain teardown keeps the pair paths diagnosable with
  # maintenance still held.
  if [ "$ROLLBACK_MAINTENANCE_HELD" = 1 ] && [ "$ROLLBACK_PROMOTION_SUCCEEDED" = 1 ] && [ "$PAIR_UP" = 1 ]; then
    if ! control_wp_command maintenance-mode deactivate >/dev/null 2>&1; then
      printf 'FAIL: ecommerce rollback maintenance release failed for %s\n' "$PAIR" >&2
      status=1
      teardown_verified=0
    else
      ROLLBACK_MAINTENANCE_HELD=0
    fi
  elif [ "$ROLLBACK_MAINTENANCE_HELD" = 1 ] && [ "$ROLLBACK_PROMOTION_SUCCEEDED" != 1 ]; then
    printf 'ecommerce rollback maintenance remains held after an incomplete recovery for %s\n' "$PAIR" >&2
  fi
  if [ "$PAIR_UP" = 1 ]; then
    # Capture/apply runs as uid 33. Normalize only this disposable repo
    # before pair.sh removes its containers.
    "${PAIR_COMPOSE[@]}" run --rm -T -u root cli1 sh -c 'chmod -R ugo+rwX /siterepo' >/dev/null 2>&1 || true
    "${PAIR_COMPOSE[@]}" run --rm -T -u root cli2 sh -c 'chmod -R ugo+rwX /siterepo' >/dev/null 2>&1 || true
    if ! bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1; then
      printf 'FAIL: ecommerce pair destroy failed for %s\n' "$PAIR" >&2
      status=1
      teardown_verified=0
    fi
    if ! pair_containers="$(docker ps -aq --filter "label=com.docker.compose.project=wprism-$PAIR" 2>/dev/null)" \
      || ! pair_volumes="$(docker volume ls -q --filter "label=com.docker.compose.project=wprism-$PAIR" 2>/dev/null)" \
      || ! pair_networks="$(docker network ls -q --filter "label=com.docker.compose.project=wprism-$PAIR" 2>/dev/null)"; then
      printf 'FAIL: ecommerce cleanup could not verify Docker resource removal for %s\n' "$PAIR" >&2
      status=1
      teardown_verified=0
    elif [ -n "$pair_containers$pair_volumes$pair_networks" ]; then
      printf 'FAIL: ecommerce cleanup left Docker resources for wprism-%s\n' "$PAIR" >&2
      status=1
      teardown_verified=0
    fi
    if ! remaining_dbs="$(docker exec -e MYSQL_PWD=root wprism-shared-db mariadb -uroot -N -B --raw -e "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME IN ('wp_${PAIR}1','wp_${PAIR}2')" 2>/dev/null)"; then
      printf 'FAIL: ecommerce cleanup could not verify database removal for %s\n' "$PAIR" >&2
      status=1
      teardown_verified=0
    elif [ -n "$remaining_dbs" ]; then
      printf 'FAIL: ecommerce cleanup left pair database(s) behind: %s\n' "$remaining_dbs" >&2
      status=1
      teardown_verified=0
    fi
  fi
  if [ "$PAIR_PATHS_OWNED" = 1 ]; then
    if [ "$teardown_verified" = 1 ]; then
      rm -rf -- "$SITE" "$OTHER_SITE" "$ORIGIN"
      if [ -e "$SITE" ] || [ -e "$OTHER_SITE" ] || [ -e "$ORIGIN" ]; then
        printf 'FAIL: ecommerce repo cleanup left a pair path behind\n' >&2
        status=1
      fi
    else
      printf 'FAIL: preserving ecommerce pair paths after uncertain teardown: %s %s %s\n' \
        "$SITE" "$OTHER_SITE" "$ORIGIN" >&2
      status=1
    fi
  fi
  rm -f -- "$ENVS_FILE"
  rm -rf -- "$V1_INPUTS"
  rm -f -- "$V1_DB_DUMP"
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# Exercise the cleanup trap without touching Docker or pair state.  This is a
# deterministic offline seam for pre-pair refusals: the flags consumed by
# cleanup must already exist, and the trap must preserve the simulated status.
if [ "${ECOMMERCE_TEST_EARLY_REFUSAL:-0}" = 1 ]; then
  fail 'simulated pre-pair refusal'
fi
assert_clean_live_checkout

assert_eq() {
  local expected="$1" actual="$2" label="$3"
  [ "$expected" = "$actual" ] || fail "$label: expected '$expected', got '$actual'"
}
assert_absent() {
  local output="$1" needle="$2" label="$3"
  ! grep -Fq "$needle" <<<"$output" || fail "$label unexpectedly included '$needle'"
}
assert_phase_order() {
  local output="$1" last=0 needle line
  shift
  for needle in "$@"; do
    line="$(grep -n -F -m1 "$needle" <<<"$output" | cut -d: -f1 || true)"
    [ -n "$line" ] || fail "output did not include phase '$needle': $output"
    [ "$line" -gt "$last" ] || fail "phase '$needle' was out of order: $output"
    last="$line"
  done
}
canonicalize_json() {
  local path="$1" tmp="${1}.canon.${BASHPID}"
  WPRISM_CANON="$REPO_ROOT/agent/src/Kernel/Canon.php" php -r '
require getenv("WPRISM_CANON");
$path = $argv[1];
$raw = file_get_contents($path);
if ($raw === false) { throw new RuntimeException("cannot read " . $path); }
echo WPrism\Canon::encode(WPrism\Canon::decode($raw));
' "$path" > "$tmp"
  mv "$tmp" "$path"
}
deploy_artifact_files() {
  [ -d "$OTHER_SITE/.wprism/artifacts" ] || return 0
  find "$OTHER_SITE/.wprism/artifacts" -type f -name 'deploy-*.json' -print | sort
}
artifact_for_new_deploy() {
  local before="$1" after added artifact count
  after="$(deploy_artifact_files)"
  added="$(comm -13 \
    <(printf '%s\n' "$before" | sed '/^$/d') \
    <(printf '%s\n' "$after" | sed '/^$/d'))"
  count="$(printf '%s\n' "$added" | sed '/^$/d' | wc -l | tr -d ' ')"
  [ "$count" -eq 1 ] || fail "deploy did not create exactly one new receipt (new count=$count)"
  artifact="$(printf '%s\n' "$added" | sed '/^$/d')"
  [ -f "$artifact" ] || fail "new deploy receipt is missing: $artifact"
  printf '%s\n' "$artifact"
}
artifact_for_promote_output() {
  local output="$1" checkpoint run_id artifact
  checkpoint="$(sed -n 's#^database checkpoint: /siterepo/\.wprism/checkpoints/promote-\(.*\)\.sql\.enc$#\1#p' <<<"$output" | tail -1)"
  [ -n "$checkpoint" ] || fail 'promote output did not expose its exact database checkpoint run'
  artifact="$OTHER_SITE/.wprism/artifacts/promote-$checkpoint.json"
  [ -f "$artifact" ] || fail "promote receipt for checkpoint run '$checkpoint' is missing: $artifact"
  printf '%s\n' "$artifact"
}

export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
PAIR_COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.artifacts.yml -f pair.journal.yml)
# pair.sh publishes the sites under localhost; using the same host avoids a
# redirect that would make the bounded body/status assertions inspect only a
# 301 response instead of the rendered frontend.
SOURCE_URL="http://localhost:${PORT1}"
TARGET_URL="http://localhost:${PORT2}"
TARGET_CRON_FREEZE_FILE=""
TARGET_CRON_FREEZE_SHA=""
source_wp() { "${PAIR_COMPOSE[@]}" run --rm -T cli1 sh -c 'umask 000; exec wp "$@"' _ "$@"; }
target_wp() { "${PAIR_COMPOSE[@]}" run --rm -T cli2 sh -c 'umask 000; exec wp "$@"' _ "$@"; }
source_php() { "${PAIR_COMPOSE[@]}" run --rm -T cli1 php -r "$1"; }
target_php() { "${PAIR_COMPOSE[@]}" run --rm -T cli2 php -r "$1"; }
target_root_php_args() {
  local code="$1"
  shift
  "${PAIR_COMPOSE[@]}" run --rm -T -u root cli2 php -r "$code" -- "$@"
}
prepare_v1_checkpoint_target() {
  # The target CLI runs as uid 33 and may leave .wprism/checkpoints host-owned
  # but non-writable. Prepare only this disposable checkpoint directory
  # through the pair's root service; the dump bytes remain host-retained and
  # are still copied and hashed at the host boundary below.
  "${PAIR_COMPOSE[@]}" run --rm -T -u root cli2 sh -c '
    mkdir -p /siterepo/.wprism/checkpoints
    chmod 0777 /siterepo/.wprism/checkpoints
  ' >/dev/null
}
remove_target_cron_freeze() {
  [ -n "${TARGET_CRON_FREEZE_FILE:-}" ] || return 0
  [ -n "${TARGET_CRON_FREEZE_SHA:-}" ] || return 1
  target_root_php_args '
$path = $argv[1] ?? "";
 $expected = $argv[2] ?? "";
if (!is_file($path) || is_link($path) || !hash_equals($expected, (string) @hash_file("sha256", $path)) || !@unlink($path)) {
    throw new RuntimeException("cron freeze file removal failed: " . $path);
}
' "$TARGET_CRON_FREEZE_FILE" "$TARGET_CRON_FREEZE_SHA" >/dev/null
  TARGET_CRON_FREEZE_FILE=""
  TARGET_CRON_FREEZE_SHA=""
}
source_db_scalar() {
  docker exec -e MYSQL_PWD=root wprism-shared-db mariadb -uroot -N -B --raw "wp_${PAIR}1" -e "$1" | tr -d '\r'
}
target_db_scalar() {
  docker exec -e MYSQL_PWD=root wprism-shared-db mariadb -uroot -N -B --raw "wp_${PAIR}2" -e "$1" | tr -d '\r'
}
ledger_value() { target_db_scalar "SELECT v FROM wp_wprism_kv WHERE k = '$1'"; }
ledger_revision() { ledger_value code_revision; }
source_wprism_ledger_snapshot() {
  source_wp eval '
global $wpdb;
$queries = [
    "wprism_map" => "SELECT uuid, entity_type, id_kind, local_id FROM {$wpdb->prefix}wprism_map ORDER BY uuid, id_kind",
    "wprism_state" => "SELECT uuid, entity_type, content_hash FROM {$wpdb->prefix}wprism_state ORDER BY uuid",
    "wprism_kv" => "SELECT k, v FROM {$wpdb->prefix}wprism_kv ORDER BY k",
];
$snapshot = [];
foreach ($queries as $name => $sql) {
    $wpdb->last_error = "";
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if (!is_array($rows) || (string) $wpdb->last_error !== "") {
        throw new RuntimeException("WPrism ledger snapshot failed for " . $name);
    }
    $snapshot[$name] = $rows;
}
echo wp_json_encode($snapshot, JSON_UNESCAPED_SLASHES);
'
}
promote() { php "$WPRISM" --envs-file="$ENVS_FILE" promote target "$@"; }
deploy() { php "$WPRISM" --envs-file="$ENVS_FILE" deploy target "$@"; }
apply_state() { php "$WPRISM" --envs-file="$ENVS_FILE" apply target "$@"; }
status() { php "$WPRISM" --envs-file="$ENVS_FILE" status target; }
plan_json() { target_wp wprism plan --repo=/siterepo --format=json; }

# Execute the exact fatal-safe checkpoint commands printed by `wprism promote`.
control_wp() {
  local method="$1"
  shift
  local encoded
  encoded="$(WPRISM_CODE_DEPLOY="$CODE_DEPLOY" php -r '
require getenv("WPRISM_CODE_DEPLOY");
$method = $argv[1];
$args = WPrism\Orchestrator\CodeDeploy::$method(...array_slice($argv, 2));
echo json_encode($args, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
' "$method" "$@")"
  local -a args
  mapfile -t args < <(jq -r '.[]' <<<"$encoded")
  target_wp "${args[@]}"
}
# Maintenance is part of the exact recovery boundary, not a regular runtime
# operation. Route it through the same isolated control bootstrap as database
# import so a staged plugin cannot migrate the database while recovery holds
# the target worker stopped.
control_wp_command() {
  local encoded
  encoded="$(WPRISM_CODE_DEPLOY="$CODE_DEPLOY" php -r '
require getenv("WPRISM_CODE_DEPLOY");
$args = WPrism\Orchestrator\CodeDeploy::controlArgs(array_slice($argv, 1));
echo json_encode($args, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
' "$@")"
  local -a args
  mapfile -t args < <(jq -r '.[]' <<<"$encoded")
  target_wp "${args[@]}"
}
target_file() { target_php "echo is_file('$1') ? 'present' : 'absent';"; }
target_directory() { target_php "echo is_dir('$1') ? 'present' : 'absent';"; }
target_path() {
  target_php "echo (file_exists('$1') || is_link('$1')) ? 'present' : 'absent';"
}
# WP-CLI's `php` subcommand still boots WordPress, so using target_php for a
# byte probe can execute the currently installed plugin. Recovery assertions
# run between database import and v1 code promotion; that process boundary must
# inspect bytes without giving the still-staged v2 plugin a chance to migrate
# the freshly restored v1 scalar. Invoke the container PHP binary directly.
target_raw_php() {
  "${PAIR_COMPOSE[@]}" run --rm -T cli2 sh -c 'umask 000; exec php "$@"' _ -r "$1"
}
target_hash() { target_raw_php "echo hash_file('sha256', '$1');"; }
source_hash() { sha256sum "$1" | awk '{print $1}'; }
state_tree_hash() {
  local root="$1"
  (
    cd "$root"
    find state -type f -print | sort | while IFS= read -r path; do
      printf '%s\t' "$path"
      sha256sum "$path"
    done
  ) | sha256sum | awk '{print $1}'
}
final_compiled_state_diff() {
  target_wp eval '
$policy = \WPrism\Policy::load("/siterepo");
$canonical = \WPrism\RepositoryCompiler::compile_staged("/siterepo/state", "/siterepo", $policy);
$recaptured = \WPrism\RepositoryCompiler::compile_staged("/siterepo/.tmp-final-state", "/siterepo", $policy);
$ordered_post_hash = static function (string $root, string $path) use ($policy): string {
    $text = \WPrism\Canon::read_file($root . "/" . $path);
    if (!str_starts_with($text, "---\n")) {
        throw new RuntimeException("bad post file (missing front matter fence): " . $path);
    }
    $end = strpos($text, "\n---\n", 3);
    if ($end === false) {
        throw new RuntimeException("bad post file (unterminated front matter): " . $path);
    }
    $front = json_decode(substr($text, 4, $end - 3), false, 512, JSON_THROW_ON_ERROR);
    if (!$front instanceof \stdClass) {
        throw new RuntimeException("bad post file (front matter must be an object): " . $path);
    }
    $body = substr($text, $end + 5);
    if (str_ends_with($body, "\n")) {
        $body = substr($body, 0, -1);
    }
    $postType = (string) ($front->type ?? "");
    foreach (array_keys(get_object_vars($front)) as $key) {
        if ($policy->field_class($postType, (string) $key) === "derived") {
            unset($front->{$key});
        }
    }
    $json = json_encode(
        $front,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    return hash("sha256", "---\n" . $json . "\n---\n" . $body . "\n");
};
$project = static function ($compiled, string $root) use ($ordered_post_hash): array {
    $out = [];
    foreach ($compiled->tree() as $entity) {
        $path = (string) $entity["path"];
        $out[$path] = $entity["type"] === "post"
            ? $ordered_post_hash($root, $path)
            : (string) $entity["hash"];
    }
    foreach ($compiled->deletions() as $entity) {
        $path = (string) $entity["path"];
        if (array_key_exists($path, $out)) {
            throw new RuntimeException("duplicate compiled state path: " . $path);
        }
        $out[$path] = (string) $entity["hash"];
    }
    ksort($out, SORT_STRING);
    return $out;
};
$left = $project($canonical, "/siterepo/state");
$right = $project($recaptured, "/siterepo/.tmp-final-state");
$paths = array_values(array_unique(array_merge(array_keys($left), array_keys($right))));
sort($paths, SORT_STRING);
$diff = [];
foreach ($paths as $path) {
    if (($left[$path] ?? null) !== ($right[$path] ?? null)) {
        $diff[] = [
            "path" => $path,
            "canonical_hash" => $left[$path] ?? null,
            "recaptured_hash" => $right[$path] ?? null,
        ];
    }
}
echo \WPrism\Canon::encode($diff);
'
}
target_plugin_tree_hash() {
  target_php '
$root = "/var/www/html/wp-content/plugins";
$rows = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->isLink()) {
        continue;
    }
    $relative = str_replace("\\\\", "/", substr($file->getPathname(), strlen($root) + 1));
    $rows[] = $relative . "\\t" . hash_file("sha256", $file->getPathname());
}
sort($rows, SORT_STRING);
echo hash("sha256", implode("\\n", $rows));
'
}
target_managed_code_tree_hash() {
  target_php '
$root = "/var/www/html/wp-content";
$roots = [
    "plugins/woocommerce",
    "plugins/advanced-custom-fields",
    "plugins/wprism-commerce-extension",
    "plugins/wprism-commerce-replacement",
    "themes/wprism-commerce-parent",
    "themes/wprism-commerce-child",
];
$rows = [];
foreach ($roots as $relativeRoot) {
    $absoluteRoot = $root . "/" . $relativeRoot;
    if (!is_dir($absoluteRoot)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) {
            continue;
        }
        $relative = str_replace("\\\\", "/", substr($file->getPathname(), strlen($root) + 1));
        $rows[] = $relative . "\\t" . hash_file("sha256", $file->getPathname());
    }
}
sort($rows, SORT_STRING);
echo hash("sha256", implode("\\n", $rows));
'
}
source_managed_code_tree_hash() {
  WPRISM_SOURCE_CODE_ROOT="$SITE/code/wp-content" php -r '
$root = rtrim((string) getenv("WPRISM_SOURCE_CODE_ROOT"), "/");
$roots = [
    "plugins/woocommerce",
    "plugins/advanced-custom-fields",
    "plugins/wprism-commerce-extension",
    "plugins/wprism-commerce-replacement",
    "themes/wprism-commerce-parent",
    "themes/wprism-commerce-child",
];
$rows = [];
foreach ($roots as $relativeRoot) {
    $absoluteRoot = $root . "/" . $relativeRoot;
    if (!is_dir($absoluteRoot)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) {
            continue;
        }
        $relative = str_replace("\\\\", "/", substr($file->getPathname(), strlen($root) + 1));
        $rows[] = $relative . "\\t" . hash_file("sha256", $file->getPathname());
    }
}
sort($rows, SORT_STRING);
echo hash("sha256", implode("\\n", $rows));
'
}
order_snapshot() {
  local runner="$1" order_id="$2"
  [[ "$order_id" =~ ^[0-9]+$ ]] || fail "target order id is not numeric: $order_id"
  "$runner" eval '
global $wpdb;
$order = wc_get_order('"$order_id"');
if (!$order) { throw new RuntimeException("runtime order missing: " . '"$order_id"'); }
$checked_rows = static function (string $sql, string $label) use ($wpdb): array {
    $wpdb->last_error = "";
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if (!is_array($rows) || (string) $wpdb->last_error !== "") {
        throw new RuntimeException("runtime order snapshot read failed: " . $label);
    }
    usort($rows, static fn(array $a, array $b): int => wp_json_encode($a) <=> wp_json_encode($b));
    return $rows;
};
$order_id = (int) $order->get_id();
$order_items_table = $wpdb->prefix . "woocommerce_order_items";
$order_itemmeta_table = $wpdb->prefix . "woocommerce_order_itemmeta";
$order_items = $checked_rows(
    $wpdb->prepare("SELECT * FROM `$order_items_table` WHERE order_id = %d", $order_id),
    "woocommerce_order_items"
);
$order_item_ids = array_values(array_filter(array_map(
    static fn(array $row): int => (int) ($row["order_item_id"] ?? 0),
    $order_items
)));
$order_itemmeta = $order_item_ids
    ? $checked_rows(
        "SELECT * FROM `$order_itemmeta_table` WHERE order_item_id IN (" . implode(",", $order_item_ids) . ")",
        "woocommerce_order_itemmeta"
    )
    : [];
$customer_lookup = $checked_rows(
    $wpdb->prepare("SELECT * FROM `{$wpdb->prefix}wc_customer_lookup` WHERE user_id = %d", (int) $order->get_customer_id()),
    "wc_customer_lookup"
);
$items = [];
foreach ($order->get_items("line_item") as $item) {
    $items[] = [
        "id" => (int) $item->get_id(),
        "name" => (string) $item->get_name(),
        "product_id" => (int) $item->get_product_id(),
        "variation_id" => (int) $item->get_variation_id(),
        "quantity" => (int) $item->get_quantity(),
        "tax_class" => (string) $item->get_tax_class(),
        "subtotal" => (string) $item->get_subtotal(),
        "subtotal_tax" => (string) $item->get_subtotal_tax(),
        "total" => (string) $item->get_total(),
        "total_tax" => (string) $item->get_total_tax(),
        "taxes" => $item->get_taxes(),
    ];
}
echo wp_json_encode([
    "id" => $order_id,
    "status" => (string) $order->get_status(),
    "customer_id" => (int) $order->get_customer_id(),
    "billing_email" => (string) $order->get_billing_email(),
    "billing" => $order->get_address("billing"),
    "shipping" => $order->get_address("shipping"),
    "payment_method" => (string) $order->get_payment_method(),
    "payment_method_title" => (string) $order->get_payment_method_title(),
    "transaction_id" => (string) $order->get_transaction_id(),
    "customer_note" => (string) $order->get_customer_note(),
    "currency" => (string) $order->get_currency(),
    "subtotal" => (string) $order->get_subtotal(),
    "discount_total" => (string) $order->get_discount_total(),
    "shipping_total" => (string) $order->get_shipping_total(),
    "total_tax" => (string) $order->get_total_tax(),
    "total" => (string) $order->get_total(),
    "items" => $items,
    "hpos_orders" => $checked_rows($wpdb->prepare("SELECT * FROM `{$wpdb->prefix}wc_orders` WHERE id = %d", $order_id), "wc_orders"),
    "hpos_addresses" => $checked_rows($wpdb->prepare("SELECT * FROM `{$wpdb->prefix}wc_order_addresses` WHERE order_id = %d", $order_id), "wc_order_addresses"),
    "hpos_operational" => $checked_rows($wpdb->prepare("SELECT * FROM `{$wpdb->prefix}wc_order_operational_data` WHERE order_id = %d", $order_id), "wc_order_operational_data"),
    "hpos_meta" => $checked_rows($wpdb->prepare("SELECT * FROM `{$wpdb->prefix}wc_orders_meta` WHERE order_id = %d", $order_id), "wc_orders_meta"),
    "customer_lookup" => $customer_lookup,
    "order_stats" => $checked_rows($wpdb->prepare("SELECT * FROM `{$wpdb->prefix}wc_order_stats` WHERE order_id = %d", $order_id), "wc_order_stats"),
    "product_lookup" => $checked_rows($wpdb->prepare("SELECT * FROM `{$wpdb->prefix}wc_order_product_lookup` WHERE order_id = %d", $order_id), "wc_order_product_lookup"),
    "order_items" => $order_items,
    "order_itemmeta" => $order_itemmeta,
], JSON_UNESCAPED_SLASHES);
'
}
target_order_snapshot() {
  order_snapshot target_wp "$1"
}
assert_target_order_snapshot() {
  local snapshot="$1" label="$2"
  jq -e --argjson expected_order "$TARGET_ORDER_ID" --argjson expected_product "$TARGET_CAP_ID" --argjson expected_customer "$TARGET_RUNTIME_CUSTOMER_ID" '
    . as $snapshot |
    ([.order_items[] | select(.order_item_type == "line_item")][0].order_item_id | tonumber) as $line_item_id |
    ([.order_items[] | select(.order_item_type == "tax")][0].order_item_id | tonumber) as $tax_item_id |
    (.customer_lookup[0].customer_id | tonumber) as $analytics_customer_id |
    .id == $expected_order and
    .status == "pending" and
    .customer_id == $expected_customer and
    .billing_email == "runtime-only@example.invalid" and
    (.items | length) == 1 and
    .items[0].product_id == $expected_product and
    .items[0].variation_id == 0 and
    .items[0].quantity == 1 and
    (.subtotal | tonumber) > 0 and
    (.total | tonumber) > 0 and
    (.items[0].subtotal | tonumber) > 0 and
    (.items[0].total | tonumber) > 0 and
    (.hpos_orders | length) == 1 and
    ((.hpos_orders[0].id | tonumber) == $expected_order) and
    ((.hpos_orders[0].type | tostring) == "shop_order") and
    .hpos_orders[0].status == "wc-pending" and
    (.hpos_orders[0].customer_id | tonumber) == $expected_customer and
    ((.hpos_orders[0].date_created_gmt | tostring | length) > 0) and
    ((.hpos_orders[0].date_updated_gmt | tostring | length) > 0) and
    (.hpos_operational | length) == 1 and
    ((.hpos_operational[0].id | tonumber) > 0) and
    ((.hpos_operational[0].order_id | tonumber) == $expected_order) and
    ((.hpos_operational[0].woocommerce_version | tostring) | test("^[0-9]+\\.[0-9]+")) and
    ((.hpos_operational[0].order_key | tostring | length) > 0) and
    (.hpos_operational[0] | has("created_via") and has("prices_include_tax") and has("recorded_sales") and has("order_stock_reduced") and has("download_permission_granted")) and
    (.hpos_addresses | length) == 2 and
    ([(.hpos_addresses[] | .address_type)] | sort) == ["billing", "shipping"] and
    (all(.hpos_addresses[]; ((.id | tonumber) > 0) and ((.order_id | tonumber) == $expected_order) and has("first_name") and has("last_name") and has("company") and has("address_1") and has("address_2") and has("city") and has("state") and has("postcode") and has("country") and has("email") and has("phone"))) and
    (any(.hpos_addresses[]; .address_type == "billing" and .first_name == "Target" and .last_name == "Runtime" and .address_1 == "200 Target Runtime Way" and .city == "Targetville" and .state == "CA" and .postcode == "90210" and .country == "US" and .email == "runtime-only@example.invalid" and .phone == "555-0101")) and
    (any(.hpos_addresses[]; .address_type == "shipping" and .first_name == "Target" and .last_name == "Runtime" and .address_1 == "201 Target Fulfillment Way" and .city == "Targetville" and .state == "CA" and .postcode == "90210" and .country == "US")) and
    (.hpos_meta | length) >= 1 and
    (all(.hpos_meta[]; ((.id | tonumber) > 0) and ((.order_id | tonumber) == $expected_order) and ((.meta_key | tostring | length) > 0) and ((.meta_value | type) == "string"))) and
    (any(.hpos_meta[]; .meta_key == "_wprism_runtime_marker" and .meta_value == "target-order-only")) and
    (.customer_lookup | length) == 1 and
    ($analytics_customer_id > 0) and
    ((.customer_lookup[0].user_id | tonumber) == $expected_customer) and
    .customer_lookup[0].username == "runtime-customer" and
    .customer_lookup[0].first_name == "Target" and
    .customer_lookup[0].last_name == "Runtime" and
    .customer_lookup[0].email == "runtime-customer@example.invalid" and
    ((.customer_lookup[0].date_registered | tostring | length) > 0) and
    (.order_stats | length) == 1 and
    ((.order_stats[0].order_id | tonumber) == $expected_order) and
    ((.order_stats[0].status | tostring) == "wc-pending") and
    ((.order_stats[0].customer_id | tonumber) == $analytics_customer_id) and
    ((.order_stats[0].num_items_sold | tonumber) == 1) and
    ((.order_stats[0].total_sales | tonumber) > 0) and
    ((.order_stats[0].net_total | tonumber) > 0) and
    ((.order_stats[0].tax_total | tonumber) >= 0) and
    ((.order_stats[0].shipping_total | tonumber) >= 0) and
    ((.order_stats[0].date_created_gmt | tostring | length) > 0) and
    (.product_lookup | length) == 1 and
    ((.product_lookup[0].order_id | tonumber) == $expected_order) and
    ((.product_lookup[0].product_id | tonumber) == $expected_product) and
    ((.product_lookup[0].variation_id | tonumber) == 0) and
    ((.product_lookup[0].customer_id | tonumber) == $analytics_customer_id) and
    ((.product_lookup[0].product_qty | tonumber) == 1) and
    ((.product_lookup[0].product_gross_revenue | tonumber) > 0) and
    ((.product_lookup[0].product_net_revenue | tonumber) > 0) and
    ((.product_lookup[0].date_created | tostring | length) > 0) and
    ((.product_lookup[0].order_item_id | tonumber) == $line_item_id) and
    (.order_items | length) == 2 and
    ([.order_items[] | select(.order_item_type == "line_item")] | length) == 1 and
    ([.order_items[] | select(.order_item_type == "tax")] | length) == 1 and
    (all(.order_items[]; ((.order_item_id | tonumber) > 0) and ((.order_id | tonumber) == $expected_order) and ((.order_item_name | tostring | length) > 0))) and
    (.order_itemmeta | length) >= 10 and
    (all(.order_itemmeta[];
      ((.meta_id | tonumber) > 0) and
      ((.meta_key | tostring | length) > 0) and
      ((.meta_value | type) == "string") and
      ((.order_item_id | tonumber) as $meta_item_id |
        any($snapshot.order_items[]; (.order_item_id | tonumber) == $meta_item_id))
    )) and
    (any(.order_itemmeta[]; (.order_item_id | tonumber) == $line_item_id and .meta_key == "_product_id" and (.meta_value | tonumber) == $expected_product)) and
    (any(.order_itemmeta[]; (.order_item_id | tonumber) == $line_item_id and .meta_key == "_variation_id" and (.meta_value | tonumber) == 0)) and
    (any(.order_itemmeta[]; (.order_item_id | tonumber) == $line_item_id and .meta_key == "_qty" and (.meta_value | tonumber) == 1)) and
    (any(.order_itemmeta[]; (.order_item_id | tonumber) == $line_item_id and .meta_key == "_line_subtotal" and (.meta_value | tonumber) > 0)) and
    (any(.order_itemmeta[]; (.order_item_id | tonumber) == $line_item_id and .meta_key == "_line_total" and (.meta_value | tonumber) > 0)) and
    (any(.order_itemmeta[]; (.order_item_id | tonumber) == $tax_item_id and .meta_key == "rate_id" and (.meta_value | tonumber) > 0)) and
    (any(.order_itemmeta[]; (.order_item_id | tonumber) == $tax_item_id and .meta_key == "label" and .meta_value == "WPrism Grind CA Sales Tax")) and
    (any(.order_itemmeta[]; (.order_item_id | tonumber) == $tax_item_id and .meta_key == "tax_amount" and (.meta_value | tonumber) > 0))
  ' <<<"$snapshot" >/dev/null || fail "$label order identity/line-item/totals acceptance failed: $snapshot"
}
assert_target_order_unchanged() {
  local label="$1"
  assert_eq "$TARGET_ORDER_BASELINE" "$(target_order_snapshot "$TARGET_ORDER_ID")" "$label exact order snapshot"
}
assert_target_order_absent() {
  local label="$1"
  [[ "$TARGET_ORDER_ITEM_IDS" =~ ^[1-9][0-9]*(,[1-9][0-9]*)*$ ]] || fail "$label target order item ID capture is malformed: $TARGET_ORDER_ITEM_IDS"
  assert_eq 0 "$(target_wp eval 'echo wc_get_order('"$TARGET_ORDER_ID"') ? 1 : 0;')" "$label absent order"
  assert_eq 0 "$(target_wp eval '
global $wpdb;
$order_id = '"$TARGET_ORDER_ID"';
$customer_user_id = '"$TARGET_RUNTIME_CUSTOMER_ID"';
$item_ids = array_values(array_unique(array_filter(array_map("absint", explode(",", '"$TARGET_ORDER_ITEM_IDS"')), static fn(int $id): bool => $id > 0)));
if (!$item_ids) { throw new RuntimeException("target order item ID capture is empty"); }
$item_id_list = implode(",", $item_ids);
$order_items_table = $wpdb->prefix . "woocommerce_order_items";
$order_itemmeta_table = $wpdb->prefix . "woocommerce_order_itemmeta";
$checks = [
    [$wpdb->prefix . "wc_orders", "id"],
    [$wpdb->prefix . "wc_order_addresses", "order_id"],
    [$wpdb->prefix . "wc_order_operational_data", "order_id"],
    [$wpdb->prefix . "wc_orders_meta", "order_id"],
    [$wpdb->prefix . "wc_order_stats", "order_id"],
    [$wpdb->prefix . "wc_order_product_lookup", "order_id"],
    [$order_items_table, "order_id"],
];
$remaining = 0;
foreach ($checks as [$table, $column]) {
    $wpdb->last_error = "";
    $remaining += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = %d", $order_id));
    if ((string) $wpdb->last_error !== "") { throw new RuntimeException("HPOS absence read failed for " . $table); }
}
$wpdb->last_error = "";
$remaining += (int) $wpdb->get_var("SELECT COUNT(*) FROM `$order_itemmeta_table` WHERE order_item_id IN ($item_id_list)");
if ((string) $wpdb->last_error !== "") { throw new RuntimeException("target order-item metadata absence read failed"); }
$wpdb->last_error = "";
$remaining += (int) $wpdb->get_var("SELECT COUNT(*) FROM `$order_itemmeta_table` AS itemmeta LEFT JOIN `$order_items_table` AS items ON items.order_item_id = itemmeta.order_item_id WHERE itemmeta.order_item_id IN ($item_id_list) AND items.order_item_id IS NULL");
if ((string) $wpdb->last_error !== "") { throw new RuntimeException("orphan target order-item metadata absence read failed"); }
$wpdb->last_error = "";
$remaining += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$wpdb->prefix}wc_customer_lookup` WHERE user_id = %d", $customer_user_id));
if ((string) $wpdb->last_error !== "") { throw new RuntimeException("target customer lookup absence read failed"); }
echo $remaining;
')" "$label absent HPOS/order-item/customer-lookup rows"
}
source_runtime_customer_snapshot() {
  local customer_id="$1"
  [[ "$customer_id" =~ ^[0-9]+$ ]] || fail "source runtime customer id is not numeric: $customer_id"
  source_wp eval '
global $wpdb;
$user = get_userdata('"$customer_id"');
if (!$user || !in_array("customer", (array) $user->roles, true)) {
    throw new RuntimeException("source runtime customer missing: " . '"$customer_id"');
}
$meta = $wpdb->get_results($wpdb->prepare(
    "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d ORDER BY meta_key, umeta_id",
    $user->ID
), ARRAY_A);
echo wp_json_encode([
    "id" => (int) $user->ID,
    "email" => (string) $user->user_email,
    "login" => (string) $user->user_login,
    "display_name" => (string) $user->display_name,
    "roles" => array_values((array) $user->roles),
    "meta" => $meta,
], JSON_UNESCAPED_SLASHES);
'
}
target_runtime_customer_snapshot() {
  local customer_id="$1"
  [[ "$customer_id" =~ ^[0-9]+$ ]] || fail "target runtime customer id is not numeric: $customer_id"
  target_wp eval '
global $wpdb;
$user = get_userdata('"$customer_id"');
if (!$user || !in_array("customer", (array) $user->roles, true)) {
    throw new RuntimeException("target runtime customer missing: " . '"$customer_id"');
}
$meta = $wpdb->get_results($wpdb->prepare(
    "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d ORDER BY meta_key, umeta_id",
    $user->ID
), ARRAY_A);
echo wp_json_encode([
    "id" => (int) $user->ID,
    "email" => (string) $user->user_email,
    "login" => (string) $user->user_login,
    "display_name" => (string) $user->display_name,
    "roles" => array_values((array) $user->roles),
    "meta" => $meta,
], JSON_UNESCAPED_SLASHES);
'
}
source_runtime_order_snapshot() {
  order_snapshot source_wp "$1"
}
source_runtime_event_snapshot() {
  local event_id="$1"
  local context_columns
  [[ "$event_id" =~ ^[0-9]+$ ]] || fail "source runtime event id is not numeric: $event_id"
  context_columns="$(source_db_scalar "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_wprism_commerce_extension_events' AND COLUMN_NAME = 'context'")"
  [[ "$context_columns" =~ ^[01]$ ]] || fail "source runtime event context-column shape is not binary: $context_columns"
  if [ "$context_columns" = 1 ]; then
    source_db_scalar "SELECT CONCAT('1|', id, '|', label, '|', DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s'), '|', context) FROM wp_wprism_commerce_extension_events WHERE id = $event_id"
  else
    source_db_scalar "SELECT CONCAT(0, '|', id, '|', label, '|', DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s')) FROM wp_wprism_commerce_extension_events WHERE id = $event_id"
  fi
}
runtime_identity_inventory() {
  local runner="$1"
  "$runner" eval '
global $wpdb;
$customers = [];
foreach (get_users(["role" => "customer", "orderby" => "ID", "order" => "ASC"]) as $user) {
    $customers[] = [
        "id" => (int) $user->ID,
        "login" => (string) $user->user_login,
        "email" => (string) $user->user_email,
        "display_name" => (string) $user->display_name,
        "roles" => array_values((array) $user->roles),
    ];
}
$wpdb->last_error = "";
$orders = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, status, customer_id, billing_email, currency, total_amount, payment_method, transaction_id "
        . "FROM `{$wpdb->prefix}wc_orders` WHERE type = %s ORDER BY id",
        "shop_order"
    ),
    ARRAY_A
);
if (!is_array($orders) || (string) $wpdb->last_error !== "") {
    throw new RuntimeException("runtime identity inventory could not read HPOS orders");
}
$events_table = $wpdb->prefix . "wprism_commerce_extension_events";
$wpdb->last_error = "";
$events_exists = (string) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $events_table));
if ((string) $wpdb->last_error !== "") {
    throw new RuntimeException("runtime identity inventory could not inspect extension event storage");
}
$events = [];
if ($events_exists === $events_table) {
    $wpdb->last_error = "";
    $events = $wpdb->get_results("SELECT id, label, created_at FROM `$events_table` ORDER BY id", ARRAY_A);
    if (!is_array($events) || (string) $wpdb->last_error !== "") {
        throw new RuntimeException("runtime identity inventory could not read extension events");
    }
}
echo wp_json_encode(["customers" => $customers, "orders" => $orders, "events" => $events], JSON_UNESCAPED_SLASHES);
'
}
target_cron_inventory() {
  target_wp cron event list --fields=hook,time,args,schedule,interval --format=json | jq -cS '
    if type != "array" then error("WP-Cron inventory is not a JSON array") else
    sort_by([
      ((.hook // "") | tostring),
      ((.time // "") | tostring),
      ((.args // "") | tostring),
      ((.schedule // "") | tostring),
      ((.interval // "") | tostring)
    ])
    end
  '
}
target_action_scheduler_inventory() {
  target_wp eval '
global $wpdb;
$table = $wpdb->prefix . "actionscheduler_actions";
$wpdb->last_error = "";
$rows = $wpdb->get_results("SELECT * FROM `" . $table . "` ORDER BY action_id", ARRAY_A);
if (!is_array($rows) || (string) $wpdb->last_error !== "") {
    throw new RuntimeException("Action Scheduler inventory read failed: " . (string) $wpdb->last_error);
}
echo wp_json_encode($rows, JSON_UNESCAPED_SLASHES);
' | jq -cS 'if type != "array" then error("Action Scheduler inventory is not a JSON array") else . end'
}
assert_source_runtime_event_baseline() {
  local label="$1" snapshot="$2" context_columns event_id event_label event_created_at event_context
  IFS='|' read -r context_columns event_id event_label event_created_at event_context <<<"$snapshot"
  assert_eq "$SOURCE_RUNTIME_EVENT_ID" "$event_id" "$label source event identity"
  assert_eq "$SOURCE_RUNTIME_EVENT_LABEL" "$event_label" "$label source event label"
  assert_eq "$SOURCE_RUNTIME_EVENT_CREATED_AT" "$event_created_at" "$label source event timestamp"
  case "$context_columns" in
    0)
      assert_eq "$SOURCE_RUNTIME_EVENT_BASELINE" "$snapshot" "$label exact immutable v1 source event baseline"
      ;;
    1)
      if [ -z "$SOURCE_RUNTIME_EVENT_V2_BASELINE" ]; then
        SOURCE_RUNTIME_EVENT_V2_BASELINE="$snapshot"
      fi
      assert_eq "$SOURCE_RUNTIME_EVENT_V2_BASELINE" "$snapshot" "$label exact derived v2 source event baseline"
      assert_eq "" "$event_context" "$label v2 source event context default"
      ;;
    *) fail "$label source event context-column shape is not 0/1: $context_columns" ;;
  esac
}
assert_source_runtime_baseline() {
  local label="$1"
  assert_eq "$SOURCE_RUNTIME_CUSTOMER_BASELINE" "$(source_runtime_customer_snapshot "$SOURCE_RUNTIME_CUSTOMER_ID")" "$label exact source customer baseline"
  assert_eq "$SOURCE_RUNTIME_ORDER_BASELINE" "$(source_runtime_order_snapshot "$SOURCE_RUNTIME_ORDER_ID")" "$label exact source order baseline"
  assert_source_runtime_event_baseline "$label" "$(source_runtime_event_snapshot "$SOURCE_RUNTIME_EVENT_ID")"
  if [ -n "$SOURCE_RUNTIME_IDENTITY_BASELINE" ]; then
    assert_eq "$SOURCE_RUNTIME_IDENTITY_BASELINE" "$(runtime_identity_inventory source_wp)" "$label complete source runtime identity inventory"
  fi
}
assert_target_runtime_baseline() {
  local label="$1" expected_context="${2:-1}"
  assert_eq "$TARGET_RUNTIME_CUSTOMER_BASELINE" "$(target_runtime_customer_snapshot "$TARGET_RUNTIME_CUSTOMER_ID")" "$label exact target customer baseline"
  assert_target_order_unchanged "$label"
  assert_extension_runtime_event "$expected_context" "" "$label target extension-event baseline"
  assert_eq "$TARGET_RUNTIME_IDENTITY_BASELINE" "$(runtime_identity_inventory target_wp)" "$label complete target runtime identity inventory"
}
assert_runtime_isolation() {
  local label="$1" expected_context="${2:-1}"
  assert_source_runtime_baseline "$label"
  assert_source_runtime_absent_from_target "$label"
  assert_target_runtime_baseline "$label" "$expected_context"
  assert_target_runtime_absent_from_source "$label"
  assert_env_secret_isolation "$label"
  assert_runtime_state_excluded "$label"
}
assert_env_secret_isolation() {
  local label="$1"
  assert_eq source-only-synthetic-secret "$(source_wp option get wprism_commerce_extension_gateway_secret)" "$label source env-owned gateway secret"
  assert_eq target-only-synthetic-secret "$(target_wp option get wprism_commerce_extension_gateway_secret)" "$label target env-owned gateway secret"
}
assert_source_runtime_absent_from_target() {
  local label="$1"
  assert_eq 0 "$(target_wp eval 'echo get_user_by("email", "source-customer@example.invalid") ? 1 : 0;')" "$label source customer absent from target"
  assert_eq 0 "$(target_wp eval 'echo count(wc_get_orders(["billing_email" => "source-order@example.invalid", "limit" => -1, "return" => "ids"]));')" "$label source order absent from target"
  assert_eq 0 "$(target_db_scalar "SELECT COUNT(*) FROM wp_wprism_commerce_extension_events WHERE label = 'WPrism Grind source-only runtime event'")" "$label source extension event absent from target"
}
assert_target_runtime_absent_from_source() {
  local label="$1"
  assert_eq 0 "$(source_wp eval 'echo get_user_by("email", "runtime-customer@example.invalid") ? 1 : 0;')" "$label target customer absent from source"
  assert_eq 0 "$(source_wp eval 'echo count(wc_get_orders(["billing_email" => "runtime-only@example.invalid", "limit" => -1, "return" => "ids"]));')" "$label target order absent from source"
  assert_eq 0 "$(source_db_scalar "SELECT COUNT(*) FROM wp_wprism_commerce_extension_events WHERE label = 'WPrism Grind runtime v1 event'")" "$label target extension event absent from source"
}
assert_runtime_state_excluded() {
  local label="$1" path marker
  for path in "$SITE/state" "$OTHER_SITE/state" "$OTHER_SITE/.tmp-final-state"; do
    [ -d "$path" ] || continue
    for marker in \
      'source-customer@example.invalid' \
      'source-order@example.invalid' \
      'WPrism Grind source-only runtime event' \
      'runtime-customer@example.invalid' \
      'runtime-only@example.invalid' \
      'target-order-only' \
      '200 Target Runtime Way' \
      '201 Target Fulfillment Way' \
      'WPrism Grind runtime v1 event' \
      'activate:replacement-fixed:fresh=yes:retiring-root=present' \
      'source-only-synthetic-secret' \
      'target-only-synthetic-secret'; do
      if grep -R -Fq -- "$marker" "$path"; then
        fail "$label runtime marker '$marker' leaked into generated repository state: $path"
      fi
    done
  done
  pass "$label source-only and target-only runtime data remain outside generated repository state"
}
target_tee_snapshot() {
  local uuid="$1"
  [[ "$uuid" =~ ^[0-9a-f-]{36}$ ]] || fail "target tee UUID is malformed: $uuid"
  target_wp eval '
global $wpdb;
$uuid = '"'"$uuid"'"';
$postId = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
    "_wprism_uuid",
    $uuid
));
if (!$postId) { throw new RuntimeException("target tee identity missing: " . $uuid); }
$post = get_post($postId);
$meta = $wpdb->get_results($wpdb->prepare(
    "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key, meta_id",
    $postId
), ARRAY_A);
$terms = $wpdb->get_results($wpdb->prepare(
    "SELECT tt.taxonomy, tt.term_id, tt.term_taxonomy_id, t.slug, tt.parent FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id = %d ORDER BY tt.taxonomy, tt.term_id, tt.term_taxonomy_id",
    $postId
), ARRAY_A);
$payload = [
    "id" => (int) $post->ID,
    "type" => (string) $post->post_type,
    "slug" => (string) $post->post_name,
    "status" => (string) $post->post_status,
    "parent" => (int) $post->post_parent,
    "title" => (string) $post->post_title,
    "excerpt" => (string) $post->post_excerpt,
    "content" => (string) $post->post_content,
    "meta" => $meta,
    "terms" => $terms,
];
$payload["authored_hash"] = hash("sha256", wp_json_encode([
    "title" => $payload["title"], "excerpt" => $payload["excerpt"], "content" => $payload["content"],
    "status" => $payload["status"], "meta" => $meta, "terms" => $terms,
], JSON_UNESCAPED_SLASHES));
echo wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
'
}
assert_target_tee_unchanged() {
  local before="$1" uuid="$2" label="$3" after
  after="$(target_tee_snapshot "$uuid")"
  assert_eq "$before" "$after" "$label exact tee identity/content/meta/terms snapshot"
  jq -e '.id > 0 and (.title | type) == "string" and (.content | type) == "string" and (.excerpt | type) == "string" and (.status | type) == "string" and (.meta | type) == "array" and (.terms | type) == "array" and (.authored_hash | test("^[0-9a-f]{64}$"))' <<<"$after" >/dev/null || fail "$label tee snapshot diagnostics malformed: $after"
}
assert_extension_runtime_event() {
  local expected_context_column="$1" expected_context="$2" label="$3"
  local context_columns row_count total_rows actual_row expected_row
  context_columns="$(target_db_scalar "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_wprism_commerce_extension_events' AND COLUMN_NAME = 'context'")"
  row_count="$(target_db_scalar "SELECT COUNT(*) FROM wp_wprism_commerce_extension_events WHERE id = $RUNTIME_EVENT_ID")"
  total_rows="$(target_db_scalar "SELECT COUNT(*) FROM wp_wprism_commerce_extension_events")"
  assert_eq "$expected_context_column" "$context_columns" "$label runtime table context column"
  assert_eq 1 "$row_count" "$label runtime event identity"
  assert_eq 1 "$total_rows" "$label runtime event total row count"
  if [ "$expected_context_column" = 1 ]; then
    actual_row="$(target_db_scalar "SELECT CONCAT(id, '|', label, '|', DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s'), '|', context) FROM wp_wprism_commerce_extension_events WHERE id = $RUNTIME_EVENT_ID")"
    expected_row="$RUNTIME_EVENT_ID|$RUNTIME_EVENT_LABEL|$RUNTIME_EVENT_CREATED_AT|$expected_context"
  else
    actual_row="$(target_db_scalar "SELECT CONCAT(id, '|', label, '|', DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s')) FROM wp_wprism_commerce_extension_events WHERE id = $RUNTIME_EVENT_ID")"
    expected_row="$RUNTIME_EVENT_ID|$RUNTIME_EVENT_LABEL|$RUNTIME_EVENT_CREATED_AT"
  fi
  assert_eq "$expected_row" "$actual_row" "$label runtime event row"
  echo "runtime extension event: $label context_column=$context_columns rows=$total_rows row=$actual_row"
}
assert_extension_runtime_event_excluded() {
  local label="$1" path
  for path in "$SITE/state" "$OTHER_SITE/state" "$OTHER_SITE/.tmp-final-state"; do
    if [ -d "$path" ] && grep -R -Fq -- "$RUNTIME_EVENT_LABEL" "$path"; then
      fail "$label runtime event leaked into canonical state: $path"
    fi
  done
  pass "$label runtime extension event remains outside canonical state"
}
acf_schema_snapshot() {
  local side="$1" probe
  probe='
if (!function_exists("acf_get_field_group") || !function_exists("acf_get_field") || !function_exists("acf_get_fields")) {
    throw new RuntimeException("ACF schema APIs are unavailable");
}
$group = acf_get_field_group("group_wprism_commerce_catalog");
$group_id = (int) ($group["ID"] ?? ($group["id"] ?? 0));
$fields = acf_get_fields($group);
$field = acf_get_field("field_wprism_inventory_note");
if (!$group || !$group_id || !is_array($fields) || !$field) {
    throw new RuntimeException("exact ACF group/field schema could not be loaded");
}
echo wp_json_encode([
    "group" => [
        "id" => $group_id,
        "key" => (string) ($group["key"] ?? ""),
        "title" => (string) ($group["title"] ?? ""),
        "location" => $group["location"] ?? null,
        "menu_order" => (int) ($group["menu_order"] ?? -1),
        "position" => (string) ($group["position"] ?? ""),
        "style" => (string) ($group["style"] ?? ""),
        "label_placement" => (string) ($group["label_placement"] ?? ""),
        "instruction_placement" => (string) ($group["instruction_placement"] ?? ""),
        "active" => (bool) ($group["active"] ?? false),
    ],
    "field_count" => count($fields),
    "field" => [
        "key" => (string) ($field["key"] ?? ""),
        "label" => (string) ($field["label"] ?? ""),
        "name" => (string) ($field["name"] ?? ""),
        "type" => (string) ($field["type"] ?? ""),
        "parent" => (int) ($field["parent"] ?? 0),
        "menu_order" => (int) ($field["menu_order"] ?? -1),
        "required" => (bool) ($field["required"] ?? false),
        "conditional_logic" => (bool) ($field["conditional_logic"] ?? false),
    ],
], JSON_UNESCAPED_SLASHES);
'
  if [ "$side" = source ]; then
    source_wp eval "$probe"
  elif [ "$side" = target ]; then
    target_wp eval "$probe"
  else
    fail "unknown ACF schema side: $side"
  fi
}
assert_acf_schema() {
  local side="$1" label="$2" schema
  schema="$(acf_schema_snapshot "$side")"
  echo "ACF schema: $label $schema"
  jq -e '
    .group.id > 0 and
    .group.key == "group_wprism_commerce_catalog" and
    .group.title == "WPrism Commerce Catalog" and
    .group.location == [[{"param":"post_type","operator":"==","value":"product"}]] and
    .group.menu_order == 0 and .group.position == "normal" and .group.style == "default" and
    .group.label_placement == "top" and .group.instruction_placement == "label" and
    .group.active == true and .field_count == 1 and
    .field.key == "field_wprism_inventory_note" and .field.label == "Inventory Note" and
    .field.name == "wprism_inventory_note" and .field.type == "text" and
    .field.parent == .group.id and .field.menu_order == 0 and
    .field.required == false and .field.conditional_logic == false
  ' <<<"$schema" >/dev/null || fail "$label exact ACF group/field schema acceptance failed: $schema"
  pass "$label exact ACF group/field schema is present (group key/location/options + field key/name/type/parent)"
}
assert_frontend_child_parent() {
  local label="$1" expected_v2="${2:-0}" body_file body http parent_offset child_offset
  body_file="$(mktemp "${TMPDIR:-/tmp}/wprism-ecommerce-front.XXXXXX")"
  if ! http="$(curl --connect-timeout 3 --max-time 10 --silent --show-error -o "$body_file" -w '%{http_code}' "$TARGET_URL/")"; then
    rm -f -- "$body_file"
    fail "$label frontend request failed"
  fi
  body="$(<"$body_file")"
  rm -f -- "$body_file"
  assert_eq 200 "$http" "$label frontend HTTP status"
  grep -Eiq '<body[^>]*wprism-commerce-storefront' <<<"$body" || fail "$label frontend body is missing the parent body_class marker"
  grep -Fq 'wprism-commerce-child-catalog' <<<"$body" || fail "$label frontend body did not execute the child theme template"
  parent_offset="$(grep -bo -m1 'wprism-commerce-parent-css' <<<"$body" | cut -d: -f1 || true)"
  child_offset="$(grep -bo -m1 'wprism-commerce-child-css' <<<"$body" | cut -d: -f1 || true)"
  [ -n "$parent_offset" ] || fail "$label frontend did not enqueue the parent stylesheet"
  [ -n "$child_offset" ] || fail "$label frontend did not enqueue the child stylesheet"
  [ "$parent_offset" -lt "$child_offset" ] || fail "$label frontend enqueued child stylesheet before its parent"
  if [ "$expected_v2" = 1 ]; then
    grep -Fq 'wprism-commerce-v2' <<<"$body" || fail "$label frontend did not execute the v2 parent/child body marker"
  fi
  pass "$label frontend exercised parent body_class, child template, and dependency-ordered parent/child enqueues"
}

assert_frontend_parent() {
  local label="$1" body_file body http
  body_file="$(mktemp "${TMPDIR:-/tmp}/wprism-ecommerce-parent-front.XXXXXX")"
  if ! http="$(curl --connect-timeout 3 --max-time 10 --silent --show-error -o "$body_file" -w '%{http_code}' "$TARGET_URL/")"; then
    rm -f -- "$body_file"
    fail "$label standalone-parent frontend request failed"
  fi
  body="$(<"$body_file")"
  rm -f -- "$body_file"
  assert_eq 200 "$http" "$label frontend HTTP status"
  grep -Eiq '<body[^>]*wprism-commerce-storefront' <<<"$body" || fail "$label frontend is missing the parent body_class marker"
  grep -Fq 'wprism-commerce-v2' <<<"$body" || fail "$label frontend did not execute the v2 parent template"
  assert_absent "$body" 'wprism-commerce-child-catalog' "$label standalone parent frontend"
  pass "$label frontend runs the reviewed standalone parent after child removal"
}
assert_extension_rest_status() {
  local expected_version="$1" expected_schema="$2" label="$3" body_file body http
  body_file="$(mktemp "${TMPDIR:-/tmp}/wprism-ecommerce-rest.XXXXXX")"
  if ! http="$(curl --connect-timeout 3 --max-time 10 --silent --show-error -o "$body_file" -w '%{http_code}' "$TARGET_URL/wp-json/wprism-commerce/v1/status")"; then
    rm -f -- "$body_file"
    fail "$label extension REST request failed"
  fi
  body="$(<"$body_file")"
  rm -f -- "$body_file"
  assert_eq 200 "$http" "$label extension REST HTTP status"
  jq -e --arg version "$expected_version" --argjson schema "$expected_schema" '.extension_version == $version and .schema == $schema and .woocommerce == true' <<<"$body" >/dev/null \
    || fail "$label extension REST status payload is not exact: $body"
  pass "$label extension REST status endpoint returned exact version/schema/Woo payload"
}
assert_replacement_rest_status() {
  local label="$1" body_file body http
  body_file="$(mktemp "${TMPDIR:-/tmp}/wprism-ecommerce-replacement-rest.XXXXXX")"
  if ! http="$(curl --connect-timeout 3 --max-time 10 --silent --show-error -o "$body_file" -w '%{http_code}' "$TARGET_URL/wp-json/wprism-commerce/v1/status")"; then
    rm -f -- "$body_file"
    fail "$label replacement REST request failed"
  fi
  body="$(<"$body_file")"
  rm -f -- "$body_file"
  assert_eq 200 "$http" "$label replacement REST HTTP status"
  jq -e '.extension_identity == "wprism-commerce-replacement" and .extension_version == "1.0.0" and .schema == 2 and .woocommerce == true' <<<"$body" >/dev/null \
    || fail "$label replacement REST status payload is not exact: $body"
  pass "$label replacement REST status identifies the distinct implementation and reviewed runtime schema"
}
assert_store_api_http() {
  local expected_cap_cents="$1" label="$2" price_body attribute_body price_negative_body attribute_negative_body
  local price_response attribute_response price_negative_response attribute_negative_response
  local price_http attribute_http price_negative_http attribute_negative_http
  if ! price_response="$(curl --connect-timeout 3 --max-time 10 --silent --show-error --get --write-out '%{http_code}' \
    "$TARGET_URL/wp-json/wc/store/v1/products" \
    --data-urlencode 'slug=wprism-grind-cap' \
    --data-urlencode 'min_price=0' \
    --data-urlencode 'max_price=100000')"; then
    fail "$label external Store API price request failed"
  fi
  price_http="${price_response: -3}"
  price_body="${price_response:0:${#price_response}-3}"
  assert_eq 200 "$price_http" "$label external Store API price HTTP status"
  if ! attribute_response="$(curl --connect-timeout 3 --max-time 10 --silent --show-error --get --write-out '%{http_code}' \
    "$TARGET_URL/wp-json/wc/store/v1/products" \
    --data-urlencode 'slug=wprism-grind-tee' \
    --data-urlencode 'attributes[0][attribute]=pa_grind-size' \
    --data-urlencode 'attributes[0][slug]=small')"; then
    fail "$label external Store API attribute request failed"
  fi
  attribute_http="${attribute_response: -3}"
  attribute_body="${attribute_response:0:${#attribute_response}-3}"
  assert_eq 200 "$attribute_http" "$label external Store API attribute HTTP status"
  if ! price_negative_response="$(curl --connect-timeout 3 --max-time 10 --silent --show-error --get --write-out '%{http_code}' \
    "$TARGET_URL/wp-json/wc/store/v1/products" \
    --data-urlencode 'slug=wprism-grind-cap' \
    --data-urlencode 'min_price=999900' \
    --data-urlencode 'max_price=1000000')"; then
    fail "$label external Store API negative-price request failed"
  fi
  price_negative_http="${price_negative_response: -3}"
  price_negative_body="${price_negative_response:0:${#price_negative_response}-3}"
  assert_eq 200 "$price_negative_http" "$label external Store API negative-price HTTP status"
  if ! attribute_negative_response="$(curl --connect-timeout 3 --max-time 10 --silent --show-error --get --write-out '%{http_code}' \
    "$TARGET_URL/wp-json/wc/store/v1/products" \
    --data-urlencode 'slug=wprism-grind-tee' \
    --data-urlencode 'attributes[0][attribute]=pa_grind-size' \
    --data-urlencode 'attributes[0][slug]=not-a-real-size')"; then
    fail "$label external Store API negative-attribute request failed"
  fi
  attribute_negative_http="${attribute_negative_response: -3}"
  attribute_negative_body="${attribute_negative_response:0:${#attribute_negative_response}-3}"
  assert_eq 200 "$attribute_negative_http" "$label external Store API negative-attribute HTTP status"
  jq -e --arg expected "$expected_cap_cents" '
    type == "array" and length == 1 and .[0].slug == "wprism-grind-cap" and .[0].prices.price == $expected
  ' <<<"$price_body" >/dev/null || fail "$label external Store API price payload is not exact: $price_body"
  jq -e '
    type == "array" and length == 1 and .[0].slug == "wprism-grind-tee"
  ' <<<"$attribute_body" >/dev/null || fail "$label external Store API attribute payload is not exact: $attribute_body"
  jq -e 'type == "array" and length == 0' <<<"$price_negative_body" >/dev/null \
    || fail "$label external Store API negative price filter leaked a product: $price_negative_body"
  jq -e 'type == "array" and length == 0' <<<"$attribute_negative_body" >/dev/null \
    || fail "$label external Store API negative attribute filter leaked a product: $attribute_negative_body"
  pass "$label external HTTP Store API resolved exact positive price/attribute filters and empty negative filters"
}
active_plugins_json() {
  target_wp option get active_plugins --format=json | jq -c 'if type == "array" then . else [to_entries | sort_by(.key | tonumber)[] | .value] end'
}
assert_trace_has() {
  local trace="$1" event="$2"
  jq -e --arg event "$event" 'index($event) != null' <<<"$trace" >/dev/null || fail "trace lacks '$event': $trace"
}
assert_ecommerce_menu() {
  local label="$1" out
  out="$(target_wp eval '
$menu = wp_get_nav_menu_object("wprism-grind-primary");
$cap = get_page_by_path("wprism-grind-cap", OBJECT, "product");
$items = $menu ? wp_get_nav_menu_items((int) $menu->term_id) : [];
$items = is_array($items) ? array_values($items) : [];
$rows = [];
foreach ($items as $item) {
    $rows[] = [
        "object" => (string) $item->object,
        "object_id" => (int) $item->object_id,
        "position" => (int) $item->menu_order,
        "title" => (string) $item->title,
        "type" => (string) $item->type,
        "url" => (string) $item->url,
    ];
}
echo wp_json_encode([
    "cap_id" => $cap ? (int) $cap->ID : 0,
    "menu_name" => $menu ? (string) $menu->name : "",
    "menu_slug" => $menu ? (string) $menu->slug : "",
    "rows" => $rows,
    "support_url" => home_url("/support/"),
], JSON_UNESCAPED_SLASHES);
')"
  jq -e '
    .cap_id > 0 and .menu_name == "WPrism Grind Primary" and .menu_slug == "wprism-grind-primary" and
    (.rows | length) == 2 and
    .rows[0].object == "product" and .rows[0].object_id == .cap_id and
    .rows[0].position == 1 and .rows[0].title == "Shop the WPrism Grind Cap" and
    .rows[0].type == "post_type" and
    .rows[1].object == "custom" and
    .rows[1].position == 2 and .rows[1].title == "WPrism Grind Support" and
    .rows[1].type == "custom" and .rows[1].url == .support_url
  ' <<<"$out" >/dev/null || fail "$label ecommerce menu did not converge: $out"
  pass "$label menu retained ordered product identity and target-bound custom URL"
}
assert_receipt() {
  local artifact="$1" label="$2" expected_code="$3" basename run_id checkpoint recomputed_hash
  [ -f "$artifact" ] || fail "$label artifact missing: $artifact"
  basename="$(basename "$artifact")"
  case "$basename" in
    deploy-*.json)
      run_id="${basename#deploy-}"
      run_id="${run_id%.json}"
      [ -n "$run_id" ] || fail "$label deploy receipt has no run identity"
      ;;
    promote-*.json)
      run_id="${basename#promote-}"
      run_id="${run_id%.json}"
      checkpoint="$OTHER_SITE/.wprism/checkpoints/promote-$run_id.sql.enc"
      [ -s "$checkpoint" ] || fail "$label receipt is not bound to a non-empty pair-local checkpoint: $checkpoint"
      ;;
    *) fail "$label artifact is not a deploy/promote receipt: $artifact" ;;
  esac
  jq -e --arg code "$expected_code" '(.artifact_hash | test("^[0-9a-f]{64}$")) and (.revision_hash | test("^[0-9a-f]{64}$")) and .code.format == "wprism-code/v1" and .code.code_revision == $code' "$artifact" >/dev/null || fail "$label artifact receipt malformed"
  recomputed_hash="$(WPRISM_CANON="$REPO_ROOT/agent/src/Kernel/Canon.php" php -r '
require getenv("WPRISM_CANON");
$payload = WPrism\Canon::decode(file_get_contents($argv[1]));
unset($payload["artifact_hash"]);
echo hash("sha256", WPrism\Canon::encode($payload));
' "$artifact")"
  assert_eq "$(jq -r '.artifact_hash' "$artifact")" "$recomputed_hash" "$label exact canonical artifact content hash"
  assert_eq "$expected_code" "$(ledger_revision)" "$label completed code revision"
  assert_eq "$(jq -r '.revision_hash' "$artifact")" "$(ledger_value applied_revision)" "$label applied state revision"
  assert_eq 0 "$(target_db_scalar "SELECT COUNT(*) FROM wp_wprism_kv WHERE k = 'promotion_lock'")" "$label released promotion lease"
}

require docker
require comm
require curl
require diff
require find
require git
require jq
require php
require sha256sum
[ -f "$WPRISM" ] || fail "host WPrism CLI missing: $WPRISM"
[ -f "$FIXTURE/v1/wp-content/plugins/$EXT_SLUG/$EXT_FILE" ] || fail "v1 extension fixture missing"
[ -f "$FIXTURE/v2/broken/$EXT_FILE" ] || fail "broken v2 extension fixture missing"
[ -f "$FIXTURE/v2/fixed/$EXT_FILE" ] || fail "fixed v2 extension fixture missing"
[ -f "$FIXTURE/replacement/fixed/$REPLACEMENT_FILE" ] || fail "fixed replacement fixture missing"
validate_artifact_library || fail "pinned artifact library is malformed"
artifact_library_jq -e --arg version "$WOO_VERSION" '.plugins.woocommerce[$version].sha256 | test("^[0-9a-f]{64}$")' >/dev/null || fail "WooCommerce $WOO_VERSION is not digest-pinned"
artifact_library_jq -e --arg version "$WOO_DOWNGRADE_VERSION" '.plugins.woocommerce[$version].sha256 | test("^[0-9a-f]{64}$")' >/dev/null || fail "WooCommerce $WOO_DOWNGRADE_VERSION is not digest-pinned"
artifact_library_jq -e --arg version "$ACF_VERSION" '.plugins["advanced-custom-fields"][$version].sha256 | test("^[0-9a-f]{64}$")' >/dev/null || fail "ACF $ACF_VERSION is not digest-pinned"
# pair.sh owns the host's locked, dynamic capacity gate. Do not pre-enumerate
# other agents' pairs here: a zero-other-pairs rule needlessly serializes a
# distributed run, and an unlocked check would race the authoritative budget
# reservation inside pair.sh up.
PAIR_CONTAINERS="$(docker ps -aq --filter "label=com.docker.compose.project=wprism-$PAIR" 2>/dev/null || true)"
PAIR_VOLUMES="$(docker volume ls -q --filter "label=com.docker.compose.project=wprism-$PAIR" 2>/dev/null || true)"
PAIR_NETWORKS="$(docker network ls -q --filter "label=com.docker.compose.project=wprism-$PAIR" 2>/dev/null || true)"
[ -z "$PAIR_CONTAINERS$PAIR_VOLUMES$PAIR_NETWORKS" ] \
  || fail "refusing to reuse existing Docker resources for wprism-$PAIR"
if docker inspect wprism-shared-db >/dev/null 2>&1; then
  if ! PAIR_DATABASES="$(docker exec -e MYSQL_PWD=root wprism-shared-db mariadb -uroot -N -B --raw -e "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME IN ('wp_${PAIR}1','wp_${PAIR}2')" 2>/dev/null)"; then
    fail "cannot verify that pair databases for $PAIR are absent"
  fi
  [ -z "$PAIR_DATABASES" ] || fail "refusing to reuse existing pair database(s): $PAIR_DATABASES"
fi

say "clean room: pair.sh HTTP pair $PAIR (side 1 author, side 2 target)"
PAIR_PATHS_OWNED=1
PAIR_UP=1
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --journal --http

say "host env registry: target is clean side-2 WordPress service"
jq -n --arg compose "$(pwd)/pair.yml" '{envs: {target: {transport: "docker", compose_file: $compose, service: "cli2", repo_path: "/siterepo"}}}' > "$ENVS_FILE"

say "install exact WooCommerce artifact on both sides (cache first; never bare latest)"
. bin/fetch-artifact.sh
"${PAIR_COMPOSE[@]}" run --rm -T -u root cli1 sh -c 'command -v unzip >/dev/null' \
  || fail "pair cli image lacks unzip required for the pinned downgrade fixture"
WOO_ARTIFACT_1="$(fetch_artifact "$WOO_SLUG" "$WOO_VERSION" cli1)"
WOO_ARTIFACT_2="$(fetch_artifact "$WOO_SLUG" "$WOO_VERSION" cli2)"
WOO_1094_ARTIFACT="$(fetch_artifact "$WOO_SLUG" "$WOO_DOWNGRADE_VERSION" cli1)"
ACF_ARTIFACT_1="$(fetch_artifact "$ACF_SLUG" "$ACF_VERSION" cli1)"
ACF_ARTIFACT_2="$(fetch_artifact "$ACF_SLUG" "$ACF_VERSION" cli2)"
source_wp plugin install "$WOO_ARTIFACT_1" --activate >/dev/null
target_wp plugin install "$WOO_ARTIFACT_2" >/dev/null
source_wp plugin install "$ACF_ARTIFACT_1" --activate >/dev/null
target_wp plugin install "$ACF_ARTIFACT_2" >/dev/null
assert_eq "$WOO_VERSION" "$(source_wp plugin get "$WOO_SLUG" --field=version)" "source Woo version"
assert_eq "$WOO_VERSION" "$(target_wp plugin get "$WOO_SLUG" --field=version)" "target Woo version"
assert_eq "$ACF_VERSION" "$(source_wp plugin get "$ACF_SLUG" --field=version)" "source ACF version"
assert_eq "$ACF_VERSION" "$(target_wp plugin get "$ACF_SLUG" --field=version)" "target ACF version"
source_wp wc hpos enable >/dev/null
pass "WooCommerce $WOO_VERSION and ACF $ACF_VERSION installed from independently verified cache artifacts; only author extensions are active"

say "seed synthetic author catalog/config plus source-only runtime probes"
CAT_ID="$(source_wp term create product_cat 'WPrism Grind Widgets' --slug=wprism-grind-widgets --porcelain)"
cat > "$SITE/.tmp-make-commerce-media.php" <<'PHPEOF'
<?php
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
file_put_contents('/siterepo/wprism-commerce-widget.png', $png);
PHPEOF
source_wp eval-file /siterepo/.tmp-make-commerce-media.php >/dev/null
MEDIA_ID="$(source_wp media import /siterepo/wprism-commerce-widget.png --title='WPrism Grind Widget Image' --porcelain)"
source_wp term meta update "$CAT_ID" thumbnail_id "$MEDIA_ID" >/dev/null
SIZE_ATTR_ID="$(source_wp wc product_attribute create --name='Grind Size' --slug=grind-size --type=select --order_by=menu_order --has_archives=false --porcelain --user=admin)"
COLOR_ATTR_ID="$(source_wp wc product_attribute create --name='Grind Color' --slug=grind-color --type=select --order_by=menu_order --has_archives=false --porcelain --user=admin)"
source_wp wc product_attribute_term create "$SIZE_ATTR_ID" --name=Small --user=admin >/dev/null
source_wp wc product_attribute_term create "$SIZE_ATTR_ID" --name=Large --user=admin >/dev/null
source_wp wc product_attribute_term create "$COLOR_ATTR_ID" --name=Red --user=admin >/dev/null
source_wp wc product_attribute_term create "$COLOR_ATTR_ID" --name=Blue --user=admin >/dev/null
MUG_ID="$(source_wp wc product create --name='WPrism Grind Mug' --slug=wprism-grind-mug --type=simple --regular_price=9.99 --sku=GRIND-MUG --status=publish --user=admin --porcelain)"
CAP_ID="$(source_wp wc product create --name='WPrism Grind Cap' --slug=wprism-grind-cap --type=simple --regular_price=14.99 --sku=GRIND-CAP --manage_stock=true --status=publish --user=admin --porcelain)"
TEE_ID="$(source_wp wc product create --name='WPrism Grind Tee' --slug=wprism-grind-tee --type=variable --attributes="[{\"id\":$SIZE_ATTR_ID,\"variation\":true,\"visible\":true,\"options\":[\"Small\",\"Large\"]},{\"id\":$COLOR_ATTR_ID,\"variation\":true,\"visible\":true,\"options\":[\"Red\",\"Blue\"]}]" --status=publish --user=admin --porcelain)"
source_wp wc product_variation create "$TEE_ID" --attributes="[{\"id\":$SIZE_ATTR_ID,\"option\":\"Small\"},{\"id\":$COLOR_ATTR_ID,\"option\":\"Red\"}]" --regular_price=19.99 --sku=GRIND-TEE-S-RED --manage_stock=true --stock_quantity=10 --user=admin --porcelain >/dev/null
source_wp wc product_variation create "$TEE_ID" --attributes="[{\"id\":$SIZE_ATTR_ID,\"option\":\"Large\"},{\"id\":$COLOR_ATTR_ID,\"option\":\"Blue\"}]" --regular_price=21.99 --sale_price=18.99 --sku=GRIND-TEE-L-BLUE --manage_stock=true --stock_quantity=8 --user=admin --porcelain >/dev/null
source_wp wc product_variation create "$TEE_ID" --attributes="[{\"id\":$SIZE_ATTR_ID,\"option\":\"Small\"},{\"id\":$COLOR_ATTR_ID,\"option\":\"Blue\"}]" --regular_price=20.99 --sku=GRIND-TEE-S-BLUE --manage_stock=true --stock_quantity=7 --user=admin --porcelain >/dev/null
source_wp wc product_variation create "$TEE_ID" --attributes="[{\"id\":$SIZE_ATTR_ID,\"option\":\"Large\"},{\"id\":$COLOR_ATTR_ID,\"option\":\"Red\"}]" --regular_price=22.99 --sku=GRIND-TEE-L-RED --manage_stock=true --stock_quantity=6 --user=admin --porcelain >/dev/null
DELETION_PROBE_SOURCE_ID="$(source_wp wc product create --name='WPrism Grind Deletion Probe' --slug=wprism-grind-delete-probe --type=simple --regular_price=5.55 --sku=GRIND-DELETE-PROBE --attributes="[{\"id\":$SIZE_ATTR_ID,\"variation\":false,\"visible\":true,\"options\":[\"Small\"]}]" --status=publish --user=admin --porcelain)"
source_wp eval "
\$probe = wc_get_product($DELETION_PROBE_SOURCE_ID);
\$term = get_term_by('slug', 'small', 'pa_grind-size');
if (!\$probe || !\$term) { throw new RuntimeException('deletion probe global attribute seed could not resolve the Small term'); }
\$attribute = new WC_Product_Attribute();
\$attribute->set_id($SIZE_ATTR_ID);
\$attribute->set_name('pa_grind-size');
\$attribute->set_options([(int) \$term->term_id]);
\$attribute->set_visible(true);
\$attribute->set_variation(false);
\$probe->set_attributes(['pa_grind-size' => \$attribute]);
\$probe->save();
" >/dev/null
source_wp post term add "$MUG_ID" product_cat wprism-grind-widgets --by=slug >/dev/null
source_wp post term add "$CAP_ID" product_cat wprism-grind-widgets --by=slug >/dev/null
source_wp post term add "$TEE_ID" product_cat wprism-grind-widgets --by=slug >/dev/null
source_wp post term add "$DELETION_PROBE_SOURCE_ID" product_cat wprism-grind-widgets --by=slug >/dev/null
TAG_ID="$(source_wp term create product_tag 'WPrism Grind Featured' --slug=wprism-grind-featured --porcelain)"
source_wp post term add "$CAP_ID" product_tag wprism-grind-featured --by=slug >/dev/null
GROUP_ID="$(source_wp wc product create --name='WPrism Grind Bundle' --slug=wprism-grind-bundle --type=grouped --status=publish --user=admin --porcelain)"
source_wp eval "update_post_meta($GROUP_ID, '_children', [$MUG_ID, $CAP_ID]);" >/dev/null
COUPON_ID="$(source_wp wc shop_coupon create --code=WPRISM-GRIND10 --discount_type=percent --amount=10 --product_ids="$CAP_ID" --product_categories="$CAT_ID" --usage_limit=25 --minimum_amount=10.00 --free_shipping=true --date_expires=2027-06-30T00:00:00 --status=publish --user=admin --porcelain)"
MENU_ID="$(source_wp menu create 'WPrism Grind Primary' --porcelain)"
MENU_PRODUCT_ITEM_ID="$(source_wp menu item add-post "$MENU_ID" "$CAP_ID" --title='Shop the WPrism Grind Cap' --position=1 --porcelain)"
SOURCE_HOME="$(source_wp option get home)"
MENU_CUSTOM_ITEM_ID="$(source_wp menu item add-custom "$MENU_ID" 'WPrism Grind Support' "${SOURCE_HOME%/}/support/" --position=2 --porcelain)"
[[ "$MENU_ID" =~ ^[0-9]+$ ]] || fail "source menu id is not numeric: $MENU_ID"
[[ "$MENU_PRODUCT_ITEM_ID" =~ ^[0-9]+$ ]] || fail "source product menu-item id is not numeric: $MENU_PRODUCT_ITEM_ID"
[[ "$MENU_CUSTOM_ITEM_ID" =~ ^[0-9]+$ ]] || fail "source custom menu-item id is not numeric: $MENU_CUSTOM_ITEM_ID"
cat > "$SITE/.tmp-seed-acf-commerce.php" <<PHP
<?php
if (!function_exists('acf_update_field_group')) {
    throw new RuntimeException('ACF 6.8.7 API is not active on the author side');
}
acf_update_field_group([
    'key' => 'group_wprism_commerce_catalog',
    'title' => 'WPrism Commerce Catalog',
    'fields' => [],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]],
    'menu_order' => 0,
    'position' => 'normal',
    'style' => 'default',
    'label_placement' => 'top',
    'instruction_placement' => 'label',
    'active' => true,
]);
\$group_posts = get_posts(['post_type' => 'acf-field-group', 'name' => 'group_wprism_commerce_catalog', 'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'any']);
\$group_id = \$group_posts ? (int) \$group_posts[0] : 0;
if (!\$group_id) { throw new RuntimeException('ACF commerce field group was not created'); }
acf_update_field([
    'key' => 'field_wprism_inventory_note',
    'label' => 'Inventory Note',
    'name' => 'wprism_inventory_note',
    'type' => 'text',
    'parent' => \$group_id,
]);
update_field('wprism_inventory_note', 'managed-stock', $CAP_ID);
echo json_encode(['group' => \$group_id, 'product' => $CAP_ID]) . "\\n";
PHP
ACF_SEED_JSON="$(source_wp eval-file /siterepo/.tmp-seed-acf-commerce.php)"
rm -f -- "$SITE/.tmp-seed-acf-commerce.php"
assert_acf_schema source 'source author seed'
source_wp option update woocommerce_calc_taxes yes >/dev/null
source_wp option update --format=json woocommerce_cod_settings \
  '{"enabled":"yes","title":"WPrism Grind COD Desk","description":"Pay at the WPrism Grind desk.","instructions":"Use code GRIND-COD-7 at pickup.","enable_for_methods":[],"enable_for_virtual":"yes"}' >/dev/null
SOURCE_COD_SETTINGS="$(source_wp eval '
$settings = (array) get_option("woocommerce_cod_settings", []);
$gateways = WC()->payment_gateways()->get_available_payment_gateways();
$cod = $gateways["cod"] ?? null;
$methods = $settings["enable_for_methods"] ?? [];
if (!is_array($methods)) { $methods = ["__invalid__"]; }
echo json_encode(["calc_taxes" => (string) get_option("woocommerce_calc_taxes", ""), "enabled" => (string) ($settings["enabled"] ?? ""), "title" => (string) ($settings["title"] ?? ""), "description" => (string) ($settings["description"] ?? ""), "instructions" => (string) ($settings["instructions"] ?? ""), "enable_for_methods" => array_values($methods), "enable_for_virtual" => (string) ($settings["enable_for_virtual"] ?? ""), "gateway_enabled" => $cod ? (string) $cod->enabled : "missing"], JSON_UNESCAPED_SLASHES);
')"
jq -e '.calc_taxes == "yes" and .enabled == "yes" and .title == "WPrism Grind COD Desk" and .description == "Pay at the WPrism Grind desk." and .instructions == "Use code GRIND-COD-7 at pickup." and .enable_for_methods == [] and .enable_for_virtual == "yes" and .gateway_enabled == "yes"' <<<"$SOURCE_COD_SETTINGS" >/dev/null || fail "source COD merchant setting did not reach Woo's gateway API: $SOURCE_COD_SETTINGS"
source_wp option update woocommerce_currency USD >/dev/null
source_wp option update woocommerce_default_country US:CA >/dev/null
source_wp option update woocommerce_allowed_countries specific >/dev/null
source_wp option update woocommerce_store_address '100 Demo Way' >/dev/null
source_wp option update woocommerce_store_city 'Testville' >/dev/null
source_wp option update woocommerce_store_postcode '90210' >/dev/null
ZONE_ID="$(source_wp wc shipping_zone create --name='WPrism Grind United States' --order=1 --user=admin --porcelain)"
source_wp eval "\$z = new WC_Shipping_Zone($ZONE_ID); \$z->add_location('US', 'country'); \$z->save();" >/dev/null
FLAT_INSTANCE="$(source_wp wc shipping_zone_method create "$ZONE_ID" --method_id=flat_rate --enabled=true --order=1 --user=admin --porcelain)"
FREE_INSTANCE="$(source_wp wc shipping_zone_method create "$ZONE_ID" --method_id=free_shipping --enabled=true --order=2 --user=admin --porcelain)"
source_wp eval "\$flat = WC_Shipping_Zones::get_shipping_method($FLAT_INSTANCE); \$flat->instance_settings['title'] = 'WPrism Grind Flat Rate'; \$flat->instance_settings['cost'] = '5.99'; \$flat->instance_settings['tax_status'] = 'taxable'; update_option(\$flat->get_instance_option_key(), \$flat->instance_settings); \$free = WC_Shipping_Zones::get_shipping_method($FREE_INSTANCE); \$free->instance_settings['title'] = 'WPrism Grind Free Shipping'; \$free->instance_settings['requires'] = 'min_amount'; \$free->instance_settings['min_amount'] = '50.00'; update_option(\$free->get_instance_option_key(), \$free->instance_settings);" >/dev/null
TAX_ID="$(source_wp wc tax create --country=US --state=CA --rate=7.2500 --name='WPrism Grind CA Sales Tax' --priority=1 --shipping=true --order=1 --class=standard --porcelain --user=admin)"
SOURCE_RUNTIME_CUSTOMER_ID="$(source_wp eval '
$customer = wc_create_new_customer("source-customer@example.invalid", "source-runtime", "source-runtime-password", ["first_name" => "Source", "last_name" => "Runtime"]);
if (is_wp_error($customer)) { throw new RuntimeException("source runtime customer seed failed: " . $customer->get_error_message()); }
echo (int) $customer;
')"
[[ "$SOURCE_RUNTIME_CUSTOMER_ID" =~ ^[0-9]+$ ]] || fail "source runtime customer id is not numeric: $SOURCE_RUNTIME_CUSTOMER_ID"
SOURCE_RUNTIME_ORDER_ID="$(source_wp eval '
$product = wc_get_product('"$CAP_ID"');
if (!$product) { throw new RuntimeException("source runtime order product missing"); }
$order = wc_create_order(["customer_id" => '"$SOURCE_RUNTIME_CUSTOMER_ID"']);
$order->set_billing_email("source-order@example.invalid");
$order->add_product($product, 1);
$order->calculate_totals();
$order->save();
echo (int) $order->get_id();
')"
[[ "$SOURCE_RUNTIME_ORDER_ID" =~ ^[0-9]+$ ]] || fail "source runtime order id is not numeric: $SOURCE_RUNTIME_ORDER_ID"
SOURCE_RUNTIME_CUSTOMER_BASELINE="$(source_runtime_customer_snapshot "$SOURCE_RUNTIME_CUSTOMER_ID")"
SOURCE_RUNTIME_ORDER_BASELINE="$(source_runtime_order_snapshot "$SOURCE_RUNTIME_ORDER_ID")"
source_wp eval 'if (count(wc_get_orders(["limit" => -1, "return" => "ids"])) !== 1) { throw new RuntimeException("fixture must contain exactly one source-only runtime order"); }' >/dev/null
rm -f -- "$SITE/.tmp-make-commerce-media.php"
rm -f -- "$SITE/wprism-commerce-widget.png"
pass "catalog seeded: category=$CAT_ID image=$MEDIA_ID products=$MUG_ID,$CAP_ID,$TEE_ID,$GROUP_ID coupon=$COUPON_ID menu=$MENU_ID attrs=$SIZE_ATTR_ID,$COLOR_ATTR_ID zone=$ZONE_ID methods=$FLAT_INSTANCE,$FREE_INSTANCE tax=$TAX_ID; source-only customer=$SOURCE_RUNTIME_CUSTOMER_ID order=$SOURCE_RUNTIME_ORDER_ID are runtime-only"

say "initialize repository policy and perform initial state-only capture"
git init --bare -b main "$ORIGIN" >/dev/null
mkdir -p "$SITE"
cat > "$SITE/site.wprism.json" <<'JSON'
{
  "manifests": ["core", "woocommerce", "acf"],
  "policy": {
    "options": {
      "wprism_commerce_extension_activations": {"class": "runtime"},
      "wprism_commerce_extension_deactivations": {"class": "runtime"},
      "wprism_commerce_extension_gateway_secret": {"class": "env", "required": true},
      "wprism_commerce_extension_schema": {"class": "runtime"},
      "wprism_commerce_extension_settings": {"class": "authored", "autoload": "preserve"},
      "wprism_commerce_extension_trace": {"class": "runtime"}
    },
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon", "acf-field-group", "acf-field"],
    "taxonomies": ["category", "post_tag", "product_cat", "product_tag", "product_type"],
    "term_meta": {}
  },
  "spec_version": 2
}
JSON
cp site-repo.gitignore.template "$SITE/.gitignore"
printf '\n# WPrism operational promotion receipts never belong to the branchable repo.\n.wprism/\n' >> "$SITE/.gitignore"
git -C "$SITE" init -q -b main
git -C "$SITE" remote add origin "../origin-${PAIR}.git"
git -C "$SITE" add site.wprism.json .gitignore
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'policy: WooCommerce ecommerce clean-room'
git -C "$SITE" push -qu origin main
source_wp wprism capture --repo=/siterepo >/dev/null
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'capture: state-only synthetic catalog'
git -C "$SITE" push -qu origin main
[ ! -d "$SITE/code" ] || fail "initial state-only capture unexpectedly materialized code"
assert_runtime_state_excluded 'initial state-only capture'
assert_eq "$SOURCE_RUNTIME_CUSTOMER_BASELINE" "$(source_runtime_customer_snapshot "$SOURCE_RUNTIME_CUSTOMER_ID")" 'initial state-only capture exact source customer baseline'
assert_eq "$SOURCE_RUNTIME_ORDER_BASELINE" "$(source_runtime_order_snapshot "$SOURCE_RUNTIME_ORDER_ID")" 'initial state-only capture exact source order baseline'
pass "initial capture contains WooCommerce catalog/config state only; source-only order/customer and secrets remain excluded"

assert_woo_catalog() {
  local out
  local expected_orders="${1:-0}"
  local expected_products="${2:-5}"
  out="$(target_wp eval '
global $wpdb;
$products = get_posts(["post_type" => "product", "post_status" => "any", "posts_per_page" => -1, "fields" => "ids"]);
$variations = get_posts(["post_type" => "product_variation", "post_status" => "any", "posts_per_page" => -1, "fields" => "ids"]);
$coupons = get_posts(["post_type" => "shop_coupon", "post_status" => "any", "posts_per_page" => -1, "fields" => "ids"]);
$attrs = array_values(array_map(static fn($a) => $a->attribute_name, wc_get_attribute_taxonomies()));
sort($attrs);
$zones = array_values(array_map(static fn($z) => $z["zone_name"], WC_Shipping_Zones::get_zones()));
$rates = WC_Tax::find_rates(["country" => "US", "state" => "CA"]);
$media = get_posts(["post_type" => "attachment", "posts_per_page" => -1, "fields" => "ids"]);
$orders = wc_get_orders(["limit" => -1, "return" => "ids"]);
$currency = (string) get_option("woocommerce_currency", "");
$default_country = (string) get_option("woocommerce_default_country", "");
$allowed_countries = (string) get_option("woocommerce_allowed_countries", "");
$store_address = (string) get_option("woocommerce_store_address", "");
$store_city = (string) get_option("woocommerce_store_city", "");
$store_postcode = (string) get_option("woocommerce_store_postcode", "");
$store_exact = $currency === "USD" && $default_country === "US:CA" && $allowed_countries === "specific"
    && $store_address === "100 Demo Way" && $store_city === "Testville" && $store_postcode === "90210";
$cap = get_page_by_path("wprism-grind-cap", OBJECT, "product");
$mug = get_page_by_path("wprism-grind-mug", OBJECT, "product");
$bundle = get_page_by_path("wprism-grind-bundle", OBJECT, "product");
$categories = $cap ? array_values((array) wp_get_post_terms((int) $cap->ID, "product_cat", ["fields" => "slugs"])) : [];
$tags = $cap ? array_values((array) wp_get_post_terms((int) $cap->ID, "product_tag", ["fields" => "slugs"])) : [];
sort($categories, SORT_STRING);
sort($tags, SORT_STRING);
$category = get_term_by("slug", "wprism-grind-widgets", "product_cat");
$category_id = $category ? (int) $category->term_id : 0;
$thumbnail_id = $category_id ? (int) get_term_meta($category_id, "thumbnail_id", true) : 0;
$thumbnail = $thumbnail_id ? get_post($thumbnail_id) : null;
$thumbnail_file = $thumbnail_id ? (string) get_attached_file($thumbnail_id) : "";
$thumbnail_hash = $thumbnail_file !== "" && is_file($thumbnail_file) ? (string) hash_file("sha256", $thumbnail_file) : "";
$thumbnail_exact = $thumbnail && $thumbnail->post_type === "attachment"
    && (string) $thumbnail->post_title === "WPrism Grind Widget Image"
    && (string) $thumbnail->post_mime_type === "image/png"
    && $thumbnail_hash === "431ced6916a2a21a156e38701afe55bbd7f88969fbbfc56d7fe099d47f265460";
$bundle_children = [];
if ($bundle) {
    $bundle_product = wc_get_product((int) $bundle->ID);
    $bundle_children = $bundle_product ? array_values(array_unique(array_map("absint", (array) $bundle_product->get_children()))) : [];
}
sort($bundle_children, SORT_NUMERIC);
$expected_bundle_children = [$mug ? (int) $mug->ID : 0, $cap ? (int) $cap->ID : 0];
sort($expected_bundle_children, SORT_NUMERIC);
$bundle_children_exact = $bundle && $mug && $cap && $bundle_children === $expected_bundle_children;
$coupon_id = function_exists("wc_get_coupon_id_by_code") ? (int) wc_get_coupon_id_by_code("WPRISM-GRIND10") : 0;
$coupon = $coupon_id ? new WC_Coupon($coupon_id) : null;
$coupon_products = $coupon ? array_values(array_unique(array_map("absint", (array) $coupon->get_product_ids()))) : [];
$coupon_categories = $coupon ? array_values(array_unique(array_map("absint", (array) $coupon->get_product_categories()))) : [];
sort($coupon_products, SORT_NUMERIC);
sort($coupon_categories, SORT_NUMERIC);
$coupon_expiry_date = $coupon ? $coupon->get_date_expires() : null;
$coupon_expiry = $coupon_expiry_date ? $coupon_expiry_date->format("Y-m-d") : "";
$coupon_exact = $coupon && strtoupper((string) $coupon->get_code()) === "WPRISM-GRIND10"
    && (string) $coupon->get_discount_type() === "percent"
    && (float) $coupon->get_amount() === 10.0
    && $coupon_products === [$cap ? (int) $cap->ID : 0]
    && $coupon_categories === [$category_id]
    && (int) $coupon->get_usage_limit() === 25
    && (float) $coupon->get_minimum_amount() === 10.0
    && (bool) $coupon->get_free_shipping()
    && $coupon_expiry === "2027-06-30";
$zone_id = (int) $wpdb->get_var($wpdb->prepare("SELECT zone_id FROM {$wpdb->prefix}woocommerce_shipping_zones WHERE zone_name = %s LIMIT 1", "WPrism Grind United States"));
$shipping_methods = $zone_id ? array_values(array_map("strval", (array) $wpdb->get_col($wpdb->prepare("SELECT method_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE zone_id = %d ORDER BY method_order", $zone_id)))) : [];
$zone_locations = [];
$shipping_settings = [];
if ($zone_id) {
    $zone = new WC_Shipping_Zone($zone_id);
    foreach ((array) $zone->get_zone_locations() as $location) {
        $zone_locations[] = [
            "code" => (string) (is_object($location) ? ($location->code ?? "") : ($location["code"] ?? "")),
            "type" => (string) (is_object($location) ? ($location->type ?? "") : ($location["type"] ?? "")),
        ];
    }
    usort($zone_locations, static fn(array $a, array $b): int => strcmp($a["type"] . ":" . $a["code"], $b["type"] . ":" . $b["code"]));
    foreach ((array) $zone->get_shipping_methods(false) as $method) {
        $shipping_settings[(string) $method->id] = [
            "enabled" => (string) ($method->enabled ?? ""),
            "title" => (string) $method->get_option("title"),
            "cost" => (string) $method->get_option("cost"),
            "tax_status" => (string) $method->get_option("tax_status"),
            "requires" => (string) $method->get_option("requires"),
            "min_amount" => (string) $method->get_option("min_amount"),
        ];
    }
}
ksort($shipping_settings, SORT_STRING);
$expected_shipping_settings = [
    "flat_rate" => ["enabled" => "yes", "title" => "WPrism Grind Flat Rate", "cost" => "5.99", "tax_status" => "taxable", "requires" => "", "min_amount" => ""],
    "free_shipping" => ["enabled" => "yes", "title" => "WPrism Grind Free Shipping", "cost" => "", "tax_status" => "", "requires" => "min_amount", "min_amount" => "50.00"],
];
$shipping_exact = $zone_locations === [["code" => "US", "type" => "country"]] && $shipping_settings === $expected_shipping_settings;
$tax_table = $wpdb->prefix . "woocommerce_tax_rates";
$tax_row = (array) $wpdb->get_row($wpdb->prepare("SELECT tax_rate_country, tax_rate_state, tax_rate, tax_rate_name, tax_rate_priority, tax_rate_shipping, tax_rate_order, tax_rate_class FROM `$tax_table` WHERE tax_rate_name = %s LIMIT 1", "WPrism Grind CA Sales Tax"), ARRAY_A);
$tax_exact = count($rates) === 1 && $tax_row
    && (string) ($tax_row["tax_rate_country"] ?? "") === "US"
    && (string) ($tax_row["tax_rate_state"] ?? "") === "CA"
    && (float) ($tax_row["tax_rate"] ?? 0) === 7.25
    && (string) ($tax_row["tax_rate_name"] ?? "") === "WPrism Grind CA Sales Tax"
    && (int) ($tax_row["tax_rate_priority"] ?? 0) === 1
    && (int) ($tax_row["tax_rate_shipping"] ?? 0) === 1
    && (int) ($tax_row["tax_rate_order"] ?? 0) === 1
    && (string) ($tax_row["tax_rate_class"] ?? "") === "";
$acf_note = function_exists("get_field") && $cap ? get_field("wprism_inventory_note", $cap->ID) : null;
$cod_settings = (array) get_option("woocommerce_cod_settings", []);
$cod_methods = $cod_settings["enable_for_methods"] ?? [];
if (!is_array($cod_methods)) {
    $cod_methods = ["__invalid__"];
}
$cod_methods = array_values(array_map("strval", $cod_methods));
sort($cod_methods, SORT_STRING);
$gateways = function_exists("WC") && WC() && WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : [];
$cod = $gateways["cod"] ?? null;
echo json_encode([
    "products" => count($products), "variations" => count($variations),
    "coupons" => count($coupons), "attrs" => $attrs, "zones" => $zones,
    "categories" => $categories, "shipping_methods" => $shipping_methods,
    "tax_rates" => count($rates), "media" => count($media), "orders" => count($orders),
    "tags" => $tags, "acf_note" => $acf_note,
    "calc_taxes" => (string) get_option("woocommerce_calc_taxes", ""),
    "currency" => $currency, "default_country" => $default_country,
    "allowed_countries" => $allowed_countries, "store_address" => $store_address,
    "store_city" => $store_city, "store_postcode" => $store_postcode,
    "store_exact" => $store_exact,
    "category_id" => $category_id, "thumbnail_id" => $thumbnail_id,
    "thumbnail_file" => $thumbnail_file, "thumbnail_hash" => $thumbnail_hash,
    "thumbnail_exact" => $thumbnail_exact,
    "bundle_children" => $bundle_children, "expected_bundle_children" => $expected_bundle_children,
    "bundle_children_exact" => $bundle_children_exact,
    "coupon_id" => $coupon_id, "coupon_products" => $coupon_products,
    "coupon_categories" => $coupon_categories, "coupon_expiry" => $coupon_expiry,
    "coupon_exact" => $coupon_exact,
    "zone_id" => $zone_id, "zone_locations" => $zone_locations,
    "shipping_settings" => $shipping_settings, "expected_shipping_settings" => $expected_shipping_settings,
    "shipping_exact" => $shipping_exact,
    "tax_row" => $tax_row, "tax_exact" => $tax_exact,
    "cod_enabled" => (string) ($cod_settings["enabled"] ?? ""),
    "cod_title" => (string) ($cod_settings["title"] ?? ""),
    "cod_description" => (string) ($cod_settings["description"] ?? ""),
    "cod_instructions" => (string) ($cod_settings["instructions"] ?? ""),
    "cod_enable_for_methods" => $cod_methods,
    "cod_enable_for_virtual" => (string) ($cod_settings["enable_for_virtual"] ?? ""),
    "cod_gateway_enabled" => $cod ? (string) $cod->enabled : "missing",
    "cod_gateway_title" => $cod ? (string) $cod->title : "missing",
], JSON_UNESCAPED_SLASHES);
')"
  echo "catalog acceptance: $out"
  jq -e --argjson expected "$expected_orders" --argjson expected_products "$expected_products" '
    .products == $expected_products and .variations == 4 and .coupons == 1 and
    .attrs == ["grind-color", "grind-size"] and .zones == ["WPrism Grind United States"] and
    .categories == ["wprism-grind-widgets", "uncategorized"] and .tags == ["wprism-grind-featured"] and
    .shipping_methods == ["flat_rate", "free_shipping"] and .tax_rates == 1 and .media == 2 and
    .orders == $expected and .acf_note == "managed-stock" and .calc_taxes == "yes" and
    .store_exact == true and .thumbnail_exact == true and .bundle_children_exact == true and
    .coupon_exact == true and .shipping_exact == true and .tax_exact == true and
    .cod_enabled == "yes" and .cod_title == "WPrism Grind COD Desk" and
    .cod_description == "Pay at the WPrism Grind desk." and
    .cod_instructions == "Use code GRIND-COD-7 at pickup." and
    .cod_enable_for_methods == [] and .cod_enable_for_virtual == "yes" and
    .cod_gateway_enabled == "yes" and .cod_gateway_title == "WPrism Grind COD Desk"
  ' <<<"$out" >/dev/null || fail "catalog/config/runtime policy acceptance failed: $out"
  pass "products, variations, categories, global attributes, coupon, shipping, tax, media and Woo config round-tripped; orders remain runtime-excluded"
}

manual_regenerate_attribute_lookup() {
  target_wp eval '
$ids = wc_get_products(["return" => "ids", "limit" => 65]);
if (!is_array($ids) || count($ids) > 64) {
    throw new RuntimeException("manual attribute fixture exceeds the 64-product synchronous bound");
}
$regenerator = wc_get_container()->get(Automattic\WooCommerce\Internal\ProductAttributesLookup\DataRegenerator::class);
$last = $regenerator->initiate_regeneration(false);
if ($last > 0 && $regenerator->do_regeneration_step(64, false)) {
    $regenerator->finalize_regeneration(false);
    throw new RuntimeException("manual attribute fixture did not converge in one bounded step");
}
if ($last > 0) {
    $regenerator->finalize_regeneration(true);
}
' >/dev/null
}

assert_deletion_probe_lookup_present() {
  local id="$1" meta_rows attribute_rows
  # issue #3411: this table is outside WPrism's verified provider authority. Build
  # the fixture through Woo's own explicit whole-table maintenance boundary
  # before auditing it; none of the assertions below are evidence of WPrism repair.
  manual_regenerate_attribute_lookup
  [[ "$id" =~ ^[0-9]+$ ]] || fail "deletion probe target id is not numeric: $id"
  assert_eq "$id" "$(target_wp post list --post_type=product --name=wprism-grind-delete-probe --field=ID)" "deletion probe target product id"
  meta_rows="$(target_db_scalar "SELECT COUNT(*) FROM wp_wc_product_meta_lookup WHERE product_id = $id AND sku = 'GRIND-DELETE-PROBE'")"
  attribute_rows="$(target_db_scalar "SELECT COUNT(*) FROM wp_wc_product_attributes_lookup WHERE (product_id = $id OR product_or_parent_id = $id) AND taxonomy = 'pa_grind-size'")"
  [ "$meta_rows" -ge 1 ] || fail "deletion probe has no wc_product_meta_lookup row for target id $id"
  [ "$attribute_rows" -ge 1 ] || fail "deletion probe has no wc_product_attributes_lookup row for target id $id"
  pass "deletion probe target id $id has Woo meta and global-attribute lookup rows"
}

# Woo's nine core identities are authored inventory, while provider v3 mixes
# merchant featured/catalog intent with target-native stock/rating projection.
assert_product_visibility_projection() {
  local slugs state_count pos_registered visibility_state
  slugs="$(target_wp eval '
$terms = get_terms(["taxonomy" => "product_visibility", "hide_empty" => false]);
if (is_wp_error($terms)) { throw new RuntimeException($terms->get_error_message()); }
$slugs = array_map(static fn($term): string => (string) $term->slug, $terms);
sort($slugs, SORT_STRING);
echo wp_json_encode($slugs);
')"
  assert_eq '["exclude-from-catalog","exclude-from-search","featured","outofstock","rated-1","rated-2","rated-3","rated-4","rated-5"]' "$slugs" "exact Woo product_visibility target inventory"
  pos_registered="$(target_wp eval 'echo taxonomy_exists("pos_product_visibility") ? "yes" : "no";')"
  assert_eq yes "$pos_registered" "Woo POS visibility taxonomy registration"
  visibility_state="$OTHER_SITE/state/terms/product_visibility"
  [ -d "$visibility_state" ] || fail "canonical product_visibility authored inventory is missing"
  state_count="$(find "$visibility_state" -maxdepth 1 -type f -name '*.json' | wc -l | tr -d ' ')"
  assert_eq 9 "$state_count" "canonical product_visibility authored term count"
  pass "product visibility keeps exact authored identities and target-native mixed projection"
}

assert_theme_and_dependency() {
  local expected_active="${1:-$AUTHORED_ACTIVE_PLUGINS_JSON}"
  assert_eq "$CHILD_THEME" "$(target_wp option get stylesheet)" "active child theme"
  assert_eq "$PARENT_THEME" "$(target_wp option get template)" "active parent theme"
  target_wp plugin is-active "$WOO_SLUG" >/dev/null || fail "WooCommerce is inactive"
  target_wp plugin is-active "$ACF_SLUG" >/dev/null || fail "ACF is inactive"
  target_wp plugin is-active "$EXT_SLUG" >/dev/null || fail "WPrism Commerce Extension is inactive"
  assert_eq "$expected_active" "$(active_plugins_json)" "exact authored active_plugins order"
  target_wp eval 'if (!class_exists("WooCommerce")) { exit(1); } if (!function_exists("woocommerce_content")) { exit(1); }' || fail "custom storefront did not load WooCommerce integration"
}
assert_replacement_and_dependencies() {
  assert_eq "$PARENT_THEME" "$(target_wp option get stylesheet)" "replacement active standalone parent theme"
  assert_eq "$PARENT_THEME" "$(target_wp option get template)" "replacement active parent template"
  assert_eq absent "$(target_directory "$CHILD_TARGET")" "replacement keeps the reviewed child-theme removal"
  target_wp plugin is-active "$WOO_SLUG" >/dev/null || fail "replacement WooCommerce dependency is inactive"
  target_wp plugin is-active "$ACF_SLUG" >/dev/null || fail "replacement ACF extension is inactive"
  target_wp plugin is-active "$EXT_SLUG" >/dev/null && fail "outgoing WPrism Commerce Extension remained active"
  target_wp plugin is-active "$REPLACEMENT_SLUG" >/dev/null || fail "WPrism Commerce Replacement is inactive"
  assert_eq "$REPLACEMENT_ACTIVE_PLUGINS_JSON" "$(active_plugins_json)" "exact replacement active_plugins order"
  target_wp eval 'if (!class_exists("WooCommerce")) { exit(1); } if (!function_exists("woocommerce_content")) { exit(1); }' \
    || fail "replacement storefront did not retain WooCommerce integration"
}

theme_lifecycle_snapshot() {
  target_wp eval '
$stylesheet = (string) get_option("stylesheet");
$template = (string) get_option("template");
$parent = wp_get_theme("wprism-commerce-parent");
$child = wp_get_theme("wprism-commerce-child");
$logo_id = (int) get_theme_mod("custom_logo");
$logo = $logo_id > 0 ? get_post($logo_id) : null;
$logo_file = $logo_id > 0 ? (string) get_attached_file($logo_id) : "";
$locations = (array) get_theme_mod("nav_menu_locations", []);
$menu_id = (int) ($locations["primary"] ?? 0);
$menu = $menu_id > 0 ? wp_get_nav_menu_object($menu_id) : false;
echo wp_json_encode([
    "stylesheet" => $stylesheet,
    "template" => $template,
    "parent_exists" => $parent->exists(),
    "parent_version" => $parent->exists() ? (string) $parent->get("Version") : "",
    "child_exists" => $child->exists(),
    "child_version" => $child->exists() ? (string) $child->get("Version") : "",
    "background_color" => (string) get_theme_mod("background_color"),
    "logo_title" => $logo ? (string) $logo->post_title : "",
    "logo_mime" => $logo ? (string) $logo->post_mime_type : "",
    "logo_hash" => $logo_file !== "" && is_file($logo_file) ? (string) hash_file("sha256", $logo_file) : "",
    "menu_name" => $menu ? (string) $menu->name : "",
    "menu_slug" => $menu ? (string) $menu->slug : "",
], JSON_UNESCAPED_SLASHES);
'
}

assert_theme_lifecycle_unchanged() {
  local before="$1" label="$2" after
  after="$(theme_lifecycle_snapshot)"
  assert_eq "$before" "$after" "$label"
}

assert_theme_portable_relationships() {
  local label="$1" expected_stylesheet="${2:-$CHILD_THEME}" out
  assert_ecommerce_menu "$label"
  out="$(theme_lifecycle_snapshot)"
  jq -e \
    --arg stylesheet "$expected_stylesheet" \
    --arg template "$PARENT_THEME" \
    --arg background "1f4b6e" '
      .stylesheet == $stylesheet and .template == $template and
      .background_color == $background and
      .logo_title == "WPrism Grind Widget Image" and .logo_mime == "image/png" and
      .logo_hash == "431ced6916a2a21a156e38701afe55bbd7f88969fbbfc56d7fe099d47f265460" and
      .menu_name == "WPrism Grind Primary" and .menu_slug == "wprism-grind-primary"
    ' <<<"$out" >/dev/null \
    || fail "$label did not preserve portable theme settings, navigation, or media: $out"
  pass "$label preserves background, mapped custom-logo media, and the primary navigation relationship"
}

assert_theme_versions() {
  local label="$1" expected_parent="$2" expected_child="$3" out
  out="$(theme_lifecycle_snapshot)"
  jq -e \
    --arg parent "$expected_parent" \
    --arg child "$expected_child" '
      .parent_exists == true and .parent_version == $parent and
      (if $child == "absent" then .child_exists == false and .child_version == ""
       else .child_exists == true and .child_version == $child end)
    ' <<<"$out" >/dev/null \
    || fail "$label theme versions are not exact: $out"
  pass "$label has exact parent/child theme versions"
}

assert_theme_child_removed() {
  local label="$1"
  assert_eq "$PARENT_THEME" "$(target_wp option get stylesheet)" "$label active standalone parent"
  assert_eq "$PARENT_THEME" "$(target_wp option get template)" "$label active parent template"
  assert_eq absent "$(target_directory "$CHILD_TARGET")" "$label retired child-theme root"
}

assert_parent_theme_and_dependencies() {
  assert_eq "$PARENT_THEME" "$(target_wp option get stylesheet)" "active standalone parent theme"
  assert_eq "$PARENT_THEME" "$(target_wp option get template)" "active parent template"
  target_wp plugin is-active "$WOO_SLUG" >/dev/null || fail "WooCommerce is inactive"
  target_wp plugin is-active "$ACF_SLUG" >/dev/null || fail "ACF is inactive"
  target_wp plugin is-active "$EXT_SLUG" >/dev/null || fail "WPrism Commerce Extension is inactive"
  assert_eq "$AUTHORED_ACTIVE_PLUGINS_JSON" "$(active_plugins_json)" "exact authored active_plugins order"
}

assert_derived_indexes() {
  local expected_cap_stock="${1:-null}"
  local expected_cap_status="${2:-}"
  local expected_cap_price="${3:-14.99}"
  local expected_bundle_min="${4:-9.99}"
  local expected_bundle_max="${5:-14.99}"
  local expected_cap_cents="${6:-1499}"
  local out
  # Product-meta/price assertions below prove WPrism's bounded provider. Attribute
  # row assertions are a separate manual Woo maintenance baseline only.
  manual_regenerate_attribute_lookup
  out="$(target_wp eval '
global $wpdb;
$cap = get_page_by_path("wprism-grind-cap", OBJECT, "product");
$tee = get_page_by_path("wprism-grind-tee", OBJECT, "product");
$bundle = get_page_by_path("wprism-grind-bundle", OBJECT, "product");
$meta_table = $wpdb->prefix . "wc_product_meta_lookup";
$attributes_table = $wpdb->prefix . "wc_product_attributes_lookup";
$cap_meta = $cap ? (array) $wpdb->get_row($wpdb->prepare("SELECT sku, min_price, max_price, onsale, stock_quantity, stock_status, tax_class FROM `$meta_table` WHERE product_id = %d", $cap->ID), ARRAY_A) : [];
$tee_meta = $tee ? (array) $wpdb->get_row($wpdb->prepare("SELECT sku, min_price, max_price, onsale, stock_quantity, stock_status, tax_class FROM `$meta_table` WHERE product_id = %d", $tee->ID), ARRAY_A) : [];
$bundle_meta = $bundle ? (array) $wpdb->get_row($wpdb->prepare("SELECT sku, min_price, max_price, onsale, stock_quantity, stock_status, tax_class FROM `$meta_table` WHERE product_id = %d", $bundle->ID), ARRAY_A) : [];
$cap_product = $cap ? wc_get_product($cap->ID) : null;
$tee_product = $tee ? wc_get_product((int) $tee->ID) : null;
$variation_ids = [];
if ($tee_product && is_a($tee_product, "WC_Product_Variable")) {
    $variation_ids = array_values(array_unique(array_map("absint", (array) $tee_product->get_children())));
    sort($variation_ids, SORT_NUMERIC);
}
$variations = [];
$variation_load_errors = [];
foreach ($variation_ids as $variation_id) {
    $variation = wc_get_product($variation_id);
    if ($variation && is_a($variation, "WC_Product_Variation")) {
        $variations[] = $variation;
    } else {
        $variation_load_errors[] = (int) $variation_id;
    }
}
$loaded_variation_ids = [];
$variation_skus = [];
$variation_id_by_sku = [];
$duplicate_variation_skus = [];
$variation_meta = [];
foreach ($variations as $variation) {
    $variation_id = (int) $variation->get_id();
    $sku = (string) $variation->get_sku();
    $loaded_variation_ids[] = $variation_id;
    $variation_skus[$variation_id] = $sku;
    if (array_key_exists($sku, $variation_id_by_sku)) {
        $duplicate_variation_skus[] = $sku;
    } else {
        $variation_id_by_sku[$sku] = $variation_id;
    }
    $variation_meta[$sku] = (array) $wpdb->get_row($wpdb->prepare("SELECT sku, min_price, max_price, onsale, stock_quantity, stock_status, tax_class FROM `$meta_table` WHERE product_id = %d", $variation_id), ARRAY_A);
}
sort($loaded_variation_ids, SORT_NUMERIC);
$variation_ids_missing = array_values(array_diff($variation_ids, $loaded_variation_ids));
$variation_ids_unexpected = array_values(array_diff($loaded_variation_ids, $variation_ids));
$variation_ids_exact = count($variation_load_errors) === 0 && count($variation_ids_missing) === 0 && count($variation_ids_unexpected) === 0 && count($loaded_variation_ids) === count($variation_ids);
$blue_id = 0;
foreach ($variation_skus as $variation_id => $sku) {
    if ($sku === "GRIND-TEE-L-BLUE") {
        $blue_id = $variation_id;
        break;
    }
}
$blue_meta = $blue_id ? (array) $wpdb->get_row($wpdb->prepare("SELECT sku, min_price, max_price, onsale, stock_quantity, stock_status, tax_class FROM `$meta_table` WHERE product_id = %d", $blue_id), ARRAY_A) : [];
$attribute_rows = $tee ? $wpdb->get_results($wpdb->prepare("SELECT l.product_id, l.taxonomy, l.term_id, l.is_variation_attribute, l.in_stock, t.slug FROM `$attributes_table` l LEFT JOIN {$wpdb->terms} t ON t.term_id = l.term_id WHERE l.product_or_parent_id = %d", $tee->ID), ARRAY_A) : [];
$variation_attribute_rows = $attribute_rows;
$expected_attributes = [
    "GRIND-TEE-S-RED" => ["pa_grind-size" => "small", "pa_grind-color" => "red"],
    "GRIND-TEE-L-BLUE" => ["pa_grind-size" => "large", "pa_grind-color" => "blue"],
    "GRIND-TEE-S-BLUE" => ["pa_grind-size" => "small", "pa_grind-color" => "blue"],
    "GRIND-TEE-L-RED" => ["pa_grind-size" => "large", "pa_grind-color" => "red"],
];
$attribute_map = [];
$attribute_actual_keys = [];
$attribute_duplicate_keys = [];
$attribute_unknown_product_ids = [];
foreach ($attribute_rows as $row) {
    $product_id = (int) ($row["product_id"] ?? 0);
    $key = $product_id . "|" . (string) ($row["taxonomy"] ?? "") . "|" . (string) ($row["slug"] ?? "");
    $attribute_actual_keys[] = $key;
    if (!array_key_exists($product_id, $variation_skus)) {
        $attribute_unknown_product_ids[] = $product_id;
    }
    if (array_key_exists($key, $attribute_map)) {
        $attribute_duplicate_keys[] = $key;
    } else {
        $attribute_map[$key] = $row;
    }
}
$attribute_expected_keys = [];
foreach ($expected_attributes as $sku => $taxonomies) {
    $variation_id = $variation_id_by_sku[$sku] ?? 0;
    foreach ($taxonomies as $taxonomy => $slug) {
        if ($variation_id) {
            $attribute_expected_keys[] = $variation_id . "|" . $taxonomy . "|" . $slug;
        }
    }
}
$attribute_actual_key_set = array_values(array_unique($attribute_actual_keys));
sort($attribute_actual_key_set, SORT_STRING);
sort($attribute_expected_keys, SORT_STRING);
$attribute_unexpected_keys = array_values(array_diff($attribute_actual_key_set, $attribute_expected_keys));
$attribute_missing_keys = array_values(array_diff($attribute_expected_keys, $attribute_actual_key_set));
$attribute_unknown_product_ids = array_values(array_unique(array_map("absint", $attribute_unknown_product_ids)));
sort($attribute_unknown_product_ids, SORT_NUMERIC);
$attribute_exact = $variation_ids_exact && count($attribute_rows) === 8 && count($attribute_map) === 8 && count($attribute_duplicate_keys) === 0 && count($attribute_unknown_product_ids) === 0 && $attribute_actual_key_set === $attribute_expected_keys;
foreach ($expected_attributes as $sku => $taxonomies) {
    foreach ($taxonomies as $taxonomy => $slug) {
        $variation_id = $variation_id_by_sku[$sku] ?? 0;
        $row = $variation_id ? ($attribute_map[$variation_id . "|" . $taxonomy . "|" . $slug] ?? null) : null;
        if (!$row || (int) $row["is_variation_attribute"] !== 1 || (int) $row["in_stock"] !== 1) {
            $attribute_exact = false;
        }
    }
}
$expected_variation_meta = [
    "GRIND-TEE-S-RED" => ["min" => 19.99, "max" => 19.99, "onsale" => 0, "stock" => 15],
    "GRIND-TEE-L-BLUE" => ["min" => 18.99, "max" => 18.99, "onsale" => 1, "stock" => 12],
    "GRIND-TEE-S-BLUE" => ["min" => 20.99, "max" => 20.99, "onsale" => 0, "stock" => 11],
    "GRIND-TEE-L-RED" => ["min" => 22.99, "max" => 22.99, "onsale" => 0, "stock" => 10],
];
$expected_variation_skus = array_keys($expected_variation_meta);
$seen_variation_skus = array_keys($variation_meta);
sort($expected_variation_skus, SORT_STRING);
sort($seen_variation_skus, SORT_STRING);
$missing_variation_skus = array_values(array_diff($expected_variation_skus, $seen_variation_skus));
$unexpected_variation_skus = array_values(array_diff($seen_variation_skus, $expected_variation_skus));
$variation_exact = count($variation_meta) === count($expected_variation_meta);
foreach ($expected_variation_meta as $sku => $expected) {
    $row = $variation_meta[$sku] ?? [];
    if (!$row || (string) ($row["sku"] ?? "") !== $sku || (float) ($row["min_price"] ?? 0) !== $expected["min"] || (float) ($row["max_price"] ?? 0) !== $expected["max"] || (int) ($row["onsale"] ?? -1) !== $expected["onsale"] || (int) ($row["stock_quantity"] ?? -1) !== $expected["stock"] || (string) ($row["stock_status"] ?? "") !== "instock" || (string) ($row["tax_class"] ?? "") !== "parent") {
        $variation_exact = false;
    }
}
$cap_stock = array_key_exists("stock_quantity", $cap_meta) && $cap_meta["stock_quantity"] !== null ? (int) $cap_meta["stock_quantity"] : null;
$price_request = new WP_REST_Request("GET", "/wc/store/v1/products");
$price_request->set_query_params(["slug" => "wprism-grind-cap", "min_price" => "0", "max_price" => "100000"]);
$price_response = rest_do_request($price_request);
$normalize_store_value = static function ($value) use (&$normalize_store_value) {
    if (is_object($value)) {
        $value = get_object_vars($value);
    }
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $normalize_store_value($item);
        }
        return $normalized;
    }
    return $value;
};
$price_data = $normalize_store_value($price_response->get_data());
$price_rows = is_array($price_data) ? array_values($price_data) : [];
$price_api = null;
if (isset($price_rows[0]) && is_array($price_rows[0]) && isset($price_rows[0]["prices"]) && is_array($price_rows[0]["prices"]) && array_key_exists("price", $price_rows[0]["prices"])) {
    $price_api = (string) $price_rows[0]["prices"]["price"];
}
$attribute_request = new WP_REST_Request("GET", "/wc/store/v1/products");
$attribute_request->set_query_params(["slug" => "wprism-grind-tee", "attributes" => [["attribute" => "pa_grind-size", "slug" => "small"]]]);
$attribute_response = rest_do_request($attribute_request);
$attribute_data = $normalize_store_value($attribute_response->get_data());
$attribute_api_rows = is_array($attribute_data) ? array_values($attribute_data) : [];
$price_negative_request = new WP_REST_Request("GET", "/wc/store/v1/products");
$price_negative_request->set_query_params(["slug" => "wprism-grind-cap", "min_price" => "999900", "max_price" => "1000000"]);
$price_negative_response = rest_do_request($price_negative_request);
$price_negative_data = $normalize_store_value($price_negative_response->get_data());
$price_negative_rows = is_array($price_negative_data) ? array_values($price_negative_data) : [];
$attribute_negative_request = new WP_REST_Request("GET", "/wc/store/v1/products");
$attribute_negative_request->set_query_params(["slug" => "wprism-grind-tee", "attributes" => [["attribute" => "pa_grind-size", "slug" => "not-a-real-size"]]]);
$attribute_negative_response = rest_do_request($attribute_negative_request);
$attribute_negative_data = $normalize_store_value($attribute_negative_response->get_data());
$attribute_negative_rows = is_array($attribute_negative_data) ? array_values($attribute_negative_data) : [];
$tax_rates = WC_Tax::find_rates(["country" => "US", "state" => "CA"]);
$tax_725 = false;
foreach ($tax_rates as $tax_rate) {
    if (abs((float) ($tax_rate["rate"] ?? 0) - 7.25) < 0.0001) {
        $tax_725 = true;
    }
}
echo json_encode([
    "meta_rows" => $cap ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$meta_table` WHERE product_id = %d", $cap->ID)) : 0,
    "attribute_rows" => count($attribute_rows),
    "variation_attribute_rows" => count($variation_attribute_rows),
    "attribute_exact" => $attribute_exact,
    "variation_ids_exact" => $variation_ids_exact,
    "variation_ids" => $variation_ids,
    "loaded_variation_ids" => $loaded_variation_ids,
    "variation_ids_missing" => $variation_ids_missing,
    "variation_ids_unexpected" => $variation_ids_unexpected,
    "variation_load_errors" => $variation_load_errors,
    "duplicate_variation_skus" => array_values(array_unique($duplicate_variation_skus)),
    "attribute_actual_keys" => $attribute_actual_key_set,
    "attribute_expected_keys" => $attribute_expected_keys,
    "attribute_missing_keys" => $attribute_missing_keys,
    "attribute_unexpected_keys" => $attribute_unexpected_keys,
    "attribute_duplicate_keys" => $attribute_duplicate_keys,
    "attribute_unknown_product_ids" => $attribute_unknown_product_ids,
    "cap_exact" => (bool) $cap_meta && (string) ($cap_meta["sku"] ?? "") === "GRIND-CAP" && (int) ($cap_meta["onsale"] ?? -1) === 0 && (string) ($cap_meta["tax_class"] ?? "") === "",
    "cap_meta" => $cap_meta,
    "cap_status" => (string) ($cap_meta["stock_status"] ?? ""),
    "cap_stock" => $cap_stock,
    "cap_manage_stock" => $cap_product ? (bool) $cap_product->get_manage_stock() : false,
    "tee_exact" => (bool) $tee_meta && (string) ($tee_meta["sku"] ?? "") === "" && (float) ($tee_meta["min_price"] ?? 0) === 18.99 && (float) ($tee_meta["max_price"] ?? 0) === 22.99 && (int) ($tee_meta["onsale"] ?? -1) === 0 && ($tee_meta["stock_quantity"] ?? null) === null && (string) ($tee_meta["stock_status"] ?? "") === "instock" && (string) ($tee_meta["tax_class"] ?? "") === "",
    "tee_meta" => $tee_meta,
    "bundle_rows" => $bundle ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$meta_table` WHERE product_id = %d", $bundle->ID)) : 0,
    "bundle_meta" => $bundle_meta,
    "variation_exact" => $variation_exact,
    "variation_meta" => $variation_meta,
    "variation_expected_skus" => $expected_variation_skus,
    "variation_seen_skus" => $seen_variation_skus,
    "variation_missing_skus" => $missing_variation_skus,
    "variation_unexpected_skus" => $unexpected_variation_skus,
    "blue_exact" => (bool) $blue_meta && (string) ($blue_meta["sku"] ?? "") === "GRIND-TEE-L-BLUE" && (float) ($blue_meta["min_price"] ?? 0) === 18.99 && (float) ($blue_meta["max_price"] ?? 0) === 18.99 && (int) ($blue_meta["stock_quantity"] ?? -1) === 12 && (string) ($blue_meta["stock_status"] ?? "") === "instock" && (string) ($blue_meta["tax_class"] ?? "") === "parent",
    "blue_meta" => $blue_meta,
    "price_matches" => count($price_rows),
    "price_api" => $price_api,
    "attribute_matches" => count($attribute_api_rows),
    "price_negative_matches" => count($price_negative_rows),
    "attribute_negative_matches" => count($attribute_negative_rows),
    "tax_725" => $tax_725,
], JSON_UNESCAPED_SLASHES);
')"
  echo "derived/index acceptance: $out"
  jq -e \
    --argjson expected_cap_stock "$expected_cap_stock" \
    --arg expected_cap_status "$expected_cap_status" \
    --argjson expected_cap_price "$expected_cap_price" \
    --argjson expected_bundle_min "$expected_bundle_min" \
    --argjson expected_bundle_max "$expected_bundle_max" \
    --arg expected_cap_cents "$expected_cap_cents" \
    '.meta_rows >= 1 and .attribute_rows == 8 and .variation_attribute_rows == 8 and .attribute_exact == true and .variation_ids_exact == true and (.variation_ids | length) == 4 and (.loaded_variation_ids | length) == 4 and (.variation_ids_missing | length) == 0 and (.variation_ids_unexpected | length) == 0 and (.variation_load_errors | length) == 0 and (.duplicate_variation_skus | length) == 0 and (.attribute_missing_keys | length) == 0 and (.attribute_unexpected_keys | length) == 0 and (.attribute_duplicate_keys | length) == 0 and (.attribute_unknown_product_ids | length) == 0 and (.attribute_actual_keys | length) == 8 and (.attribute_expected_keys | length) == 8 and .cap_exact == true and (.cap_meta.min_price | tonumber) == $expected_cap_price and (.cap_meta.max_price | tonumber) == $expected_cap_price and .cap_status == $expected_cap_status and .cap_manage_stock == true and .cap_stock == $expected_cap_stock and .tee_exact == true and .bundle_rows == 1 and (.bundle_meta.min_price | tonumber) == $expected_bundle_min and (.bundle_meta.max_price | tonumber) == $expected_bundle_max and (.bundle_meta.onsale | tonumber) == 0 and .variation_exact == true and .blue_exact == true and .price_matches >= 1 and .price_api == $expected_cap_cents and .attribute_matches >= 1 and .price_negative_matches == 0 and .attribute_negative_matches == 0 and .tax_725 == true' \
    <<<"$out" >/dev/null || fail "Woo derived indexes, exact simple/grouped/variable price/SKU/stock/tax rows, or positive/negative Store API filters did not converge: $out"
  pass "WPrism product-meta/price rows converge; separately, manual Woo attribute regeneration yields exact attribute rows and Store API price/attribute filters resolve"
}

ROLLBACK_MAINTENANCE_HELD=0
ROLLBACK_PROMOTION_SUCCEEDED=0
say "opt into code: vendored pinned WooCommerce + custom extension + parent/child storefront"
mkdir -p "$SITE/code/wp-content/plugins" "$SITE/code/wp-content/themes"
# Copy the exact installed artifact bytes from the author container rather
# than downloading or reimplementing WooCommerce in the fixture repository.
"${PAIR_COMPOSE[@]}" run --rm -T -u root cli1 sh -c '
  set -eu
  rm -rf /siterepo/code/wp-content/plugins/woocommerce
  cp -a /var/www/html/wp-content/plugins/woocommerce /siterepo/code/wp-content/plugins/woocommerce
  chown -R "$1:$2" /siterepo/code/wp-content/plugins/woocommerce
' _ "$ECOMMERCE_HOST_UID" "$ECOMMERCE_HOST_GID"
"${PAIR_COMPOSE[@]}" run --rm -T -u root cli1 sh -c '
  set -eu
  rm -rf /siterepo/code/wp-content/plugins/advanced-custom-fields
  cp -a /var/www/html/wp-content/plugins/advanced-custom-fields /siterepo/code/wp-content/plugins/advanced-custom-fields
  chown -R "$1:$2" /siterepo/code/wp-content/plugins/advanced-custom-fields
' _ "$ECOMMERCE_HOST_UID" "$ECOMMERCE_HOST_GID"
cp -a "$FIXTURE/v1/wp-content/plugins/$EXT_SLUG" "$SITE/code/wp-content/plugins/"
cp -a "$FIXTURE/v1/wp-content/themes/$PARENT_THEME" "$SITE/code/wp-content/themes/"
cp -a "$FIXTURE/v1/wp-content/themes/$CHILD_THEME" "$SITE/code/wp-content/themes/"
jq '.code = {format: 1, layout: "wp-content", source: "code/wp-content"}' "$SITE/site.wprism.json" > "$SITE/site.wprism.next.json"
mv "$SITE/site.wprism.next.json" "$SITE/site.wprism.json"
canonicalize_json "$SITE/site.wprism.json"

# Materialize the author checkout into the author WordPress installation and
# use ordinary public WordPress lifecycle APIs.  Capture observes these real
# activation/theme-switch side effects; it does not hand-edit managed state.
source_wp option update wprism_commerce_extension_gateway_secret 'source-only-synthetic-secret' >/dev/null
"${PAIR_COMPOSE[@]}" run --rm -T cli1 sh -c 'cp -a /siterepo/code/wp-content/plugins/wprism-commerce-extension /var/www/html/wp-content/plugins/'
"${PAIR_COMPOSE[@]}" run --rm -T cli1 sh -c 'cp -a /siterepo/code/wp-content/themes/wprism-commerce-parent /var/www/html/wp-content/themes/ && cp -a /siterepo/code/wp-content/themes/wprism-commerce-child /var/www/html/wp-content/themes/'
source_wp plugin activate "$EXT_SLUG" >/dev/null
source_wp theme activate "$PARENT_THEME" >/dev/null
source_wp theme activate "$CHILD_THEME" >/dev/null
source_wp eval '
set_theme_mod("background_color", "1f4b6e");
set_theme_mod("custom_logo", '"$MEDIA_ID"');
$locations = (array) get_theme_mod("nav_menu_locations", []);
$locations["primary"] = '"$MENU_ID"';
set_theme_mod("nav_menu_locations", $locations);
' >/dev/null
assert_eq "$NATIVE_ACTIVE_PLUGINS_JSON" "$(source_wp option get active_plugins --format=json | jq -c '.')" "author native active_plugins order"
assert_eq "$PARENT_THEME" "$(source_wp option get template)" "author parent theme after public switch"
assert_eq "$CHILD_THEME" "$(source_wp option get stylesheet)" "author child theme after public switch"
SOURCE_RUNTIME_EVENT_LABEL='WPrism Grind source-only runtime event'
SOURCE_RUNTIME_EVENT_CREATED_AT='2026-01-01 02:03:04'
SOURCE_RUNTIME_EVENT_ID="$(source_wp eval '
global $wpdb;
$table = $wpdb->prefix . "wprism_commerce_extension_events";
if (!$wpdb->insert($table, ["label" => "WPrism Grind source-only runtime event", "created_at" => "2026-01-01 02:03:04"], ["%s", "%s"])) {
    throw new RuntimeException("source-only runtime extension event seed failed: " . $wpdb->last_error);
}
echo (int) $wpdb->insert_id;
')"
[[ "$SOURCE_RUNTIME_EVENT_ID" =~ ^[0-9]+$ ]] || fail "source runtime event id is not numeric: $SOURCE_RUNTIME_EVENT_ID"
SOURCE_RUNTIME_EVENT_BASELINE="$(source_runtime_event_snapshot "$SOURCE_RUNTIME_EVENT_ID")"
SOURCE_RUNTIME_IDENTITY_BASELINE="$(runtime_identity_inventory source_wp)"
assert_source_runtime_baseline 'source-only runtime seed before capture'
assert_runtime_state_excluded 'source-only runtime seed before capture'
source_wp wprism capture --repo=/siterepo >/dev/null
assert_source_runtime_baseline 'source code capture'
assert_runtime_state_excluded 'source code capture'
mkdir -p "$V1_INPUTS"
cp -a "$SITE/code" "$V1_INPUTS/code"
cp -a "$SITE/state" "$V1_INPUTS/state"
cp "$SITE/site.wprism.json" "$V1_INPUTS/site.wprism.json"
cp "$SITE/state/options/core.json" "$V1_INPUTS/core.json"
V1_SOURCE_MANAGED_CODE_TREE_HASH="$(source_managed_code_tree_hash)"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'code: opt in WooCommerce extension and storefront v1'
git -C "$SITE" push -qu origin main
git clone -q "$ORIGIN" "$OTHER_SITE"
if ! printf '%s\n' 'target-only-synthetic-secret' \
  | php "$WPRISM" --envs-file="$ENVS_FILE" env-set target --name=wprism_commerce_extension_gateway_secret --stdin >/dev/null; then
  fail "target env-owned gateway secret could not be provisioned through wprism env-set"
fi
assert_eq source-only-synthetic-secret "$(source_wp option get wprism_commerce_extension_gateway_secret)" "source env-owned gateway secret"
assert_eq target-only-synthetic-secret "$(target_wp option get wprism_commerce_extension_gateway_secret)" "target env-owned gateway secret"
if grep -R -Fq 'source-only-synthetic-secret' "$SITE/state"; then
  fail "env-owned source gateway secret leaked into canonical state"
fi
if grep -R -Fq 'target-only-synthetic-secret' "$OTHER_SITE/state"; then
  fail "env-owned target gateway secret leaked into canonical state"
fi
pass "code descriptor opts into pinned WooCommerce + ACF, custom extension v1, child theme, and separate env-owned gateway values"

say "publish target-only env registry and materialize v1 code/lifecycle"
V1_DEPLOY_ARTIFACTS_BEFORE="$(deploy_artifact_files)"
if ! V1_DEPLOY_OUT="$(deploy 2>&1)"; then
  echo "$V1_DEPLOY_OUT" >&2
  fail "v1 wprism deploy failed"
fi
echo "$V1_DEPLOY_OUT"
V1_ARTIFACT="$(artifact_for_new_deploy "$V1_DEPLOY_ARTIFACTS_BEFORE")"
# The target starts with both ecosystem extensions installed but inactive;
# deploy must perform the real lifecycle activation before Woo's HPOS command
# is available.  HPOS remains target-local runtime configuration.
target_wp wc hpos enable >/dev/null
if ! V1_APPLY_OUT="$(apply_state --adopt-by-slug=terms,posts --default-author=admin 2>&1)"; then
  echo "$V1_APPLY_OUT" >&2
  fail "v1 state apply failed"
fi
echo "$V1_APPLY_OUT"
target_wp wprism capture --repo=/siterepo --out=/siterepo/.tmp-v1-recapture >/dev/null
if ! diff -r "$OTHER_SITE/state" "$OTHER_SITE/.tmp-v1-recapture" >/dev/null; then
  diff -ru "$OTHER_SITE/state" "$OTHER_SITE/.tmp-v1-recapture" >&2 || true
  fail 'initial v1 target recapture did not match canonical state byte-for-byte'
fi
rm -rf -- "$OTHER_SITE/.tmp-v1-recapture"
pass 'initial v1 target recapture is byte-identical to canonical state'
assert_theme_and_dependency "$NATIVE_ACTIVE_PLUGINS_JSON"
assert_eq "$V1_SOURCE_MANAGED_CODE_TREE_HASH" "$(target_managed_code_tree_hash)" 'exact v1 source/target managed code tree'
assert_acf_schema target 'v1 target apply'
assert_source_runtime_baseline 'v1 target apply'
assert_source_runtime_absent_from_target 'v1 target apply'
assert_env_secret_isolation 'v1 target apply'
assert_runtime_state_excluded 'v1 target apply'
assert_frontend_child_parent 'v1 target apply'
assert_theme_versions 'v1 target apply' '1.0.0' '1.0.0'
assert_theme_portable_relationships 'v1 target apply'
assert_extension_rest_status '1.0.0' 1 'v1 target apply'
assert_eq retail "$(target_wp option get wprism_commerce_extension_settings)" "v1 authored extension setting"
assert_eq 1 "$(target_wp option get wprism_commerce_extension_schema)" "v1 extension schema"
assert_eq 0 "$(target_db_scalar "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_wprism_commerce_extension_events' AND COLUMN_NAME = 'context'")" "v1 extension table shape"
assert_woo_catalog 0 5
assert_ecommerce_menu 'v1 target apply'
TARGET_CAP_ID="$(target_wp post list --post_type=product --name=wprism-grind-cap --field=ID)"
[[ "$TARGET_CAP_ID" =~ ^[0-9]+$ ]] || fail "target cap id is not numeric: $TARGET_CAP_ID"
DELETION_PROBE_TARGET_ID="$(target_wp post list --post_type=product --name=wprism-grind-delete-probe --field=ID)"
assert_deletion_probe_lookup_present "$DELETION_PROBE_TARGET_ID"
DELETION_PROBE_V1_TARGET_ID="$DELETION_PROBE_TARGET_ID"
# Product quantities/statuses are deliberately runtime-only in the Woo
# contract.  Seed a distinct target-local inventory picture after the first
# apply so the exact lookup assertions prove that authored prices/SKUs and
# attributes round-tripped without copying source inventory values.
target_wp eval '
$tee = get_page_by_path("wprism-grind-tee", OBJECT, "product");
if (!$tee) { throw new RuntimeException("target tee missing before runtime variation inventory setup"); }
$stock = [
    "GRIND-TEE-S-RED" => 15,
    "GRIND-TEE-L-BLUE" => 12,
    "GRIND-TEE-S-BLUE" => 11,
    "GRIND-TEE-L-RED" => 10,
];
$expectedSkus = array_keys($stock);
sort($expectedSkus, SORT_STRING);
$teeProduct = wc_get_product((int) $tee->ID);
if (!$teeProduct || !is_a($teeProduct, "WC_Product_Variable")) {
    throw new RuntimeException("target variable product API lookup failed: " . ($teeProduct ? get_class($teeProduct) : "missing"));
}
$childIds = array_values(array_unique(array_map("absint", (array) $teeProduct->get_children())));
sort($childIds, SORT_NUMERIC);
$seen = [];
$observed = [];
$updates = [];
foreach ($childIds as $childId) {
    $variation = wc_get_product($childId);
    if (!$variation || !is_a($variation, "WC_Product_Variation")) {
        $observed[] = "(id:" . $childId . ":unreadable)";
        continue;
    }
    $sku = (string) $variation->get_sku();
    $observed[] = $sku !== "" ? $sku : "(id:" . $childId . ":empty-sku)";
    if (!array_key_exists($sku, $stock)) {
        continue;
    }
    if (!$variation->get_manage_stock()) {
        throw new RuntimeException("authored manage_stock flag was not present for " . $sku);
    }
    $updates[] = [$variation, $sku];
    $seen[$sku] = true;
}
$seenSkus = array_keys($seen);
$observedSkus = array_values(array_unique($observed));
sort($seenSkus, SORT_STRING);
sort($observedSkus, SORT_STRING);
$missingSkus = array_values(array_diff($expectedSkus, $seenSkus));
$unexpectedSkus = array_values(array_diff($observedSkus, $expectedSkus));
if ($missingSkus || $unexpectedSkus || count($seenSkus) !== count($expectedSkus) || count($observed) !== count($observedSkus)) {
    throw new RuntimeException("target variation inventory setup mismatch: " . wp_json_encode([
        "expected" => $expectedSkus,
        "seen" => $seenSkus,
        "missing" => $missingSkus,
        "unexpected" => $unexpectedSkus,
        "child_ids" => $childIds,
    ], JSON_UNESCAPED_SLASHES));
}
foreach ($updates as [$variation, $sku]) {
    $variation->set_stock_quantity($stock[$sku]);
    $variation->set_stock_status("instock");
    $variation->save();
}
' >/dev/null
assert_product_visibility_projection
assert_derived_indexes 0 ""
assert_store_api_http 1499 'v1 target runtime setup'
RUNTIME_EVENT_LABEL='WPrism Grind runtime v1 event'
RUNTIME_EVENT_CREATED_AT='2026-01-02 03:04:05'
RUNTIME_EVENT_ID="$(target_wp eval '
global $wpdb;
$table = $wpdb->prefix . "wprism_commerce_extension_events";
if (!$wpdb->insert($table, ["label" => "WPrism Grind runtime v1 event", "created_at" => "2026-01-02 03:04:05"], ["%s", "%s"])) {
    throw new RuntimeException("runtime extension event seed failed: " . $wpdb->last_error);
}
echo (int) $wpdb->insert_id;
')"
[[ "$RUNTIME_EVENT_ID" =~ ^[0-9]+$ ]] || fail "runtime extension event id is not numeric: $RUNTIME_EVENT_ID"
assert_extension_runtime_event 0 "" 'v1 runtime row before checkpoint'
assert_extension_runtime_event_excluded 'v1 runtime row'
assert_source_runtime_baseline 'target-only runtime seed before order'
assert_source_runtime_absent_from_target 'target-only runtime seed'
V1_REVISION="$(jq -r '.code.code_revision' "$V1_ARTIFACT")"
V1_ARTIFACT_HASH="$(jq -r '.artifact_hash' "$V1_ARTIFACT")"
V1_STATE_REVISION="$(jq -r '.revision_hash' "$V1_ARTIFACT")"
assert_receipt "$V1_ARTIFACT" 'v1 deploy/apply' "$V1_REVISION"
V1_TARGET_MANAGED_CODE_TREE_HASH="$(target_managed_code_tree_hash)"
assert_eq "$V1_SOURCE_MANAGED_CODE_TREE_HASH" "$V1_TARGET_MANAGED_CODE_TREE_HASH" 'v1 source/target managed code tree checkpoint'
assert_eq "$V1_REVISION" "$(ledger_revision)" 'recorded v1 target code revision'
assert_eq retail "$(target_db_scalar "SELECT option_value FROM wp_options WHERE option_name = 'wprism_commerce_extension_settings' LIMIT 1")" 'raw v1 setting before database checkpoint export'
target_wp db export /siterepo/.tmp-ecommerce-v1-db.sql --porcelain >/dev/null
cp "$OTHER_SITE/.tmp-ecommerce-v1-db.sql" "$V1_DB_DUMP"
[ -s "$V1_DB_DUMP" ] || fail "v1 exact rollback checkpoint was not exported"
V1_DB_DUMP_SHA256="$(sha256sum "$V1_DB_DUMP" | awk '{print $1}')"
[[ "$V1_DB_DUMP_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail "v1 pair-local database checkpoint SHA-256 is malformed"
assert_eq "$V1_DB_DUMP_SHA256" "$(sha256sum "$OTHER_SITE/.tmp-ecommerce-v1-db.sql" | awk '{print $1}')" 'pair-local v1 database checkpoint bytes before runtime order'
grep -Eq "'wprism_commerce_extension_settings','retail'," "$V1_DB_DUMP" \
  || fail 'v1 database checkpoint does not contain the raw scalar option value'
TARGET_ORDER_ID="$(target_wp eval '
$customer = wc_create_new_customer("runtime-customer@example.invalid", "runtime-customer", "runtime-customer-password", ["first_name" => "Target", "last_name" => "Runtime"]);
if (is_wp_error($customer)) { throw new RuntimeException("target runtime customer seed failed: " . $customer->get_error_message()); }
$p = get_page_by_path("wprism-grind-cap", OBJECT, "product");
if (!$p) { throw new RuntimeException("target cap missing before runtime order"); }
$order = wc_create_order(["customer_id" => (int) $customer]);
$order->set_address([
    "first_name" => "Target",
    "last_name" => "Runtime",
    "address_1" => "200 Target Runtime Way",
    "city" => "Targetville",
    "state" => "CA",
    "postcode" => "90210",
    "country" => "US",
    "email" => "runtime-only@example.invalid",
    "phone" => "555-0101",
], "billing");
$order->set_address([
    "first_name" => "Target",
    "last_name" => "Runtime",
    "address_1" => "201 Target Fulfillment Way",
    "city" => "Targetville",
    "state" => "CA",
    "postcode" => "90210",
    "country" => "US",
], "shipping");
$order->add_meta_data("_wprism_runtime_marker", "target-order-only", true);
$order->add_product(wc_get_product($p->ID), 1);
$order->calculate_totals();
$order->save();
$product = wc_get_product($p->ID);
$product->set_stock_status("instock");
$product->set_stock_quantity(7);
$product->save();
echo $order->get_id();
')"
[ "$TARGET_ORDER_ID" -gt 0 ] || fail "target-only HPOS runtime order was not created"
# Woo 11 schedules this order's analytics import five seconds in the future.
# Execute only that exact public Action Scheduler job before freezing the
# runtime baseline; never drain unrelated application work from the queue.
TARGET_ORDER_IMPORT_ACTION_ID="$(target_wp action-scheduler action list --hook=wc-admin_import_orders --args="[$TARGET_ORDER_ID]" --status=pending --format=ids)"
[[ "$TARGET_ORDER_IMPORT_ACTION_ID" =~ ^[1-9][0-9]*$ ]] || fail "target order analytics import did not schedule exactly one action: $TARGET_ORDER_IMPORT_ACTION_ID"
target_wp action-scheduler action run "$TARGET_ORDER_IMPORT_ACTION_ID" >/dev/null
TARGET_ORDER_COMPLETED_IMPORT_ACTION_ID="$(target_wp action-scheduler action list --hook=wc-admin_import_orders --args="[$TARGET_ORDER_ID]" --status=complete --format=ids)"
assert_eq "$TARGET_ORDER_IMPORT_ACTION_ID" "$TARGET_ORDER_COMPLETED_IMPORT_ACTION_ID" 'target order exact analytics import completion'
TARGET_RUNTIME_CUSTOMER_ID="$(target_wp eval 'echo get_user_by("email", "runtime-customer@example.invalid") ? (int) get_user_by("email", "runtime-customer@example.invalid")->ID : 0;')"
[[ "$TARGET_RUNTIME_CUSTOMER_ID" =~ ^[0-9]+$ ]] && [ "$TARGET_RUNTIME_CUSTOMER_ID" -gt 0 ] || fail "target runtime customer id is not numeric: $TARGET_RUNTIME_CUSTOMER_ID"
TARGET_RUNTIME_CUSTOMER_BASELINE="$(target_runtime_customer_snapshot "$TARGET_RUNTIME_CUSTOMER_ID")"
TARGET_ORDER_BASELINE="$(target_order_snapshot "$TARGET_ORDER_ID")"
TARGET_ORDER_ITEM_IDS="$(jq -r '[.order_items[] | .order_item_id | tonumber] | unique | join(",")' <<<"$TARGET_ORDER_BASELINE")"
[[ "$TARGET_ORDER_ITEM_IDS" =~ ^[1-9][0-9]*(,[1-9][0-9]*)*$ ]] || fail "target-only HPOS order item ID capture is malformed: $TARGET_ORDER_ITEM_IDS"
TARGET_RUNTIME_IDENTITY_BASELINE="$(runtime_identity_inventory target_wp)"
assert_target_order_snapshot "$TARGET_ORDER_BASELINE" 'target-only HPOS order'
assert_runtime_isolation 'target-only runtime seed' 0
assert_eq 7 "$(target_wp eval '$p = get_page_by_path("wprism-grind-cap", OBJECT, "product"); $product = $p ? wc_get_product($p->ID) : null; echo $product ? (int) $product->get_stock_quantity() : -1;')" "target-only stock adjustment"
pass "v1 code/state materialized and checkpointed; synthetic target-only HPOS order $TARGET_ORDER_ID and stock=7 are runtime data, never canonical"

say "bounded WordPress cron move: execute one named future-post event"
# The hook is the existing Apply::run() future-post native scheduling
# contract, not a harness-specific engine branch.  The fixture only supplies
# one runtime-only post so the public WP-Cron action can be observed without
# changing canonical authored state.
TARGET_CRON_FREEZE_CANDIDATE="/var/www/html/wp-content/mu-plugins/wprism-cron-freeze-${PAIR}.php"
TARGET_CRON_FREEZE_SHA="$(target_root_php_args '
$path = $argv[1] ?? "";
$bytes = "<?php\nif (!defined(\"DISABLE_WP_CRON\")) { define(\"DISABLE_WP_CRON\", true); }\n";
if (file_exists($path) || is_link($path)) {
    throw new RuntimeException("cron freeze file already exists: " . $path);
}
 $created = false;
try {
    $handle = @fopen($path, "xb");
    if (!is_resource($handle)) {
        throw new RuntimeException("cron freeze file exclusive create failed: " . $path);
    }
    $created = true;
    if (@fwrite($handle, $bytes) !== strlen($bytes) || !@fflush($handle) || !@fclose($handle)) {
        @fclose($handle);
        throw new RuntimeException("cron freeze file write failed: " . $path);
    }
    echo hash("sha256", $bytes);
} catch (Throwable $e) {
    if ($created && is_file($path) && !is_link($path)) {
        @unlink($path);
    }
    throw $e;
}
' "$TARGET_CRON_FREEZE_CANDIDATE")" || fail 'scoped cron freeze exclusive create failed'
[[ "$TARGET_CRON_FREEZE_SHA" =~ ^[0-9a-f]{64}$ ]] || fail 'scoped cron freeze hash is malformed'
TARGET_CRON_FREEZE_FILE="$TARGET_CRON_FREEZE_CANDIDATE"
assert_eq present "$(target_file "$TARGET_CRON_FREEZE_FILE")" 'cron freeze file exists before WP boot'
assert_eq 1 "$(target_wp eval 'echo defined("DISABLE_WP_CRON") && DISABLE_WP_CRON ? 1 : 0;')" 'automatic WP-Cron is disabled by the scoped freeze'
TARGET_CRON_INVENTORY_BEFORE="$(target_cron_inventory)"
TARGET_ACTION_SCHEDULER_INVENTORY_BEFORE="$(target_action_scheduler_inventory)"
TARGET_CRON_POST_ID="$(target_wp eval '
$timestamp = time() + 3600;
$post_id = wp_insert_post([
    "post_title" => "WPrism Grind bounded cron move",
    "post_name" => "wprism-grind-bounded-cron-move",
    "post_type" => "page",
    "post_status" => "future",
    "post_date_gmt" => gmdate("Y-m-d H:i:s", $timestamp),
    "post_date" => get_date_from_gmt(gmdate("Y-m-d H:i:s", $timestamp), "Y-m-d H:i:s"),
    "post_content" => "ecommerce-developer named WordPress cron transition.",
], true);
if (is_wp_error($post_id)) {
    throw new RuntimeException("bounded cron post seed failed: " . $post_id->get_error_message());
}
// Keep the authored status as future but move its stored dates behind the
// clock.  Core check_and_publish_future_post() otherwise clears this event
// and reschedules it for the original future date instead of publishing it.
global $wpdb;
$past_gmt = gmdate("Y-m-d H:i:s", time() - 120);
$past_local = get_date_from_gmt($past_gmt, "Y-m-d H:i:s");
$updated = $wpdb->update(
    $wpdb->posts,
    [
        "post_date" => $past_local,
        "post_date_gmt" => $past_gmt,
        "post_modified" => $past_local,
        "post_modified_gmt" => $past_gmt,
    ],
    ["ID" => (int) $post_id],
    ["%s", "%s", "%s", "%s"],
    ["%d"]
);
if ($updated !== 1) {
    throw new RuntimeException("bounded cron post date update failed: " . (string) $wpdb->last_error);
}
clean_post_cache((int) $post_id);
$cleared = wp_clear_scheduled_hook("publish_future_post", [(int) $post_id]);
if ($cleared === false) {
    throw new RuntimeException("bounded cron seed could not clear the auto-scheduled event");
}
if (!wp_schedule_single_event(time() - 1, "publish_future_post", [(int) $post_id])) {
    throw new RuntimeException("bounded cron seed could not schedule its due event");
}
echo (int) $post_id;
')"
[[ "$TARGET_CRON_POST_ID" =~ ^[1-9][0-9]*$ ]] || fail "bounded cron post id is not numeric: $TARGET_CRON_POST_ID"
assert_eq future "$(target_wp post get "$TARGET_CRON_POST_ID" --field=post_status)" 'named cron post is future before run'
TARGET_CRON_EVENT_LIST="$(target_wp cron event list --hook=publish_future_post --fields=hook,time,args,schedule,interval --format=json)"
jq -e --argjson expected_id "$TARGET_CRON_POST_ID" '
  def event_args:
    if (.args | type) == "string" then ((.args | fromjson) // []) else ((.args // []) | arrays) end;
  ([.[] | select(.hook == "publish_future_post" and ((event_args[0] // null) | tonumber) == $expected_id)] | length) == 1
' <<<"$TARGET_CRON_EVENT_LIST" >/dev/null \
  || fail "named publish_future_post event was not listed exactly once"
TARGET_CRON_DUE="$(target_wp eval '
$post_id = (int) '"$TARGET_CRON_POST_ID"';
$due = [];
foreach ((array) _get_cron_array() as $timestamp => $hooks) {
    if ((int) $timestamp > time() || !isset($hooks["publish_future_post"])) {
        continue;
    }
    foreach ((array) $hooks["publish_future_post"] as $event) {
        $args = array_values((array) ($event["args"] ?? []));
        $due[] = ["timestamp" => (int) $timestamp, "hook" => "publish_future_post", "args" => $args];
    }
}
echo wp_json_encode($due, JSON_UNESCAPED_SLASHES);
')"
jq -e --argjson expected_id "$TARGET_CRON_POST_ID" '
  length == 1 and .[0].hook == "publish_future_post" and ((.[0].args[0] // null) | tonumber) == $expected_id
' <<<"$TARGET_CRON_DUE" >/dev/null \
  || fail "named cron hook is not the only due publish_future_post event"
target_wp cron event run publish_future_post --due-now >/dev/null
assert_eq publish "$(target_wp post get "$TARGET_CRON_POST_ID" --field=post_status)" 'named cron transition publishes the post'
TARGET_CRON_AFTER_RUN="$(target_wp cron event list --hook=publish_future_post --fields=hook,time,args,schedule,interval --format=json)"
jq -e --argjson expected_id "$TARGET_CRON_POST_ID" '
  def event_args:
    if (.args | type) == "string" then ((.args | fromjson) // []) else ((.args // []) | arrays) end;
  ([.[] | select(.hook == "publish_future_post" and ((event_args[0] // null) | tonumber) == $expected_id)] | length) == 0
' <<<"$TARGET_CRON_AFTER_RUN" >/dev/null \
  || fail "named cron event remained after its one public run"
target_wp post delete "$TARGET_CRON_POST_ID" --force >/dev/null
assert_eq 0 "$(target_wp eval 'echo get_post('"$TARGET_CRON_POST_ID"') ? 1 : 0;')" 'named cron post cleanup'
assert_eq "$TARGET_CRON_INVENTORY_BEFORE" "$(target_cron_inventory)" 'unrelated WP-Cron inventory after named move'
assert_eq "$TARGET_ACTION_SCHEDULER_INVENTORY_BEFORE" "$(target_action_scheduler_inventory)" 'unrelated Action Scheduler inventory after named move'
assert_eq "$TARGET_RUNTIME_IDENTITY_BASELINE" "$(runtime_identity_inventory target_wp)" 'runtime identities after named cron move'
remove_target_cron_freeze
pass "named WordPress cron event $TARGET_CRON_POST_ID converged exactly once; unrelated cron/Action Scheduler/runtime identities stayed unchanged"

say "compile preflight failure/retry: remove desired extension main file before touching target"
TARGET_TREE_BEFORE="$(target_hash "$EXT_TARGET")"
PREFLIGHT_MANAGED_CODE_TREE_BEFORE="$(target_managed_code_tree_hash)"
REV_BEFORE="$(ledger_revision)"
PREFLIGHT_SESSION_BEFORE="$(ledger_value promotion_session)"
PREFLIGHT_LOCK_BEFORE="$(ledger_value promotion_lock)"
rm -f -- "$SITE/code/wp-content/plugins/$EXT_SLUG/$EXT_FILE"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'test: remove active extension main file'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
if PREFLIGHT_OUT="$(deploy 2>&1)"; then
  echo "$PREFLIGHT_OUT" >&2
  fail "compile preflight unexpectedly accepted an active extension without its main file"
fi
echo "$PREFLIGHT_OUT"
grep -Fq "canonical active plugin '$EXT_BASENAME' has no matching plugin main file" <<<"$PREFLIGHT_OUT" || fail "preflight did not name the missing extension main file"
assert_absent "$PREFLIGHT_OUT" 'deploy phase: promotion-begin' 'compile preflight'
assert_eq "$TARGET_TREE_BEFORE" "$(target_hash "$EXT_TARGET")" 'target bytes after compile preflight refusal'
assert_eq "$PREFLIGHT_MANAGED_CODE_TREE_BEFORE" "$(target_managed_code_tree_hash)" 'full managed code tree after compile preflight refusal'
assert_eq "$REV_BEFORE" "$(ledger_revision)" 'completed revision after compile preflight refusal'
assert_eq "$PREFLIGHT_SESSION_BEFORE" "$(ledger_value promotion_session)" 'promotion session after compile preflight refusal'
assert_eq "$PREFLIGHT_LOCK_BEFORE" "$(ledger_value promotion_lock)" 'promotion lease after compile preflight refusal'
if [ -z "$PREFLIGHT_SESSION_BEFORE$PREFLIGHT_LOCK_BEFORE" ]; then
  assert_eq 0 "$(target_db_scalar "SELECT COUNT(*) FROM wp_wprism_kv WHERE k IN ('promotion_session', 'promotion_lock')")" 'promotion lease/session absence after compile preflight refusal'
fi
cp "$FIXTURE/v1/wp-content/plugins/$EXT_SLUG/$EXT_FILE" "$SITE/code/wp-content/plugins/$EXT_SLUG/$EXT_FILE"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'test: retry extension main file'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
pass "missing active code was rejected before lease/checkpoint; restoring the exact file keeps target and revision unchanged"

say "extension lifecycle boundary: deactivate v1 while WooCommerce and ACF remain active"
jq --arg woo "$WOO_BASENAME" --arg acf "$ACF_BASENAME" '.records.active_plugins.value = [$woo, $acf]' "$STATE" > "$STATE.next"
mv "$STATE.next" "$STATE"
canonicalize_json "$STATE"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'lifecycle: deactivate custom extension only'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
if ! V1_DEACTIVATE_OUT="$(promote 2>&1)"; then
  echo "$V1_DEACTIVATE_OUT" >&2
  fail 'v1 extension deactivation promotion failed'
fi
echo "$V1_DEACTIVATE_OUT"
target_wp plugin is-active "$WOO_SLUG" >/dev/null || fail 'WooCommerce was deactivated with the custom extension'
target_wp plugin is-active "$ACF_SLUG" >/dev/null || fail 'ACF was deactivated with the custom extension'
target_wp plugin is-active "$EXT_SLUG" >/dev/null && fail 'custom extension remained active after lifecycle removal'
assert_trace_has "$(target_wp option get wprism_commerce_extension_trace --format=json)" 'deactivate:commerce-v1:woo=yes'
assert_runtime_isolation 'v1 extension deactivation' 0
V1_DEACTIVATE_ARTIFACT="$(artifact_for_promote_output "$V1_DEACTIVATE_OUT")"
assert_receipt "$V1_DEACTIVATE_ARTIFACT" 'v1 extension deactivation promote' "$V1_REVISION"
pass "public lifecycle retire deactivated only the custom extension and observed both available extensions still active"

say "target runtime compatibility: inactive vendored plugin refuses before promotion-begin and force flags cannot bypass"
INACTIVE_COMPAT_SOURCE="$SITE/code/wp-content/plugins/$EXT_SLUG/$EXT_FILE"
INACTIVE_COMPAT_TARGET_RUNTIME="$(target_wp eval 'global $wp_version; echo wp_json_encode(["php" => PHP_VERSION, "wordpress" => (string) $wp_version]);')"
INACTIVE_COMPAT_TARGET_PHP="$(jq -r '.php' <<<"$INACTIVE_COMPAT_TARGET_RUNTIME")"
INACTIVE_COMPAT_TARGET_WORDPRESS="$(jq -r '.wordpress' <<<"$INACTIVE_COMPAT_TARGET_RUNTIME")"
[ -n "$INACTIVE_COMPAT_TARGET_PHP" ] && [ "$INACTIVE_COMPAT_TARGET_PHP" != null ] \
  || fail 'target runtime compatibility probe returned no PHP version'
[ -n "$INACTIVE_COMPAT_TARGET_WORDPRESS" ] && [ "$INACTIVE_COMPAT_TARGET_WORDPRESS" != null ] \
  || fail 'target runtime compatibility probe returned no WordPress version'
target_wp plugin is-active "$EXT_SLUG" >/dev/null && fail 'inactive compatibility probe started with the extension active'
INACTIVE_COMPAT_TREE_BEFORE="$(target_managed_code_tree_hash)"
INACTIVE_COMPAT_PLUGIN_TREE_BEFORE="$(target_plugin_tree_hash)"
INACTIVE_COMPAT_REVISION_BEFORE="$(ledger_revision)"
INACTIVE_COMPAT_ACTIVE_BEFORE="$(active_plugins_json)"
INACTIVE_COMPAT_TRACE_BEFORE="$(target_wp option get wprism_commerce_extension_trace --format=json)"
INACTIVE_COMPAT_SESSION_BEFORE="$(ledger_value promotion_session)"
INACTIVE_COMPAT_LOCK_BEFORE="$(ledger_value promotion_lock)"
php -r '
$path = $argv[1];
$bytes = file_get_contents($path);
$needle = " * Version: 1.0.0\n";
if (!is_string($bytes) || substr_count($bytes, $needle) !== 1) { exit(2); }
$next = str_replace($needle, $needle . " * Requires PHP: 99.0\n", $bytes);
if (file_put_contents($path, $next) === false) { exit(3); }
' "$INACTIVE_COMPAT_SOURCE"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'test: inactive extension requires a newer target PHP'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
INACTIVE_COMPAT_PLAN="$(plan_json)"
jq -e \
  --arg plugin "$EXT_BASENAME" \
  --arg php "$INACTIVE_COMPAT_TARGET_PHP" \
  --arg wordpress "$INACTIVE_COMPAT_TARGET_WORDPRESS" \
  '[.code_mismatch[] | select(
      .issue == "code_source_requires_php_incompatible"
      and .kind == "plugin"
      and .plugin == $plugin
      and .target_php == $php
      and .target_wordpress == $wordpress
      and .required_version == "99.0"
      and .non_forceable == true
      and (.code_revision | test("^[0-9a-f]{64}$"))
      and (.component_sha256 | test("^[0-9a-f]{64}$"))
    )] | length == 1' <<<"$INACTIVE_COMPAT_PLAN" >/dev/null \
  || fail 'semantic plan did not bind the inactive plugin requirement to exact target/runtime code evidence'
if INACTIVE_COMPAT_OUT="$(deploy --force-code-mismatch 2>&1)"; then
  echo "$INACTIVE_COMPAT_OUT" >&2
  fail 'inactive extension with incompatible Requires PHP unexpectedly deployed'
fi
echo "$INACTIVE_COMPAT_OUT"
grep -Fq 'code_source_requires_php_incompatible' <<<"$INACTIVE_COMPAT_OUT" \
  || fail 'inactive extension refusal did not retain its structured runtime diagnostic'
grep -Fq "$EXT_BASENAME" <<<"$INACTIVE_COMPAT_OUT" \
  || fail 'inactive extension refusal did not name the exact plugin identity'
grep -Fq "target PHP is $INACTIVE_COMPAT_TARGET_PHP" <<<"$INACTIVE_COMPAT_OUT" \
  || fail 'inactive extension refusal did not name the exact target PHP value'
assert_absent "$INACTIVE_COMPAT_OUT" 'deploy phase: promotion-begin' 'inactive extension runtime compatibility refusal'
assert_eq "$INACTIVE_COMPAT_TREE_BEFORE" "$(target_managed_code_tree_hash)" 'managed code tree after inactive compatibility refusal'
assert_eq "$INACTIVE_COMPAT_PLUGIN_TREE_BEFORE" "$(target_plugin_tree_hash)" 'plugin tree after inactive compatibility refusal'
assert_eq "$INACTIVE_COMPAT_REVISION_BEFORE" "$(ledger_revision)" 'code revision after inactive compatibility refusal'
assert_eq "$INACTIVE_COMPAT_ACTIVE_BEFORE" "$(active_plugins_json)" 'active plugin order after inactive compatibility refusal'
assert_eq "$INACTIVE_COMPAT_TRACE_BEFORE" "$(target_wp option get wprism_commerce_extension_trace --format=json)" 'lifecycle trace after inactive compatibility refusal'
assert_eq "$INACTIVE_COMPAT_SESSION_BEFORE" "$(ledger_value promotion_session)" 'promotion session after inactive compatibility refusal'
assert_eq "$INACTIVE_COMPAT_LOCK_BEFORE" "$(ledger_value promotion_lock)" 'promotion lock after inactive compatibility refusal'
target_wp plugin is-active "$EXT_SLUG" >/dev/null && fail 'runtime compatibility refusal activated the inactive extension'
cp "$FIXTURE/v1/wp-content/plugins/$EXT_SLUG/$EXT_FILE" "$INACTIVE_COMPAT_SOURCE"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'test: restore headerless inactive extension compatibility control'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
pass "inactive incompatible plugin produced an exact non-forceable plan/refusal with no lease, replacement, or lifecycle effect; headerless control restored"

say "v2 reviewed change: migrate scalar setting/table and deliberately fail activation"
cp "$FIXTURE/v2/broken/$EXT_FILE" "$SITE/code/wp-content/plugins/$EXT_SLUG/$EXT_FILE"
cp -a "$FIXTURE/v2/wp-content/themes/$PARENT_THEME" "$SITE/code/wp-content/themes/"
cp -a "$FIXTURE/v2/wp-content/themes/$CHILD_THEME" "$SITE/code/wp-content/themes/"
jq --arg woo "$WOO_BASENAME" --arg acf "$ACF_BASENAME" --arg extension "$EXT_BASENAME" '.records.active_plugins.value = [$woo, $acf, $extension] | .records.wprism_commerce_extension_settings = {autoload: "off", state: "present", value: {schema: 2, channel: "retail", catalog_mode: "managed"}}' "$STATE" > "$STATE.next"
mv "$STATE.next" "$STATE"
canonicalize_json "$STATE"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'code: reviewed v2 migration with activation failure fixture'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
if V2_BROKEN_OUT="$(promote 2>&1)"; then
  echo "$V2_BROKEN_OUT" >&2
  fail 'broken v2 activation unexpectedly succeeded'
fi
echo "$V2_BROKEN_OUT"
assert_phase_order "$V2_BROKEN_OUT" 'promote phase: compile' 'promote phase: code-preflight' 'promote phase: promotion-begin' 'promote phase: checkpoint' 'promote phase: code-stage' 'promote phase: lifecycle-retire' 'promote phase: lifecycle-activate'
grep -Fq 'WPrism Commerce Extension reviewed v2 activation failure' <<<"$V2_BROKEN_OUT" || fail 'controlled v2 activation failure did not reach reviewed hook'
assert_absent "$V2_BROKEN_OUT" 'promote phase: code-finalize' 'broken v2 activation'
assert_absent "$V2_BROKEN_OUT" 'promote phase: apply' 'broken v2 activation'
grep -Fq 'promotion lease cleanup confirmed' <<<"$V2_BROKEN_OUT" || fail 'broken v2 activation did not abort its lease'
assert_eq "$V1_REVISION" "$(ledger_revision)" 'completed revision after broken v2 activation'
V2_FAILED_CHECKPOINT="$(sed -n 's/^database checkpoint: //p' <<<"$V2_BROKEN_OUT" | head -1)"
[ -n "$V2_FAILED_CHECKPOINT" ] || fail 'broken v2 activation did not report its checkpoint'
V2_FAILED_RUN_ID="$(basename "$V2_FAILED_CHECKPOINT")"
V2_FAILED_RUN_ID="${V2_FAILED_RUN_ID#promote-}"
V2_FAILED_RUN_ID="${V2_FAILED_RUN_ID%.sql.enc}"
[[ "$V2_FAILED_RUN_ID" =~ ^[0-9]{8}-[0-9]{6}-[0-9a-f]{32}$ ]] || fail "failed v2 checkpoint run identity is malformed: $V2_FAILED_RUN_ID"
assert_eq "/siterepo/.wprism/checkpoints/promote-$V2_FAILED_RUN_ID.sql.enc" "$V2_FAILED_CHECKPOINT" 'failed v2 checkpoint path/run identity'
V2_FAILED_CHECKPOINT_HOST="$OTHER_SITE/.wprism/checkpoints/promote-$V2_FAILED_RUN_ID.sql.enc"
V2_FAILED_ARTIFACT_FILE="$OTHER_SITE/.wprism/artifacts/promote-$V2_FAILED_RUN_ID.json"
[ -s "$V2_FAILED_CHECKPOINT_HOST" ] || fail 'failed v2 checkpoint not target-visible and non-empty'
[ -f "$V2_FAILED_ARTIFACT_FILE" ] || fail 'failed v2 compiled artifact does not share the checkpoint run identity'
V2_FAILED_CHECKPOINT_SHA256="$(sha256sum "$V2_FAILED_CHECKPOINT_HOST" | awk '{print $1}')"
[[ "$V2_FAILED_CHECKPOINT_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail 'failed v2 checkpoint SHA-256 is malformed'
V2_FAILED_COMPILED_HASH="$(jq -r '.artifact_hash' "$V2_FAILED_ARTIFACT_FILE")"
V2_FAILED_RECOMPUTED_HASH="$(WPRISM_CANON="$REPO_ROOT/agent/src/Kernel/Canon.php" php -r '
require getenv("WPRISM_CANON");
$payload = WPrism\Canon::decode(file_get_contents($argv[1]));
unset($payload["artifact_hash"]);
echo hash("sha256", WPrism\Canon::encode($payload));
' "$V2_FAILED_ARTIFACT_FILE")"
assert_eq "$V2_FAILED_COMPILED_HASH" "$V2_FAILED_RECOMPUTED_HASH" 'failed v2 exact canonical artifact content hash'
V2_FAILED_SESSION="$(ledger_value promotion_session)"
V2_FAILED_OWNER="$(jq -r '.owner' <<<"$V2_FAILED_SESSION")"
V2_FAILED_ARTIFACT="$(jq -r '.artifact_hash' <<<"$V2_FAILED_SESSION")"
assert_eq "$V2_FAILED_RUN_ID" "$V2_FAILED_OWNER" 'failed v2 checkpoint/session owner identity'
assert_eq "$V2_FAILED_COMPILED_HASH" "$V2_FAILED_ARTIFACT" 'failed v2 checkpoint/session compiled artifact identity'
jq -e '.lifecycle_attempt.phase == "activate" and .lifecycle_attempt.entity == "options/core"' <<<"$V2_FAILED_SESSION" >/dev/null || fail 'failed v2 activation did not retain lifecycle boundary receipt'
assert_extension_runtime_event 1 "" 'broken v2 migration runtime row'
assert_runtime_isolation 'broken v2 migration' 1
pass "v2 migration attempt stopped at controlled activation failure; later code-finalize/apply phases never ran"

say "exact checkpoint recovery, then fixed v2 retry"
# Restore every v1-managed code root before importing the v1 checkpoint; a
# database restore alone is not a safe code rollback.  The target repo is the
# only shared transport-visible staging area for this recovery operation.
rm -rf -- "$OTHER_SITE/.tmp-v1-code"
mkdir -p "$OTHER_SITE/.tmp-v1-code/wp-content/plugins" "$OTHER_SITE/.tmp-v1-code/wp-content/themes"
cp -a "$FIXTURE/v1/wp-content/plugins/$EXT_SLUG" "$OTHER_SITE/.tmp-v1-code/wp-content/plugins/"
cp -a "$FIXTURE/v1/wp-content/themes/$PARENT_THEME" "$OTHER_SITE/.tmp-v1-code/wp-content/themes/"
cp -a "$FIXTURE/v1/wp-content/themes/$CHILD_THEME" "$OTHER_SITE/.tmp-v1-code/wp-content/themes/"
"${PAIR_COMPOSE[@]}" run --rm -T cli2 sh -c 'cp -a /siterepo/.tmp-v1-code/wp-content/plugins/wprism-commerce-extension /var/www/html/wp-content/plugins/ && cp -a /siterepo/.tmp-v1-code/wp-content/themes/wprism-commerce-parent /var/www/html/wp-content/themes/ && cp -a /siterepo/.tmp-v1-code/wp-content/themes/wprism-commerce-child /var/www/html/wp-content/themes/'
rm -rf -- "$OTHER_SITE/.tmp-v1-code"
assert_eq "$V1_TARGET_MANAGED_CODE_TREE_HASH" "$(target_managed_code_tree_hash)" 'full managed v1 code tree before checkpoint recovery'
assert_eq "$V1_REVISION" "$(ledger_revision)" 'v1 code revision before checkpoint recovery'
assert_eq "$V2_FAILED_CHECKPOINT_SHA256" "$(sha256sum "$V2_FAILED_CHECKPOINT_HOST" | awk '{print $1}')" 'failed v2 checkpoint bytes before recovery import'
if ! V2_RECOVERY_OUT="$(php "$WPRISM" --envs-file="$ENVS_FILE" recover target \
  --restore="$V2_FAILED_RUN_ID" --writers-excluded --operator-directed 2>&1)"; then
  echo "$V2_RECOVERY_OUT" >&2
  fail 'failed v2 encrypted checkpoint recovery did not complete'
fi
assert_phase_order "$V2_RECOVERY_OUT" '  abort: ok' '  begin: ok' '  import: ok' '  final-abort: ok'
assert_eq "$V1_TARGET_MANAGED_CODE_TREE_HASH" "$(target_managed_code_tree_hash)" 'full managed v1 code tree after checkpoint recovery'
assert_eq "$V1_REVISION" "$(ledger_revision)" 'v1 revision after failed-v2 checkpoint restore'
assert_eq retail "$(target_db_scalar "SELECT option_value FROM wp_options WHERE option_name = 'wprism_commerce_extension_settings' LIMIT 1")" 'v1 scalar after checkpoint restore'
assert_eq 0 "$(target_db_scalar "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_wprism_commerce_extension_events' AND COLUMN_NAME = 'context'")" 'v1 table shape after checkpoint restore'
assert_extension_runtime_event 0 "" 'v1 runtime row after checkpoint restore'
# The failed-v2 checkpoint was intentionally taken after the preceding
# public deactivation milestone. Recovery must therefore restore that exact
# lifecycle state; expecting the extension active here would validate a
# state the checkpoint never contained.
assert_eq "$EXTENSION_INACTIVE_ACTIVE_PLUGINS_JSON" "$(active_plugins_json)" 'inactive-extension plugin order after checkpoint restore'
assert_runtime_isolation 'v1 checkpoint recovery' 0
assert_theme_versions 'v1 checkpoint recovery' '1.0.0' '1.0.0'
assert_theme_portable_relationships 'v1 checkpoint recovery'

cp "$FIXTURE/v2/fixed/$EXT_FILE" "$SITE/code/wp-content/plugins/$EXT_SLUG/$EXT_FILE"
V2_SOURCE_MANAGED_CODE_TREE_HASH="$(source_managed_code_tree_hash)"
[ "$V2_SOURCE_MANAGED_CODE_TREE_HASH" != "$V1_TARGET_MANAGED_CODE_TREE_HASH" ] \
  || fail 'fixed v2 repository managed code tree did not differ from v1'
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'code: fixed v2 migration retry'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
if ! V2_OUT="$(promote 2>&1)"; then
  echo "$V2_OUT" >&2
  fail 'fixed v2 retry failed'
fi
echo "$V2_OUT"
assert_phase_order "$V2_OUT" 'promote phase: compile' 'promote phase: code-preflight' 'promote phase: promotion-begin' 'promote phase: checkpoint' 'promote phase: code-stage' 'promote phase: lifecycle-retire' 'promote phase: lifecycle-activate' 'promote phase: code-finalize' 'promote phase: apply'
assert_theme_and_dependency "$AUTHORED_ACTIVE_PLUGINS_JSON"
assert_frontend_child_parent 'fixed v2 retry' 1
assert_theme_versions 'reviewed v2 theme upgrade' '1.1.0' '2.0.0'
assert_theme_portable_relationships 'reviewed v2 theme upgrade'
assert_extension_rest_status '2.0.0' 2 'fixed v2 retry'
target_wp option get wprism_commerce_extension_settings --format=json | jq -e '.schema == 2 and .channel == "retail" and .catalog_mode == "managed"' >/dev/null || fail 'v2 migration/state object did not converge'
assert_eq 2 "$(target_wp option get wprism_commerce_extension_schema)" 'v2 extension schema'
assert_eq 1 "$(target_db_scalar "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_wprism_commerce_extension_events' AND COLUMN_NAME = 'context'")" 'v2 migrated table shape'
assert_extension_runtime_event 1 "" 'fixed v2 migrated runtime row'
assert_trace_has "$(target_wp option get wprism_commerce_extension_trace --format=json)" 'migrate:v1-to-v2:retail'
assert_trace_has "$(target_wp option get wprism_commerce_extension_trace --format=json)" 'activate:commerce-v2'
assert_eq target-only-synthetic-secret "$(target_wp option get wprism_commerce_extension_gateway_secret)" 'env-owned target gateway secret survives v2'
assert_eq "$DELETION_PROBE_V1_TARGET_ID" "$(target_wp post list --post_type=product --name=wprism-grind-delete-probe --field=ID)" 'deletion probe id survives v2'
assert_deletion_probe_lookup_present "$DELETION_PROBE_V1_TARGET_ID"
V2_ARTIFACT="$(artifact_for_promote_output "$V2_OUT")"
V2_REVISION="$(jq -r '.code.code_revision' "$V2_ARTIFACT")"
V2_ARTIFACT_HASH="$(jq -r '.artifact_hash' "$V2_ARTIFACT")"
assert_receipt "$V2_ARTIFACT" 'reviewed v2 theme upgrade promote' "$V2_REVISION"
[ "$V2_REVISION" != "$V1_REVISION" ] || fail 'v2 did not publish a distinct code revision'
assert_eq "$V2_SOURCE_MANAGED_CODE_TREE_HASH" "$(target_managed_code_tree_hash)" 'exact fixed v2 managed code tree'
assert_woo_catalog 1 5
assert_product_visibility_projection
assert_derived_indexes 7 instock
assert_store_api_http 1499 'fixed v2 retry'
assert_eq 7 "$(target_wp eval '$p = get_page_by_path("wprism-grind-cap", OBJECT, "product"); $product = $p ? wc_get_product($p->ID) : null; echo $product ? (int) $product->get_stock_quantity() : -1;')" 'target-only stock survives v2 promote'
assert_target_order_unchanged 'target-only order survives v2 promote'

say "source author v2 runtime migration: load the reviewed plugin and derive the evolved event baseline"
"${PAIR_COMPOSE[@]}" run --rm -T cli1 sh -c 'cp -a /siterepo/code/wp-content/plugins/wprism-commerce-extension/. /var/www/html/wp-content/plugins/wprism-commerce-extension/'
source_wp option get wprism_commerce_extension_schema >/dev/null
assert_eq 2 "$(source_wp option get wprism_commerce_extension_schema)" 'source v2 runtime migration schema'
assert_eq 1 "$(source_db_scalar "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_wprism_commerce_extension_events' AND COLUMN_NAME = 'context'")" 'source v2 runtime migration table shape'
source_wp option update --format=json active_plugins "$AUTHORED_ACTIVE_PLUGINS_JSON" >/dev/null
assert_eq "$AUTHORED_ACTIVE_PLUGINS_JSON" "$(source_wp option get active_plugins --format=json | jq -c '.')" 'source v2 runtime migration authored plugin order'
assert_source_runtime_baseline 'source v2 runtime migration'
assert_runtime_isolation 'fixed v2 retry' 1
pass "theme lifecycle: reviewed upgrade preserves portable relationships"
pass "exact v1 checkpoint recovery removed failed migration effects; fixed v2 activation migrated before canonical object apply and preserved target runtime order/stock"

say "real author product update: change grouped child price/merchandising, capture, and promote the state delta"
source_wp wc product update "$CAP_ID" --regular_price=16.49 --user=admin >/dev/null
source_wp post update "$CAP_ID" --post_excerpt='Managed stock copy after v2 review' >/dev/null
source_wp wprism capture --repo=/siterepo >/dev/null
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'catalog: author updates cap merchandising excerpt'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
if ! PRODUCT_UPDATE_OUT="$(promote 2>&1)"; then
  echo "$PRODUCT_UPDATE_OUT" >&2
  fail 'author product update promote failed'
fi
echo "$PRODUCT_UPDATE_OUT"
assert_eq 'Managed stock copy after v2 review' "$(target_wp eval '$p = get_page_by_path("wprism-grind-cap", OBJECT, "product"); echo $p ? $p->post_excerpt : "";')" 'author product excerpt after promote'
assert_deletion_probe_lookup_present "$DELETION_PROBE_V1_TARGET_ID"
assert_derived_indexes 7 instock 16.49 9.99 16.49 1649
assert_store_api_http 1649 'author product update promote'
assert_eq 7 "$(target_wp eval '$p = get_page_by_path("wprism-grind-cap", OBJECT, "product"); $product = $p ? wc_get_product($p->ID) : null; echo $product ? (int) $product->get_stock_quantity() : -1;')" 'runtime stock survives author product promote'
assert_target_order_unchanged 'runtime order survives author product promote'
assert_runtime_isolation 'author product update promote' 1
PRODUCT_UPDATE_ARTIFACT="$(artifact_for_promote_output "$PRODUCT_UPDATE_OUT")"
assert_receipt "$PRODUCT_UPDATE_ARTIFACT" 'author product update promote' "$V2_REVISION"
pass "the author-side grouped-child price/merchandising edit refreshed the grouped root while target-only HPOS order/stock remained runtime-local"

say "theme lifecycle: incompatible downgrade refuses before target mutation"
THEME_TARGET_RUNTIME="$(target_wp eval 'global $wp_version; echo wp_json_encode(["php" => PHP_VERSION, "wordpress" => (string) $wp_version]);')"
THEME_TARGET_PHP="$(jq -r '.php' <<<"$THEME_TARGET_RUNTIME")"
THEME_TARGET_WORDPRESS="$(jq -r '.wordpress' <<<"$THEME_TARGET_RUNTIME")"
THEME_DOWNGRADE_TREE_BEFORE="$(target_managed_code_tree_hash)"
THEME_DOWNGRADE_REVISION_BEFORE="$(ledger_revision)"
THEME_DOWNGRADE_LIFECYCLE_BEFORE="$(theme_lifecycle_snapshot)"
THEME_DOWNGRADE_SESSION_BEFORE="$(ledger_value promotion_session)"
THEME_DOWNGRADE_LOCK_BEFORE="$(ledger_value promotion_lock)"
rm -rf -- "$SITE/code/wp-content/themes/$PARENT_THEME"
rm -rf -- "$SITE/code/wp-content/themes/$CHILD_THEME"
cp -a "$FIXTURE/v1/wp-content/themes/$PARENT_THEME" "$SITE/code/wp-content/themes/"
cp -a "$FIXTURE/v1/wp-content/themes/$CHILD_THEME" "$SITE/code/wp-content/themes/"
php -r '
$path = $argv[1];
$bytes = file_get_contents($path);
$needle = "Version: 1.0.0\n";
if (!is_string($bytes) || substr_count($bytes, $needle) !== 1) { exit(2); }
$next = str_replace($needle, $needle . "Requires PHP: 99.0\n", $bytes);
if (file_put_contents($path, $next) === false) { exit(3); }
' "$SITE/code/wp-content/themes/$CHILD_THEME/style.css"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'test: reject incompatible child-theme downgrade'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
THEME_DOWNGRADE_PLAN="$(plan_json)"
jq -e \
  --arg theme "$CHILD_THEME" \
  --arg php "$THEME_TARGET_PHP" \
  --arg wordpress "$THEME_TARGET_WORDPRESS" \
  '[.code_mismatch[] | select(
      .issue == "code_source_requires_php_incompatible"
      and .kind == "theme"
      and .theme == $theme
      and .target_php == $php
      and .target_wordpress == $wordpress
      and .required_version == "99.0"
      and .non_forceable == true
      and (.code_revision | test("^[0-9a-f]{64}$"))
      and (.component_sha256 | test("^[0-9a-f]{64}$"))
    )] | length == 1' <<<"$THEME_DOWNGRADE_PLAN" >/dev/null \
  || fail 'semantic plan did not bind the incompatible child-theme downgrade to exact target/runtime code evidence'
if THEME_DOWNGRADE_OUT="$(deploy --force-code-mismatch 2>&1)"; then
  echo "$THEME_DOWNGRADE_OUT" >&2
  fail 'incompatible child-theme downgrade unexpectedly deployed'
fi
echo "$THEME_DOWNGRADE_OUT"
grep -Fq 'code_source_requires_php_incompatible' <<<"$THEME_DOWNGRADE_OUT" \
  || fail 'theme downgrade refusal lost its structured runtime diagnostic'
grep -Fq "$CHILD_THEME" <<<"$THEME_DOWNGRADE_OUT" \
  || fail 'theme downgrade refusal did not name the exact child theme'
grep -Fq "target PHP is $THEME_TARGET_PHP" <<<"$THEME_DOWNGRADE_OUT" \
  || fail 'theme downgrade refusal did not name the exact target PHP value'
assert_absent "$THEME_DOWNGRADE_OUT" 'deploy phase: promotion-begin' 'incompatible theme downgrade'
assert_eq "$THEME_DOWNGRADE_TREE_BEFORE" "$(target_managed_code_tree_hash)" 'managed code tree after incompatible theme downgrade refusal'
assert_eq "$THEME_DOWNGRADE_REVISION_BEFORE" "$(ledger_revision)" 'code revision after incompatible theme downgrade refusal'
assert_theme_lifecycle_unchanged "$THEME_DOWNGRADE_LIFECYCLE_BEFORE" 'incompatible theme downgrade refusal'
assert_eq "$THEME_DOWNGRADE_SESSION_BEFORE" "$(ledger_value promotion_session)" 'promotion session after incompatible theme downgrade refusal'
assert_eq "$THEME_DOWNGRADE_LOCK_BEFORE" "$(ledger_value promotion_lock)" 'promotion lock after incompatible theme downgrade refusal'
rm -rf -- "$SITE/code/wp-content/themes/$PARENT_THEME"
rm -rf -- "$SITE/code/wp-content/themes/$CHILD_THEME"
cp -a "$FIXTURE/v2/wp-content/themes/$PARENT_THEME" "$SITE/code/wp-content/themes/"
cp -a "$FIXTURE/v2/wp-content/themes/$CHILD_THEME" "$SITE/code/wp-content/themes/"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'test: restore reviewed v2 themes after downgrade refusal'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
pass "theme lifecycle: incompatible downgrade refuses before target mutation"

say "theme lifecycle: unsafe parent removal refuses before target mutation"
THEME_PARENT_REMOVE_TREE_BEFORE="$(target_managed_code_tree_hash)"
THEME_PARENT_REMOVE_REVISION_BEFORE="$(ledger_revision)"
THEME_PARENT_REMOVE_LIFECYCLE_BEFORE="$(theme_lifecycle_snapshot)"
THEME_PARENT_REMOVE_SESSION_BEFORE="$(ledger_value promotion_session)"
THEME_PARENT_REMOVE_LOCK_BEFORE="$(ledger_value promotion_lock)"
rm -rf -- "$SITE/code/wp-content/themes/$PARENT_THEME"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'test: reject child theme without its canonical parent'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
if THEME_PARENT_REMOVE_OUT="$(deploy 2>&1)"; then
  echo "$THEME_PARENT_REMOVE_OUT" >&2
  fail 'unsafe parent-theme removal unexpectedly deployed'
fi
echo "$THEME_PARENT_REMOVE_OUT"
grep -Fq 'code_state_mismatch' <<<"$THEME_PARENT_REMOVE_OUT" \
  || fail 'unsafe parent removal did not retain the code_state_mismatch diagnostic'
grep -Fq "canonical template '$PARENT_THEME' has no matching theme directory in code/wp-content/themes" <<<"$THEME_PARENT_REMOVE_OUT" \
  || fail 'unsafe parent removal did not name the exact canonical template dependency'
assert_absent "$THEME_PARENT_REMOVE_OUT" 'deploy phase: promotion-begin' 'unsafe parent-theme removal'
assert_eq "$THEME_PARENT_REMOVE_TREE_BEFORE" "$(target_managed_code_tree_hash)" 'managed code tree after unsafe parent removal refusal'
assert_eq "$THEME_PARENT_REMOVE_REVISION_BEFORE" "$(ledger_revision)" 'code revision after unsafe parent removal refusal'
assert_theme_lifecycle_unchanged "$THEME_PARENT_REMOVE_LIFECYCLE_BEFORE" 'unsafe parent removal refusal'
assert_eq "$THEME_PARENT_REMOVE_SESSION_BEFORE" "$(ledger_value promotion_session)" 'promotion session after unsafe parent removal refusal'
assert_eq "$THEME_PARENT_REMOVE_LOCK_BEFORE" "$(ledger_value promotion_lock)" 'promotion lock after unsafe parent removal refusal'
cp -a "$FIXTURE/v2/wp-content/themes/$PARENT_THEME" "$SITE/code/wp-content/themes/"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'test: restore parent after dependency refusal'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
pass "theme lifecycle: unsafe parent removal refuses before target mutation"

say "theme lifecycle: explicit safe child removal failure/retry"
# Correct the refused dependency move through ordinary WordPress lifecycle
# APIs. Capture records standalone-parent intent and resolves the theme-local
# media/menu ids; the reviewed code descriptor can then remove only the child.
source_wp theme activate "$PARENT_THEME" >/dev/null
source_wp eval '
set_theme_mod("background_color", "1f4b6e");
set_theme_mod("custom_logo", '"$MEDIA_ID"');
$locations = (array) get_theme_mod("nav_menu_locations", []);
$locations["primary"] = '"$MENU_ID"';
set_theme_mod("nav_menu_locations", $locations);
' >/dev/null
assert_eq "$PARENT_THEME" "$(source_wp option get template)" 'source template before safe child removal'
assert_eq "$PARENT_THEME" "$(source_wp option get stylesheet)" 'source stylesheet before safe child removal'
source_wp wprism capture --repo=/siterepo >/dev/null
rm -rf -- "$SITE/code/wp-content/themes/$CHILD_THEME"
THEME_SAFE_REMOVE_SOURCE_TREE="$(source_managed_code_tree_hash)"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'lifecycle: switch to parent and remove child theme'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
if ! THEME_SAFE_REMOVE_OUT="$(promote --force-theirs 2>&1)"; then
  echo "$THEME_SAFE_REMOVE_OUT" >&2
  fail 'safe child-theme removal promote failed'
fi
echo "$THEME_SAFE_REMOVE_OUT"
assert_phase_order "$THEME_SAFE_REMOVE_OUT" \
  'promote phase: compile' \
  'promote phase: code-preflight' \
  'promote phase: promotion-begin' \
  'promote phase: checkpoint' \
  'promote phase: code-stage' \
  'promote phase: lifecycle-retire' \
  'promote phase: lifecycle-activate' \
  'promote phase: code-finalize' \
  'promote phase: apply'
assert_parent_theme_and_dependencies
assert_theme_child_removed 'theme safe child removal'
assert_theme_versions 'safe child-theme removal' '1.1.0' 'absent'
assert_theme_portable_relationships 'theme safe child removal' "$PARENT_THEME"
assert_frontend_parent 'safe child-theme removal'
assert_eq "$THEME_SAFE_REMOVE_SOURCE_TREE" "$(target_managed_code_tree_hash)" 'exact managed code tree after safe child-theme removal'
assert_ecommerce_menu 'safe child-theme removal'
assert_target_order_unchanged 'target-only order survives safe child-theme removal'
assert_eq 7 "$(target_wp eval '$p = get_page_by_path("wprism-grind-cap", OBJECT, "product"); $product = $p ? wc_get_product($p->ID) : null; echo $product ? (int) $product->get_stock_quantity() : -1;')" 'target-only stock survives safe child-theme removal'
assert_runtime_isolation 'safe child-theme removal' 1
THEME_SAFE_REMOVE_ARTIFACT="$(artifact_for_promote_output "$THEME_SAFE_REMOVE_OUT")"
THEME_SAFE_REMOVE_REVISION="$(jq -r '.code.code_revision' "$THEME_SAFE_REMOVE_ARTIFACT")"
assert_receipt "$THEME_SAFE_REMOVE_ARTIFACT" 'safe child-theme removal promote' "$THEME_SAFE_REMOVE_REVISION"
[ "$THEME_SAFE_REMOVE_REVISION" != "$V2_REVISION" ] || fail 'safe child-theme removal did not publish a distinct code revision'
pass "theme lifecycle: explicit safe child removal failure/retry"

# Keep this boundary after every ordinary source capture. Once a public Woo delete
# removes the probe from the source database, every later capture would
# correctly encounter the unsupported disappearance again.
say "Woo deletion boundary: public product delete is refused before WPrism capture mutation"
DELETION_PROBE_STATE_FILE="$(find "$SITE/state/posts/product" -type f -name '*--wprism-grind-delete-probe.md' -print -quit)"
[ -n "$DELETION_PROBE_STATE_FILE" ] || fail 'source deletion probe state file was not captured before deletion'
DELETION_PROBE_UUID="$(WPRISM_CANON="$REPO_ROOT/agent/src/Kernel/Canon.php" php -r '
require getenv("WPRISM_CANON");
[$front] = WPrism\Canon::parse_post_file(file_get_contents($argv[1]));
echo $front["uuid"];
' "$DELETION_PROBE_STATE_FILE")"
[[ "$DELETION_PROBE_UUID" =~ ^[0-9a-f-]{36}$ ]] || fail "source deletion probe UUID is malformed: $DELETION_PROBE_UUID"
DELETION_PROBE_STATE_TREE_BEFORE="$(state_tree_hash "$SITE")"
DELETION_PROBE_REPO_HEAD_BEFORE="$(git -C "$SITE" rev-parse HEAD)"
DELETION_PROBE_ORIGIN_HEAD_BEFORE="$(git --git-dir="$ORIGIN" rev-parse refs/heads/main)"
DELETION_PROBE_STATUS_BEFORE="$(git -C "$SITE" status --porcelain=v1 --untracked-files=all)"
source_wp wc product delete "$DELETION_PROBE_SOURCE_ID" --force=true --user=admin >/dev/null
assert_eq "" "$(source_wp post list --post_type=product --name=wprism-grind-delete-probe --field=ID)" 'source product is gone after public Woo delete'
DELETION_PROBE_LEDGER_BEFORE="$(source_wprism_ledger_snapshot)"
if DELETION_REFUSAL_OUT="$(source_wp wprism capture --repo=/siterepo 2>&1)"; then
  echo "$DELETION_REFUSAL_OUT" >&2
  fail 'unsupported Woo product deletion capture unexpectedly succeeded'
fi
echo "$DELETION_REFUSAL_OUT"
grep -Fq 'deletion intent for post:product is unsupported' <<<"$DELETION_REFUSAL_OUT" \
  || fail 'Woo product deletion refusal did not name the unsupported selector'
assert_eq "$DELETION_PROBE_STATE_TREE_BEFORE" "$(state_tree_hash "$SITE")" 'state tree after unsupported Woo product deletion refusal'
assert_eq "$DELETION_PROBE_REPO_HEAD_BEFORE" "$(git -C "$SITE" rev-parse HEAD)" 'repository revision after unsupported Woo product deletion refusal'
assert_eq "$DELETION_PROBE_ORIGIN_HEAD_BEFORE" "$(git --git-dir="$ORIGIN" rev-parse refs/heads/main)" 'published origin after unsupported Woo product deletion refusal'
assert_eq "$DELETION_PROBE_STATUS_BEFORE" "$(git -C "$SITE" status --porcelain=v1 --untracked-files=all)" 'repository status after unsupported Woo product deletion refusal'
assert_eq "$DELETION_PROBE_LEDGER_BEFORE" "$(source_wprism_ledger_snapshot)" 'WPrism ledgers after unsupported Woo product deletion refusal'
[ -e "$DELETION_PROBE_STATE_FILE" ] || fail 'unsupported Woo product deletion refusal removed the canonical product state'
[ ! -e "$SITE/state/deletions/$DELETION_PROBE_UUID.json" ] || fail 'unsupported Woo product deletion refusal published a tombstone'
assert_parent_theme_and_dependencies
assert_theme_versions 'unsupported Woo product deletion refusal' '1.1.0' 'absent'
assert_theme_portable_relationships 'unsupported Woo product deletion refusal' "$PARENT_THEME"
assert_woo_catalog 1 5
assert_deletion_probe_lookup_present "$DELETION_PROBE_V1_TARGET_ID"
assert_derived_indexes 7 instock 16.49 9.99 16.49 1649
assert_store_api_http 1649 'unsupported Woo product deletion refusal'
assert_eq "$THEME_SAFE_REMOVE_REVISION" "$(ledger_revision)" 'code revision survives unsupported Woo product deletion refusal'
assert_eq target-only-synthetic-secret "$(target_wp option get wprism_commerce_extension_gateway_secret)" 'env-owned secret survives unsupported Woo product deletion refusal'
assert_target_order_unchanged 'target-only order survives unsupported Woo product deletion refusal'
assert_eq 7 "$(target_wp eval '$p = get_page_by_path("wprism-grind-cap", OBJECT, "product"); $product = $p ? wc_get_product($p->ID) : null; echo $product ? (int) $product->get_stock_quantity() : -1;')" 'target-only cap stock survives unsupported Woo product deletion refusal'
assert_product_visibility_projection
assert_runtime_isolation 'unsupported Woo product deletion refusal' 1
pass 'public Woo product deletion reached the source boundary, but WPrism capture failed closed with no tombstone, repository mutation, or target change'

say "source compatibility guard: pinned WooCommerce 10.9.4 downgrade is rejected before promotion-begin"
"${PAIR_COMPOSE[@]}" run --rm -T -u root cli1 sh -c '
  set -eu
  archive="$1"
  expected_version="$2"
  host_uid="$3"
  host_gid="$4"
  plugin_root=/siterepo/code/wp-content/plugins
  current="$plugin_root/woocommerce"
  next="$plugin_root/.wprism-woocommerce-next"
  previous="$plugin_root/.wprism-woocommerce-previous"
  unpack=/tmp/wprism-woo-1094
  cleanup_stage() {
    rm -rf "$unpack" "$next"
    if [ ! -e "$current" ] && [ -e "$previous" ]; then
      mv "$previous" "$current"
    elif [ -e "$current" ] && [ -e "$previous" ]; then
      rm -rf "$previous"
    fi
  }
  trap cleanup_stage EXIT
  test -f "$current/woocommerce.php"
  test ! -e "$next"
  test ! -e "$previous"
  rm -rf "$unpack"
  mkdir -p "$unpack"
  unzip -q "$archive" -d "$unpack"
  test -f "$unpack/woocommerce/woocommerce.php"
  version="$(sed -n "s/^[[:space:]]*\\*[[:space:]]*Version:[[:space:]]*//p" "$unpack/woocommerce/woocommerce.php" | head -1 | tr -d "\r")"
  test "$version" = "$expected_version"
  cp -a "$unpack/woocommerce" "$next"
  test -f "$next/woocommerce.php"
  chown -R "$host_uid:$host_gid" "$next"
  mv "$current" "$previous"
  if ! mv "$next" "$current"; then
    mv "$previous" "$current"
    exit 1
  fi
  rm -rf "$previous" "$unpack"
  trap - EXIT
' _ "$WOO_1094_ARTIFACT" "$WOO_DOWNGRADE_VERSION" "$ECOMMERCE_HOST_UID" "$ECOMMERCE_HOST_GID"
TARGET_PLUGIN_TREE_BEFORE_WOO_DOWNGRADE="$(target_plugin_tree_hash)"
REV_BEFORE_WOO_DOWNGRADE="$(ledger_revision)"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'test: reject pinned WooCommerce 10.9.4 source downgrade'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
if WOO_DOWNGRADE_OUT="$(deploy 2>&1)"; then
  echo "$WOO_DOWNGRADE_OUT" >&2
  fail 'WooCommerce 10.9.4 source downgrade unexpectedly passed the compatibility gate'
fi
echo "$WOO_DOWNGRADE_OUT"
grep -Fq 'code_source_outside_version_range' <<<"$WOO_DOWNGRADE_OUT" || fail 'Woo downgrade refusal did not name the pinned version-range diagnostic'
assert_absent "$WOO_DOWNGRADE_OUT" 'deploy phase: promotion-begin' 'Woo 10.9.4 source compatibility refusal'
assert_eq "$TARGET_PLUGIN_TREE_BEFORE_WOO_DOWNGRADE" "$(target_plugin_tree_hash)" 'entire target plugin tree after Woo downgrade refusal'
assert_eq "$REV_BEFORE_WOO_DOWNGRADE" "$(ledger_revision)" 'target code revision after Woo downgrade refusal'
rm -rf -- "$SITE/code/wp-content/plugins/$WOO_SLUG"
cp -a "$V1_INPUTS/code/wp-content/plugins/$WOO_SLUG" "$SITE/code/wp-content/plugins/"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'test: restore pinned WooCommerce 11.0.0 after downgrade refusal'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
pass "pinned WooCommerce 10.9.4 was rejected at source compile with the target plugin tree and code revision untouched; 11.0.0 restored"

say "dependency topology/order guard: native active_plugins order is accepted; missing Woo provider stops before target mutation"
TARGET_PLUGIN_TREE_BEFORE_DEPENDENCY="$(target_plugin_tree_hash)"
REV_BEFORE_DEPENDENCY="$(ledger_revision)"
jq --arg woo "$WOO_BASENAME" --arg acf "$ACF_BASENAME" --arg extension "$EXT_BASENAME" '.records.active_plugins.value = [$acf, $extension, $woo]' "$STATE" > "$STATE.next"
mv "$STATE.next" "$STATE"
canonicalize_json "$STATE"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'test: accept native active plugin order with dependency topology'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
if ! NATIVE_ORDER_OUT="$(deploy 2>&1)"; then
  echo "$NATIVE_ORDER_OUT" >&2
  fail 'native/alphabetical active_plugins order was rejected despite valid dependency closure'
fi
echo "$NATIVE_ORDER_OUT"
assert_eq "$NATIVE_ACTIVE_PLUGINS_JSON" "$(active_plugins_json)" 'native/alphabetical active_plugins order after accepted deploy'
assert_eq "$TARGET_PLUGIN_TREE_BEFORE_DEPENDENCY" "$(target_plugin_tree_hash)" 'target plugin tree after accepted native order'
assert_eq "$REV_BEFORE_DEPENDENCY" "$(ledger_revision)" 'target code revision after accepted native order'

jq --arg acf "$ACF_BASENAME" --arg extension "$EXT_BASENAME" '.records.active_plugins.value = [$acf, $extension]' "$STATE" > "$STATE.next"
mv "$STATE.next" "$STATE"
canonicalize_json "$STATE"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'test: reject custom plugin without Woo dependency closure'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
if DEPENDENCY_CLOSURE_OUT="$(deploy 2>&1)"; then
  echo "$DEPENDENCY_CLOSURE_OUT" >&2
  fail 'custom-without-Woo dependency closure unexpectedly passed compile'
fi
echo "$DEPENDENCY_CLOSURE_OUT"
grep -Fq 'code_plugin_dependency_inactive' <<<"$DEPENDENCY_CLOSURE_OUT" || fail 'dependency closure refusal did not name code_plugin_dependency_inactive'
assert_absent "$DEPENDENCY_CLOSURE_OUT" 'deploy phase: promotion-begin' 'custom-without-Woo dependency refusal'
assert_eq "$TARGET_PLUGIN_TREE_BEFORE_DEPENDENCY" "$(target_plugin_tree_hash)" 'target plugin tree after dependency-closure refusal'
assert_eq "$REV_BEFORE_DEPENDENCY" "$(ledger_revision)" 'target code revision after dependency-closure refusal'

jq --arg woo "$WOO_BASENAME" --arg acf "$ACF_BASENAME" --arg extension "$EXT_BASENAME" '.records.active_plugins.value = [$woo, $acf, $extension]' "$STATE" > "$STATE.next"
mv "$STATE.next" "$STATE"
canonicalize_json "$STATE"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'test: restore Woo dependency closure and order'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
if ! DEPENDENCY_ORDER_RESTORE_OUT="$(deploy 2>&1)"; then
  echo "$DEPENDENCY_ORDER_RESTORE_OUT" >&2
  fail 'restoring authored active_plugins order after closure refusal failed'
fi
assert_eq "$AUTHORED_ACTIVE_PLUGINS_JSON" "$(active_plugins_json)" 'authored active_plugins order after dependency phase restore'
pass "native active_plugins order was accepted after provider-first lifecycle planning; missing Woo provider was rejected before promotion-begin and authored order restored"

say "state drift then conflict: target edit is visible before an intentional branch edit"
PRODUCT_FILE="$(find "$SITE/state/posts/product" -type f -name '*--wprism-grind-tee.md' -print -quit)"
[ -n "$PRODUCT_FILE" ] || fail 'canonical variable product file missing'
TEE_UUID="$(WPRISM_CANON="$REPO_ROOT/agent/src/Kernel/Canon.php" php -r '
require getenv("WPRISM_CANON");
[$front] = WPrism\Canon::parse_post_file(file_get_contents($argv[1]));
echo $front["uuid"];
' "$PRODUCT_FILE")"
CONFLICT_PRODUCT_PATH="${PRODUCT_FILE#"$SITE/state/"}"
[[ "$TEE_UUID" =~ ^[0-9a-f-]{36}$ ]] || fail "canonical tee UUID is malformed: $TEE_UUID"
[[ "$CONFLICT_PRODUCT_PATH" == posts/product/* ]] || fail "canonical tee path is malformed: $CONFLICT_PRODUCT_PATH"
cp "$PRODUCT_FILE" "$V1_INPUTS/product-before-conflict.md"
target_wp eval 'if ($p = get_page_by_path("wprism-grind-tee", OBJECT, "product")) { wp_update_post(["ID" => $p->ID, "post_title" => "WPrism Grind Tee (target drift)"]); } else { throw new RuntimeException("target tee missing"); }' >/dev/null
if DRIFT_STATUS="$(status 2>&1)"; then
  echo "$DRIFT_STATUS" >&2
  fail 'status unexpectedly accepted target product drift'
fi
echo "$DRIFT_STATUS"
grep -Eqi 'drift' <<<"$DRIFT_STATUS" || fail 'status did not report ordinary state drift'
WPRISM_CANON="$REPO_ROOT/agent/src/Kernel/Canon.php" php -r '
require getenv("WPRISM_CANON");
$path = $argv[1];
[$front, $body] = WPrism\Canon::parse_post_file(WPrism\Canon::read_file($path));
if (($front["title"] ?? null) !== "WPrism Grind Tee") {
    throw new RuntimeException("unexpected canonical tee title before branch edit");
}
$front["title"] = "WPrism Grind Tee (branch change)";
WPrism\Canon::write_file($path, WPrism\Canon::post_file($front, $body));
' "$PRODUCT_FILE"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'state: intentional product branch edit against target drift'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
if ! CONFLICT_PLAN="$(plan_json)"; then
  fail 'conflict plan command failed before returning machine-readable JSON'
fi
echo "$CONFLICT_PLAN" | jq -e --arg uuid "$TEE_UUID" --arg path "$CONFLICT_PRODUCT_PATH" '(.conflict // []) | length == 1 and .[0].uuid == $uuid and .[0].path == $path' >/dev/null || fail "plan did not expose exactly the tee conflict ($TEE_UUID, $CONFLICT_PRODUCT_PATH): $CONFLICT_PLAN"
CONFLICT_TEE_BEFORE="$(target_tee_snapshot "$TEE_UUID")"
CONFLICT_TEE_STATE_BEFORE="$(target_db_scalar "SELECT CONCAT(entity_type, '|', content_hash) FROM wp_wprism_state WHERE uuid = '$TEE_UUID'")"
CONFLICT_LEDGER_REVISION_BEFORE="$(ledger_revision)"
CONFLICT_APPLIED_REVISION_BEFORE="$(ledger_value applied_revision)"
CONFLICT_APPLY_PROGRESS_BEFORE="$(ledger_value apply_in_progress)"
CONFLICT_CAP_STOCK_BEFORE="$(target_wp eval '$p = get_page_by_path("wprism-grind-cap", OBJECT, "product"); $product = $p ? wc_get_product($p->ID) : null; echo $product ? (int) $product->get_stock_quantity() : -1;')"
assert_target_order_unchanged 'target-only order before conflict refusal'
jq -e '.id > 0 and .title == "WPrism Grind Tee (target drift)" and (.content | type) == "string" and (.excerpt | type) == "string" and .status == "publish" and (.meta | type) == "array" and (.terms | type) == "array" and (.authored_hash | test("^[0-9a-f]{64}$"))' <<<"$CONFLICT_TEE_BEFORE" >/dev/null || fail "target tee conflict snapshot diagnostics failed: $CONFLICT_TEE_BEFORE"
if CONFLICT_APPLY="$(apply_state --adopt-by-slug=terms,posts --default-author=admin 2>&1)"; then
  echo "$CONFLICT_APPLY" >&2
  fail 'apply unexpectedly overwrote a conflicted WooCommerce product'
fi
echo "$CONFLICT_APPLY"
grep -Eqi 'conflict' <<<"$CONFLICT_APPLY" || fail 'conflict refusal did not name conflict'
assert_target_tee_unchanged "$CONFLICT_TEE_BEFORE" "$TEE_UUID" 'conflict refusal'
assert_eq "$CONFLICT_TEE_STATE_BEFORE" "$(target_db_scalar "SELECT CONCAT(entity_type, '|', content_hash) FROM wp_wprism_state WHERE uuid = '$TEE_UUID'")" 'tee state hash after conflict refusal'
assert_eq "$CONFLICT_LEDGER_REVISION_BEFORE" "$(ledger_revision)" 'code revision after conflict refusal'
assert_eq "$CONFLICT_APPLIED_REVISION_BEFORE" "$(ledger_value applied_revision)" 'applied revision after conflict refusal'
assert_eq "$CONFLICT_APPLY_PROGRESS_BEFORE" "$(ledger_value apply_in_progress)" 'apply progress marker after conflict refusal'
assert_runtime_isolation 'conflict refusal' 1
if [ -z "$CONFLICT_APPLY_PROGRESS_BEFORE" ]; then
  assert_eq 0 "$(target_db_scalar "SELECT COUNT(*) FROM wp_wprism_kv WHERE k = 'apply_in_progress'")" 'apply progress marker absence after conflict refusal'
fi
assert_eq "$CONFLICT_CAP_STOCK_BEFORE" "$(target_wp eval '$p = get_page_by_path("wprism-grind-cap", OBJECT, "product"); $product = $p ? wc_get_product($p->ID) : null; echo $product ? (int) $product->get_stock_quantity() : -1;')" 'target-only stock after conflict refusal'
assert_target_order_unchanged 'target-only order after conflict refusal'
cp "$V1_INPUTS/product-before-conflict.md" "$PRODUCT_FILE"
rm -f -- "$V1_INPUTS/product-before-conflict.md"
target_wp eval 'if ($p = get_page_by_path("wprism-grind-tee", OBJECT, "product")) { wp_update_post(["ID" => $p->ID, "post_title" => "WPrism Grind Tee"]); } else { throw new RuntimeException("target tee missing"); }' >/dev/null
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'state: resolve product conflict to canonical v2'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
apply_state --adopt-by-slug=terms,posts --default-author=admin >/dev/null
pass "ordinary state drift was reported, a conflicting branch edit was refused, and explicit operator resolution restored a clean plan"

say "code drift/preflight: mutate a target byte, observe stale status, then heal via public promote"
V2_TARGET_HASH="$(target_hash "$EXT_TARGET")"
target_php "file_put_contents('$EXT_TARGET', file_get_contents('$EXT_TARGET') . \"\\n// target-only byte drift\\n\");"
[ "$V2_TARGET_HASH" != "$(target_hash "$EXT_TARGET")" ] || fail 'could not introduce target code drift'
if CODE_DRIFT_STATUS="$(status 2>&1)"; then
  echo "$CODE_DRIFT_STATUS" >&2
  fail 'status unexpectedly accepted target code drift'
fi
echo "$CODE_DRIFT_STATUS"
grep -Eqi 'CODE_REVISION_STALE|code_drift|drift' <<<"$CODE_DRIFT_STATUS" || fail 'status did not surface target code drift/preflight'
if ! DRIFT_HEAL_OUT="$(promote 2>&1)"; then
  echo "$DRIFT_HEAL_OUT" >&2
  fail 'public promote did not heal target code drift'
fi
echo "$DRIFT_HEAL_OUT"
assert_eq "$(source_hash "$SITE/code/wp-content/plugins/$EXT_SLUG/$EXT_FILE")" "$(target_hash "$EXT_TARGET")" 'healed extension bytes'
assert_eq "$THEME_SAFE_REMOVE_REVISION" "$(ledger_revision)" 'code revision after byte-drift healing'
assert_eq target-only-synthetic-secret "$(target_wp option get wprism_commerce_extension_gateway_secret)" 'env-owned secret after code-drift healing'
DRIFT_HEAL_ARTIFACT="$(artifact_for_promote_output "$DRIFT_HEAL_OUT")"
assert_receipt "$DRIFT_HEAL_ARTIFACT" 'target code-drift healing promote' "$THEME_SAFE_REMOVE_REVISION"
assert_runtime_isolation 'target code-drift healing' 1
pass "target-only code drift was blocked by status and overwritten only by reviewed public promote"

say "explicit plugin identity replacement: preflight dependency refusal, semantic plan, retire old, activate new"
REPLACEMENT_PRIOR_INPUTS="$V1_INPUTS/replacement-prior"
mkdir -p "$REPLACEMENT_PRIOR_INPUTS"
cp -a "$SITE/code" "$REPLACEMENT_PRIOR_INPUTS/code"
cp -a "$SITE/state" "$REPLACEMENT_PRIOR_INPUTS/state"
cp "$SITE/site.wprism.json" "$REPLACEMENT_PRIOR_INPUTS/site.wprism.json"
OPTION_RECORD="$(jq -c '.records.wprism_commerce_extension_settings' "$STATE")"
OPTION_EXPECTED_HASH="$(WPRISM_CANON="$REPO_ROOT/agent/src/Kernel/Canon.php" php -r '
require getenv("WPRISM_CANON");
$record = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
echo hash("sha256", WPrism\Canon::encode($record));
' "$OPTION_RECORD")"
REPLACEMENT_PLUGIN_TREE_BEFORE="$(target_plugin_tree_hash)"
REPLACEMENT_MANAGED_TREE_BEFORE="$(target_managed_code_tree_hash)"
REPLACEMENT_OLD_FILE_HASH_BEFORE="$(target_hash "$EXT_TARGET")"
REPLACEMENT_REVISION_BEFORE="$(ledger_revision)"
REPLACEMENT_SESSION_BEFORE="$(ledger_value promotion_session)"
REPLACEMENT_LOCK_BEFORE="$(ledger_value promotion_lock)"
REPLACEMENT_ACTIVE_BEFORE="$(active_plugins_json)"
REPLACEMENT_PRIOR_ARTIFACT_HASH="$(jq -r '.artifact_hash' "$DRIFT_HEAL_ARTIFACT")"
REPLACEMENT_PRIOR_STATE_REVISION="$(jq -r '.revision_hash' "$DRIFT_HEAL_ARTIFACT")"
rm -rf -- "$SITE/code/wp-content/plugins/$EXT_SLUG"
mkdir -p "$SITE/code/wp-content/plugins/$REPLACEMENT_SLUG"
cp "$FIXTURE/replacement/fixed/$REPLACEMENT_FILE" \
  "$SITE/code/wp-content/plugins/$REPLACEMENT_SLUG/$REPLACEMENT_FILE"
jq --arg acf "$ACF_BASENAME" --arg replacement "$REPLACEMENT_BASENAME" --arg expected "$OPTION_EXPECTED_HASH" '
  .records.active_plugins.value = [$acf, $replacement]
  | .records.wprism_commerce_extension_settings = {expected_hash: $expected, state: "deleted"}
' "$STATE" > "$STATE.next"
mv "$STATE.next" "$STATE"
canonicalize_json "$STATE"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'replacement: reject replacement without its Woo provider'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
if REPLACEMENT_PREFLIGHT_OUT="$(deploy 2>&1)"; then
  echo "$REPLACEMENT_PREFLIGHT_OUT" >&2
  fail 'replacement without its declared Woo provider unexpectedly passed compile'
fi
echo "$REPLACEMENT_PREFLIGHT_OUT"
grep -Fq 'code_plugin_dependency_inactive' <<<"$REPLACEMENT_PREFLIGHT_OUT" \
  || fail 'replacement preflight did not name code_plugin_dependency_inactive'
grep -Fq "$REPLACEMENT_BASENAME" <<<"$REPLACEMENT_PREFLIGHT_OUT" \
  || fail 'replacement preflight did not name the incoming plugin identity'
grep -Fq "$WOO_SLUG" <<<"$REPLACEMENT_PREFLIGHT_OUT" \
  || fail 'replacement preflight did not name the missing canonical Woo provider'
assert_absent "$REPLACEMENT_PREFLIGHT_OUT" 'deploy phase: promotion-begin' 'replacement dependency preflight'
assert_eq "$REPLACEMENT_PLUGIN_TREE_BEFORE" "$(target_plugin_tree_hash)" 'target plugin tree after replacement preflight refusal'
assert_eq "$REPLACEMENT_MANAGED_TREE_BEFORE" "$(target_managed_code_tree_hash)" 'managed target tree after replacement preflight refusal'
assert_eq "$REPLACEMENT_OLD_FILE_HASH_BEFORE" "$(target_hash "$EXT_TARGET")" 'outgoing plugin bytes after replacement preflight refusal'
assert_eq absent "$(target_file "$REPLACEMENT_TARGET")" 'incoming plugin after replacement preflight refusal'
target_wp plugin is-active "$EXT_SLUG" >/dev/null || fail 'replacement preflight deactivated the outgoing plugin'
assert_eq "$REPLACEMENT_REVISION_BEFORE" "$(ledger_revision)" 'completed revision after replacement preflight refusal'
assert_eq "$REPLACEMENT_SESSION_BEFORE" "$(ledger_value promotion_session)" 'promotion session after replacement preflight refusal'
assert_eq "$REPLACEMENT_LOCK_BEFORE" "$(ledger_value promotion_lock)" 'promotion lease after replacement preflight refusal'
assert_eq "$REPLACEMENT_ACTIVE_BEFORE" "$(active_plugins_json)" 'active plugins after replacement preflight refusal'
assert_runtime_isolation 'replacement preflight refusal' 1

jq --arg woo "$WOO_BASENAME" --arg acf "$ACF_BASENAME" --arg replacement "$REPLACEMENT_BASENAME" '
  .records.active_plugins.value = [$woo, $acf, $replacement]
' "$STATE" > "$STATE.next"
mv "$STATE.next" "$STATE"
canonicalize_json "$STATE"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'replacement: declare reviewed Woo-backed plugin identity'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only

REPLACEMENT_PLAN_PLUGIN_TREE_BEFORE="$(target_plugin_tree_hash)"
REPLACEMENT_PLAN_MANAGED_TREE_BEFORE="$(target_managed_code_tree_hash)"
REPLACEMENT_PLAN_REVISION_BEFORE="$(ledger_revision)"
REPLACEMENT_PLAN_SESSION_BEFORE="$(ledger_value promotion_session)"
REPLACEMENT_PLAN_LOCK_BEFORE="$(ledger_value promotion_lock)"
REPLACEMENT_PLAN_ACTIVE_BEFORE="$(active_plugins_json)"
REPLACEMENT_PLAN_SETTING_BEFORE="$(target_wp option get wprism_commerce_extension_settings --format=json)"
if ! REPLACEMENT_PLAN="$(plan_json)"; then
  fail 'replacement semantic plan command failed before returning machine-readable JSON'
fi
jq -e --arg outgoing "$EXT_BASENAME" --arg incoming "$REPLACEMENT_BASENAME" --arg completed "$REPLACEMENT_REVISION_BEFORE" '
  ([.code_mismatch[] | select(.issue == "unexpected_active_plugin" and .kind == "plugin" and .plugin == $outgoing)] | length) == 1
  and ([.code_mismatch[] | select(.issue == "missing_in_code" and .kind == "plugin" and .plugin == $incoming)] | length) == 1
  and ([.code_mismatch[] | select(.issue == "code_revision_stale" and .kind == "code" and .completed_revision == $completed)] | length) == 1
  and ([.update[] | select(
    .uuid == "options/core"
    and .rebuild_option_names == ["wprism_commerce_extension_settings"]
    and (.option_deletes | index("wprism_commerce_extension_settings") != null)
  )] | length) == 1
  and (.adapter_dispositions == [])
  and (.provider_problems == [])
  and ([.effects_inventory[] | select(.phase == "rebuild" and .source == "provider:woocommerce-cache/invalidate_cache_groups")] | length) > 0
  and ([.effects_inventory[] | select(.phase == "rebuild" and .source == "provider:woocommerce-product-lookups/rebuild_product_lookups")] | length) > 0
' <<<"$REPLACEMENT_PLAN" >/dev/null \
  || fail "replacement plan did not expose the exact outgoing/incoming lifecycle, code/state, provider, and effect evidence: $REPLACEMENT_PLAN"
assert_eq "$REPLACEMENT_PLAN_PLUGIN_TREE_BEFORE" "$(target_plugin_tree_hash)" 'target plugin tree after replacement plan'
assert_eq "$REPLACEMENT_PLAN_MANAGED_TREE_BEFORE" "$(target_managed_code_tree_hash)" 'managed target tree after replacement plan'
assert_eq "$REPLACEMENT_PLAN_REVISION_BEFORE" "$(ledger_revision)" 'completed revision after replacement plan'
assert_eq "$REPLACEMENT_PLAN_SESSION_BEFORE" "$(ledger_value promotion_session)" 'promotion session after replacement plan'
assert_eq "$REPLACEMENT_PLAN_LOCK_BEFORE" "$(ledger_value promotion_lock)" 'promotion lease after replacement plan'
assert_eq "$REPLACEMENT_PLAN_ACTIVE_BEFORE" "$(active_plugins_json)" 'active plugins after replacement plan'
assert_eq "$REPLACEMENT_PLAN_SETTING_BEFORE" "$(target_wp option get wprism_commerce_extension_settings --format=json)" 'authored extension setting after replacement plan'
assert_runtime_isolation 'replacement semantic plan' 1

REPLACEMENT_SOURCE_MANAGED_CODE_TREE_HASH="$(source_managed_code_tree_hash)"
if ! REPLACEMENT_OUT="$(promote --with-deletes 2>&1)"; then
  echo "$REPLACEMENT_OUT" >&2
  fail 'reviewed plugin identity replacement promote failed'
fi
echo "$REPLACEMENT_OUT"
assert_phase_order "$REPLACEMENT_OUT" \
  'promote phase: compile' \
  'promote phase: promotion-begin' \
  'promote phase: checkpoint' \
  'promote phase: code-stage' \
  'promote phase: lifecycle-retire' \
  'promote phase: lifecycle-activate' \
  'promote phase: code-finalize' \
  'promote phase: apply'
assert_eq absent "$(target_file "$EXT_TARGET")" 'outgoing extension after replacement finalization'
assert_eq present "$(target_file "$REPLACEMENT_TARGET")" 'incoming replacement after finalization'
assert_eq absent "$(target_path "$CONTENT/plugins/$EXT_SLUG")" 'outgoing extension root after replacement finalization'
assert_eq present "$(target_path "$CONTENT/plugins/$REPLACEMENT_SLUG")" 'incoming replacement root after finalization'
assert_replacement_and_dependencies
assert_replacement_rest_status 'reviewed replacement'
assert_eq 0 "$(target_db_scalar "SELECT COUNT(*) FROM wp_options WHERE option_name = 'wprism_commerce_extension_settings'")" 'retired outgoing authored setting'
REPLACEMENT_TRACE="$(target_wp option get wprism_commerce_extension_trace --format=json)"
jq -e '
  (index("deactivate:commerce-v2:woo=yes")) as $retired
  | (index("activate:replacement-fixed:fresh=yes:retiring-root=present")) as $activated
  | $retired != null and $activated != null and $retired < $activated
' <<<"$REPLACEMENT_TRACE" >/dev/null \
  || fail "replacement lifecycle trace did not retire old before fresh activation while its root remained: $REPLACEMENT_TRACE"
assert_eq "$REPLACEMENT_SOURCE_MANAGED_CODE_TREE_HASH" "$(target_managed_code_tree_hash)" 'exact replacement managed code tree'
assert_eq target-only-synthetic-secret "$(target_wp option get wprism_commerce_extension_gateway_secret)" 'env-owned target secret survives replacement'
assert_target_order_unchanged 'target-only order survives replacement'
assert_eq 7 "$(target_wp eval '$p = get_page_by_path("wprism-grind-cap", OBJECT, "product"); $product = $p ? wc_get_product($p->ID) : null; echo $product ? (int) $product->get_stock_quantity() : -1;')" 'target-only stock survives replacement'
assert_derived_indexes 7 instock 16.49 9.99 16.49 1649
assert_store_api_http 1649 'reviewed replacement'
assert_runtime_isolation 'reviewed replacement' 1
REPLACEMENT_ARTIFACT="$(artifact_for_promote_output "$REPLACEMENT_OUT")"
REPLACEMENT_REVISION="$(jq -r '.code.code_revision' "$REPLACEMENT_ARTIFACT")"
assert_receipt "$REPLACEMENT_ARTIFACT" 'reviewed replacement promote' "$REPLACEMENT_REVISION"
[ "$REPLACEMENT_REVISION" != "$THEME_SAFE_REMOVE_REVISION" ] || fail 'replacement did not publish a distinct code revision after the theme lifecycle'
if ! REPLACEMENT_STATUS="$(status 2>&1)"; then
  echo "$REPLACEMENT_STATUS" >&2
  fail 'reviewed replacement did not converge to clean status'
fi
pass "declared descriptor replacement refused before mutation without Woo, then retired old code, activated the distinct implementation, verified effects/runtime sovereignty, and converged"

say "exact replacement rollback: reverse-promote the immediate prior v2 code/state; retain the superseded checkpoint as evidence"
REPLACEMENT_CHECKPOINT="$(sed -n 's/^database checkpoint: //p' <<<"$REPLACEMENT_OUT" | head -1)"
[ -n "$REPLACEMENT_CHECKPOINT" ] || fail 'successful replacement did not report its retained checkpoint'
REPLACEMENT_CHECKPOINT_HOST="$OTHER_SITE/${REPLACEMENT_CHECKPOINT#/siterepo/}"
[ -s "$REPLACEMENT_CHECKPOINT_HOST" ] || fail 'successful replacement checkpoint is not target-visible and non-empty'
REPLACEMENT_CHECKPOINT_SHA256="$(sha256sum "$REPLACEMENT_CHECKPOINT_HOST" | awk '{print $1}')"
[[ "$REPLACEMENT_CHECKPOINT_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail 'replacement checkpoint SHA-256 is malformed'
rm -rf -- "$SITE/code"
rm -rf -- "$SITE/state"
cp -a "$REPLACEMENT_PRIOR_INPUTS/code" "$SITE/code"
cp -a "$REPLACEMENT_PRIOR_INPUTS/state" "$SITE/state"
cp "$REPLACEMENT_PRIOR_INPUTS/site.wprism.json" "$SITE/site.wprism.json"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'rollback: restore exact pre-replacement v2 descriptors'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
if ! target_wp maintenance-mode activate >/dev/null; then
  fail 'could not establish target maintenance before replacement rollback'
fi
ROLLBACK_MAINTENANCE_HELD=1
assert_replacement_and_dependencies
if ! REPLACEMENT_ROLLBACK_OUT="$(promote 2>&1)"; then
  echo "$REPLACEMENT_ROLLBACK_OUT" >&2
  fail 'exact replacement rollback promotion failed'
fi
echo "$REPLACEMENT_ROLLBACK_OUT"
assert_phase_order "$REPLACEMENT_ROLLBACK_OUT" \
  'promote phase: compile' \
  'promote phase: promotion-begin' \
  'promote phase: checkpoint' \
  'promote phase: code-stage' \
  'promote phase: lifecycle-retire' \
  'promote phase: lifecycle-activate' \
  'promote phase: code-finalize' \
  'promote phase: apply'
assert_eq present "$(target_file "$EXT_TARGET")" 'outgoing extension after replacement rollback'
assert_eq absent "$(target_file "$REPLACEMENT_TARGET")" 'replacement after exact immediate-prior rollback'
assert_eq present "$(target_path "$CONTENT/plugins/$EXT_SLUG")" 'outgoing extension root after replacement rollback'
assert_eq absent "$(target_path "$CONTENT/plugins/$REPLACEMENT_SLUG")" 'replacement root after exact immediate-prior rollback'
assert_eq "$REPLACEMENT_CHECKPOINT_SHA256" "$(sha256sum "$REPLACEMENT_CHECKPOINT_HOST" | awk '{print $1}')" 'superseded replacement checkpoint remains immutable evidence after reverse promotion'
assert_eq 0 "$(target_db_scalar "SELECT COUNT(*) FROM wp_wprism_kv WHERE k = 'promotion_lock'")" 'replacement reverse promotion released its lease'
assert_eq "$AUTHORED_ACTIVE_PLUGINS_JSON" "$(active_plugins_json)" 'pre-replacement active plugin order after reverse promotion'
if ! target_wp maintenance-mode deactivate >/dev/null; then
  fail 'could not release target maintenance after replacement rollback'
fi
ROLLBACK_MAINTENANCE_HELD=0
assert_eq "$REPLACEMENT_MANAGED_TREE_BEFORE" "$(target_managed_code_tree_hash)" 'exact managed code tree after replacement rollback'
assert_parent_theme_and_dependencies
assert_theme_child_removed 'replacement rollback'
assert_theme_versions 'replacement rollback' '1.1.0' 'absent'
assert_theme_portable_relationships 'replacement rollback' "$PARENT_THEME"
assert_frontend_parent 'replacement rollback'
assert_extension_rest_status '2.0.0' 2 'replacement rollback'
target_wp option get wprism_commerce_extension_settings --format=json | jq -e '.schema == 2 and .channel == "retail" and .catalog_mode == "managed"' >/dev/null \
  || fail 'replacement rollback did not restore the exact v2 authored setting'
assert_eq target-only-synthetic-secret "$(target_wp option get wprism_commerce_extension_gateway_secret)" 'env-owned target secret after replacement rollback'
assert_target_order_unchanged 'target-only order after replacement rollback'
assert_eq 7 "$(target_wp eval '$p = get_page_by_path("wprism-grind-cap", OBJECT, "product"); $product = $p ? wc_get_product($p->ID) : null; echo $product ? (int) $product->get_stock_quantity() : -1;')" 'target-only stock after replacement rollback'
assert_derived_indexes 7 instock 16.49 9.99 16.49 1649
assert_store_api_http 1649 'replacement rollback'
assert_runtime_isolation 'replacement rollback' 1
REPLACEMENT_ROLLBACK_ARTIFACT="$(artifact_for_promote_output "$REPLACEMENT_ROLLBACK_OUT")"
assert_eq "$REPLACEMENT_PRIOR_ARTIFACT_HASH" "$(jq -r '.artifact_hash' "$REPLACEMENT_ROLLBACK_ARTIFACT")" 'exact pre-replacement artifact after rollback'
assert_eq "$REPLACEMENT_PRIOR_STATE_REVISION" "$(jq -r '.revision_hash' "$REPLACEMENT_ROLLBACK_ARTIFACT")" 'exact pre-replacement state revision after rollback'
assert_receipt "$REPLACEMENT_ROLLBACK_ARTIFACT" 'exact replacement rollback promotion' "$REPLACEMENT_REVISION_BEFORE"
if ! REPLACEMENT_ROLLBACK_STATUS="$(status 2>&1)"; then
  echo "$REPLACEMENT_ROLLBACK_STATUS" >&2
  fail 'exact replacement rollback did not converge to clean status'
fi
pass "public reverse promotion restored the exact reviewed v2 result; the superseded forward checkpoint remained byte-verified evidence and was not imported"

say "exact rollback: import v1 checkpoint under maintenance, then promote v1"
rm -rf -- "$SITE/code"
rm -rf -- "$SITE/state"
cp -a "$V1_INPUTS/code" "$SITE/code"
cp -a "$V1_INPUTS/state" "$SITE/state"
cp "$V1_INPUTS/site.wprism.json" "$SITE/site.wprism.json"
git -C "$SITE" add -A
git -C "$SITE" -c user.name=wprism-ecommerce -c user.email=ecommerce@example.test commit -qm 'rollback: restore exact v1 code and state descriptors'
git -C "$SITE" push -qu origin main
git -C "$OTHER_SITE" pull -q --ff-only
ROLLBACK_MAINTENANCE_HELD=0
ROLLBACK_PROMOTION_SUCCEEDED=0
if ! control_wp_command maintenance-mode activate >/dev/null; then
  fail 'could not establish target maintenance before v1 checkpoint recovery'
fi
ROLLBACK_MAINTENANCE_HELD=1
# Maintenance mode is a request boundary, not a process fence: the target
# web worker can still boot the currently staged v2 plugin while the database
# is being restored. Close only this disposable target worker with a hard
# fence so its v2 migration cannot race the v1 import; the CLI control plane
# remains available for the isolated import and the subsequent public v1
# promotion. A single kill is intentional: a preceding graceful stop would
# make Docker compose kill return nonzero after the container exits.
if ! "${PAIR_COMPOSE[@]}" kill -s SIGKILL wp2 >/dev/null; then
  fail 'could not hard-stop target web service before v1 checkpoint import'
fi
assert_eq "$V1_DB_DUMP_SHA256" "$(sha256sum "$V1_DB_DUMP" | awk '{print $1}')" 'retained v1 database checkpoint bytes before rollback import'
prepare_v1_checkpoint_target
mkdir -p "$(dirname "$V1_CHECKPOINT_TARGET")"
cp "$V1_DB_DUMP" "$V1_CHECKPOINT_TARGET"
chmod 0644 "$V1_CHECKPOINT_TARGET"
assert_eq "$V1_DB_DUMP_SHA256" "$(sha256sum "$V1_CHECKPOINT_TARGET" | awk '{print $1}')" 'immutable v1 database checkpoint bytes before rollback import'
grep -Eq "'wprism_commerce_extension_settings','retail'," "$V1_CHECKPOINT_TARGET" \
  || fail 'immutable v1 database checkpoint lost the raw scalar option value before rollback import'
control_wp recoveryDbImportArgs "/siterepo/.tmp-ecommerce-v1.sql" >/dev/null
ROLLBACK_SETTING_AFTER_IMPORT="$(target_db_scalar "SELECT option_value FROM wp_options WHERE option_name = 'wprism_commerce_extension_settings' LIMIT 1")"
printf 'exact v1 raw setting immediately after checkpoint import: %s\n' "$ROLLBACK_SETTING_AFTER_IMPORT" >&2
ROLLBACK_ACTIVE_PLUGINS_RAW="$(target_db_scalar "SELECT option_value FROM wp_options WHERE option_name = 'active_plugins' LIMIT 1")"
if ! ROLLBACK_ACTIVE_PLUGINS_JSON="$(php -r '
$value = unserialize((string) ($argv[1] ?? ""), ["allowed_classes" => false]);
if (!is_array($value)) { exit(2); }
echo json_encode(array_values($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
' "$ROLLBACK_ACTIVE_PLUGINS_RAW")"; then
  fail 'v1 checkpoint active_plugins could not be decoded without bootstrapping WordPress'
fi
assert_eq "$NATIVE_ACTIVE_PLUGINS_JSON" "$ROLLBACK_ACTIVE_PLUGINS_JSON" 'v1 checkpoint active plugin order before code staging'
assert_eq "$REPLACEMENT_OLD_FILE_HASH_BEFORE" "$(target_hash "$EXT_TARGET")" 'exact v2 extension bytes before v1 control-plane staging'
assert_eq "$V1_REVISION" "$(ledger_revision)" 'exact v1 code revision after checkpoint import'
assert_eq retail "$ROLLBACK_SETTING_AFTER_IMPORT" 'exact v1 setting after checkpoint import'
assert_eq 0 "$(target_db_scalar "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_wprism_commerce_extension_events' AND COLUMN_NAME = 'context'")" 'exact v1 runtime table shape'
assert_extension_runtime_event 0 "" 'exact v1 runtime row after rollback'
if ! RESTORE_OUT="$(promote 2>&1)"; then
  echo "$RESTORE_OUT" >&2
  fail 'v1 rollback promotion failed'
fi
ROLLBACK_PROMOTION_SUCCEEDED=1
if ! "${PAIR_COMPOSE[@]}" start wp2 >/dev/null; then
  fail 'could not restart target web service after v1 checkpoint promotion'
fi
if ! control_wp_command maintenance-mode deactivate >/dev/null; then
  fail 'could not release target maintenance after successful v1 rollback promotion'
fi
ROLLBACK_MAINTENANCE_HELD=0
echo "$RESTORE_OUT"
assert_phase_order "$RESTORE_OUT" \
  'promote phase: compile' \
  'promote phase: promotion-begin' \
  'promote phase: checkpoint' \
  'promote phase: code-stage' \
  'promote phase: lifecycle-retire' \
  'promote phase: lifecycle-activate' \
  'promote phase: code-finalize' \
  'promote phase: apply'
assert_eq "$(source_hash "$V1_INPUTS/code/wp-content/plugins/$EXT_SLUG/$EXT_FILE")" "$(target_hash "$EXT_TARGET")" 'exact v1 extension bytes'
assert_eq absent "$(target_file "$REPLACEMENT_TARGET")" 'replacement code after exact v1 rollback'
assert_eq absent "$(target_path "$CONTENT/plugins/$REPLACEMENT_SLUG")" 'replacement root after exact v1 rollback'
assert_eq "$V1_TARGET_MANAGED_CODE_TREE_HASH" "$(target_managed_code_tree_hash)" 'exact v1 managed code tree after checkpoint import'
assert_theme_and_dependency "$NATIVE_ACTIVE_PLUGINS_JSON"
assert_theme_versions 'exact v1 rollback' '1.0.0' '1.0.0'
assert_theme_portable_relationships 'exact v1 rollback'
assert_frontend_child_parent 'exact v1 rollback'
assert_eq target-only-synthetic-secret "$(target_wp option get wprism_commerce_extension_gateway_secret)" 'env-owned target secret after rollback'
assert_target_order_absent 'checkpoint rollback restores pre-runtime-order baseline'
assert_eq 0 "$(target_wp eval 'echo get_user_by("email", "runtime-customer@example.invalid") ? 1 : 0;')" 'checkpoint rollback removes target-only runtime customer'
assert_source_runtime_baseline 'exact v1 rollback source runtime baseline'
assert_source_runtime_absent_from_target 'exact v1 rollback source runtime absence'
assert_target_runtime_absent_from_source 'exact v1 rollback target runtime absence'
assert_runtime_state_excluded 'exact v1 rollback generated state exclusion'
assert_woo_catalog 0 5
assert_ecommerce_menu 'exact v1 rollback'
assert_eq "$DELETION_PROBE_V1_TARGET_ID" "$(target_wp post list --post_type=product --name=wprism-grind-delete-probe --field=ID)" 'checkpoint rollback restores the v1 probe id'
assert_deletion_probe_lookup_present "$DELETION_PROBE_V1_TARGET_ID"
assert_product_visibility_projection
assert_derived_indexes 0 ""
assert_store_api_http 1499 'exact v1 rollback'
RESTORED_ARTIFACT="$(artifact_for_promote_output "$RESTORE_OUT")"
assert_receipt "$RESTORED_ARTIFACT" 'exact v1 rollback promotion' "$V1_REVISION"
assert_eq "$V1_REVISION" "$(jq -r '.code.code_revision' "$RESTORED_ARTIFACT")" 'restored v1 artifact code revision'
assert_eq "$V1_ARTIFACT_HASH" "$(jq -r '.artifact_hash' "$RESTORED_ARTIFACT")" 'restored v1 outer artifact hash'
assert_eq "$V1_STATE_REVISION" "$(jq -r '.revision_hash' "$RESTORED_ARTIFACT")" 'restored v1 state revision'
pass "code, active dependency/theme lifecycle, authored setting, runtime table shape, and promotion identities returned to exact v1 values"

say "final recapture/status and exact clean-room cleanup"
target_wp wprism capture --repo=/siterepo --out=/siterepo/.tmp-final-state >/dev/null
if FINAL_RAW_DIFF="$(diff -rq "$OTHER_SITE/state" "$OTHER_SITE/.tmp-final-state")"; then
  FINAL_RAW_DIFF_STATUS=0
else
  FINAL_RAW_DIFF_STATUS=$?
fi
[ "$FINAL_RAW_DIFF_STATUS" -le 1 ] \
  || fail "could not compare final raw state trees (diff exit $FINAL_RAW_DIFF_STATUS)"
FINAL_SEMANTIC_DIFF="$(final_compiled_state_diff)"
if [ -n "$FINAL_RAW_DIFF" ]; then
  if diff -ru "$OTHER_SITE/state" "$OTHER_SITE/.tmp-final-state" >&2; then
    fail "raw diff summary reported changes but the unified diff was empty"
  else
    FINAL_RAW_DIFF_STATUS=$?
    [ "$FINAL_RAW_DIFF_STATUS" -eq 1 ] \
      || fail "could not render final raw state diagnostics (diff exit $FINAL_RAW_DIFF_STATUS)"
  fi
fi
if [ "$FINAL_SEMANTIC_DIFF" != "[]" ]; then
  fail "final target recapture changed authored or identity-bearing state: $FINAL_SEMANTIC_DIFF"
fi
if [ -n "$FINAL_RAW_DIFF" ]; then
  pass "final raw recapture differences are limited to manifest-declared derived post fields"
else
  pass "final target recapture is also byte-identical"
fi
assert_extension_runtime_event_excluded 'final recapture'
assert_eq 0 "$(target_wp eval 'echo get_user_by("email", "runtime-customer@example.invalid") ? 1 : 0;')" 'final recapture target-only runtime customer remains absent'
assert_source_runtime_baseline 'final source runtime baseline'
assert_source_runtime_absent_from_target 'final source runtime absence'
assert_target_runtime_absent_from_source 'final target runtime absence'
assert_runtime_state_excluded 'final generated state exclusion'
rm -rf -- "$OTHER_SITE/.tmp-final-state"
if ! FINAL_STATUS="$(status 2>&1)"; then
  echo "$FINAL_STATUS" >&2
  fail 'final target status was not clean'
fi
echo "$FINAL_STATUS"
pass "final recapture is semantically identical under the pinned policy, status is clean, runtime probes/secrets remain outside generated state, and the trap will destroy only this pair"

printf '\n\033[1;32m✔ ECOMMERCE DEVELOPER GRIND PASSED (%s)\033[0m\n' "$PAIR"
