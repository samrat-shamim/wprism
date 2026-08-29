#!/usr/bin/env bash
# First-sync lifecycle ambiguity grind. A real activation hook commits an
# authored option and then throws before WordPress records plugin membership.
# WPrism must preserve the pre-hook boundary even though no three-way base exists,
# block a new artifact/owner, and converge only after exact checkpoint + code
# restoration under external exclusion.
set -euo pipefail
cd "$(dirname "$0")/../.."

REPO_ROOT="$(cd .. && pwd)"
WPRISM="$REPO_ROOT/cli/wprism"
CODE_DEPLOY="$REPO_ROOT/cli/src/Transport/CodeDeploy.php"
FIXTURE="$REPO_ROOT/sandbox/fixtures/wprism-first-sync-hook"
PAIR="firstsync${BASHPID}${RANDOM}"
PORT1=8894
PORT2=8895
SITE="siterepo/${PAIR}1"
OTHER_SITE="siterepo/${PAIR}2"
ENVS_FILE="$(mktemp "${TMPDIR:-/tmp}/wprism-first-sync-envs.XXXXXX")"
PAIR_UP=0

PLUGIN_SLUG="wprism-first-sync-hook"
PLUGIN_BASENAME="$PLUGIN_SLUG/$PLUGIN_SLUG.php"
THEME_SLUG="wprism-first-sync-theme"
CONTENT="/var/www/html/wp-content"
PLUGIN_TARGET="$CONTENT/plugins/$PLUGIN_SLUG"
THEME_TARGET="$CONTENT/themes/$THEME_SLUG"
STATE="$SITE/state/options/core.json"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

cleanup() {
  local status=$?
  local pair_containers pair_volumes pair_networks remaining_dbs
  trap - EXIT INT TERM
  set +e
  if [ "$PAIR_UP" = 1 ]; then
    "${COMPOSE[@]}" run --rm -T -u root cli1 sh -c 'chmod -R ugo+rwX /siterepo' >/dev/null 2>&1 || true
    if ! bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1; then
      printf 'FAIL: first-sync pair destroy failed for %s\n' "$PAIR" >&2
      status=1
    fi
    if ! pair_containers="$(docker ps -aq --filter "label=com.docker.compose.project=wprism-$PAIR" 2>/dev/null)" \
      || ! pair_volumes="$(docker volume ls -q --filter "label=com.docker.compose.project=wprism-$PAIR" 2>/dev/null)" \
      || ! pair_networks="$(docker network ls -q --filter "label=com.docker.compose.project=wprism-$PAIR" 2>/dev/null)"; then
      printf 'FAIL: first-sync cleanup could not verify Docker resource removal for %s\n' "$PAIR" >&2
      status=1
    elif [ -n "$pair_containers$pair_volumes$pair_networks" ]; then
      printf 'FAIL: first-sync cleanup left Docker resources for project wprism-%s behind\n' "$PAIR" >&2
      status=1
    fi
    if ! remaining_dbs="$(docker exec -e MYSQL_PWD=root wprism-shared-db mariadb -uroot -N -B --raw \
      -e "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME IN ('wp_${PAIR}1','wp_${PAIR}2')" 2>/dev/null)"; then
      printf 'FAIL: first-sync cleanup could not verify database removal for %s\n' "$PAIR" >&2
      status=1
    elif [ -n "$remaining_dbs" ]; then
      printf 'FAIL: first-sync cleanup left pair database(s) behind: %s\n' "$remaining_dbs" >&2
      status=1
    fi
  fi
  rm -rf -- "$SITE" "$OTHER_SITE"
  if [ -e "$SITE" ] || [ -e "$OTHER_SITE" ]; then
    printf 'FAIL: first-sync cleanup left %s or %s behind\n' "$SITE" "$OTHER_SITE" >&2
    status=1
  fi
  rm -f -- "$ENVS_FILE"
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

require() { command -v "$1" >/dev/null 2>&1 || fail "required command is missing: $1"; }
assert_eq() {
  local expected="$1" actual="$2" label="$3"
  [ "$expected" = "$actual" ] || fail "$label: expected '$expected', got '$actual'"
}
assert_phase_order() {
  local output="$1" last=0 needle line
  shift
  for needle in "$@"; do
    line="$(grep -n -F -m1 "$needle" <<<"$output" | cut -d: -f1 || true)"
    [ -n "$line" ] || fail "promotion output did not include phase '$needle': $output"
    [ "$line" -gt "$last" ] || fail "promotion phase '$needle' was out of order: $output"
    last="$line"
  done
}
assert_absent() {
  local output="$1" needle="$2" label="$3"
  ! grep -Fq "$needle" <<<"$output" || fail "$label unexpectedly included '$needle': $output"
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
artifact_files() {
  if [ -d "$SITE/.wprism/artifacts" ]; then
    find "$SITE/.wprism/artifacts" -type f -name 'promote-*.json' -print | sort
  fi
}

export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml)
target_wp() {
  "${COMPOSE[@]}" run --rm -T cli1 sh -c 'umask 000; exec wp "$@"' _ "$@"
}
root_wp() {
  "${COMPOSE[@]}" run --rm -T -u root cli1 wp --allow-root "$@"
}
target_php() { "${COMPOSE[@]}" run --rm -T cli1 php -r "$1"; }
db_scalar() {
  docker exec -e MYSQL_PWD=root wprism-shared-db mariadb -uroot -N -B --raw "wp_${PAIR}1" -e "$1" \
    | tr -d '\r'
}
ledger_value() { db_scalar "SELECT v FROM wp_wprism_kv WHERE k = '$1'"; }
ledger_count() { db_scalar "SELECT COUNT(*) FROM wp_wprism_kv WHERE k = '$1'"; }
state_base_count() { db_scalar "SELECT COUNT(*) FROM wp_wprism_state WHERE uuid = 'options/core'"; }
promote() { php "$WPRISM" --envs-file="$ENVS_FILE" promote target "$@"; }
status() { php "$WPRISM" --envs-file="$ENVS_FILE" status target; }
target_path_state() {
  target_php "echo file_exists('$1') || is_link('$1') ? 'present' : 'absent';"
}

# Reuse the product's exact isolated argument builders instead of copying the
# private --exec bootstrap into this test. This exercises the same commands the
# host prints for checkpoint recovery, including fatal-safe DB import.
control_wp() {
  local method="$1"
  shift
  local encoded
  encoded="$(WPRISM_CODE_DEPLOY="$CODE_DEPLOY" php -r '
require getenv("WPRISM_CODE_DEPLOY");
$class = WPrism\Orchestrator\CodeDeploy::class;
$method = $argv[1];
$args = $class::$method(...array_slice($argv, 2));
echo json_encode($args, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
' "$method" "$@")"
  local -a args
  mapfile -t args < <(jq -r '.[]' <<<"$encoded")
  target_wp "${args[@]}"
}

require docker
require jq
require php
[ -f "$WPRISM" ] || fail "host CLI missing: $WPRISM"
[ -f "$FIXTURE/broken/wp-content/plugins/$PLUGIN_SLUG/$PLUGIN_SLUG.php" ] || fail "broken hook fixture missing"
[ -f "$FIXTURE/fixed/wp-content/plugins/$PLUGIN_SLUG/$PLUGIN_SLUG.php" ] || fail "fixed hook fixture missing"
[ -f "$FIXTURE/theme/wp-content/themes/$THEME_SLUG/style.css" ] || fail "theme fixture missing"

ACTIVE_PAIRS="$(docker compose ls --format json 2>/dev/null | jq -r '
  .[] | select(.Status | startswith("running"))
  | select(.ConfigFiles | test("/pair\\.yml(,|$)")) | .Name
' 2>/dev/null || true)"
[ -z "$ACTIVE_PAIRS" ] || fail "refusing to start $PAIR while another pair is active: $ACTIVE_PAIRS"

say "clean room: first-sync pair $PAIR, without --codebind or an existing state base"
PAIR_UP=1
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless
jq -n --arg compose "$(pwd)/pair.yml" \
  '{envs: {target: {transport: "docker", compose_file: $compose, service: "cli1", repo_path: "/siterepo"}}}' \
  > "$ENVS_FILE"

say "capture canonical state out-of-band so the target still has no three-way base"
mkdir -p "$SITE/code/wp-content/plugins/$PLUGIN_SLUG" \
         "$SITE/code/wp-content/themes/$THEME_SLUG"
cp "$FIXTURE/broken/wp-content/plugins/$PLUGIN_SLUG/$PLUGIN_SLUG.php" \
  "$SITE/code/wp-content/plugins/$PLUGIN_SLUG/$PLUGIN_SLUG.php"
cp "$FIXTURE/theme/wp-content/themes/$THEME_SLUG/style.css" \
  "$SITE/code/wp-content/themes/$THEME_SLUG/style.css"
cp "$FIXTURE/theme/wp-content/themes/$THEME_SLUG/index.php" \
  "$SITE/code/wp-content/themes/$THEME_SLUG/index.php"
cat > "$SITE/site.wprism.json" <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "options": {"wprism_first_sync_hook_trace": {"class": "runtime"}},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"],
    "term_meta": {}
  },
  "spec_version": 2
}
EOF
target_wp option update blogname canonical-first-sync >/dev/null
target_wp wprism capture --repo=/siterepo --out=/siterepo/.seed-state >/dev/null
mv "$SITE/.seed-state" "$SITE/state"
assert_eq 0 "$(state_base_count)" "first-sync options/core base before promotion"
assert_eq 0 "$(ledger_count applied_revision)" "first-sync applied revision before promotion"

jq '.code = {format: 1, layout: "wp-content", source: "code/wp-content"}' "$SITE/site.wprism.json" \
  > "$SITE/site.wprism.next.json"
mv "$SITE/site.wprism.next.json" "$SITE/site.wprism.json"
canonicalize_json "$SITE/site.wprism.json"
jq --arg plugin "$PLUGIN_BASENAME" --arg theme "$THEME_SLUG" '
  .records.active_plugins.value = [$plugin]
  | .records.template.value = $theme
  | .records.stylesheet.value = $theme
' "$STATE" > "$STATE.next"
mv "$STATE.next" "$STATE"
canonicalize_json "$STATE"
assert_eq absent "$(target_path_state "$PLUGIN_TARGET")" "plugin target before first sync"
assert_eq absent "$(target_path_state "$THEME_TARGET")" "theme target before first sync"
pass "repository state is canonical and code-enabled while the target has no state base or managed code"

say "configured MU and sunrise layouts fail before compile can begin a target mutation"
root_wp config set WPMU_PLUGIN_DIR /var/www/html/wp-content/custom-mu >/dev/null
if CUSTOM_MU_OUT="$(promote 2>&1)"; then
  echo "$CUSTOM_MU_OUT" >&2
  fail "custom WPMU_PLUGIN_DIR unexpectedly entered the control plane"
fi
root_wp config delete WPMU_PLUGIN_DIR >/dev/null
echo "$CUSTOM_MU_OUT"
grep -Eq 'control-plane bootstrap (requires standard wp-content/mu-plugins|started with WPMU_PLUGIN_DIR already defined|cannot safely isolate an explicit WPMU_PLUGIN_DIR)' <<<"$CUSTOM_MU_OUT" \
  || fail "custom MU refusal did not identify the unsafe configured layout"
assert_phase_order "$CUSTOM_MU_OUT" "promote phase: compile"
assert_absent "$CUSTOM_MU_OUT" "promote phase: promotion-begin" "custom MU refusal"
assert_eq absent "$(target_path_state "$PLUGIN_TARGET")" "plugin target after custom MU refusal"
assert_eq 0 "$(ledger_count promotion_session)" "promotion session after custom MU refusal"

root_wp config set SUNRISE true --raw >/dev/null
if SUNRISE_OUT="$(promote 2>&1)"; then
  echo "$SUNRISE_OUT" >&2
  fail "SUNRISE unexpectedly entered the isolated control plane"
fi
root_wp config delete SUNRISE >/dev/null
echo "$SUNRISE_OUT"
grep -Fq 'cannot safely isolate a configured SUNRISE loader' <<<"$SUNRISE_OUT" \
  || fail "SUNRISE refusal did not identify the unsafe bootstrap surface"
assert_phase_order "$SUNRISE_OUT" "promote phase: compile"
assert_absent "$SUNRISE_OUT" "promote phase: promotion-begin" "SUNRISE refusal"
assert_eq absent "$(target_path_state "$PLUGIN_TARGET")" "plugin target after SUNRISE refusal"
assert_eq 0 "$(ledger_count promotion_session)" "promotion session after SUNRISE refusal"
pass "control bootstrap waits for wp-config, proves its layout, and performs no mutation when isolation is unsafe"

say "the first activation commits authored state, throws, and leaves an exact durable ambiguity receipt"
if BROKEN_OUT="$(promote 2>&1)"; then
  echo "$BROKEN_OUT" >&2
  fail "broken first-sync hook unexpectedly completed"
fi
echo "$BROKEN_OUT"
assert_phase_order "$BROKEN_OUT" \
  "promote phase: compile" \
  "promote phase: promotion-begin" \
  "promote phase: checkpoint" \
  "promote phase: code-stage" \
  "promote phase: lifecycle-retire" \
  "promote phase: lifecycle-activate"
grep -Fq 'wprism first-sync intentional activation failure' <<<"$BROKEN_OUT" \
  || fail "broken activation hook did not run"
assert_absent "$BROKEN_OUT" "promote phase: code-finalize" "broken first sync"
assert_absent "$BROKEN_OUT" "promote phase: apply" "broken first sync"
grep -Fq 'promotion lease cleanup confirmed' <<<"$BROKEN_OUT" \
  || fail "failed activation did not release its bounded lease"
CHECKPOINT="$(sed -n 's/^database checkpoint: //p' <<<"$BROKEN_OUT" | head -1)"
[ -n "$CHECKPOINT" ] || fail "failed first sync did not report its checkpoint"
[ -f "$SITE/${CHECKPOINT#/siterepo/}" ] || fail "reported first-sync checkpoint is not target-visible"
assert_eq hook-mutated-first-sync "$(target_wp option get blogname)" "authored hook write after exception"
assert_eq '["broken-hook-wrote"]' "$(target_wp option get wprism_first_sync_hook_trace --format=json | jq -c .)" \
  "runtime hook trace after exception"
if target_wp plugin is-active "$PLUGIN_BASENAME" >/dev/null 2>&1; then
  fail "WordPress persisted active membership despite the throwing activation hook"
fi
target_wp option get active_plugins --format=json \
  | jq -e --arg plugin "$PLUGIN_BASENAME" 'index($plugin) == null' >/dev/null \
  || fail "active_plugins contains the throwing first-sync plugin"
assert_eq 0 "$(state_base_count)" "first-sync base after failed activation"
assert_eq 0 "$(ledger_count applied_revision)" "applied revision after failed activation"
assert_eq 0 "$(ledger_count code_revision)" "completed code revision after failed activation"
assert_eq 0 "$(ledger_count promotion_lock)" "bounded lease after failed activation"
[[ "$(ledger_value code_stage_revision)" =~ ^[0-9a-f]{64}$ ]] \
  || fail "failed activation lost its atomic staged-code receipt"

ORIGINAL_SESSION="$(ledger_value promotion_session)"
ORIGINAL_OWNER="$(jq -r '.owner' <<<"$ORIGINAL_SESSION")"
ORIGINAL_ARTIFACT="$(jq -r '.artifact_hash' <<<"$ORIGINAL_SESSION")"
jq -e '
  (.owner | type == "string" and length > 0)
  and (.artifact_hash | test("^[0-9a-f]{64}$"))
  and .lifecycle_attempt.entity == "options/core"
  and .lifecycle_attempt.phase == "activate"
  and (.lifecycle_attempt.before_hash | test("^[0-9a-f]{64}$"))
' <<<"$ORIGINAL_SESSION" >/dev/null || fail "failed activation did not retain the exact pre-hook boundary"
if AMBIGUOUS_STATUS="$(status 2>&1)"; then
  echo "$AMBIGUOUS_STATUS" >&2
  fail "status reported an unresolved first-sync lifecycle attempt as ready"
fi
grep -Fq '1 incomplete_lifecycle' <<<"$AMBIGUOUS_STATUS" \
  || fail "status did not count the unresolved lifecycle receipt"
grep -Fq 'INCOMPLETE_LIFECYCLE' <<<"$AMBIGUOUS_STATUS" \
  || fail "status did not render the unresolved lifecycle receipt"
grep -Fq 'exact pre-lifecycle database checkpoint' <<<"$AMBIGUOUS_STATUS" \
  || fail "status did not direct exact lifecycle recovery"
BROKEN_TARGET_HASH="$(target_php "echo hash_file('sha256', '$PLUGIN_TARGET/$PLUGIN_SLUG.php');")"
pass "WordPress committed the hook mutation, but WPrism retained the pre-hook receipt without inventing a first-sync base"

say "a reviewed source fix cannot overwrite the unresolved original owner/artifact session"
cp "$FIXTURE/fixed/wp-content/plugins/$PLUGIN_SLUG/$PLUGIN_SLUG.php" \
  "$SITE/code/wp-content/plugins/$PLUGIN_SLUG/$PLUGIN_SLUG.php"
ARTIFACTS_BEFORE_RETRY="$(artifact_files)"
CHECKPOINT_COUNT="$(find "$SITE/.wprism/checkpoints" -type f | wc -l | tr -d ' ')"
if RETRY_OUT="$(promote 2>&1)"; then
  echo "$RETRY_OUT" >&2
  fail "new promotion session crossed the unresolved lifecycle attempt"
fi
echo "$RETRY_OUT"
assert_phase_order "$RETRY_OUT" "promote phase: compile" "promote phase: promotion-begin"
grep -Fq 'unresolved lifecycle attempt blocks a new promotion session' <<<"$RETRY_OUT" \
  || fail "new owner/artifact refusal did not identify the unresolved hook boundary"
grep -Fq 'definite protected session; no cleanup was attempted' <<<"$RETRY_OUT" \
  || fail "host treated the protected session as an uncertain begin"
assert_absent "$RETRY_OUT" "promote phase: checkpoint" "blocked reviewed retry"
assert_absent "$RETRY_OUT" "promote phase: code-stage" "blocked reviewed retry"
mapfile -t RETRY_ARTIFACTS < <(
  comm -13 \
    <(sed '/^$/d' <<<"$ARTIFACTS_BEFORE_RETRY") \
    <(artifact_files)
)
[ "${#RETRY_ARTIFACTS[@]}" -eq 1 ] \
  || fail "blocked reviewed retry did not create exactly one new compiled artifact"
RETRY_ARTIFACT_HASH="$(jq -r '.artifact_hash' "${RETRY_ARTIFACTS[0]}")"
[[ "$RETRY_ARTIFACT_HASH" =~ ^[0-9a-f]{64}$ ]] \
  || fail "blocked reviewed retry artifact has no valid outer hash"
[ "$RETRY_ARTIFACT_HASH" != "$ORIGINAL_ARTIFACT" ] \
  || fail "reviewed source fix did not produce a distinct outer artifact"
assert_eq "$CHECKPOINT_COUNT" "$(find "$SITE/.wprism/checkpoints" -type f | wc -l | tr -d ' ')" \
  "checkpoint count after blocked retry"
assert_eq "$ORIGINAL_SESSION" "$(ledger_value promotion_session)" "original recovery session after blocked retry"
assert_eq "$BROKEN_TARGET_HASH" \
  "$(target_php "echo hash_file('sha256', '$PLUGIN_TARGET/$PLUGIN_SLUG.php');")" \
  "staged target code after blocked retry"
assert_eq hook-mutated-first-sync "$(target_wp option get blogname)" "authored hook write after blocked retry"
assert_eq 0 "$(state_base_count)" "first-sync base after blocked retry"
pass "the fixed artifact cannot turn a failed first sync into an implicit adoption"

say "under external exclusion, restore pre-promotion code then run the printed four-step checkpoint repair"
# This disposable pair's exact pre-promotion code state had neither root. The
# retained checkpoint restores only the database, so reconcile these two
# explicitly known staged roots before importing it. Never broaden this path.
"${COMPOSE[@]}" run --rm -T cli1 sh -c 'rm -rf -- "$1" "$2"' _ "$PLUGIN_TARGET" "$THEME_TARGET"
assert_eq absent "$(target_path_state "$PLUGIN_TARGET")" "plugin root after pre-promotion code restore"
assert_eq absent "$(target_path_state "$THEME_TARGET")" "theme root after pre-promotion code restore"

control_wp abortArgs "$ORIGINAL_OWNER" "$ORIGINAL_ARTIFACT" >/dev/null
control_wp beginArgs "$ORIGINAL_OWNER" "$ORIGINAL_ARTIFACT" >/dev/null
jq -e '.lifecycle_attempt.phase == "activate"' <<<"$(ledger_value promotion_session)" >/dev/null \
  || fail "exact recovery begin erased the ambiguity receipt before checkpoint import"
assert_eq 1 "$(ledger_count promotion_lock)" "exact recovery lease before import"
control_wp recoveryDbImportArgs "$CHECKPOINT" >/dev/null
control_wp abortArgs "$ORIGINAL_OWNER" "$ORIGINAL_ARTIFACT" >/dev/null

assert_eq canonical-first-sync "$(target_wp option get blogname)" "authored option after checkpoint restore"
assert_eq '' "$(target_wp option get wprism_first_sync_hook_trace 2>/dev/null || true)" \
  "runtime hook trace after checkpoint restore"
assert_eq 0 "$(state_base_count)" "first-sync base after checkpoint restore"
assert_eq 0 "$(ledger_count code_stage_revision)" "staged receipt after checkpoint restore"
assert_eq 0 "$(ledger_count promotion_lock)" "recovery lease after final abort"
RESTORED_SESSION="$(ledger_value promotion_session)"
jq -e 'has("lifecycle_attempt") | not' <<<"$RESTORED_SESSION" >/dev/null \
  || fail "checkpoint restore retained the post-checkpoint lifecycle receipt"
pass "the exact checkpoint removed both the authored mutation and ambiguity receipt without creating a base"

say "the reviewed fixed artifact now performs the real first sync and converges"
if ! FIXED_OUT="$(promote 2>&1)"; then
  echo "$FIXED_OUT" >&2
  fail "fixed first-sync promotion failed after exact recovery"
fi
echo "$FIXED_OUT"
assert_phase_order "$FIXED_OUT" \
  "promote phase: compile" \
  "promote phase: promotion-begin" \
  "promote phase: checkpoint" \
  "promote phase: code-stage" \
  "promote phase: lifecycle-retire" \
  "promote phase: lifecycle-activate" \
  "promote phase: code-finalize" \
  "promote phase: apply"
assert_eq canonical-first-sync "$(target_wp option get blogname)" "canonical blogname after fixed first sync"
assert_eq '["fixed-hook-completed"]' "$(target_wp option get wprism_first_sync_hook_trace --format=json | jq -c .)" \
  "fixed activation trace"
target_wp plugin is-active "$PLUGIN_SLUG" >/dev/null || fail "fixed plugin is not active"
assert_eq "$THEME_SLUG" "$(target_wp option get template)" "fixed standalone template"
assert_eq "$THEME_SLUG" "$(target_wp option get stylesheet)" "fixed standalone stylesheet"
assert_eq 1 "$(state_base_count)" "first-sync base after successful apply"
[[ "$(ledger_value applied_revision)" =~ ^[0-9a-f]{64}$ ]] || fail "successful first sync has no state revision"
[[ "$(ledger_value code_revision)" =~ ^[0-9a-f]{64}$ ]] || fail "successful first sync has no code revision"
assert_eq 0 "$(ledger_count code_stage_revision)" "staged receipt after successful finalize"
jq -e 'has("lifecycle_attempt") | not' <<<"$(ledger_value promotion_session)" >/dev/null \
  || fail "successful fixed lifecycle retained an ambiguity receipt"
if ! FINAL_STATUS="$(status 2>&1)"; then
  echo "$FINAL_STATUS" >&2
  fail "status was not clean after fixed first sync"
fi
echo "$FINAL_STATUS"
pass "fixed first sync materialized code, ran lifecycle, established the first base, and converged cleanly"

printf '\n\033[1;32m✔ FIRST-SYNC HOOK RECOVERY GRIND PASSED (%s)\033[0m\n' "$PAIR"
