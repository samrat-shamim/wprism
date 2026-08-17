#!/usr/bin/env bash
# Advanced clean-room code-half grind. It owns one headless pair and never
# uses pair.sh's --codebind overlay: all executable code starts absent and
# reaches WordPress only through the public host promote workflow.
set -euo pipefail
cd "$(dirname "$0")/.."

REPO_ROOT="$(cd .. && pwd)"
DUO="$REPO_ROOT/cli/duo"
CODE_DEPLOY="$REPO_ROOT/cli/src/Transport/CodeDeploy.php"
FIXTURE="$REPO_ROOT/sandbox/fixtures/duo-code-half-ecosystem"
PAIR="ecosystem${BASHPID}${RANDOM}"
PORT1=8892
PORT2=8893
SITE="siterepo/${PAIR}1"
OTHER_SITE="siterepo/${PAIR}2"
ENVS_FILE="$(mktemp "${TMPDIR:-/tmp}/duo-code-half-ecosystem-envs.XXXXXX")"
PAIR_UP=0

BASE_SLUG="duo-ecosystem-base"
DEPENDENT_SLUG="duo-ecosystem-dependent"
REPLACEMENT_SLUG="duo-ecosystem-replacement"
SINGLE_FILE="duo-ecosystem-single.php"
USER_MU_FILE="duo-ecosystem-user.php"
FATAL_MU_FILE="duo-ecosystem-fatal.php"
BASE_BASENAME="$BASE_SLUG/$BASE_SLUG.php"
DEPENDENT_BASENAME="$DEPENDENT_SLUG/$DEPENDENT_SLUG.php"
REPLACEMENT_BASENAME="$REPLACEMENT_SLUG/$REPLACEMENT_SLUG.php"
PARENT_THEME="duo-ecosystem-parent"
CHILD_THEME="duo-ecosystem-child"
CONTENT="/var/www/html/wp-content"
BASE_TARGET="$CONTENT/plugins/$BASE_SLUG"
DEPENDENT_TARGET="$CONTENT/plugins/$DEPENDENT_SLUG"
REPLACEMENT_TARGET="$CONTENT/plugins/$REPLACEMENT_SLUG"
SINGLE_TARGET="$CONTENT/plugins/$SINGLE_FILE"
USER_MU_TARGET="$CONTENT/mu-plugins/$USER_MU_FILE"
FATAL_MU_TARGET="$CONTENT/mu-plugins/$FATAL_MU_FILE"
UNMANAGED_TARGET="$CONTENT/plugins/duo-ecosystem-unmanaged/unmanaged.php"
STATE="$SITE/state/options/core.json"
V1_INPUTS="$SITE/.ecosystem-v1-inputs"
CODE_REVISION_KEY="code_revision"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

cleanup() {
  local status=$?
  local pair_containers pair_volumes pair_networks remaining_dbs
  trap - EXIT INT TERM
  set +e
  if [ "$PAIR_UP" = 1 ]; then
    # Capture and artifacts can be uid 33 descendants. Normalize only this
    # disposable pair root before pair.sh deletes it.
    "${COMPOSE[@]}" run --rm -T -u root cli1 sh -c 'chmod -R ugo+rwX /siterepo' >/dev/null 2>&1 || true
    if ! bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1; then
      printf 'FAIL: ecosystem pair destroy failed for %s\n' "$PAIR" >&2
      status=1
    fi
    if ! pair_containers="$(docker ps -aq --filter "label=com.docker.compose.project=duo-$PAIR" 2>/dev/null)" \
      || ! pair_volumes="$(docker volume ls -q --filter "label=com.docker.compose.project=duo-$PAIR" 2>/dev/null)" \
      || ! pair_networks="$(docker network ls -q --filter "label=com.docker.compose.project=duo-$PAIR" 2>/dev/null)"; then
      printf 'FAIL: ecosystem cleanup could not verify Docker resource removal for %s\n' "$PAIR" >&2
      status=1
    elif [ -n "$pair_containers$pair_volumes$pair_networks" ]; then
      printf 'FAIL: ecosystem cleanup left Docker resources for project duo-%s behind\n' "$PAIR" >&2
      status=1
    fi
    if ! remaining_dbs="$(docker exec -e MYSQL_PWD=root duo-shared-db mariadb -uroot -N -B --raw \
      -e "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME IN ('wp_${PAIR}1','wp_${PAIR}2')" 2>/dev/null)"; then
      printf 'FAIL: ecosystem cleanup could not verify database removal for %s\n' "$PAIR" >&2
      status=1
    elif [ -n "$remaining_dbs" ]; then
      printf 'FAIL: ecosystem cleanup left pair database(s) behind: %s\n' "$remaining_dbs" >&2
      status=1
    fi
  fi
  rm -rf -- "$SITE" "$OTHER_SITE"
  if [ -e "$SITE" ] || [ -e "$OTHER_SITE" ]; then
    printf 'FAIL: ecosystem cleanup left %s or %s behind\n' "$SITE" "$OTHER_SITE" >&2
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
  DUO_CANON="$REPO_ROOT/agent/src/Kernel/Canon.php" php -r '
require getenv("DUO_CANON");
$path = $argv[1];
$raw = file_get_contents($path);
if ($raw === false) { throw new RuntimeException("cannot read " . $path); }
echo Duo\Canon::encode(Duo\Canon::decode($raw));
' "$path" > "$tmp"
  mv "$tmp" "$path"
}
latest_artifact() {
  find "$SITE/.duo/artifacts" -type f -name 'promote-*.json' -printf '%T@ %p\n' \
    | sort -n | tail -1 | cut -d' ' -f2-
}

export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2"
COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml)
target_wp() {
  "${COMPOSE[@]}" run --rm -T cli1 sh -c 'umask 000; exec wp "$@"' _ "$@"
}
target_php() { "${COMPOSE[@]}" run --rm -T cli1 php -r "$1"; }
db_scalar() {
  docker exec -e MYSQL_PWD=root duo-shared-db mariadb -uroot -N -B --raw "wp_${PAIR}1" -e "$1" \
    | tr -d '\r'
}
ledger_value() { db_scalar "SELECT v FROM wp_duo_kv WHERE k = '$1'"; }
ledger_revision() { ledger_value "$CODE_REVISION_KEY"; }
promote() { php "$DUO" --envs-file="$ENVS_FILE" promote target "$@"; }
status() { php "$DUO" --envs-file="$ENVS_FILE" status target; }

# Execute the same isolated commands the host prints for exact checkpoint
# recovery without copying its private --exec bootstrap into this grind.
control_wp() {
  local method="$1"
  shift
  local encoded
  encoded="$(DUO_CODE_DEPLOY="$CODE_DEPLOY" php -r '
require getenv("DUO_CODE_DEPLOY");
$class = Duo\Orchestrator\CodeDeploy::class;
$method = $argv[1];
$args = $class::$method(...array_slice($argv, 2));
echo json_encode($args, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
' "$method" "$@")"
  local -a args
  mapfile -t args < <(jq -r '.[]' <<<"$encoded")
  target_wp "${args[@]}"
}
target_file() { target_php "echo is_file('$1') ? 'present' : 'absent';"; }
target_dir() { target_php "echo is_dir('$1') ? 'present' : 'absent';"; }
trace_json() { target_wp option get duo_ecosystem_trace --format=json; }
# Core preserves numeric holes after deactivate_plugins(). WP loads the values
# in iteration order, so normalize its JSON object form back to the canonical
# ordered plugin list before asserting lifecycle membership.
active_plugins_json() {
  target_wp option get active_plugins --format=json \
    | jq -c 'if type == "array" then . else [to_entries | sort_by(.key | tonumber)[] | .value] end'
}
assert_trace_order() {
  local trace="$1" first="$2" second="$3" first_index second_index
  first_index="$(jq -r --arg event "$first" 'to_entries[] | select(.value == $event) | .key' <<<"$trace" | head -1)"
  second_index="$(jq -r --arg event "$second" 'to_entries[] | select(.value == $event) | .key' <<<"$trace" | head -1)"
  [[ "$first_index" =~ ^[0-9]+$ ]] || fail "trace lacks '$first': $trace"
  [[ "$second_index" =~ ^[0-9]+$ ]] || fail "trace lacks '$second': $trace"
  [ "$first_index" -lt "$second_index" ] || fail "trace order is wrong ($first_index !< $second_index): $trace"
}
assert_trace_has() {
  local trace="$1" event="$2"
  jq -e --arg event "$event" 'index($event) != null' <<<"$trace" >/dev/null \
    || fail "trace lacks '$event': $trace"
}
assert_receipt() {
  local artifact="$1" label="$2" expected_code="$3"
  [ -f "$artifact" ] || fail "$label artifact missing: $artifact"
  jq -e --arg code "$expected_code" '
    (.artifact_hash | test("^[0-9a-f]{64}$"))
    and (.revision_hash | test("^[0-9a-f]{64}$"))
    and .code.format == "duo-code/v1"
    and .code.code_revision == $code
  ' "$artifact" >/dev/null || fail "$label artifact has malformed code receipt"
  assert_eq "$expected_code" "$(ledger_revision)" "$label completed code receipt"
  assert_eq "$(jq -r '.revision_hash' "$artifact")" "$(ledger_value applied_revision)" \
    "$label applied state receipt"
  assert_eq 0 "$(db_scalar "SELECT COUNT(*) FROM wp_duo_kv WHERE k = 'promotion_lock'")" \
    "$label released promotion lease"
}

require docker
require jq
require php
[ -f "$DUO" ] || fail "host CLI missing: $DUO"
[ -f "$FIXTURE/v1/wp-content/plugins/$BASE_SLUG/$BASE_SLUG.php" ] || fail "v1 base fixture missing"
[ -f "$FIXTURE/v1/wp-content/plugins/$DEPENDENT_SLUG/$DEPENDENT_SLUG.php" ] || fail "v1 dependent fixture missing"
[ -f "$FIXTURE/v2/broken/$REPLACEMENT_SLUG.php" ] || fail "broken replacement fixture missing"
[ -f "$FIXTURE/v2/fixed/$REPLACEMENT_SLUG.php" ] || fail "fixed replacement fixture missing"
[ -f "$FIXTURE/fatal/$FATAL_MU_FILE" ] || fail "fatal MU fixture missing"

ACTIVE_PAIRS="$(docker compose ls --format json 2>/dev/null | jq -r '
  .[] | select(.Status | startswith("running"))
  | select(.ConfigFiles | test("/pair\\.yml(,|$)")) | .Name
' 2>/dev/null || true)"
[ -z "$ACTIVE_PAIRS" ] || fail "refusing to start $PAIR while another pair is active: $ACTIVE_PAIRS"

say "clean room: headless pair $PAIR, without --codebind"
# Arm exact-pair cleanup before pair.sh can create a partial compose project.
PAIR_UP=1
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless
jq -n --arg compose "$(pwd)/pair.yml" \
  '{envs: {target: {transport: "docker", compose_file: $compose, service: "cli1", repo_path: "/siterepo"}}}' \
  > "$ENVS_FILE"

say "harness preparation: permit a uid-33 user MU sibling without changing protected Duo mounts"
# pair.yml bind-mounts the protected Duo directory and loader as root-owned
# entries beneath this otherwise ordinary WordPress directory. The harness
# needs one writable parent so code-stage can create a separate top-level
# user MU plugin; do not chmod the protected entries themselves.
"${COMPOSE[@]}" run --rm -T -u root cli1 sh -c 'chmod 0777 /var/www/html/wp-content/mu-plugins'
assert_eq writable "$(target_php "echo is_writable('$CONTENT/mu-plugins') ? 'writable' : 'not-writable';")" \
  "uid-33 user MU parent after harness preparation"

say "repository v1 exists while every managed executable target is absent"
mkdir -p "$SITE/code"
cp -a "$FIXTURE/v1/wp-content" "$SITE/code/"
cat > "$SITE/site.duo.json" <<'EOF'
{
  "manifests": ["core", "duo-code-half-ecosystem"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"],
    "term_meta": {}
  },
  "spec_version": 2
}
EOF
assert_eq absent "$(target_dir "$BASE_TARGET")" "base target before v1"
assert_eq absent "$(target_dir "$DEPENDENT_TARGET")" "dependent target before v1"
assert_eq absent "$(target_file "$SINGLE_TARGET")" "single-file target before v1"
assert_eq absent "$(target_file "$USER_MU_TARGET")" "user MU target before v1"
assert_eq absent "$(target_dir "$CONTENT/themes/$CHILD_THEME")" "child theme target before v1"

say "capture clean state, then declare v1 lifecycle and code inputs"
target_wp duo capture --repo=/siterepo >/dev/null
jq '.code = {format: 1, layout: "wp-content", source: "code/wp-content"}' "$SITE/site.duo.json" \
  > "$SITE/site.duo.next.json"
mv "$SITE/site.duo.next.json" "$SITE/site.duo.json"
canonicalize_json "$SITE/site.duo.json"
jq --arg base "$BASE_BASENAME" --arg dependent "$DEPENDENT_BASENAME" --arg single "$SINGLE_FILE" \
  --arg parent "$PARENT_THEME" --arg child "$CHILD_THEME" '
  .records.active_plugins.value = [$base, $dependent, $single]
  | .records.template.value = $parent
  | .records.stylesheet.value = $child
' "$STATE" > "$STATE.next"
mv "$STATE.next" "$STATE"
canonicalize_json "$STATE"
mkdir -p "$V1_INPUTS"
cp -a "$SITE/code" "$V1_INPUTS/code"
cp "$SITE/site.duo.json" "$V1_INPUTS/site.duo.json"
cp "$STATE" "$V1_INPUTS/core.json"

say "v1 public promote stages code, activates dependency order, and switches child theme"
if ! V1_OUT="$(promote 2>&1)"; then
  echo "$V1_OUT" >&2
  fail "v1 public promotion failed"
fi
echo "$V1_OUT"
assert_phase_order "$V1_OUT" \
  "promote phase: compile" \
  "promote phase: promotion-begin" \
  "promote phase: checkpoint" \
  "promote phase: code-stage" \
  "promote phase: lifecycle-retire" \
  "promote phase: lifecycle-activate" \
  "promote phase: code-finalize" \
  "promote phase: apply"
assert_eq present "$(target_dir "$BASE_TARGET")" "v1 base materialized"
assert_eq present "$(target_dir "$DEPENDENT_TARGET")" "v1 dependent materialized"
assert_eq present "$(target_file "$SINGLE_TARGET")" "v1 single-file materialized"
assert_eq present "$(target_file "$USER_MU_TARGET")" "v1 top-level user MU materialized"
assert_eq present "$(target_dir "$CONTENT/themes/$PARENT_THEME")" "v1 parent theme materialized"
assert_eq present "$(target_dir "$CONTENT/themes/$CHILD_THEME")" "v1 child theme materialized"
assert_eq "[\"$BASE_BASENAME\",\"$DEPENDENT_BASENAME\",\"$SINGLE_FILE\"]" \
  "$(active_plugins_json)" "v1 exact active-plugin dependency order"
assert_eq "$PARENT_THEME" "$(target_wp option get template)" "v1 parent template"
assert_eq "$CHILD_THEME" "$(target_wp option get stylesheet)" "v1 child stylesheet"
V1_TRACE="$(trace_json)"
assert_trace_order "$V1_TRACE" 'activate:base-v1' 'activate:dependent-v1'
assert_trace_has "$V1_TRACE" 'activate:single-v1'
[ "$(target_wp option get duo_ecosystem_mu_boots)" -gt 0 ] || fail "v1 user MU code did not load normally"
V1_ARTIFACT="$(latest_artifact)"
V1_REVISION="$(jq -r '.code.code_revision' "$V1_ARTIFACT")"
V1_ARTIFACT_HASH="$(jq -r '.artifact_hash' "$V1_ARTIFACT")"
V1_STATE_REVISION="$(jq -r '.revision_hash' "$V1_ARTIFACT")"
assert_receipt "$V1_ARTIFACT" "v1" "$V1_REVISION"
pass "v1 has the ordered base/dependent pair, single-file plugin, user MU plugin, and child theme"

say "unmanaged sibling is outside all component-scoped lifecycle and prune ownership"
target_php "mkdir(dirname('$UNMANAGED_TARGET'), 0777, true); file_put_contents('$UNMANAGED_TARGET', '<?php // unmanaged ecosystem sibling');"
UNMANAGED_HASH="$(target_php "echo hash_file('sha256', '$UNMANAGED_TARGET');")"
[[ "$UNMANAGED_HASH" =~ ^[0-9a-f]{64}$ ]] || fail "could not create unmanaged sibling"

say "v2 stages a same-symbol replacement, retires dependent then base, and stops on reviewed activation failure"
rm -rf -- "$SITE/code/wp-content/plugins/$BASE_SLUG" "$SITE/code/wp-content/plugins/$DEPENDENT_SLUG"
mkdir -p "$SITE/code/wp-content/plugins/$REPLACEMENT_SLUG"
cp "$FIXTURE/v2/broken/$REPLACEMENT_SLUG.php" \
  "$SITE/code/wp-content/plugins/$REPLACEMENT_SLUG/$REPLACEMENT_SLUG.php"
jq --arg replacement "$REPLACEMENT_BASENAME" --arg single "$SINGLE_FILE" '
  .records.active_plugins.value = [$replacement, $single]
' "$STATE" > "$STATE.next"
mv "$STATE.next" "$STATE"
canonicalize_json "$STATE"
if V2_BROKEN_OUT="$(promote 2>&1)"; then
  echo "$V2_BROKEN_OUT" >&2
  fail "broken replacement unexpectedly activated"
fi
echo "$V2_BROKEN_OUT"
assert_phase_order "$V2_BROKEN_OUT" \
  "promote phase: compile" \
  "promote phase: promotion-begin" \
  "promote phase: checkpoint" \
  "promote phase: code-stage" \
  "promote phase: lifecycle-retire" \
  "promote phase: lifecycle-activate"
grep -Fq 'duo ecosystem reviewed activation failure' <<<"$V2_BROKEN_OUT" \
  || fail "controlled activation failure did not reach the replacement hook"
assert_absent "$V2_BROKEN_OUT" 'fresh activation process after retirement' \
  "replacement ran in a fresh process rather than colliding with v1's symbol"
assert_absent "$V2_BROKEN_OUT" 'promote phase: code-finalize' "broken activation path"
assert_absent "$V2_BROKEN_OUT" 'promote phase: apply' "broken activation path"
grep -Fq 'promotion lease cleanup confirmed' <<<"$V2_BROKEN_OUT" \
  || fail "broken activation path did not safely abort its lease"
assert_eq present "$(target_dir "$BASE_TARGET")" "v1 base before finalized v2 retry"
assert_eq present "$(target_dir "$DEPENDENT_TARGET")" "v1 dependent before finalized v2 retry"
assert_eq present "$(target_dir "$REPLACEMENT_TARGET")" "staged replacement after failed activation"
assert_eq "$V1_REVISION" "$(ledger_revision)" "completed revision after failed activation"
V2_FAIL_ACTIVE="$(active_plugins_json)"
V2_FAIL_TRACE="$(trace_json)"
printf 'post-failed-activation active_plugins=%s trace=%s\n' "$V2_FAIL_ACTIVE" "$V2_FAIL_TRACE"
assert_eq "[\"$SINGLE_FILE\"]" "$V2_FAIL_ACTIVE" \
  "retirement persisted before replacement activation failed"
assert_trace_order "$V2_FAIL_TRACE" \
  'deactivate:dependent-v1:base=present:dependent=present' \
  'deactivate:base-v1:base=present:dependent=present'
assert_trace_has "$V2_FAIL_TRACE" 'activate:replacement-broken'
assert_eq "$UNMANAGED_HASH" "$(target_php "echo hash_file('sha256', '$UNMANAGED_TARGET');")" \
  "unmanaged sibling after failed replacement"
V2_FAILED_CHECKPOINT="$(sed -n 's/^database checkpoint: //p' <<<"$V2_BROKEN_OUT" | head -1)"
[ -n "$V2_FAILED_CHECKPOINT" ] || fail "failed replacement did not report its checkpoint"
[ -f "$SITE/${V2_FAILED_CHECKPOINT#/siterepo/}" ] \
  || fail "failed replacement checkpoint is not target-visible"
V2_FAILED_SESSION="$(ledger_value promotion_session)"
V2_FAILED_OWNER="$(jq -r '.owner' <<<"$V2_FAILED_SESSION")"
V2_FAILED_ARTIFACT="$(jq -r '.artifact_hash' <<<"$V2_FAILED_SESSION")"
jq -e '
  (.artifact_hash | test("^[0-9a-f]{64}$"))
  and .lifecycle_attempt.entity == "options/core"
  and .lifecycle_attempt.phase == "activate"
  and (.lifecycle_attempt.before_hash | test("^[0-9a-f]{64}$"))
' <<<"$V2_FAILED_SESSION" >/dev/null \
  || fail "failed replacement did not retain its exact pre-hook lifecycle boundary"
pass "retirement used reverse dependency order while both old roots still existed; the new same-symbol plugin reached its deliberate activation refusal"

say "restore exact v1 DB/code under exclusion, then review/fix replacement; only the recovered retry may prune"
# The retained checkpoint predates code-stage. Its exact code tree was v1, so
# remove only the newly staged replacement root; the base/dependent/single/MU/
# theme roots are still byte-identical and remain in place. External exclusion
# is provided by this grind's sole ownership of the disposable target pair.
"${COMPOSE[@]}" run --rm -T cli1 sh -c 'rm -rf -- "$1"' _ "$REPLACEMENT_TARGET"
assert_eq absent "$(target_dir "$REPLACEMENT_TARGET")" "replacement after exact pre-promotion code restore"
control_wp abortArgs "$V2_FAILED_OWNER" "$V2_FAILED_ARTIFACT" >/dev/null
control_wp beginArgs "$V2_FAILED_OWNER" "$V2_FAILED_ARTIFACT" >/dev/null
jq -e '.lifecycle_attempt.phase == "activate"' <<<"$(ledger_value promotion_session)" >/dev/null \
  || fail "exact recovery begin erased the failed replacement boundary before import"
control_wp recoveryDbImportArgs "$V2_FAILED_CHECKPOINT" >/dev/null
control_wp abortArgs "$V2_FAILED_OWNER" "$V2_FAILED_ARTIFACT" >/dev/null
assert_eq "$V1_REVISION" "$(ledger_revision)" "completed revision after v1 checkpoint restore"
assert_eq "[\"$BASE_BASENAME\",\"$DEPENDENT_BASENAME\",\"$SINGLE_FILE\"]" \
  "$(active_plugins_json)" "active plugins after v1 checkpoint restore"
assert_eq 0 "$(db_scalar "SELECT COUNT(*) FROM wp_duo_kv WHERE k = 'promotion_lock'")" \
  "recovery lease after final abort"
jq -e 'has("lifecycle_attempt") | not' <<<"$(ledger_value promotion_session)" >/dev/null \
  || fail "v1 checkpoint restore retained the failed replacement boundary"
V2_RESTORED_TRACE="$(trace_json)"
if jq -e 'index("activate:replacement-broken") != null' <<<"$V2_RESTORED_TRACE" >/dev/null; then
  fail "v1 checkpoint restore retained the failed activation trace: $V2_RESTORED_TRACE"
fi

cp "$FIXTURE/v2/fixed/$REPLACEMENT_SLUG.php" \
  "$SITE/code/wp-content/plugins/$REPLACEMENT_SLUG/$REPLACEMENT_SLUG.php"
if ! V2_OUT="$(promote 2>&1)"; then
  echo "$V2_OUT" >&2
  fail "fixed replacement retry failed"
fi
echo "$V2_OUT"
assert_phase_order "$V2_OUT" \
  "promote phase: compile" \
  "promote phase: promotion-begin" \
  "promote phase: checkpoint" \
  "promote phase: code-stage" \
  "promote phase: lifecycle-retire" \
  "promote phase: lifecycle-activate" \
  "promote phase: code-finalize" \
  "promote phase: apply"
assert_eq absent "$(target_dir "$BASE_TARGET")" "retired base after v2 finalization"
assert_eq absent "$(target_dir "$DEPENDENT_TARGET")" "retired dependent after v2 finalization"
assert_eq present "$(target_dir "$REPLACEMENT_TARGET")" "fixed replacement after v2 finalization"
assert_eq present "$(target_file "$SINGLE_TARGET")" "single-file plugin survives v2"
assert_eq present "$(target_file "$USER_MU_TARGET")" "user MU plugin survives v2"
assert_eq "[\"$REPLACEMENT_BASENAME\",\"$SINGLE_FILE\"]" \
  "$(active_plugins_json)" "v2 exact active-plugin order"
V2_TRACE="$(trace_json)"
assert_trace_has "$V2_TRACE" 'activate:replacement-fixed'
assert_eq "$UNMANAGED_HASH" "$(target_php "echo hash_file('sha256', '$UNMANAGED_TARGET');")" \
  "unmanaged sibling after v2 finalization"
V2_ARTIFACT="$(latest_artifact)"
V2_REVISION="$(jq -r '.code.code_revision' "$V2_ARTIFACT")"
assert_receipt "$V2_ARTIFACT" "v2-fixed" "$V2_REVISION"
[ "$V2_REVISION" != "$V1_REVISION" ] || fail "v2 replacement did not produce a distinct code revision"
pass "checkpoint recovery removed the ambiguous hook effects; the fixed fresh-process retry then activated replacement and pruned only retired Duo-owned roots"

say "stage a user MU bootstrap fatal through public promote; normal lifecycle must fail while control abort remains safe"
cp "$FIXTURE/fatal/$FATAL_MU_FILE" "$SITE/code/wp-content/mu-plugins/$FATAL_MU_FILE"
if FATAL_OUT="$(promote 2>&1)"; then
  echo "$FATAL_OUT" >&2
  fail "staged fatal user MU plugin unexpectedly allowed normal lifecycle"
fi
echo "$FATAL_OUT"
assert_phase_order "$FATAL_OUT" \
  "promote phase: compile" \
  "promote phase: promotion-begin" \
  "promote phase: checkpoint" \
  "promote phase: code-stage" \
  "promote phase: lifecycle-retire"
grep -Fq 'duo ecosystem staged fatal MU bootstrap marker' <<<"$FATAL_OUT" \
  || fail "normal lifecycle failure did not expose the staged user MU fatal"
assert_absent "$FATAL_OUT" 'promote phase: lifecycle-activate' "fatal MU path"
assert_absent "$FATAL_OUT" 'promote phase: code-finalize' "fatal MU path"
assert_absent "$FATAL_OUT" 'promote phase: apply' "fatal MU path"
grep -Fq 'promotion lease cleanup confirmed' <<<"$FATAL_OUT" \
  || fail "fatal MU path did not use isolated promotion-abort"
assert_eq present "$(target_file "$FATAL_MU_TARGET")" "fatal user MU after isolated stage"
assert_eq "$V2_REVISION" "$(ledger_revision)" "completed revision after fatal MU lifecycle refusal"
if NORMAL_FATAL_OUT="$(target_wp option get siteurl 2>&1)"; then
  echo "$NORMAL_FATAL_OUT" >&2
  fail "normal WordPress bootstrap unexpectedly survived the staged fatal MU plugin"
fi
grep -Fq 'duo ecosystem staged fatal MU bootstrap marker' <<<"$NORMAL_FATAL_OUT" \
  || fail "ordinary bootstrap failure did not identify the fixture MU plugin"
pass "public promote reached safe control-plane stage, normal lifecycle failed closed, and isolated abort released the lease"

say "review source and use a second ordinary public promote to recover only the abandoned staged MU file"
rm -f -- "$SITE/code/wp-content/mu-plugins/$FATAL_MU_FILE"
if ! MU_RECOVERY_OUT="$(promote 2>&1)"; then
  echo "$MU_RECOVERY_OUT" >&2
  fail "second public promote did not recover the abandoned fatal MU file"
fi
echo "$MU_RECOVERY_OUT"
assert_phase_order "$MU_RECOVERY_OUT" \
  "promote phase: compile" \
  "promote phase: promotion-begin" \
  "promote phase: checkpoint" \
  "promote phase: code-stage" \
  "promote phase: lifecycle-retire" \
  "promote phase: lifecycle-activate" \
  "promote phase: code-finalize" \
  "promote phase: apply"
grep -Fq 'recovered 1 abandoned staged MU file' <<<"$MU_RECOVERY_OUT" \
  || fail "second public stage did not report its bounded abandoned-MU recovery"
assert_eq absent "$(target_file "$FATAL_MU_TARGET")" "fatal user MU after public recovery finalization"
target_wp option get siteurl >/dev/null || fail "normal bootstrap did not recover after public code-finalize"
assert_eq "$V2_REVISION" "$(ledger_revision)" "completed revision after fatal-MU recovery convergence"
MU_RECOVERY_ARTIFACT="$(latest_artifact)"
assert_receipt "$MU_RECOVERY_ARTIFACT" "fatal-MU public recovery" "$V2_REVISION"
pass "the second public promote used its isolated code-stage/finalize around normal lifecycle, recovered only the abandoned MU file, and converged"

say "restore exact v1 repository inputs and prove original descriptor and outer artifact identities return"
rm -rf -- "$SITE/code"
cp -a "$V1_INPUTS/code" "$SITE/code"
cp "$V1_INPUTS/site.duo.json" "$SITE/site.duo.json"
cp "$V1_INPUTS/core.json" "$STATE"
if ! RESTORE_OUT="$(promote 2>&1)"; then
  echo "$RESTORE_OUT" >&2
  fail "exact v1 restore promotion failed"
fi
echo "$RESTORE_OUT"
assert_phase_order "$RESTORE_OUT" \
  "promote phase: compile" \
  "promote phase: promotion-begin" \
  "promote phase: checkpoint" \
  "promote phase: code-stage" \
  "promote phase: lifecycle-retire" \
  "promote phase: lifecycle-activate" \
  "promote phase: code-finalize" \
  "promote phase: apply"
assert_eq present "$(target_dir "$BASE_TARGET")" "restored base"
assert_eq present "$(target_dir "$DEPENDENT_TARGET")" "restored dependent"
assert_eq absent "$(target_dir "$REPLACEMENT_TARGET")" "replacement after restored v1 finalization"
assert_eq present "$(target_file "$SINGLE_TARGET")" "restored single-file plugin"
assert_eq present "$(target_file "$USER_MU_TARGET")" "restored user MU plugin"
assert_eq present "$(target_dir "$CONTENT/themes/$PARENT_THEME")" "restored parent theme"
assert_eq present "$(target_dir "$CONTENT/themes/$CHILD_THEME")" "restored child theme"
assert_eq "[\"$BASE_BASENAME\",\"$DEPENDENT_BASENAME\",\"$SINGLE_FILE\"]" \
  "$(active_plugins_json)" "restored v1 active-plugin order"
assert_eq "$UNMANAGED_HASH" "$(target_php "echo hash_file('sha256', '$UNMANAGED_TARGET');")" \
  "unmanaged sibling after exact v1 restore"
RESTORED_ARTIFACT="$(latest_artifact)"
assert_eq "$V1_REVISION" "$(jq -r '.code.code_revision' "$RESTORED_ARTIFACT")" \
  "exact v1 code revision restored"
assert_eq "$V1_ARTIFACT_HASH" "$(jq -r '.artifact_hash' "$RESTORED_ARTIFACT")" \
  "exact v1 outer artifact identity restored"
assert_eq "$V1_STATE_REVISION" "$(jq -r '.revision_hash' "$RESTORED_ARTIFACT")" \
  "exact v1 state revision restored"
assert_receipt "$RESTORED_ARTIFACT" "restored-v1" "$V1_REVISION"
if ! FINAL_STATUS="$(status 2>&1)"; then
  echo "$FINAL_STATUS" >&2
  fail "host status was not clean after exact v1 restoration"
fi
echo "$FINAL_STATUS"
pass "target trees, receipts, unmanaged sibling, and public status converge on the original v1 identity"

printf '\n\033[1;32m✔ CODE-HALF ECOSYSTEM GRIND PASSED (%s)\033[0m\n' "$PAIR"
