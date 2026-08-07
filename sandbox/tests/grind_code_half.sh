#!/usr/bin/env bash
# Code-half clean-room grind.  This deliberately does NOT use pair.sh's
# --codebind overlay: /siterepo/code is a repository source, while WordPress
# starts with no probe plugin at its executable target path.  Every change to
# target code below must therefore pass through the public host `duo promote`
# workflow and the agent's code-stage/finalize materializer.
set -euo pipefail
cd "$(dirname "$0")/.."

REPO_ROOT="$(cd .. && pwd)"
DUO="$REPO_ROOT/cli/duo"
FIXTURE="$REPO_ROOT/sandbox/fixtures/duo-code-half-probe"
PAIR="codehalf${BASHPID}${RANDOM}"
PORT1=8872
PORT2=8873
SITE="siterepo/${PAIR}1"
OTHER_SITE="siterepo/${PAIR}2"
ENVS_FILE="$(mktemp "${TMPDIR:-/tmp}/duo-code-half-envs.XXXXXX")"
PAIR_UP=0

PLUGIN_SLUG="duo-code-half-probe"
PLUGIN_FILE="duo-code-half-probe.php"
PLUGIN_BASENAME="$PLUGIN_SLUG/$PLUGIN_FILE"
PLUGIN_SOURCE="$SITE/code/wp-content/plugins/$PLUGIN_SLUG/$PLUGIN_FILE"
PLUGIN_TARGET="/var/www/html/wp-content/plugins/$PLUGIN_SLUG/$PLUGIN_FILE"
CONFLICT_FILE="zz-conflict.php"
CONFLICT_SOURCE="$SITE/code/wp-content/plugins/$PLUGIN_SLUG/$CONFLICT_FILE"
CONFLICT_TARGET="/var/www/html/wp-content/plugins/$PLUGIN_SLUG/$CONFLICT_FILE"
UNMANAGED_TARGET="/var/www/html/wp-content/plugins/duo-unmanaged-sibling/duo-unmanaged-sibling.php"
THEME_SLUG="duo-code-half-theme"
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
    # Capture publishes with uid 33 inside the cli container.  Its staging
    # descendants can therefore be host-undeletable after a failed run;
    # normalize only this disposable pair's bind mount before removing it.
    "${COMPOSE[@]}" run --rm -T -u root cli1 sh -c 'chmod -R ugo+rwX /siterepo' >/dev/null 2>&1 || true
    if ! bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1; then
      printf 'FAIL: clean-room pair destroy failed for %s\n' "$PAIR" >&2
      status=1
    fi
    if ! pair_containers="$(docker ps -aq --filter "label=com.docker.compose.project=duo-$PAIR" 2>/dev/null)" \
      || ! pair_volumes="$(docker volume ls -q --filter "label=com.docker.compose.project=duo-$PAIR" 2>/dev/null)" \
      || ! pair_networks="$(docker network ls -q --filter "label=com.docker.compose.project=duo-$PAIR" 2>/dev/null)"; then
      printf 'FAIL: clean-room cleanup could not verify Docker resource removal for %s\n' "$PAIR" >&2
      status=1
    elif [ -n "$pair_containers$pair_volumes$pair_networks" ]; then
      printf 'FAIL: clean-room cleanup left Docker resources for project duo-%s behind\n' "$PAIR" >&2
      status=1
    fi
    if ! remaining_dbs="$(docker exec -e MYSQL_PWD=root duo-shared-db mariadb -uroot -N -B --raw \
      -e "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME IN ('wp_${PAIR}1','wp_${PAIR}2')" 2>/dev/null)"; then
      printf 'FAIL: clean-room cleanup could not verify database removal for %s\n' "$PAIR" >&2
      status=1
    elif [ -n "$remaining_dbs" ]; then
      printf 'FAIL: clean-room cleanup left pair database(s) behind: %s\n' "$remaining_dbs" >&2
      status=1
    fi
  fi
  rm -rf -- "$SITE" "$OTHER_SITE"
  if [ -e "$SITE" ] || [ -e "$OTHER_SITE" ]; then
    printf 'FAIL: clean-room site-repo cleanup left %s or %s behind\n' "$SITE" "$OTHER_SITE" >&2
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
canonicalize_json() {
  local path="$1" tmp="${1}.canon.${BASHPID}"
  DUO_CANON="$REPO_ROOT/agent/src/Canon.php" php -r '
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
artifact_files() {
  if [ -d "$SITE/.duo/artifacts" ]; then
    find "$SITE/.duo/artifacts" -type f -name 'promote-*.json' -print | sort
  fi
}
checkpoint_files() {
  if [ -d "$SITE/.duo/checkpoints" ]; then
    find "$SITE/.duo/checkpoints" -type f -name '*.sql' -print | sort
  fi
}

export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2"
COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml)
target_wp() {
  "${COMPOSE[@]}" run --rm -T cli1 sh -c 'umask 000; exec wp "$@"' _ "$@"
}
target_php() { "${COMPOSE[@]}" run --rm -T cli1 php -r "$1"; }
db_scalar() {
  # --raw preserves JSON bytes from duo_kv. Without it, batch mode rewrites
  # `\/` as `\\/`, making a valid promotion_session fail jq receipt checks.
  docker exec -e MYSQL_PWD=root duo-shared-db mariadb -uroot -N -B --raw "wp_${PAIR}1" -e "$1" \
    | tr -d '\r'
}
target_hash() { target_php "echo hash_file('sha256', '$PLUGIN_TARGET');"; }
target_exists() { target_php "echo is_file('$PLUGIN_TARGET') ? 'present' : 'absent';"; }
target_dir_exists() { target_php "echo is_dir('/var/www/html/wp-content/plugins/$PLUGIN_SLUG') ? 'present' : 'absent';"; }
target_code_tree_hash() {
  target_php '
$roots = [
    "/var/www/html/wp-content/plugins",
    "/var/www/html/wp-content/themes",
];
$rows = [];
foreach ($roots as $root) {
    if (!is_dir($root)) {
        $rows[] = $root . "|missing";
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $info) {
        $path = $info->getPathname();
        $relative = substr($path, strlen($root) + 1);
        if ($info->isLink()) {
            $kind = "link:" . (string) readlink($path);
        } elseif ($info->isDir()) {
            $kind = "dir";
        } elseif ($info->isFile()) {
            $kind = "file:" . (string) hash_file("sha256", $path);
        } else {
            $kind = "special";
        }
        $rows[] = $root . "/" . $relative . "|" . $kind;
    }
}
sort($rows, SORT_STRING);
echo hash("sha256", implode("\n", $rows));
'
}
source_hash() { sha256sum "$PLUGIN_SOURCE" | awk '{print $1}'; }
ledger_value() { db_scalar "SELECT v FROM wp_duo_kv WHERE k = '$1'"; }
ledger_revision() { ledger_value "$CODE_REVISION_KEY"; }
promote() { php "$DUO" --envs-file="$ENVS_FILE" promote target "$@"; }
status() { php "$DUO" --envs-file="$ENVS_FILE" status target; }
assert_promotion_receipts() {
  local artifact="$1" output="$2" label="$3"
  [ -f "$artifact" ] || fail "$label promotion artifact is missing: $artifact"
  jq -e '
    (.artifact_hash | test("^[0-9a-f]{64}$"))
    and (.revision_hash | test("^[0-9a-f]{64}$"))
    and (.code.code_revision | test("^[0-9a-f]{64}$"))
    and .code.format == "duo-code/v1"
    and .code.source == "code/wp-content"
  ' "$artifact" >/dev/null || fail "$label artifact identities/descriptor are malformed"

  local artifact_hash state_revision code_revision session checkpoint target_checkpoint
  artifact_hash="$(jq -r '.artifact_hash' "$artifact")"
  state_revision="$(jq -r '.revision_hash' "$artifact")"
  code_revision="$(jq -r '.code.code_revision' "$artifact")"
  assert_eq "$code_revision" "$(ledger_revision)" "$label completed code_revision receipt"
  assert_eq "$state_revision" "$(ledger_value applied_revision)" "$label applied state revision receipt"
  assert_eq 0 "$(db_scalar "SELECT COUNT(*) FROM wp_duo_kv WHERE k = 'promotion_lock'")" \
    "$label released promotion lease"
  session="$(ledger_value promotion_session)"
  jq -e --arg artifact "$artifact_hash" '
    (.owner | type == "string" and length > 0)
    and .artifact_hash == $artifact
    and (.begun_at | type == "number")
  ' <<<"$session" >/dev/null || fail "$label durable promotion session is missing or bound to another artifact"

  checkpoint="$(grep -F 'database checkpoint: ' <<<"$output" | head -1 | sed 's/^database checkpoint: //')"
  [ -n "$checkpoint" ] || fail "$label output did not name its database checkpoint"
  case "$checkpoint" in
    /siterepo/*) target_checkpoint="$SITE/${checkpoint#/siterepo/}" ;;
    *) fail "$label checkpoint was outside /siterepo: $checkpoint" ;;
  esac
  [ -s "$target_checkpoint" ] || fail "$label checkpoint was not retained at $target_checkpoint"
}

require docker
require jq
require php
require sha256sum
[ -f "$DUO" ] || fail "host CLI missing: $DUO"
[ -f "$FIXTURE/v1/$PLUGIN_FILE" ] || fail "v1 fixture missing"
[ -f "$FIXTURE/v2/$PLUGIN_FILE" ] || fail "v2 fixture missing"

say "clean room: pair.sh up without --codebind (headless, unique pair $PAIR)"
# Arm exact-pair cleanup before pair.sh can create a partial database,
# repository root, volume, or compose project.
PAIR_UP=1
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless

say "repository source exists while the executable target plugin path is absent"
mkdir -p "$SITE/code/wp-content/plugins/$PLUGIN_SLUG" \
         "$SITE/code/wp-content/themes/$THEME_SLUG"
cp "$FIXTURE/v1/$PLUGIN_FILE" "$PLUGIN_SOURCE"
cp "$FIXTURE/v2/$PLUGIN_FILE" "$SITE/.fixture-v2.php"
cp "$FIXTURE/theme/style.css" "$SITE/code/wp-content/themes/$THEME_SLUG/style.css"
cp "$FIXTURE/theme/index.php" "$SITE/code/wp-content/themes/$THEME_SLUG/index.php"
cat > "$SITE/site.duo.json" <<'EOF'
{
  "manifests": ["core", "duo-code-half-probe"],
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
jq -n --arg compose "$(pwd)/pair.yml" \
  '{envs: {target: {transport: "docker", compose_file: $compose, service: "cli1", repo_path: "/siterepo"}}}' \
  > "$ENVS_FILE"
[ -f "$PLUGIN_SOURCE" ] || fail "repository v1 source was not created"
assert_eq absent "$(target_exists)" "target plugin path before first promote"
pass "repository has v1 code, but WordPress has no executable probe plugin"

say "capture the clean target, then declare v1 plugin/theme lifecycle state"
# The clean environment is still on the bundled theme, so this first capture
# is deliberately legacy/state-only.  Enabling code before it would make the
# compiler correctly reject a target theme the repository has not declared.
# The next edit atomically changes both the canonical lifecycle identities
# and the code declaration before the first host promotion compiles them.
target_wp duo capture --repo=/siterepo >/dev/null
STATE="$SITE/state/options/core.json"
jq '.code = {format: 1, layout: "wp-content", source: "code/wp-content"}' "$SITE/site.duo.json" \
  > "$SITE/site.duo.next.json"
mv "$SITE/site.duo.next.json" "$SITE/site.duo.json"
canonicalize_json "$SITE/site.duo.json"
jq --arg plugin "$PLUGIN_BASENAME" --arg theme "$THEME_SLUG" '
  .records.active_plugins.value = [$plugin]
  | .records.template.value = $theme
  | .records.stylesheet.value = $theme
  | .records.duo_code_half_probe_settings = {
      autoload: "off", state: "present", value: "blue"
    }
' "$STATE" > "$STATE.next"
mv "$STATE.next" "$STATE"
canonicalize_json "$STATE"

say "v1 public promotion: compile -> lease/checkpoint -> materialize -> lifecycle -> finalize -> apply"
if ! V1_OUT="$(promote 2>&1)"; then
  echo "$V1_OUT" >&2
  fail "v1 public host promotion failed"
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
target_wp plugin is-active "$PLUGIN_SLUG" >/dev/null || fail "v1 lifecycle did not activate the probe"
target_wp theme is-active "$THEME_SLUG" >/dev/null || fail "v1 lifecycle did not switch to the code-owned theme"
assert_eq "$(source_hash)" "$(target_hash)" "v1 materialized plugin bytes"
assert_eq blue "$(db_scalar "SELECT option_value FROM wp_options WHERE option_name = 'duo_code_half_probe_settings'")" "v1 scalar setting"
assert_eq 1 "$(db_scalar "SELECT option_value FROM wp_options WHERE option_name = 'duo_code_half_probe_schema'")" "v1 schema"
V1_REVISION="$(ledger_revision)"
[[ "$V1_REVISION" =~ ^[0-9a-f]{64}$ ]] || fail "v1 did not publish a code_revision"
grep -Fq 'activate-v1' <<<"$(db_scalar "SELECT option_value FROM wp_options WHERE option_name = 'duo_code_half_probe_trace'")" \
  || fail "v1 activation trace is missing"
V1_ARTIFACT="$(latest_artifact)"
assert_promotion_receipts "$V1_ARTIFACT" "$V1_OUT" "v1"
V1_ARTIFACT_HASH="$(jq -r '.artifact_hash' "$V1_ARTIFACT")"
V1_STATE_REVISION="$(jq -r '.revision_hash' "$V1_ARTIFACT")"
pass "v1 was materialized, lifecycle-activated, applied, and finalized"

say "compile-time code/state mismatch fails before lease, checkpoint, or target contact"
COMPILE_TREE="$(target_code_tree_hash)"
COMPILE_REVISION="$(ledger_revision)"
COMPILE_SESSION="$(ledger_value promotion_session)"
COMPILE_ARTIFACTS="$(artifact_files)"
COMPILE_CHECKPOINTS="$(checkpoint_files)"
rm -f -- "$PLUGIN_SOURCE"
if COMPILE_OUT="$(promote 2>&1)"; then
  echo "$COMPILE_OUT" >&2
  fail "promotion unexpectedly compiled canonical activation without its plugin main file"
fi
echo "$COMPILE_OUT"
grep -Fq "canonical active plugin '$PLUGIN_BASENAME' has no matching plugin main file" <<<"$COMPILE_OUT" \
  || fail "compile failure did not name the cross-half activation contract"
grep -Fq 'compile failed; no checkpoint or target mutation occurred' <<<"$COMPILE_OUT" \
  || fail "compile failure did not state its before-target boundary"
if grep -Fq 'promote phase: promotion-begin' <<<"$COMPILE_OUT"; then
  fail "compile-time code/state mismatch acquired a target promotion lease"
fi
assert_eq "$COMPILE_TREE" "$(target_code_tree_hash)" "target code tree after compile refusal"
assert_eq "$COMPILE_REVISION" "$(ledger_revision)" "completed code_revision after compile refusal"
assert_eq "$COMPILE_SESSION" "$(ledger_value promotion_session)" "promotion session after compile refusal"
assert_eq "$COMPILE_ARTIFACTS" "$(artifact_files)" "artifact files after compile refusal"
assert_eq "$COMPILE_CHECKPOINTS" "$(checkpoint_files)" "checkpoint files after compile refusal"
cp "$FIXTURE/v1/$PLUGIN_FILE" "$PLUGIN_SOURCE"
pass "the narrow code/state bridge rejected an unsatisfied lifecycle identity entirely offline"

say "v2 promotion proves migration runs before canonical object state apply"
cp "$FIXTURE/v2/$PLUGIN_FILE" "$PLUGIN_SOURCE"
jq '
  .records.duo_code_half_probe_settings = {
      autoload: "off", state: "present", value: {schema: 2, color: "blue"}
    }
' "$STATE" > "$STATE.next"
mv "$STATE.next" "$STATE"
canonicalize_json "$STATE"
if ! V2_OUT="$(promote 2>&1)"; then
  echo "$V2_OUT" >&2
  fail "v2 public host promotion failed"
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
assert_eq "$(source_hash)" "$(target_hash)" "v2 materialized plugin bytes"
target_wp option get duo_code_half_probe_settings --format=json \
  | jq -e '.schema == 2 and .color == "blue"' >/dev/null \
  || fail "v2 canonical object after migration was not semantically correct"
assert_eq 2 "$(db_scalar "SELECT option_value FROM wp_options WHERE option_name = 'duo_code_half_probe_schema'")" "v2 schema"
assert_eq 1 "$(db_scalar "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_duo_code_half_probe_rows' AND COLUMN_NAME = 'color'")" \
  "v2 migrated color column"
V2_TRACE="$(db_scalar "SELECT option_value FROM wp_options WHERE option_name = 'duo_code_half_probe_trace'")"
grep -Fq 'migrate-v1-scalar:blue' <<<"$V2_TRACE" \
  || fail "v2 did not observe and convert the v1 scalar during lifecycle activation"
V2_REVISION="$(ledger_revision)"
[[ "$V2_REVISION" =~ ^[0-9a-f]{64}$ && "$V2_REVISION" != "$V1_REVISION" ]] \
  || fail "v2 code_revision was not finalized as a new descriptor"
V2_ARTIFACT="$(latest_artifact)"
assert_promotion_receipts "$V2_ARTIFACT" "$V2_OUT" "v2"
V2_ARTIFACT_HASH="$(jq -r '.artifact_hash' "$V2_ARTIFACT")"
V2_STATE_REVISION="$(jq -r '.revision_hash' "$V2_ARTIFACT")"
[ "$V2_ARTIFACT_HASH" != "$V1_ARTIFACT_HASH" ] || fail "v1 and v2 outer artifacts unexpectedly matched"
[ "$V2_STATE_REVISION" != "$V1_STATE_REVISION" ] || fail "v1 and v2 state revisions unexpectedly matched"
pass "v2 accepted the v1 scalar during lifecycle migration and left canonical v2 object state for apply"

say "byte-level code drift is surfaced, then healed by a public promotion"
target_php "file_put_contents('$PLUGIN_TARGET', \"\\n// external byte drift\\n\", FILE_APPEND);"
DRIFTED_HASH="$(target_hash)"
[ "$DRIFTED_HASH" != "$(source_hash)" ] || fail "could not introduce target code drift"
if DRIFT_STATUS="$(status 2>&1)"; then
  echo "$DRIFT_STATUS" >&2
  fail "status unexpectedly accepted byte-drifted completed code"
fi
echo "$DRIFT_STATUS"
grep -Fq CODE_REVISION_STALE <<<"$DRIFT_STATUS" || fail "status did not report the drifted completed code revision"
if ! HEAL_OUT="$(promote 2>&1)"; then
  echo "$HEAL_OUT" >&2
  fail "public promotion did not heal byte-level code drift"
fi
echo "$HEAL_OUT"
assert_eq "$(source_hash)" "$(target_hash)" "healed target plugin bytes"
assert_eq "$V2_REVISION" "$(ledger_revision)" "code_revision after drift healing"
HEAL_ARTIFACT="$(latest_artifact)"
assert_promotion_receipts "$HEAL_ARTIFACT" "$HEAL_OUT" "drift-heal"
assert_eq "$V2_ARTIFACT_HASH" "$(jq -r '.artifact_hash' "$HEAL_ARTIFACT")" \
  "immutable artifact identity after healing target-only drift"
pass "code-stage rewrote the drifted byte and code-finalize re-proved the completed descriptor"

say "create an unmanaged plugin sibling; materialization and prune must never traverse it"
target_php "mkdir(dirname('$UNMANAGED_TARGET'), 0777, true); file_put_contents('$UNMANAGED_TARGET', '<?php // unmanaged sibling\\n');"
UNMANAGED_HASH="$(target_php "echo hash_file('sha256', '$UNMANAGED_TARGET');")"
[[ "$UNMANAGED_HASH" =~ ^[0-9a-f]{64}$ ]] || fail "could not create unmanaged sibling"

say "late desired-path materialization preflight changes no target byte or completed revision"
printf '%s\n' '<?php // desired late-path conflict fixture' > "$CONFLICT_SOURCE"
target_php "mkdir('$CONFLICT_TARGET', 0777, true); file_put_contents('$CONFLICT_TARGET/keep.txt', 'directory conflicts with desired file');"
MATERIALIZE_TREE="$(target_code_tree_hash)"
MATERIALIZE_REVISION="$(ledger_revision)"
if MATERIALIZE_OUT="$(promote 2>&1)"; then
  echo "$MATERIALIZE_OUT" >&2
  fail "promotion unexpectedly wrote around a desired file-vs-directory conflict"
fi
echo "$MATERIALIZE_OUT"
grep -Fq "code-stage target path is not a regular file 'plugins/$PLUGIN_SLUG/$CONFLICT_FILE'" <<<"$MATERIALIZE_OUT" \
  || fail "materialization preflight did not name the late desired-path conflict"
if grep -Fq 'promote phase: lifecycle-' <<<"$MATERIALIZE_OUT"; then
  fail "desired-path preflight allowed a lifecycle phase to run"
fi
assert_eq "$MATERIALIZE_TREE" "$(target_code_tree_hash)" "full target code tree after failed materialization preflight"
assert_eq "$MATERIALIZE_REVISION" "$(ledger_revision)" "code_revision after failed materialization preflight"
assert_eq "$UNMANAGED_HASH" "$(target_php "echo hash_file('sha256', '$UNMANAGED_TARGET');")" \
  "unmanaged sibling after failed materialization preflight"
target_php "unlink('$CONFLICT_TARGET/keep.txt'); rmdir('$CONFLICT_TARGET');"
if ! TRACK_OUT="$(promote 2>&1)"; then
  echo "$TRACK_OUT" >&2
  fail "promotion failed after clearing the desired-path conflict"
fi
echo "$TRACK_OUT"
assert_eq present "$(target_php "echo is_file('$CONFLICT_TARGET') ? 'present' : 'absent';")" \
  "new desired file after successful retry"
TRACKED_REVISION="$(ledger_revision)"
[[ "$TRACKED_REVISION" =~ ^[0-9a-f]{64}$ && "$TRACKED_REVISION" != "$V2_REVISION" ]] \
  || fail "successful desired-file retry did not publish a new code_revision"
TRACKED_ARTIFACT="$(latest_artifact)"
assert_promotion_receipts "$TRACKED_ARTIFACT" "$TRACK_OUT" "tracked-file"
TRACKED_ARTIFACT_HASH="$(jq -r '.artifact_hash' "$TRACKED_ARTIFACT")"
[ "$TRACKED_ARTIFACT_HASH" != "$V2_ARTIFACT_HASH" ] \
  || fail "code-only addition did not change the outer artifact identity"
assert_eq "$V2_STATE_REVISION" "$(jq -r '.revision_hash' "$TRACKED_ARTIFACT")" \
  "state revision across code-only addition"
pass "the complete desired inventory was preflighted before any stage write; retry tracked the new file"

say "changed obsolete tracked file is rejected before stage writes or lifecycle"
cp "$CONFLICT_SOURCE" "$SITE/.fixture-zz-conflict.php"
rm -f -- "$CONFLICT_SOURCE"
target_php "file_put_contents('$CONFLICT_TARGET', \"\\n// changed prior-owned byte: refuse prune\\n\", FILE_APPEND);"
REMOVAL_TREE="$(target_code_tree_hash)"
REMOVAL_REVISION="$(ledger_revision)"
if REMOVAL_OUT="$(promote 2>&1)"; then
  echo "$REMOVAL_OUT" >&2
  fail "promotion unexpectedly staged around changed obsolete tracked code"
fi
echo "$REMOVAL_OUT"
grep -Fq 'promote phase: code-stage' <<<"$REMOVAL_OUT" \
  || fail "removal preflight did not reach code-stage"
grep -Fq 'changed prior-owned file' <<<"$REMOVAL_OUT" \
  || fail "removal preflight did not refuse the changed obsolete tracked file"
if grep -Fq 'promote phase: lifecycle-' <<<"$REMOVAL_OUT"; then
  fail "changed obsolete-file preflight allowed a lifecycle phase to run"
fi
assert_eq "$REMOVAL_TREE" "$(target_code_tree_hash)" "full target code tree after failed removal preflight"
assert_eq "$REMOVAL_REVISION" "$(ledger_revision)" "code_revision after failed removal preflight"
assert_eq "$UNMANAGED_HASH" "$(target_php "echo hash_file('sha256', '$UNMANAGED_TARGET');")" \
  "unmanaged sibling after failed removal preflight"
target_php "copy('/siterepo/.fixture-zz-conflict.php', '$CONFLICT_TARGET') || exit(1);"
if ! PRUNE_OUT="$(promote 2>&1)"; then
  echo "$PRUNE_OUT" >&2
  fail "public promotion failed after restoring changed obsolete code"
fi
echo "$PRUNE_OUT"
assert_eq absent "$(target_php "echo file_exists('$CONFLICT_TARGET') ? 'present' : 'absent';")" \
  "obsolete tracked file after successful retry"
PRUNED_TRACKED_REVISION="$(ledger_revision)"
[[ "$PRUNED_TRACKED_REVISION" =~ ^[0-9a-f]{64}$ && "$PRUNED_TRACKED_REVISION" != "$TRACKED_REVISION" ]] \
  || fail "successful obsolete-file prune did not publish a new code_revision"
PRUNED_ARTIFACT="$(latest_artifact)"
assert_promotion_receipts "$PRUNED_ARTIFACT" "$PRUNE_OUT" "tracked-file-prune"
[ "$(jq -r '.artifact_hash' "$PRUNED_ARTIFACT")" != "$TRACKED_ARTIFACT_HASH" ] \
  || fail "code-only removal did not change the outer artifact identity"
assert_eq "$V2_STATE_REVISION" "$(jq -r '.revision_hash' "$PRUNED_ARTIFACT")" \
  "state revision across code-only removal"
pass "changed obsolete code blocked all stage writes; restoring the recorded byte allowed only that owned file to prune"

say "public lifecycle deactivation runs while the old target component still exists, then finalize prunes it"
target_wp plugin is-active "$PLUGIN_SLUG" >/dev/null \
  || fail "probe unexpectedly became inactive before the public removal promotion"
assert_eq present "$(target_exists)" "target plugin before lifecycle deactivation/prune"
jq '
  .records.active_plugins.value = []
' "$STATE" > "$STATE.next"
mv "$STATE.next" "$STATE"
OPTION_RECORD="$(jq -c '.records.duo_code_half_probe_settings' "$STATE")"
OPTION_EXPECTED_HASH="$(DUO_CANON="$REPO_ROOT/agent/src/Canon.php" php -r '
require getenv("DUO_CANON");
$record = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
echo hash("sha256", Duo\Canon::encode($record));
' "$OPTION_RECORD")"
jq --arg expected "$OPTION_EXPECTED_HASH" '
  .records.duo_code_half_probe_settings = {
    expected_hash: $expected,
    state: "deleted"
  }
' "$STATE" > "$STATE.next"
mv "$STATE.next" "$STATE"
canonicalize_json "$STATE"
rm -f -- "$PLUGIN_SOURCE"
rmdir "$(dirname "$PLUGIN_SOURCE")" 2>/dev/null || true

say "public promotion deactivates first and completes empty-component prune"
if ! REMOVE_OUT="$(promote --with-deletes 2>&1)"; then
  echo "$REMOVE_OUT" >&2
  fail "public empty-component removal promotion failed"
fi
echo "$REMOVE_OUT"
assert_phase_order "$REMOVE_OUT" \
  "promote phase: compile" \
  "promote phase: promotion-begin" \
  "promote phase: checkpoint" \
  "promote phase: code-stage" \
  "promote phase: lifecycle-retire" \
  "promote phase: lifecycle-activate" \
  "promote phase: code-finalize" \
  "promote phase: apply"
assert_eq absent "$(target_exists)" "target plugin main file after finalized removal"
assert_eq absent "$(target_dir_exists)" "target plugin directory after finalized prune"
target_wp plugin is-active "$PLUGIN_SLUG" >/dev/null && fail "public lifecycle did not deactivate the plugin before prune"
assert_eq 1 "$(db_scalar "SELECT option_value FROM wp_options WHERE option_name = 'duo_code_half_probe_deactivations'")" \
  "v2 deactivation hook count"
grep -Fq 'deactivate-v2' <<<"$(db_scalar "SELECT option_value FROM wp_options WHERE option_name = 'duo_code_half_probe_trace'")" \
  || fail "v2 deactivation trace is missing after lifecycle-before-prune promotion"
assert_eq 0 "$(db_scalar "SELECT COUNT(*) FROM wp_options WHERE option_name = 'duo_code_half_probe_settings'")" \
  "removed authored setting after apply"
FINAL_REVISION="$(ledger_revision)"
[[ "$FINAL_REVISION" =~ ^[0-9a-f]{64}$ && "$FINAL_REVISION" != "$PRUNED_TRACKED_REVISION" ]] \
  || fail "successful removal did not finalize a new code_revision"
FINAL_ARTIFACT="$(latest_artifact)"
assert_promotion_receipts "$FINAL_ARTIFACT" "$REMOVE_OUT" "component-removal"
FINAL_SESSION="$(ledger_value promotion_session)"
jq -e '
  .state_transition.entity == "options/core"
  and (.state_transition.before_hash | test("^[0-9a-f]{64}$"))
  and (.state_transition.after_hash | test("^[0-9a-f]{64}$"))
  and .state_transition.before_hash != .state_transition.after_hash
' <<<"$FINAL_SESSION" >/dev/null \
  || fail "component-removal session did not retain its exact lifecycle state handoff"
assert_eq "$UNMANAGED_HASH" "$(target_php "echo hash_file('sha256', '$UNMANAGED_TARGET');")" \
  "unmanaged sibling after finalized prune"
pass "the public lifecycle hook fired before prune, the old Duo-owned component was removed, and the unmanaged sibling survived unchanged"

say "recapture convergence and host status"
RECAPTURE="$SITE/.recapture"
rm -rf -- "$RECAPTURE"
target_wp duo capture --repo=/siterepo --out=/siterepo/.recapture >/dev/null
diff -r "$SITE/state" "$RECAPTURE" >/dev/null \
  || fail "fresh recapture did not converge byte-for-byte with canonical state"
rm -rf -- "$RECAPTURE"
if ! FINAL_STATUS="$(status 2>&1)"; then
  echo "$FINAL_STATUS" >&2
  fail "host status was not clean after final recapture"
fi
echo "$FINAL_STATUS"
pass "final capture converged and public host status is clean"

printf '\n\033[1;32m✔ CODE-HALF GRIND PASSED (%s)\033[0m\n' "$PAIR"
