#!/usr/bin/env bash
# Offline contract checks for the ecommerce developer grind.  This test is
# intentionally Docker-free: the live pair is a separately authorized step.
set -euo pipefail
cd "$(dirname "$0")/.."

SCRIPT="tests/grind_ecommerce_developer.sh"
FIXTURE="fixtures/duo-ecommerce-developer-grind"
STATIC_STRUCTURE_FILE=""

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "ok: $*"; }
STATIC_INVALID_OUT="$(mktemp "${TMPDIR:-/tmp}/duo-ecommerce-invalid.XXXXXX")"
STATIC_EXISTING_OUT="$(mktemp "${TMPDIR:-/tmp}/duo-ecommerce-existing.XXXXXX")"
STATIC_SENTINEL=""
STATIC_CREATED_SITEREPO=0
cleanup_static() {
  local status=$?
  trap - EXIT
  rm -f -- "$STATIC_INVALID_OUT" "$STATIC_EXISTING_OUT" "$STATIC_STRUCTURE_FILE"
  if [ -n "$STATIC_SENTINEL" ] && [ -d "$STATIC_SENTINEL" ]; then
    rm -f -- "$STATIC_SENTINEL/keep"
    rmdir "$STATIC_SENTINEL" 2>/dev/null || true
  fi
  if [ "$STATIC_CREATED_SITEREPO" = 1 ]; then
    rmdir siterepo 2>/dev/null || true
  fi
  exit "$status"
}
trap cleanup_static EXIT

# Scan only executable structure: the prelude, named shell functions, and
# named `say` phase blocks.  This prevents a comment or dead relocation from
# satisfying a contract check intended for a specific helper/phase.
strip_static_comments() {
  awk '
    function comment_start(prev) {
      return prev == "" || prev ~ /[[:space:];{}(),=!]/
    }
    function strip_line(line, i, c, following, prev, out, quote, escaped) {
      out = ""
      quote = ""
      escaped = 0
      for (i = 1; i <= length(line); ) {
        c = substr(line, i, 1)
        following = substr(line, i + 1, 1)
        if (php_block_comment) {
          if (c == "*" && following == "/") {
            php_block_comment = 0
            i += 2
          } else {
            i++
          }
          continue
        }
        if (quote != "") {
          out = out c
          if (escaped) {
            escaped = 0
          } else if (c == "\\") {
            escaped = 1
          } else if (c == quote) {
            quote = ""
          }
          i++
          continue
        }
        # PHP strings inside a shell double-quoted `eval` are written as
        # `\"...\"`; an escaped quote outside a tracked string is data, not
        # the beginning of a new shell/PHP string.  This lets a trailing
        # comment on the same eval line still be removed.
        prev = i > 1 ? substr(line, i - 1, 1) : ""
        if ((c == "\"" || c == "\047") && prev != "\\") {
          quote = c
          out = out c
          i++
          continue
        }
        if (c == "#" && (i == 1 || prev ~ /[[:space:]]/)) {
          break
        }
        if (c == "/" && following == "/" && comment_start(prev)) {
          break
        }
        if (c == "/" && following == "*" && comment_start(prev)) {
          php_block_comment = 1
          i += 2
          continue
        }
        out = out c
        i++
      }
      return out
    }
    {
      stripped = strip_line($0)
      if (stripped !~ /^[[:space:]]*$/) {
        print stripped
      }
    }
  '
}
function_block() {
  local name="$1"
  awk -v name="$name" '
    $0 ~ ("^" name "\\(\\)[[:space:]]*\\{") { inside = 1 }
    inside && seen && $0 ~ "^[A-Za-z_][A-Za-z0-9_]*\\(\\)[[:space:]]*\\{" { exit }
    inside { print; seen = 1 }
  ' "$SCRIPT_SOURCE"
}
phase_block() {
  local marker="$1"
  awk -v marker="$marker" '
    /^say "/ {
      if (inside) { exit }
      if (index($0, "say \"" marker) > 0) { inside = 1 }
    }
    inside { print }
  ' "$SCRIPT_SOURCE"
}
block_contains() {
  local block_name="$1" block="$2" needle="$3" message="$4"
  grep -Fq -- "$needle" <<<"$block" || fail "$message ($block_name)"
}
block_absent() {
  local block_name="$1" block="$2" needle="$3" message="$4"
  if grep -Fq -- "$needle" <<<"$block"; then
    fail "$message ($block_name)"
  fi
}
block_position() {
  local block="$1" needle="$2" minimum_line="$3" minimum_column="$4"
  awk -v needle="$needle" -v minimum_line="$minimum_line" -v minimum_column="$minimum_column" '
    NR < minimum_line { next }
    {
      start = NR == minimum_line ? minimum_column + 1 : 1
      offset = index(substr($0, start), needle)
      if (offset) {
        print NR ":" (start + offset - 1)
        exit
      }
    }
  ' <<<"$block"
}
ordered_contract() {
  local block_name="$1" block="$2" token position line column previous_line=1 previous_column=0
  shift 2
  [ "$#" -gt 0 ] || fail "ordered contract has no required tokens ($block_name)"
  while [ "$#" -gt 0 ]; do
    token="$1"
    shift
    position="$(block_position "$block" "$token" "$previous_line" "$previous_column")"
    [ -n "$position" ] || fail "ordered contract token missing or out of order: $token ($block_name)"
    IFS=: read -r line column <<<"$position"
    previous_line="$line"
    previous_column="$column"
  done
}
block_sha256() {
  printf '%s' "$1" | sha256sum | awk '{print $1}'
}
assert_block_golden_hash() {
  local block_name="$1" block="$2" expected="$3" actual
  actual="$(block_sha256 "$block")"
  [ "$actual" = "$expected" ] || fail "comment-stripped executable block hash drifted: $block_name (expected $expected, got $actual)"
}

COMMENT_URL_LINE='    $url = "https://example.test/path";'
[ "$(printf '%s\n' "$COMMENT_URL_LINE" | strip_static_comments)" = "$COMMENT_URL_LINE" ] \
  || fail 'comment stripper damaged a URL inside a PHP string'
COMMENT_URL_WITH_COMMENT='    $url = "https://example.test/path"; // PHP comment'
COMMENT_URL_EXPECTED='    $url = "https://example.test/path"; '
[ "$(printf '%s\n' "$COMMENT_URL_WITH_COMMENT" | strip_static_comments)" = "$COMMENT_URL_EXPECTED" ] \
  || fail 'comment stripper did not remove a PHP trailing comment without damaging the URL'
COMMENT_ESCAPED_URL_LINE='    \$url = \"https://example.test/path\";'
[ "$(printf '%s\n' "$COMMENT_ESCAPED_URL_LINE" | strip_static_comments)" = "$COMMENT_ESCAPED_URL_LINE" ] \
  || fail 'comment stripper damaged an escaped PHP URL inside source_wp eval'
COMMENT_ESCAPED_URL_WITH_COMMENT='    \$url = \"https://example.test/path\"; // PHP comment'
[ "$(printf '%s\n' "$COMMENT_ESCAPED_URL_WITH_COMMENT" | strip_static_comments)" = "$COMMENT_ESCAPED_URL_LINE " ] \
  || fail 'comment stripper did not remove a PHP comment after an escaped PHP URL'
COMMENT_BLOCK_INPUT="$(printf '%s\n' '    /* PHP comment */' '    $url = "https://example.test/path";' | strip_static_comments)"
[ "$COMMENT_BLOCK_INPUT" = "$COMMENT_URL_LINE" ] \
  || fail 'comment stripper did not remove a PHP block comment while preserving the URL'

[ -x "$SCRIPT" ] || fail "$SCRIPT must be executable"
bash -n "$SCRIPT"

# Build the bounded scan artifact before any text contracts run.  Keep the
# original path in SCRIPT_SOURCE because the live collision probes and the
# heredoc checks still need the actual executable.
SCRIPT_SOURCE="$SCRIPT"
STATIC_STRUCTURE_FILE="$(mktemp "${TMPDIR:-/tmp}/duo-ecommerce-structure.XXXXXX")"
{
  awk '/^say "/ { exit } { print }' "$SCRIPT_SOURCE" | strip_static_comments
  while IFS= read -r name; do
    function_block "$name" | strip_static_comments
  done < <(sed -nE 's/^([A-Za-z_][A-Za-z0-9_]*)\(\)[[:space:]]*\{.*/\1/p' "$SCRIPT_SOURCE" | sort -u)
  while IFS= read -r marker; do
    phase_block "$marker" | strip_static_comments
  done < <(sed -nE 's/^say "([^"]*)".*/\1/p' "$SCRIPT_SOURCE")
} >"$STATIC_STRUCTURE_FILE"

CLEANUP_HELPER_BLOCK="$(function_block cleanup | strip_static_comments)"
ORDER_HELPER_BLOCK="$(function_block assert_target_order_unchanged | strip_static_comments)"
ORDER_SNAPSHOT_DATA_HELPER_BLOCK="$(function_block order_snapshot | strip_static_comments)"
VISIBILITY_HELPER_BLOCK="$(function_block assert_product_visibility_runtime | strip_static_comments)"
EQ_HELPER_BLOCK="$(function_block assert_eq | strip_static_comments)"
RECEIPT_HELPER_BLOCK="$(function_block assert_receipt | strip_static_comments)"
THEME_HELPER_BLOCK="$(function_block assert_theme_and_dependency | strip_static_comments)"
PHASE_ORDER_HELPER_BLOCK="$(function_block assert_phase_order | strip_static_comments)"
ABSENT_HELPER_BLOCK="$(function_block assert_absent | strip_static_comments)"
TRACE_HELPER_BLOCK="$(function_block assert_trace_has | strip_static_comments)"
STATE_TREE_HASH_HELPER_BLOCK="$(function_block state_tree_hash | strip_static_comments)"
FINAL_COMPILED_STATE_DIFF_HELPER_BLOCK="$(function_block final_compiled_state_diff | strip_static_comments)"
TARGET_PLUGIN_TREE_HASH_HELPER_BLOCK="$(function_block target_plugin_tree_hash | strip_static_comments)"
TARGET_MANAGED_CODE_TREE_HASH_HELPER_BLOCK="$(function_block target_managed_code_tree_hash | strip_static_comments)"
SOURCE_MANAGED_CODE_TREE_HASH_HELPER_BLOCK="$(function_block source_managed_code_tree_hash | strip_static_comments)"
TARGET_TEE_UNCHANGED_HELPER_BLOCK="$(function_block assert_target_tee_unchanged | strip_static_comments)"
TARGET_ORDER_SNAPSHOT_HELPER_BLOCK="$(function_block assert_target_order_snapshot | strip_static_comments)"
TARGET_ORDER_ABSENT_HELPER_BLOCK="$(function_block assert_target_order_absent | strip_static_comments)"
DELETION_PROBE_PRESENT_HELPER_BLOCK="$(function_block assert_deletion_probe_lookup_present | strip_static_comments)"
PRELUDE_BLOCK="$(awk '/^say "/ { exit } { print }' "$SCRIPT_SOURCE" | strip_static_comments)"
LIVE_CHECKOUT_HELPER_BLOCK="$(function_block assert_clean_live_checkout | strip_static_comments)"
DEPLOY_ARTIFACT_FILES_HELPER_BLOCK="$(function_block deploy_artifact_files | strip_static_comments)"
NEW_DEPLOY_ARTIFACT_HELPER_BLOCK="$(function_block artifact_for_new_deploy | strip_static_comments)"
PROMOTE_ARTIFACT_HELPER_BLOCK="$(function_block artifact_for_promote_output | strip_static_comments)"
SOURCE_EVENT_BASELINE_HELPER_BLOCK="$(function_block assert_source_runtime_event_baseline | strip_static_comments)"
RUNTIME_ISOLATION_HELPER_BLOCK="$(function_block assert_runtime_isolation | strip_static_comments)"
RUNTIME_STATE_EXCLUSION_HELPER_BLOCK="$(function_block assert_runtime_state_excluded | strip_static_comments)"
RUNTIME_IDENTITY_HELPER_BLOCK="$(function_block runtime_identity_inventory | strip_static_comments)"
ENV_SECRET_HELPER_BLOCK="$(function_block assert_env_secret_isolation | strip_static_comments)"
SOURCE_LEDGER_HELPER_BLOCK="$(function_block source_duo_ledger_snapshot | strip_static_comments)"
ACF_SCHEMA_HELPER_BLOCK="$(function_block assert_acf_schema | strip_static_comments)"
FRONTEND_HELPER_BLOCK="$(function_block assert_frontend_child_parent | strip_static_comments)"
REST_HELPER_BLOCK="$(function_block assert_extension_rest_status | strip_static_comments)"
STORE_API_HTTP_HELPER_BLOCK="$(function_block assert_store_api_http | strip_static_comments)"
INITIAL_V1_PHASE_BLOCK="$(phase_block 'publish target-only env registry and materialize v1 code/lifecycle' | strip_static_comments)"
FAILED_V2_PHASE_BLOCK="$(phase_block 'v2 reviewed change: migrate scalar setting/table and deliberately fail activation' | strip_static_comments)"
FAILED_V2_RECOVERY_PHASE_BLOCK="$(phase_block 'exact checkpoint recovery, then fixed v2 retry' | strip_static_comments)"
ROLLBACK_PHASE_BLOCK="$(phase_block 'exact rollback: import v1 checkpoint under maintenance, then promote v1' | strip_static_comments)"
FAIL_CLOSED_PHASE_BLOCK="$(phase_block 'Woo deletion boundary: public product delete is refused before Duo capture mutation' | strip_static_comments)"
FINAL_RECAPTURE_PHASE_BLOCK="$(phase_block 'final recapture/status and exact clean-room cleanup' | strip_static_comments)"
CLEAN_ROOM_PHASE_BLOCK="$(phase_block 'clean room: pair.sh HTTP pair' | strip_static_comments)"
CLEANUP_PAIR_DESTROY='if ! bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1; then'
CLEANUP_DOCKER_CONTAINERS='pair_containers="$(docker ps -aq --filter "label=com.docker.compose.project=duo-$PAIR" 2>/dev/null)"'
CLEANUP_DOCKER_VOLUMES='pair_volumes="$(docker volume ls -q --filter "label=com.docker.compose.project=duo-$PAIR" 2>/dev/null)"'
CLEANUP_DOCKER_NETWORKS='pair_networks="$(docker network ls -q --filter "label=com.docker.compose.project=duo-$PAIR" 2>/dev/null)"'
CLEANUP_DOCKER_LEFTOVER='[ -n "$pair_containers$pair_volumes$pair_networks" ]; then'
CLEANUP_DB_QUERY="remaining_dbs=\"\$(docker exec -e MYSQL_PWD=root duo-shared-db mariadb -uroot -N -B --raw -e \"SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME IN ('wp_\${PAIR}1','wp_\${PAIR}2')\" 2>/dev/null)\""
CLEANUP_DB_LEFTOVER='[ -n "$remaining_dbs" ]; then'
CLEANUP_PAIR_PATH_GUARD='if [ "$PAIR_PATHS_OWNED" = 1 ]; then'
CLEANUP_TEARDOWN_GUARD='if [ "$teardown_verified" = 1 ]; then'
CLEANUP_TEARDOWN_PRESERVE='preserving ecommerce pair paths after uncertain teardown'
CLEANUP_PAIR_PATH_DELETE='rm -rf -- "$SITE" "$OTHER_SITE" "$ORIGIN"'
CLEANUP_PAIR_PATH_VERIFY='if [ -e "$SITE" ] || [ -e "$OTHER_SITE" ] || [ -e "$ORIGIN" ]; then'
CLEANUP_ENV_DELETE='rm -f -- "$ENVS_FILE"'
CLEANUP_INPUT_DELETE='rm -rf -- "$V1_INPUTS"'
CLEANUP_DB_DUMP_DELETE='rm -f -- "$V1_DB_DUMP"'
ORDER_ASSERT='assert_eq "$TARGET_ORDER_BASELINE" "$(target_order_snapshot "$TARGET_ORDER_ID")" "$label exact order snapshot"'
block_contains order-helper "$ORDER_HELPER_BLOCK" "$ORDER_ASSERT" 'target order helper does not compare the exact baseline snapshot'
block_contains order-helper "$ORDER_HELPER_BLOCK" 'target_order_snapshot "$TARGET_ORDER_ID"' 'target order helper does not query the target order snapshot'
VISIBILITY_EVAL='count="$(target_wp eval '\''echo count(get_terms(["taxonomy" => "product_visibility", "hide_empty" => false, "fields" => "ids"]));'\'')"'
VISIBILITY_STATE_ASSERT='[ ! -e "$OTHER_SITE/state/terms/product_visibility" ]'
block_contains visibility-helper "$VISIBILITY_HELPER_BLOCK" "$VISIBILITY_EVAL" 'visibility helper does not query Woo product_visibility runtime terms'
block_contains visibility-helper "$VISIBILITY_HELPER_BLOCK" '[ "$count" -ge 1 ]' 'visibility helper does not require runtime visibility terms'
block_contains visibility-helper "$VISIBILITY_HELPER_BLOCK" "$VISIBILITY_STATE_ASSERT" 'visibility helper does not reject canonical product_visibility state'
EQ_PREDICATE='[ "$expected" = "$actual" ] || fail "$label: expected '\''$expected'\'', got '\''$actual'\''"'
RECEIPT_FILE_ASSERT='[ -f "$artifact" ] || fail "$label artifact missing: $artifact"'
RECEIPT_JQ_CALL="jq -e --arg code \"\$expected_code\" '(.artifact_hash | test(\"^[0-9a-f]{64}\$\")) and (.revision_hash | test(\"^[0-9a-f]{64}\$\")) and .code.format == \"duo-code/v1\" and .code.code_revision == \$code' \"\$artifact\" >/dev/null || fail \"\$label artifact receipt malformed\""
RECEIPT_CODE_ASSERT='assert_eq "$expected_code" "$(ledger_revision)" "$label completed code revision"'
RECEIPT_STATE_ASSERT='assert_eq "$(jq -r '\''.revision_hash'\'' "$artifact")" "$(ledger_value applied_revision)" "$label applied state revision"'
RECEIPT_LOCK_ASSERT='assert_eq 0 "$(target_db_scalar "SELECT COUNT(*) FROM wp_duo_kv WHERE k = '\''promotion_lock'\''")" "$label released promotion lease"'
THEME_CHILD_ASSERT='assert_eq "$CHILD_THEME" "$(target_wp option get stylesheet)" "active child theme"'
THEME_PARENT_ASSERT='assert_eq "$PARENT_THEME" "$(target_wp option get template)" "active parent theme"'
THEME_WOO_ACTIVE='target_wp plugin is-active "$WOO_SLUG" >/dev/null || fail "WooCommerce is inactive"'
THEME_ACF_ACTIVE='target_wp plugin is-active "$ACF_SLUG" >/dev/null || fail "ACF is inactive"'
THEME_EXT_ACTIVE='target_wp plugin is-active "$EXT_SLUG" >/dev/null || fail "Duo Commerce Extension is inactive"'
THEME_PLUGINS_ASSERT='assert_eq "$expected_active" "$(active_plugins_json)" "exact authored active_plugins order"'
THEME_RUNTIME_ASSERT='target_wp eval '\''if (!class_exists("WooCommerce")) { exit(1); } if (!function_exists("woocommerce_content")) { exit(1); }'\'' || fail "custom storefront did not load WooCommerce integration"'
block_contains eq-helper "$EQ_HELPER_BLOCK" 'local expected="$1" actual="$2" label="$3"' 'assert_eq helper does not bind expected/actual/label arguments'
block_contains eq-helper "$EQ_HELPER_BLOCK" "$EQ_PREDICATE" 'assert_eq helper does not retain its exact equality predicate'
block_contains receipt-helper "$RECEIPT_HELPER_BLOCK" "$RECEIPT_FILE_ASSERT" 'assert_receipt helper does not require the artifact file'
block_contains receipt-helper "$RECEIPT_HELPER_BLOCK" "$RECEIPT_JQ_CALL" 'assert_receipt helper does not retain the exact receipt shape predicate'
block_contains receipt-helper "$RECEIPT_HELPER_BLOCK" "$RECEIPT_CODE_ASSERT" 'assert_receipt helper does not assert the completed code revision'
block_contains receipt-helper "$RECEIPT_HELPER_BLOCK" "$RECEIPT_STATE_ASSERT" 'assert_receipt helper does not assert the applied state revision'
block_contains receipt-helper "$RECEIPT_HELPER_BLOCK" "$RECEIPT_LOCK_ASSERT" 'assert_receipt helper does not assert promotion-lock release'
block_contains theme-helper "$THEME_HELPER_BLOCK" "$THEME_CHILD_ASSERT" 'theme/dependency helper does not assert the child theme'
block_contains theme-helper "$THEME_HELPER_BLOCK" "$THEME_PARENT_ASSERT" 'theme/dependency helper does not assert the parent theme'
block_contains theme-helper "$THEME_HELPER_BLOCK" "$THEME_WOO_ACTIVE" 'theme/dependency helper does not assert Woo activation'
block_contains theme-helper "$THEME_HELPER_BLOCK" "$THEME_ACF_ACTIVE" 'theme/dependency helper does not assert ACF activation'
block_contains theme-helper "$THEME_HELPER_BLOCK" "$THEME_EXT_ACTIVE" 'theme/dependency helper does not assert extension activation'
block_contains theme-helper "$THEME_HELPER_BLOCK" "$THEME_PLUGINS_ASSERT" 'theme/dependency helper does not assert authored plugin order'
block_contains theme-helper "$THEME_HELPER_BLOCK" "$THEME_RUNTIME_ASSERT" 'theme/dependency helper does not assert Woo runtime integration'
ordered_contract initial-v1-recapture "$INITIAL_V1_PHASE_BLOCK" \
  'target_wp duo capture --repo=/siterepo --out=/siterepo/.tmp-v1-recapture >/dev/null' \
  'if ! diff -r "$OTHER_SITE/state" "$OTHER_SITE/.tmp-v1-recapture" >/dev/null; then' \
  'diff -ru "$OTHER_SITE/state" "$OTHER_SITE/.tmp-v1-recapture" >&2 || true' \
  "fail 'initial v1 target recapture did not match canonical state byte-for-byte'" \
  'rm -rf -- "$OTHER_SITE/.tmp-v1-recapture"'
ordered_contract final-derived-aware-recapture "$FINAL_RECAPTURE_PHASE_BLOCK" \
  'target_wp duo capture --repo=/siterepo --out=/siterepo/.tmp-final-state >/dev/null' \
  'if FINAL_RAW_DIFF="$(diff -rq "$OTHER_SITE/state" "$OTHER_SITE/.tmp-final-state")"; then' \
  '[ "$FINAL_RAW_DIFF_STATUS" -le 1 ]' \
  'FINAL_SEMANTIC_DIFF="$(final_compiled_state_diff)"' \
  'if diff -ru "$OTHER_SITE/state" "$OTHER_SITE/.tmp-final-state" >&2; then' \
  '[ "$FINAL_RAW_DIFF_STATUS" -eq 1 ]' \
  'if [ "$FINAL_SEMANTIC_DIFF" != "[]" ]; then' \
  'fail "final target recapture changed authored or identity-bearing state: $FINAL_SEMANTIC_DIFF"' \
  'rm -rf -- "$OTHER_SITE/.tmp-final-state"' \
  'FINAL_STATUS="$(status 2>&1)"'
block_absent final-derived-aware-recapture "$FINAL_RECAPTURE_PHASE_BLOCK" \
  'final target recapture did not match canonical v1 state byte-for-byte' \
  'final recapture still enforces the invalid raw-byte contract'
block_absent final-compiled-state-diff "$FINAL_COMPILED_STATE_DIFF_HELPER_BLOCK" \
  '| tail -1' 'final semantic diagnostics are truncated to the last pretty-JSON line'
block_absent prelude "$PRELUDE_BLOCK" 'ACTIVE_PAIRS=' \
  'live harness still serializes against every other active pair instead of using pair.sh capacity'
block_absent prelude "$PRELUDE_BLOCK" 'refusing to start $PAIR while another pair is active' \
  'live harness still carries the stale zero-other-pairs refusal'
block_contains clean-room "$CLEAN_ROOM_PHASE_BLOCK" \
  'bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --journal --http' \
  'live harness does not delegate its atomic capacity reservation to pair.sh up'
noop_helper_block() {
  awk '
    NR == 1 { print; next }
    /^[[:space:]]*}[[:space:]]*$/ && !replaced {
      print "  :"
      replaced = 1
      print
      next
    }
    { next }
  ' <<<"$1"
}
assert_helper_contracts() {
  local helper_name="$1" block="$2" golden="$3" token message mutated
  shift 3
  assert_block_golden_hash "$helper_name" "$block" "$golden"
  mutated="$(noop_helper_block "$block")"
  if (assert_block_golden_hash "$helper_name-noop" "$mutated" "$golden") >/dev/null 2>&1; then
    fail "helper no-op mutation unexpectedly passed: $helper_name"
  fi
  while [ "$#" -gt 0 ]; do
    token="$1"
    message="$2"
    shift 2
    block_contains "$helper_name" "$block" "$token" "$message"
    mutated="$(grep -Fv -- "$token" <<<"$block")"
    if (block_contains "$helper_name-body-negative" "$mutated" "$token" 'helper body mutation unexpectedly passed') >/dev/null 2>&1; then
      fail "helper body mutation unexpectedly passed: $helper_name ($token)"
    fi
  done
}
assert_rm_rf_targets() {
  local scan_file="${1:-$STATIC_STRUCTURE_FILE}" line normalized
  while IFS= read -r line; do
    case "$line" in
      *'rm -rf'*) ;;
      *) continue ;;
    esac
    normalized="${line#"${line%%[![:space:]]*}"}"
    normalized="${normalized%"${normalized##*[![:space:]]}"}"
    case "$normalized" in
      'rm -rf -- "$SITE" "$OTHER_SITE" "$ORIGIN"' | \
      'rm -rf -- "$V1_INPUTS"' | \
      'rm -rf /siterepo/code/wp-content/plugins/woocommerce' | \
      'rm -rf /siterepo/code/wp-content/plugins/advanced-custom-fields' | \
      'rm -rf -- "$OTHER_SITE/.tmp-v1-code"' | \
      'rm -rf "$unpack" "$next"' | \
      'rm -rf "$previous"' | \
      'rm -rf "$unpack"' | \
      'rm -rf "$previous" "$unpack"' | \
      'rm -rf -- "$SITE/code/wp-content/plugins/$WOO_SLUG"' | \
      'rm -rf -- "$SITE/code/wp-content/plugins/$EXT_SLUG"' | \
      'rm -rf -- "$SITE/code"' | \
      'rm -rf -- "$SITE/state"' | \
      'rm -rf -- "$OTHER_SITE/.tmp-v1-recapture"' | \
      'rm -rf -- "$OTHER_SITE/.tmp-final-state"') ;;
      *)
        printf 'unexpected or over-broad rm -rf target: %s\n' "$normalized" >&2
        return 1
        ;;
    esac
  done < "$scan_file"
}
helper_noop_rejected() {
  local helper_name="$1" required_token="$2"
  if (block_contains "$helper_name-noop" ':' "$required_token" 'helper no-op mutation unexpectedly retained a required body contract') >/dev/null 2>&1; then
    fail "helper no-op mutation unexpectedly passed: $helper_name"
  fi
}
helper_noop_rejected order-helper "$ORDER_ASSERT"
helper_noop_rejected visibility-helper "$VISIBILITY_STATE_ASSERT"
helper_noop_rejected eq-helper "$EQ_PREDICATE"
helper_noop_rejected receipt-helper "$RECEIPT_JQ_CALL"
helper_noop_rejected theme-helper "$THEME_RUNTIME_ASSERT"
CLEANUP_HELPER_GOLDEN_HASH=9cac02c1c9d2ff7667e56018b20321c25aa093ae7a034f241260448581609e75
ORDER_HELPER_GOLDEN_HASH=a5e218adaba2ef1c2f7dcee7078886c36fd4e743f8108aa883d1b5de3c3f0f64
ORDER_SNAPSHOT_DATA_HELPER_GOLDEN_HASH=95777d9b3c8dd94e1a9c27febc42b3bff1ccbc7d47e5ce87aee5517637fcd35c
VISIBILITY_HELPER_GOLDEN_HASH=7cc6d2e93dc5c78c222f033c0ed941e7e41afcf421ecefbf8d6a04fd89e46357
EQ_HELPER_GOLDEN_HASH=4533ae3a46601a7646bbfc7e6258d08136783621e32487b906784be559a7d3c1
RECEIPT_HELPER_GOLDEN_HASH=b5fa253532ef4e4ae36dadb06060cdd49dcb97bfbc9de6b91753df72391e109e
THEME_HELPER_GOLDEN_HASH=92f7178cab9469fee55245f405839ce130c1fc9e8e113ada4ab8cfa560f5810e
PHASE_ORDER_HELPER_GOLDEN_HASH=b8be7ab1221ac36f7ee6128ce24341d86ae46d66d7f0623ee567d3202b4e9aff
ABSENT_HELPER_GOLDEN_HASH=74e54e8d9c00ba9d83634d57f7d56248999adf428426ab97d95e48dfb5a05616
TRACE_HELPER_GOLDEN_HASH=dc4e232dae6bbae8b99cd00355e3b890d420d0c74ef70ef8baf170391aad73c5
STATE_TREE_HASH_HELPER_GOLDEN_HASH=0b405c1bd3820c990ab1e6c3f2fe303fc8e8071be20fe65b133f73771c040349
FINAL_COMPILED_STATE_DIFF_HELPER_GOLDEN_HASH=a6880a0178cbdab3968d75671dd90acdddc2476c621e5f513e30cdf88c768b11
TARGET_PLUGIN_TREE_HASH_HELPER_GOLDEN_HASH=6c9343b7af357aa093efaae96328a2315ee2c1c421a27d0afecc72d4bf68a3a6
TARGET_MANAGED_CODE_TREE_HASH_HELPER_GOLDEN_HASH=d80740550295c2df86f1a011941531aca503a475081bfbec685f9f158d9fa1e4
SOURCE_MANAGED_CODE_TREE_HASH_HELPER_GOLDEN_HASH=830daaccf4702c0f2dbb27efd6b4aa5b1dd936bd089d744eaa8ec14f0ff35c31
TARGET_TEE_UNCHANGED_HELPER_GOLDEN_HASH=d2dba3b69d1c9faba4ee697313197c02616d7686e537f953c482bdaf3163bad0
TARGET_ORDER_SNAPSHOT_HELPER_GOLDEN_HASH=7bfecd258305c19f28e31c9058ede01bfbc844479030369fc7e14f83d03bcfb8
TARGET_ORDER_ABSENT_HELPER_GOLDEN_HASH=25473f5c9ff32ce4ae0834dcda96c10bd5eca04a4f0577254bbf63087ad7c99d
DELETION_PROBE_PRESENT_HELPER_GOLDEN_HASH=e24bc5069b3f4f905e12bf04fcf30c14308c9c7cc007c8a56e9052cd2490ae82
LIVE_CHECKOUT_HELPER_GOLDEN_HASH=1ef4a9c1f02943311c2767a7f336fe24588ce0f6d7e641ca826adfb7588edea2
DEPLOY_ARTIFACT_FILES_HELPER_GOLDEN_HASH=74c60dc1cd6c256058840f36863e0f14e8b84c994a97036642e6ba0bf3d53eb4
NEW_DEPLOY_ARTIFACT_HELPER_GOLDEN_HASH=021e208799477388afb71a60c933bd8ceab7145a48485c7f319f9687a569ff1d
PROMOTE_ARTIFACT_HELPER_GOLDEN_HASH=877ea91d017f105aa689e7d68e64fdeea0d06138fe0f552f9ad7e781867d94a8
SOURCE_EVENT_BASELINE_HELPER_GOLDEN_HASH=2dae7f427f639ab1bcb9d4e1e12122a2c8fba8499e6fdc81d8d1cf245ea4120c
RUNTIME_ISOLATION_HELPER_GOLDEN_HASH=b785e1dffaf6ee68c850bc3e78af0573220af20ff3e2255457386f54aa18c5da
RUNTIME_STATE_EXCLUSION_HELPER_GOLDEN_HASH=09055c952a6e0f470c482a20317b2e0eb0b51321226782cea81aa3da70473b71
RUNTIME_IDENTITY_HELPER_GOLDEN_HASH=5bb575bc44f32865f890383e47d341a3862a01c713ac74e2f675be36288276cc
ENV_SECRET_HELPER_GOLDEN_HASH=434293b1db5c243c18bdb0f036d3d29e3140e2277844cb4ade597a76c1672a5d
SOURCE_LEDGER_HELPER_GOLDEN_HASH=c1de83ea8b60d634fbd77e761b35612591aa354d4c55aa703ec9eb8a5cc8a17d
ACF_SCHEMA_HELPER_GOLDEN_HASH=8ebb0003d070fea83241ddf3a565c1fe62485b4e0345a2101c82fd51cf584a65
FRONTEND_HELPER_GOLDEN_HASH=92273a989a026102a60f14fb5904101c2f2e50e66e5afa12372ed225812979ca
REST_HELPER_GOLDEN_HASH=04e2ae5929d0f588dac14cf7fe5090bf8039e07fe2d9ea6d743635a059564b61
STORE_API_HTTP_HELPER_GOLDEN_HASH=166220142d61874d76e56c6a18a30f09149c1ed06be33c40bdf8d560d5ef80c6
FAIL_CLOSED_PHASE_GOLDEN_HASH=2f7531a124792da175133ad4501492bcc94d2abc10947d762f3a57138e810fa3
FINAL_RECAPTURE_PHASE_GOLDEN_HASH=8ad39bfd59ef81c8c78ef9c6f4c1800af877f2ae5cae2a0ce40488123c10559c
assert_block_golden_hash cleanup "$CLEANUP_HELPER_BLOCK" "$CLEANUP_HELPER_GOLDEN_HASH"
assert_block_golden_hash order-helper "$ORDER_HELPER_BLOCK" "$ORDER_HELPER_GOLDEN_HASH"
assert_block_golden_hash visibility-helper "$VISIBILITY_HELPER_BLOCK" "$VISIBILITY_HELPER_GOLDEN_HASH"
assert_block_golden_hash eq-helper "$EQ_HELPER_BLOCK" "$EQ_HELPER_GOLDEN_HASH"
assert_block_golden_hash receipt-helper "$RECEIPT_HELPER_BLOCK" "$RECEIPT_HELPER_GOLDEN_HASH"
assert_block_golden_hash theme-helper "$THEME_HELPER_BLOCK" "$THEME_HELPER_GOLDEN_HASH"
assert_helper_contracts phase-order "$PHASE_ORDER_HELPER_BLOCK" "$PHASE_ORDER_HELPER_GOLDEN_HASH" \
  'local output="$1" last=0 needle line' 'phase-order helper does not bind output/line state' \
  'shift' 'phase-order helper does not discard the output argument before scanning phases' \
  'for needle in "$@"; do' 'phase-order helper does not scan every requested phase' \
  'grep -n -F -m1 "$needle" <<<"$output"' 'phase-order helper does not find the first matching phase line' \
  '[ -n "$line" ] || fail' 'phase-order helper does not reject a missing phase' \
  '[ "$line" -gt "$last" ] || fail' 'phase-order helper does not reject out-of-order phases' \
  'last="$line"' 'phase-order helper does not advance its phase-order cursor'
assert_helper_contracts absent "$ABSENT_HELPER_BLOCK" "$ABSENT_HELPER_GOLDEN_HASH" \
  'local output="$1" needle="$2" label="$3"' 'assert_absent helper does not bind output/needle/label arguments' \
  '! grep -Fq "$needle" <<<"$output" || fail' 'assert_absent helper does not reject output containing the forbidden needle'
assert_helper_contracts trace-has "$TRACE_HELPER_BLOCK" "$TRACE_HELPER_GOLDEN_HASH" \
  'local trace="$1" event="$2"' 'assert_trace_has helper does not bind trace/event arguments' \
  'jq -e --arg event "$event"' 'assert_trace_has helper does not bind the expected event into jq' \
  'index($event) != null' 'assert_trace_has helper does not require the event to be present' \
  '<<<"$trace" >/dev/null || fail' 'assert_trace_has helper does not fail on a missing trace event'
assert_helper_contracts state-tree-hash "$STATE_TREE_HASH_HELPER_BLOCK" "$STATE_TREE_HASH_HELPER_GOLDEN_HASH" \
  'local root="$1"' 'state_tree_hash helper does not bind the repository root' \
  'cd "$root"' 'state_tree_hash helper does not hash relative to the requested root' \
  'find state -type f -print | sort | while IFS= read -r path; do' 'state_tree_hash helper does not enumerate sorted state files' \
  'printf '\''%s\t'\'' "$path"' 'state_tree_hash helper does not include each relative path in the digest input' \
  'sha256sum "$path"' 'state_tree_hash helper does not hash each state file' \
  ') | sha256sum | awk' 'state_tree_hash helper does not hash the complete path/content manifest'
assert_helper_contracts final-compiled-state-diff "$FINAL_COMPILED_STATE_DIFF_HELPER_BLOCK" "$FINAL_COMPILED_STATE_DIFF_HELPER_GOLDEN_HASH" \
  '\Duo\Policy::load("/siterepo")' 'final semantic diff does not load the pinned repository policy' \
  '\Duo\RepositoryCompiler::compile_staged("/siterepo/state", "/siterepo", $policy)' 'final semantic diff does not compile the complete canonical tree' \
  '\Duo\RepositoryCompiler::compile_staged("/siterepo/.tmp-final-state", "/siterepo", $policy)' 'final semantic diff does not compile the complete recaptured tree' \
  '$ordered_post_hash = static function (string $root, string $path) use ($policy)' 'final semantic diff does not define an ordered raw post projection' \
  '\Duo\Canon::read_file($root . "/" . $path)' 'ordered raw post projection does not read the compiled entity file' \
  'json_decode(substr($text, 4, $end - 3), false, 512, JSON_THROW_ON_ERROR)' 'ordered raw post projection does not preserve JSON object insertion order' \
  'array_keys(get_object_vars($front))' 'ordered raw post projection does not enumerate only top-level front fields' \
  '$policy->field_class($postType, (string) $key) === "derived"' 'ordered raw post projection does not consult the pinned field classification' \
  'unset($front->{$key})' 'ordered raw post projection does not strip only derived top-level fields' \
  'JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR' 'ordered raw post projection does not directly encode the ordered front matter' \
  'static function ($compiled, string $root) use ($ordered_post_hash)' 'final semantic diff does not project each compiled tree from its own raw root' \
  '$entity["type"] === "post"' 'final semantic diff does not restrict raw projection to post entities' \
  '$ordered_post_hash($root, $path)' 'final semantic diff does not use the ordered raw post projection' \
  ': (string) $entity["hash"];' 'final semantic diff does not preserve compiled hashes for non-post entities' \
  '$left = $project($canonical, "/siterepo/state");' 'final semantic diff does not project the canonical raw tree' \
  '$right = $project($recaptured, "/siterepo/.tmp-final-state");' 'final semantic diff does not project the recaptured raw tree' \
  'foreach ($compiled->deletions() as $entity)' 'final semantic diff omits deletion intents' \
  '$out[$path] = (string) $entity["hash"];' 'final semantic diff does not preserve deletion hashes' \
  '"canonical_hash" => $left[$path] ?? null' 'final semantic diff omits canonical mismatch evidence' \
  '"recaptured_hash" => $right[$path] ?? null' 'final semantic diff omits recapture mismatch evidence' \
  'echo \Duo\Canon::encode($diff);' 'final semantic diff is not deterministic canonical JSON'
block_absent final-compiled-state-diff "$FINAL_COMPILED_STATE_DIFF_HELPER_BLOCK" \
  'Canon::post_hash_basis' 'final semantic diff regressed to the key-sorting post hash basis'
assert_helper_contracts target-plugin-tree-hash "$TARGET_PLUGIN_TREE_HASH_HELPER_BLOCK" "$TARGET_PLUGIN_TREE_HASH_HELPER_GOLDEN_HASH" \
  'target_php' 'target_plugin_tree_hash helper does not execute its target-side PHP probe' \
  '$root = "/var/www/html/wp-content/plugins";' 'target_plugin_tree_hash helper is not rooted at the complete plugin tree' \
  'new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)' 'target_plugin_tree_hash helper does not recurse through plugin files' \
  'if (!$file->isFile() || $file->isLink()) {' 'target_plugin_tree_hash helper does not exclude directories and symlinks' \
  '$relative = str_replace("\\\\", "/", substr($file->getPathname(), strlen($root) + 1));' 'target_plugin_tree_hash helper does not normalize relative plugin paths' \
  '$rows[] = $relative . "\\t" . hash_file("sha256", $file->getPathname());' 'target_plugin_tree_hash helper does not bind each path to its file hash' \
  'sort($rows, SORT_STRING);' 'target_plugin_tree_hash helper does not sort its manifest deterministically' \
  'echo hash("sha256", implode("\\n", $rows));' 'target_plugin_tree_hash helper does not return a manifest SHA-256'
assert_helper_contracts target-managed-code-tree-hash "$TARGET_MANAGED_CODE_TREE_HASH_HELPER_BLOCK" "$TARGET_MANAGED_CODE_TREE_HASH_HELPER_GOLDEN_HASH" \
  'target_php' 'target_managed_code_tree_hash helper does not execute its target-side PHP probe' \
  '$root = "/var/www/html/wp-content";' 'target_managed_code_tree_hash helper is not rooted at wp-content' \
  '"plugins/woocommerce",' 'target_managed_code_tree_hash helper omits WooCommerce' \
  '"plugins/advanced-custom-fields",' 'target_managed_code_tree_hash helper omits ACF' \
  '"plugins/duo-commerce-extension",' 'target_managed_code_tree_hash helper omits the extension' \
  '"themes/duo-commerce-parent",' 'target_managed_code_tree_hash helper omits the parent theme' \
  '"themes/duo-commerce-child",' 'target_managed_code_tree_hash helper omits the child theme' \
  '$absoluteRoot = $root . "/" . $relativeRoot;' 'target_managed_code_tree_hash helper does not resolve each managed root' \
  'if (!is_dir($absoluteRoot)) {' 'target_managed_code_tree_hash helper does not handle absent managed roots explicitly' \
  'new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)' 'target_managed_code_tree_hash helper does not recurse through managed files' \
  'if (!$file->isFile() || $file->isLink()) {' 'target_managed_code_tree_hash helper does not exclude directories and symlinks' \
  '$relative = str_replace("\\\\", "/", substr($file->getPathname(), strlen($root) + 1));' 'target_managed_code_tree_hash helper does not normalize managed relative paths' \
  '$rows[] = $relative . "\\t" . hash_file("sha256", $file->getPathname());' 'target_managed_code_tree_hash helper does not bind each path to its file hash' \
  'sort($rows, SORT_STRING);' 'target_managed_code_tree_hash helper does not sort its manifest deterministically' \
  'echo hash("sha256", implode("\\n", $rows));' 'target_managed_code_tree_hash helper does not return a manifest SHA-256'
assert_helper_contracts source-managed-code-tree-hash "$SOURCE_MANAGED_CODE_TREE_HASH_HELPER_BLOCK" "$SOURCE_MANAGED_CODE_TREE_HASH_HELPER_GOLDEN_HASH" \
  'DUO_SOURCE_CODE_ROOT="$SITE/code/wp-content" php -r' 'source_managed_code_tree_hash helper does not bind the source managed-code root' \
  '$root = rtrim((string) getenv("DUO_SOURCE_CODE_ROOT"), "/");' 'source_managed_code_tree_hash helper does not read the bound source root safely' \
  '"plugins/woocommerce",' 'source_managed_code_tree_hash helper omits WooCommerce' \
  '"plugins/advanced-custom-fields",' 'source_managed_code_tree_hash helper omits ACF' \
  '"plugins/duo-commerce-extension",' 'source_managed_code_tree_hash helper omits the extension' \
  '"themes/duo-commerce-parent",' 'source_managed_code_tree_hash helper omits the parent theme' \
  '"themes/duo-commerce-child",' 'source_managed_code_tree_hash helper omits the child theme' \
  '$absoluteRoot = $root . "/" . $relativeRoot;' 'source_managed_code_tree_hash helper does not resolve each managed root' \
  'if (!is_dir($absoluteRoot)) {' 'source_managed_code_tree_hash helper does not handle absent managed roots explicitly' \
  'new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)' 'source_managed_code_tree_hash helper does not recurse through managed files' \
  'if (!$file->isFile() || $file->isLink()) {' 'source_managed_code_tree_hash helper does not exclude directories and symlinks' \
  '$relative = str_replace("\\\\", "/", substr($file->getPathname(), strlen($root) + 1));' 'source_managed_code_tree_hash helper does not normalize managed relative paths' \
  '$rows[] = $relative . "\\t" . hash_file("sha256", $file->getPathname());' 'source_managed_code_tree_hash helper does not bind each path to its file hash' \
  'sort($rows, SORT_STRING);' 'source_managed_code_tree_hash helper does not sort its manifest deterministically' \
  'echo hash("sha256", implode("\\n", $rows));' 'source_managed_code_tree_hash helper does not return a manifest SHA-256'
assert_helper_contracts target-tee-unchanged "$TARGET_TEE_UNCHANGED_HELPER_BLOCK" "$TARGET_TEE_UNCHANGED_HELPER_GOLDEN_HASH" \
  'local before="$1" uuid="$2" label="$3" after' 'target tee unchanged helper does not bind before/UUID/label state' \
  'after="$(target_tee_snapshot "$uuid")"' 'target tee unchanged helper does not recapture the target tee' \
  'assert_eq "$before" "$after" "$label exact tee identity/content/meta/terms snapshot"' 'target tee unchanged helper does not compare the complete snapshot' \
  'jq -e' 'target tee unchanged helper does not validate snapshot diagnostics' \
  '.authored_hash | test("^[0-9a-f]{64}$")' 'target tee unchanged helper does not require an authored-content digest' \
  '<<<"$after" >/dev/null || fail' 'target tee unchanged helper does not reject malformed snapshot diagnostics'
assert_helper_contracts cleanup "$CLEANUP_HELPER_BLOCK" "$CLEANUP_HELPER_GOLDEN_HASH" \
  "$CLEANUP_PAIR_DESTROY" 'cleanup does not destroy the exact pair through pair.sh' \
  "$CLEANUP_DOCKER_CONTAINERS" 'cleanup does not query pair containers for removal verification' \
  "$CLEANUP_DOCKER_VOLUMES" 'cleanup does not query pair volumes for removal verification' \
  "$CLEANUP_DOCKER_NETWORKS" 'cleanup does not query pair networks for removal verification' \
  "$CLEANUP_DOCKER_LEFTOVER" 'cleanup does not reject leftover Docker resources' \
  "$CLEANUP_DB_QUERY" 'cleanup does not query the exact pair databases for removal verification' \
  "$CLEANUP_DB_LEFTOVER" 'cleanup does not reject leftover pair databases' \
  "$CLEANUP_PAIR_PATH_GUARD" 'cleanup does not gate pair-path deletion on ownership' \
  "$CLEANUP_TEARDOWN_GUARD" 'cleanup does not gate pair-path deletion on verified teardown' \
  "$CLEANUP_TEARDOWN_PRESERVE" 'cleanup does not preserve pair paths when teardown is uncertain' \
  "$CLEANUP_PAIR_PATH_DELETE" 'cleanup does not delete exactly the owned pair paths' \
  "$CLEANUP_PAIR_PATH_VERIFY" 'cleanup does not verify exact pair-path removal' \
  "$CLEANUP_ENV_DELETE" 'cleanup does not remove its temporary environment registry' \
  "$CLEANUP_INPUT_DELETE" 'cleanup does not remove its temporary rollback inputs' \
  "$CLEANUP_DB_DUMP_DELETE" 'cleanup does not remove its temporary database dump' \
  'exit "$status"' 'cleanup does not preserve and return the original failure status'
if ! assert_rm_rf_targets "$STATIC_STRUCTURE_FILE"; then
  fail 'executable rm -rf target allowlist rejected the scenario'
fi
MUTATED_RM_REPO_ROOT="$(sed 's#rm -rf -- "\$SITE/code"#rm -rf -- "\$REPO_ROOT"#' "$STATIC_STRUCTURE_FILE")"
if assert_rm_rf_targets /dev/stdin <<<"$MUTATED_RM_REPO_ROOT" >/dev/null 2>&1; then
  fail 'rm -rf "$REPO_ROOT" mutation unexpectedly passed the exact target allowlist'
fi
MUTATED_RM_SITEREPO="$(sed 's#rm -rf /siterepo/code/wp-content/plugins/woocommerce#rm -rf /siterepo#' "$STATIC_STRUCTURE_FILE")"
if assert_rm_rf_targets /dev/stdin <<<"$MUTATED_RM_SITEREPO" >/dev/null 2>&1; then
  fail 'container rm -rf /siterepo mutation unexpectedly passed the exact target allowlist'
fi
assert_rm_mutation_rejected() {
  local label="$1" old="$2" replacement="$3" mutated
  mutated="$(awk -v old="$old" -v replacement="$replacement" '
    {
      position = index($0, old)
      if (position) {
        print substr($0, 1, position - 1) replacement substr($0, position + length(old))
      } else {
        print
      }
    }
  ' "$STATIC_STRUCTURE_FILE")"
  if assert_rm_rf_targets /dev/stdin <<<"$mutated" >/dev/null 2>&1; then
    fail "rm -rf target mutation unexpectedly passed the exact target allowlist: $label"
  fi
}
assert_rm_mutation_rejected 'cleanup pair-root broadening' \
  'rm -rf -- "$SITE" "$OTHER_SITE" "$ORIGIN"' 'rm -rf -- "$REPO_ROOT"'
assert_rm_mutation_rejected 'source Woo staging root broadening' \
  'rm -rf /siterepo/code/wp-content/plugins/woocommerce' 'rm -rf /siterepo'
assert_rm_mutation_rejected 'source ACF staging root broadening' \
  'rm -rf /siterepo/code/wp-content/plugins/advanced-custom-fields' 'rm -rf /siterepo'
assert_rm_mutation_rejected 'v1 target code staging root broadening' \
  'rm -rf -- "$OTHER_SITE/.tmp-v1-code"' 'rm -rf -- "$REPO_ROOT"'
assert_rm_mutation_rejected 'Woo downgrade staging root broadening' \
  'rm -rf -- "$SITE/code/wp-content/plugins/$WOO_SLUG"' 'rm -rf -- "$REPO_ROOT"'
assert_rm_mutation_rejected 'extension removal root broadening' \
  'rm -rf -- "$SITE/code/wp-content/plugins/$EXT_SLUG"' 'rm -rf -- "$REPO_ROOT"'
assert_rm_mutation_rejected 'rollback code root broadening' \
  'rm -rf -- "$SITE/code"' 'rm -rf -- "$REPO_ROOT"'
assert_rm_mutation_rejected 'rollback state root broadening' \
  'rm -rf -- "$SITE/state"' 'rm -rf -- "$REPO_ROOT"'
assert_rm_mutation_rejected 'initial recapture state root broadening' \
  'rm -rf -- "$OTHER_SITE/.tmp-v1-recapture"' 'rm -rf -- "$REPO_ROOT"'
assert_rm_mutation_rejected 'final temporary state root broadening' \
  'rm -rf -- "$OTHER_SITE/.tmp-final-state"' 'rm -rf -- "$REPO_ROOT"'
assert_helper_contracts order-snapshot-data "$ORDER_SNAPSHOT_DATA_HELPER_BLOCK" "$ORDER_SNAPSHOT_DATA_HELPER_GOLDEN_HASH" \
  'local runner="$1" order_id="$2"' 'order snapshot does not bind its side and order identity' \
  '"hpos_orders" => $checked_rows' 'order snapshot omits the primary HPOS order row' \
  '"hpos_addresses" => $checked_rows' 'order snapshot omits HPOS address rows' \
  '"hpos_operational" => $checked_rows' 'order snapshot omits HPOS operational rows' \
  '"hpos_meta" => $checked_rows' 'order snapshot omits HPOS order metadata' \
  '"customer_lookup" => $customer_lookup' 'order snapshot omits the Woo customer identity mapping' \
  '"order_stats" => $checked_rows' 'order snapshot omits derived order statistics' \
  '"product_lookup" => $checked_rows' 'order snapshot omits order product lookups' \
  '"order_items" => $order_items' 'order snapshot omits order-item rows' \
  '"order_itemmeta" => $order_itemmeta' 'order snapshot omits order-item metadata'
assert_helper_contracts target-order-snapshot "$TARGET_ORDER_SNAPSHOT_HELPER_BLOCK" "$TARGET_ORDER_SNAPSHOT_HELPER_GOLDEN_HASH" \
  'local snapshot="$1" label="$2"' 'target order snapshot helper does not bind snapshot/label arguments' \
  'jq -e --argjson expected_order "$TARGET_ORDER_ID" --argjson expected_product "$TARGET_CAP_ID" --argjson expected_customer "$TARGET_RUNTIME_CUSTOMER_ID"' 'target order snapshot helper does not bind the expected target order/product/customer IDs' \
  '.id == $expected_order and' 'target order snapshot helper does not assert order identity' \
  '.status == "pending" and' 'target order snapshot helper does not assert order status' \
  '.customer_id == $expected_customer and' 'target order snapshot helper does not assert customer identity' \
  '.billing_email == "runtime-only@example.invalid" and' 'target order snapshot helper does not assert the runtime-only billing identity' \
  '(.items | length) == 1 and' 'target order snapshot helper does not assert exact line-item cardinality' \
  '.items[0].product_id == $expected_product and' 'target order snapshot helper does not bind the expected line-item product' \
  '.items[0].variation_id == 0 and' 'target order snapshot helper does not assert a non-variation line item' \
  '.items[0].quantity == 1 and' 'target order snapshot helper does not assert exact quantity' \
  '(.subtotal | tonumber) > 0 and' 'target order snapshot helper does not assert a positive subtotal' \
  '(.total | tonumber) > 0 and' 'target order snapshot helper does not assert a positive total' \
  '(.items[0].subtotal | tonumber) > 0 and' 'target order snapshot helper does not assert a positive line subtotal' \
  '(.items[0].total | tonumber) > 0 and' 'target order snapshot helper does not assert a positive line total' \
  '(.hpos_orders | length) == 1 and' 'target order snapshot helper does not require the HPOS primary row' \
  '((.hpos_orders[0].id | tonumber) == $expected_order)' 'target order snapshot helper does not bind the primary HPOS row ID' \
  '((.hpos_orders[0].type | tostring) == "shop_order")' 'target order snapshot helper does not require the shop_order HPOS type' \
  'date_created_gmt' 'target order snapshot helper does not require a created HPOS timestamp' \
  'date_updated_gmt' 'target order snapshot helper does not require an updated HPOS timestamp' \
  '(.hpos_operational | length) == 1 and' 'target order snapshot helper does not require the HPOS operational row' \
  '((.hpos_operational[0].id | tonumber) > 0)' 'target order snapshot helper does not require an operational-row ID' \
  '((.hpos_operational[0].order_id | tonumber) == $expected_order)' 'target order snapshot helper does not bind the operational row to the order' \
  'woocommerce_version' 'target order snapshot helper does not require the Woo operational version' \
  'order_key' 'target order snapshot helper does not require the operational order key' \
  'has("created_via") and has("prices_include_tax") and has("recorded_sales")' 'target order snapshot helper does not require detailed operational fields' \
  '(.hpos_addresses | length) == 2 and' 'target order snapshot helper does not require billing and shipping address rows' \
  '[(.hpos_addresses[] | .address_type)] | sort' 'target order snapshot helper does not require both address types' \
  'has("first_name")' 'target order snapshot helper does not require detailed address columns' \
  'has("phone")' 'target order snapshot helper does not require the address phone column' \
  '200 Target Runtime Way' 'target order snapshot helper does not require the exact billing address' \
  '201 Target Fulfillment Way' 'target order snapshot helper does not require the exact shipping address' \
  'any(.hpos_addresses[]; .address_type == "billing"' 'target order snapshot helper does not bind the billing address' \
  'any(.hpos_addresses[]; .address_type == "shipping"' 'target order snapshot helper does not bind the shipping address' \
  '(.hpos_meta | length) >= 1 and' 'target order snapshot helper does not require HPOS order metadata rows' \
  '((.meta_key | tostring | length) > 0)' 'target order snapshot helper does not require nonempty HPOS metadata keys' \
  '((.meta_value | type) == "string")' 'target order snapshot helper does not require valid HPOS metadata values' \
  'any(.hpos_meta[]; .meta_key == "_duo_runtime_marker" and .meta_value == "target-order-only")' 'target order snapshot helper does not require the exact HPOS metadata marker' \
  '(.customer_lookup | length) == 1 and' 'target order snapshot helper does not require one Woo customer lookup row' \
  '(.customer_lookup[0].customer_id | tonumber) as $analytics_customer_id' 'target order snapshot helper does not bind the Woo analytics customer identity' \
  '((.customer_lookup[0].user_id | tonumber) == $expected_customer)' 'target order snapshot helper does not map the Woo customer to the WordPress user' \
  '.customer_lookup[0].username == "runtime-customer"' 'target order snapshot helper does not require the exact Woo customer username' \
  '.customer_lookup[0].email == "runtime-customer@example.invalid"' 'target order snapshot helper does not require the exact Woo customer email' \
  '(.order_stats | length) == 1 and' 'target order snapshot helper does not require one order-stats row' \
  '((.order_stats[0].order_id | tonumber) == $expected_order)' 'target order snapshot helper does not bind order stats to the order' \
  '((.order_stats[0].status | tostring) == "wc-pending")' 'target order snapshot helper does not require pending order stats' \
  '((.order_stats[0].customer_id | tonumber) == $analytics_customer_id)' 'target order snapshot helper does not bind order stats to the Woo customer identity' \
  '((.order_stats[0].num_items_sold | tonumber) == 1)' 'target order snapshot helper does not require one sold item in order stats' \
  '((.order_stats[0].total_sales | tonumber) > 0)' 'target order snapshot helper does not require positive order-stat sales' \
  '((.order_stats[0].net_total | tonumber) > 0)' 'target order snapshot helper does not require positive order-stat net revenue' \
  '((.order_stats[0].tax_total | tonumber) >= 0)' 'target order snapshot helper does not validate order-stat tax totals' \
  '((.order_stats[0].shipping_total | tonumber) >= 0)' 'target order snapshot helper does not validate order-stat shipping totals' \
  '((.order_stats[0].date_created_gmt | tostring | length) > 0)' 'target order snapshot helper does not require an order-stat creation timestamp' \
  '(.product_lookup | length) == 1 and' 'target order snapshot helper does not require one order-product lookup row' \
  '((.product_lookup[0].order_item_id | tonumber) == $line_item_id)' 'target order snapshot helper does not bind the product lookup to the raw line item' \
  '((.product_lookup[0].order_id | tonumber) == $expected_order)' 'target order snapshot helper does not bind product lookup to the order' \
  '((.product_lookup[0].product_id | tonumber) == $expected_product)' 'target order snapshot helper does not bind product lookup to the expected product' \
  '((.product_lookup[0].variation_id | tonumber) == 0)' 'target order snapshot helper does not validate the product lookup variation' \
  '((.product_lookup[0].customer_id | tonumber) == $analytics_customer_id)' 'target order snapshot helper does not bind product lookup to the Woo customer identity' \
  '((.product_lookup[0].product_qty | tonumber) == 1)' 'target order snapshot helper does not require one product quantity' \
  '((.product_lookup[0].product_gross_revenue | tonumber) > 0)' 'target order snapshot helper does not require positive gross product revenue' \
  '((.product_lookup[0].product_net_revenue | tonumber) > 0)' 'target order snapshot helper does not require positive net product revenue' \
  '((.product_lookup[0].date_created | tostring | length) > 0)' 'target order snapshot helper does not require a product lookup timestamp' \
  '(.order_items | length) == 2 and' 'target order snapshot helper does not require exact line-item and tax-item rows' \
  '[.order_items[] | select(.order_item_type == "line_item")]' 'target order snapshot helper does not identify the raw line item semantically' \
  '[.order_items[] | select(.order_item_type == "tax")]' 'target order snapshot helper does not identify the raw tax item semantically' \
  'any($snapshot.order_items[]; (.order_item_id | tonumber) == $meta_item_id)' 'target order snapshot helper does not bind metadata to a known raw order item' \
  '.meta_key == "_product_id" and (.meta_value | tonumber) == $expected_product' 'target order snapshot helper does not bind raw product metadata' \
  '.meta_key == "_variation_id" and (.meta_value | tonumber) == 0' 'target order snapshot helper does not bind raw variation metadata' \
  '.meta_key == "_qty" and (.meta_value | tonumber) == 1' 'target order snapshot helper does not bind raw quantity metadata' \
  '.meta_key == "_line_subtotal" and (.meta_value | tonumber) > 0' 'target order snapshot helper does not require a positive raw line subtotal' \
  '.meta_key == "_line_total" and (.meta_value | tonumber) > 0' 'target order snapshot helper does not require a positive raw line total' \
  '.meta_key == "rate_id" and (.meta_value | tonumber) > 0' 'target order snapshot helper does not require the raw tax rate' \
  '.meta_key == "label" and .meta_value == "Duo Grind CA Sales Tax"' 'target order snapshot helper does not require the exact raw tax label' \
  '.meta_key == "tax_amount" and (.meta_value | tonumber) > 0' 'target order snapshot helper does not require a positive raw tax amount' \
  '(.order_itemmeta | length) >= 10 and' 'target order snapshot helper does not require complete order-item metadata' \
  '((.meta_id | tonumber) > 0)' 'target order snapshot helper does not require valid order-item metadata IDs' \
  '((.meta_key | tostring | length) > 0)' 'target order snapshot helper does not require nonempty order-item metadata keys' \
  '((.meta_value | type) == "string")' 'target order snapshot helper does not require valid order-item metadata values' \
  '<<<"$snapshot" >/dev/null || fail' 'target order snapshot helper does not reject an invalid snapshot'
assert_helper_contracts target-order-absent "$TARGET_ORDER_ABSENT_HELPER_BLOCK" "$TARGET_ORDER_ABSENT_HELPER_GOLDEN_HASH" \
  'local label="$1"' 'target order absent helper does not bind its label' \
  'TARGET_ORDER_ITEM_IDS' 'target order absent helper does not use the captured item IDs' \
  '^[1-9][0-9]*(,[1-9][0-9]*)*$' 'target order absent helper does not validate captured item IDs' \
  'assert_eq 0 "$(target_wp eval' 'target order absent helper does not query Woo order existence' \
  'wc_get_order' 'target order absent helper does not use the Woo order API' \
  'array_map("absint"' 'target order absent helper does not sanitize captured item IDs' \
  'explode(",", ' 'target order absent helper does not parse the captured item ID list' \
  '$item_id_list = implode(",", $item_ids);' 'target order absent helper does not build a safe item-ID SQL list' \
  '$order_itemmeta_table' 'target order absent helper does not bind the order-itemmeta table' \
  'wc_order_operational_data' 'target order absent helper does not inspect HPOS operational rows' \
  'wc_order_product_lookup' 'target order absent helper does not inspect order product lookups' \
  'woocommerce_order_items' 'target order absent helper does not inspect order items' \
  'LEFT JOIN `$order_items_table` AS items' 'target order absent helper does not inspect orphaned order-item metadata' \
  'itemmeta.order_item_id IN ($item_id_list)' 'target order absent helper does not bind orphan checks to captured item IDs' \
  'items.order_item_id IS NULL' 'target order absent helper does not detect orphaned order-item metadata' \
  'wc_customer_lookup' 'target order absent helper does not inspect the Woo customer identity mapping' \
  'WHERE user_id = %d' 'target order absent helper does not bind Woo customer cleanup to the target WordPress user' \
  'target customer lookup absence read failed' 'target order absent helper does not fail closed on Woo customer lookup reads' \
  'target order-item metadata absence read failed' 'target order absent helper does not fail closed on metadata read errors' \
  'orphan target order-item metadata absence read failed' 'target order absent helper does not fail closed on orphan reads' \
  '"$label absent HPOS/order-item/customer-lookup rows"' 'target order absent helper does not require order, item, metadata, and Woo customer absence' \
  '"$label absent order"' 'target order absent helper does not label the absence assertion'
assert_helper_contracts deletion-probe-present "$DELETION_PROBE_PRESENT_HELPER_BLOCK" "$DELETION_PROBE_PRESENT_HELPER_GOLDEN_HASH" \
  'local id="$1" meta_rows attribute_rows' 'deletion probe present helper does not bind ID and lookup counts' \
  '[[ "$id" =~ ^[0-9]+$ ]] || fail' 'deletion probe present helper does not validate a numeric ID' \
  'target_wp post list --post_type=product --name=duo-grind-delete-probe --field=ID' 'deletion probe present helper does not query the canonical probe slug' \
  'assert_eq "$id"' 'deletion probe present helper does not assert the probe product identity' \
  'wp_wc_product_meta_lookup WHERE product_id = $id' 'deletion probe present helper does not query Woo meta lookup rows' \
  'sku = '\''GRIND-DELETE-PROBE'\''' 'deletion probe present helper does not bind the probe SKU' \
  'wp_wc_product_attributes_lookup WHERE (product_id = $id OR product_or_parent_id = $id)' 'deletion probe present helper does not query Woo attribute lookup rows' \
  'taxonomy = '\''pa_grind-size'\''' 'deletion probe present helper does not bind the probe attribute taxonomy' \
  '[ "$meta_rows" -ge 1 ] || fail' 'deletion probe present helper does not require its meta lookup row' \
  '[ "$attribute_rows" -ge 1 ] || fail' 'deletion probe present helper does not require its attribute lookup row'
assert_helper_contracts live-checkout "$LIVE_CHECKOUT_HELPER_BLOCK" "$LIVE_CHECKOUT_HELPER_GOLDEN_HASH" \
  'git -C "$REPO_ROOT" rev-parse --path-format=absolute --git-dir' 'live checkout guard does not resolve the checkout git-dir' \
  'git -C "$REPO_ROOT" rev-parse --path-format=absolute --git-common-dir' 'live checkout guard does not resolve the canonical common-dir' \
  '[ "$git_dir" != "$common_dir" ]' 'live checkout guard does not reject linked-worktree git-dir identity' \
  '[ -f "$REPO_ROOT/.git" ]' 'live checkout guard does not reject a linked-worktree .git file' \
  'git -C "$REPO_ROOT" status --porcelain=v1 --untracked-files=all' 'live checkout guard does not reject dirty source bytes' \
  'DUO_AGENT_SRC=' 'live checkout guard does not validate the canonical agent mount source' \
  'DUO_MANIFESTS_SRC=' 'live checkout guard does not validate the canonical manifests mount source'
block_contains prelude "$PRELUDE_BLOCK" '[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]]' 'invalid-name probe disappeared before live checkout mutation'
block_contains prelude "$PRELUDE_BLOCK" 'for pair_path in "$SITE" "$OTHER_SITE" "$ORIGIN"; do' 'pre-existing-root probe disappeared before live checkout mutation'
block_contains prelude "$PRELUDE_BLOCK" 'refusing to reuse pre-existing pair path' 'pre-existing-root refusal disappeared before live checkout mutation'
PRELUDE_GUARD_CALL=$'assert_clean_live_checkout\nENVS_FILE='
block_contains prelude "$PRELUDE_BLOCK" "$PRELUDE_GUARD_CALL" 'live checkout guard is not invoked before temporary mutation inputs'
ordered_contract prelude "$PRELUDE_BLOCK" \
  '[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]]' \
  'for pair_path in "$SITE" "$OTHER_SITE" "$ORIGIN"; do' \
  'assert_clean_live_checkout()' \
  'ENVS_FILE="$(mktemp'

assert_helper_contracts deploy-artifact-files "$DEPLOY_ARTIFACT_FILES_HELPER_BLOCK" "$DEPLOY_ARTIFACT_FILES_HELPER_GOLDEN_HASH" \
  '[ -d "$OTHER_SITE/.duo/artifacts" ] || return 0' 'deploy artifact probe does not tolerate a fresh target artifact directory' \
  "-name 'deploy-*.json'" 'deploy artifact probe does not restrict the receipt kind' \
  'print | sort' 'deploy artifact probe does not produce deterministic receipt inventory'
assert_helper_contracts new-deploy-artifact "$NEW_DEPLOY_ARTIFACT_HELPER_BLOCK" "$NEW_DEPLOY_ARTIFACT_HELPER_GOLDEN_HASH" \
  'comm -13' 'deploy receipt binding does not compute the before/after set difference' \
  'new receipt (new count=' 'deploy receipt binding does not require exactly one new receipt' \
  '[ "$count" -eq 1 ]' 'deploy receipt binding does not reject ambiguous receipt selection' \
  '[ -f "$artifact" ]' 'deploy receipt binding does not require the selected new file'
assert_helper_contracts promote-artifact "$PROMOTE_ARTIFACT_HELPER_BLOCK" "$PROMOTE_ARTIFACT_HELPER_GOLDEN_HASH" \
  'checkpoint="$(sed -n' 'promote artifact binding does not parse the printed checkpoint' \
  'promote-\(.*\)\.sql' 'promote artifact binding does not bind the checkpoint run name' \
  'artifact="$OTHER_SITE/.duo/artifacts/promote-$checkpoint.json"' 'promote artifact binding does not derive the exact receipt path' \
  '[ -f "$artifact" ]' 'promote artifact binding does not require the exact receipt file'

assert_helper_contracts source-event-baseline "$SOURCE_EVENT_BASELINE_HELPER_BLOCK" "$SOURCE_EVENT_BASELINE_HELPER_GOLDEN_HASH" \
  'case "$context_columns" in' 'source event baseline does not branch on v1/v2 table shape' \
  'SOURCE_RUNTIME_EVENT_BASELINE' 'source event baseline does not preserve the immutable v1 shape' \
  'SOURCE_RUNTIME_EVENT_V2_BASELINE' 'source event baseline does not derive and pin the v2 shape' \
  'v2 source event context default' 'source event baseline does not require the migrated empty context default'
assert_helper_contracts runtime-isolation "$RUNTIME_ISOLATION_HELPER_BLOCK" "$RUNTIME_ISOLATION_HELPER_GOLDEN_HASH" \
  'assert_source_runtime_baseline "$label"' 'runtime isolation does not assert the source baseline' \
  'assert_source_runtime_absent_from_target "$label"' 'runtime isolation does not reject source data in target' \
  'assert_target_runtime_baseline "$label" "$expected_context"' 'runtime isolation does not assert target runtime survival' \
  'assert_target_runtime_absent_from_source "$label"' 'runtime isolation does not reject target data in source' \
  'assert_env_secret_isolation "$label"' 'runtime isolation does not assert source/target secret separation' \
  'assert_runtime_state_excluded "$label"' 'runtime isolation does not reject runtime markers in generated state'
assert_helper_contracts runtime-state-exclusion "$RUNTIME_STATE_EXCLUSION_HELPER_BLOCK" "$RUNTIME_STATE_EXCLUSION_HELPER_GOLDEN_HASH" \
  'source-customer@example.invalid' 'runtime state exclusion omits the source customer marker' \
  'source-order@example.invalid' 'runtime state exclusion omits the source order marker' \
  'Duo Grind source-only runtime event' 'runtime state exclusion omits the source event marker' \
  'runtime-customer@example.invalid' 'runtime state exclusion omits the target customer marker' \
  'runtime-only@example.invalid' 'runtime state exclusion omits the target order marker' \
  'target-order-only' 'runtime state exclusion omits the target HPOS metadata marker' \
  '200 Target Runtime Way' 'runtime state exclusion omits the target billing-address marker' \
  '201 Target Fulfillment Way' 'runtime state exclusion omits the target shipping-address marker' \
  'Duo Grind runtime v1 event' 'runtime state exclusion omits the target event marker' \
  'source-only-synthetic-secret' 'runtime state exclusion omits the source secret marker' \
  'target-only-synthetic-secret' 'runtime state exclusion omits the target secret marker'
assert_helper_contracts runtime-identity "$RUNTIME_IDENTITY_HELPER_BLOCK" "$RUNTIME_IDENTITY_HELPER_GOLDEN_HASH" \
  'get_users(["role" => "customer"' 'runtime identity inventory omits all customer identities' \
  'FROM `{$wpdb->prefix}wc_orders` WHERE type = %s ORDER BY id' 'runtime identity inventory omits all HPOS order identities' \
  '"shop_order"' 'runtime identity inventory does not bind the shop-order type' \
  'SELECT id, label, created_at FROM `$events_table` ORDER BY id' 'runtime identity inventory omits all extension event identities' \
  '"customers" => $customers, "orders" => $orders, "events" => $events' 'runtime identity inventory does not emit the complete normalized inventory'
assert_helper_contracts env-secret "$ENV_SECRET_HELPER_BLOCK" "$ENV_SECRET_HELPER_GOLDEN_HASH" \
  'source-only-synthetic-secret' 'env-secret helper does not assert the source-owned value' \
  'target-only-synthetic-secret' 'env-secret helper does not assert the target-owned value'
assert_helper_contracts source-ledger "$SOURCE_LEDGER_HELPER_BLOCK" "$SOURCE_LEDGER_HELPER_GOLDEN_HASH" \
  '"duo_map" => "SELECT uuid, entity_type, id_kind, local_id' 'source ledger snapshot omits the identity map' \
  '"duo_state" => "SELECT uuid, entity_type, content_hash' 'source ledger snapshot omits state hashes' \
  '"duo_kv" => "SELECT k, v' 'source ledger snapshot omits control-plane keys' \
  'wp_json_encode($snapshot, JSON_UNESCAPED_SLASHES)' 'source ledger snapshot is not normalized JSON'
assert_helper_contracts acf-schema "$ACF_SCHEMA_HELPER_BLOCK" "$ACF_SCHEMA_HELPER_GOLDEN_HASH" \
  '.group.key == "group_duo_commerce_catalog"' 'ACF schema assertion does not bind the exact group key' \
  '.group.location == [[{"param":"post_type","operator":"==","value":"product"}]]' 'ACF schema assertion does not bind the exact product location' \
  '.field_count == 1' 'ACF schema assertion does not reject extra fields' \
  '.field.key == "field_duo_inventory_note"' 'ACF schema assertion does not bind the exact field key' \
  '.field.parent == .group.id' 'ACF schema assertion does not bind the field to the exact group ID' \
  '.field.conditional_logic == false' 'ACF schema assertion does not reject conditional field logic'
assert_helper_contracts frontend "$FRONTEND_HELPER_BLOCK" "$FRONTEND_HELPER_GOLDEN_HASH" \
  'curl --connect-timeout 3 --max-time 10' 'frontend probe is not bounded by connect and total timeouts' \
  'duo-commerce-storefront' 'frontend probe does not assert the parent body marker' \
  'duo-commerce-child-catalog' 'frontend probe does not assert child template execution' \
  'duo-commerce-parent-css' 'frontend probe does not assert parent stylesheet enqueue' \
  'duo-commerce-child-css' 'frontend probe does not assert child stylesheet enqueue' \
  '[ "$parent_offset" -lt "$child_offset" ]' 'frontend probe does not assert dependency order'
assert_helper_contracts extension-rest "$REST_HELPER_BLOCK" "$REST_HELPER_GOLDEN_HASH" \
  '/wp-json/duo-commerce/v1/status' 'extension REST probe does not call the public status endpoint' \
  'jq -e --arg version "$expected_version" --argjson schema "$expected_schema"' 'extension REST probe does not bind exact version/schema expectations' \
  '.extension_version == $version' 'extension REST probe does not assert extension version' \
  '.schema == $schema' 'extension REST probe does not assert schema version' \
  '.woocommerce == true' 'extension REST probe does not assert Woo availability'
assert_helper_contracts store-api-http "$STORE_API_HTTP_HELPER_BLOCK" "$STORE_API_HTTP_HELPER_GOLDEN_HASH" \
  'curl --connect-timeout 3 --max-time 10 --silent --show-error --get' 'external Store API probe is not bounded' \
  "--write-out '%{http_code}'" 'external Store API probe does not capture HTTP status' \
  'price_response' 'external Store API probe does not retain the price response/status' \
  'price_http="${price_response: -3}"' 'external Store API price request does not parse HTTP status' \
  'price_body="${price_response:0:${#price_response}-3}"' 'external Store API price request does not separate body from status' \
  'assert_eq 200 "$price_http"' 'external Store API price request does not require HTTP 200' \
  'attribute_http="${attribute_response: -3}"' 'external Store API attribute request does not parse HTTP status' \
  'assert_eq 200 "$attribute_http"' 'external Store API attribute request does not require attribute HTTP 200' \
  'price_negative_http="${price_negative_response: -3}"' 'external Store API negative-price request does not parse HTTP status' \
  'assert_eq 200 "$price_negative_http"' 'external Store API negative-price request does not require HTTP 200' \
  'attribute_negative_http="${attribute_negative_response: -3}"' 'external Store API negative-attribute request does not parse HTTP status' \
  'assert_eq 200 "$attribute_negative_http"' 'external Store API negative-attribute request does not require HTTP 200' \
  '/wp-json/wc/store/v1/products' 'external Store API probe does not call the public products endpoint' \
  'attributes[0][attribute]=pa_grind-size' 'external Store API probe omits the positive attribute filter' \
  'attributes[0][slug]=not-a-real-size' 'external Store API probe omits the negative attribute filter' \
  '.[0].prices.price == $expected' 'external Store API probe does not assert exact public price data' \
  'type == "array" and length == 0' 'external Store API probe does not assert empty negative filters'

ordered_contract failed-v2-checkpoint "$FAILED_V2_PHASE_BLOCK" \
  'V2_FAILED_CHECKPOINT="$(sed -n' \
  'V2_FAILED_RUN_ID="$(basename "$V2_FAILED_CHECKPOINT")"' \
  'assert_eq "/siterepo/.duo/checkpoints/promote-$V2_FAILED_RUN_ID.sql"' \
  'V2_FAILED_CHECKPOINT_HOST="$OTHER_SITE/.duo/checkpoints/promote-$V2_FAILED_RUN_ID.sql"' \
  'V2_FAILED_ARTIFACT_FILE="$OTHER_SITE/.duo/artifacts/promote-$V2_FAILED_RUN_ID.json"' \
  'V2_FAILED_CHECKPOINT_SHA256="$(sha256sum "$V2_FAILED_CHECKPOINT_HOST"' \
  'V2_FAILED_COMPILED_HASH="$(jq -r '\''.artifact_hash'\'' "$V2_FAILED_ARTIFACT_FILE")"' \
  'assert_eq "$V2_FAILED_COMPILED_HASH" "$V2_FAILED_RECOMPUTED_HASH"' \
  'V2_FAILED_SESSION="$(ledger_value promotion_session)"' \
  'assert_eq "$V2_FAILED_RUN_ID" "$V2_FAILED_OWNER"' \
  'assert_eq "$V2_FAILED_COMPILED_HASH" "$V2_FAILED_ARTIFACT"'
ordered_contract failed-v2-recovery "$FAILED_V2_RECOVERY_PHASE_BLOCK" \
  'control_wp abortArgs "$V2_FAILED_OWNER" "$V2_FAILED_ARTIFACT"' \
  'control_wp beginArgs "$V2_FAILED_OWNER" "$V2_FAILED_ARTIFACT"' \
  'V2_RECOVERY_LOCK="$(ledger_value promotion_lock)"' \
  '.owner == $owner and .artifact_hash == $artifact and .phase == "checkpoint"' \
  'assert_eq "$V2_FAILED_CHECKPOINT_SHA256" "$(sha256sum "$V2_FAILED_CHECKPOINT_HOST"' \
  'control_wp recoveryDbImportArgs "$V2_FAILED_CHECKPOINT"' \
  'control_wp abortArgs "$V2_FAILED_OWNER" "$V2_FAILED_ARTIFACT"'

ordered_contract exact-v1-rollback "$ROLLBACK_PHASE_BLOCK" \
  'target_wp maintenance-mode activate' \
  'assert_eq "$V1_DB_DUMP_SHA256" "$(sha256sum "$V1_DB_DUMP"' \
  'assert_eq "$V1_DB_DUMP_SHA256" "$(sha256sum "$OTHER_SITE/.tmp-ecommerce-v1-db.sql"' \
  'control_wp recoveryDbImportArgs "/siterepo/.tmp-ecommerce-v1-db.sql"' \
  'assert_eq "$NATIVE_ACTIVE_PLUGINS_JSON" "$(active_plugins_json)"' \
  'assert_eq absent "$(target_file "$EXT_TARGET")"' \
  'if ! RESTORE_OUT="$(promote 2>&1)"; then' \
  'ROLLBACK_PROMOTION_SUCCEEDED=1' \
  'target_wp maintenance-mode deactivate' \
  'ROLLBACK_MAINTENANCE_HELD=0' \
  'assert_phase_order "$RESTORE_OUT"'
if grep -Fq 'target_wp db import' "$SCRIPT"; then
  fail 'exact v1 rollback bypasses the isolated control-plane database import'
fi
block_contains exact-v1-rollback "$ROLLBACK_PHASE_BLOCK" 'control_wp recoveryDbImportArgs "/siterepo/.tmp-ecommerce-v1-db.sql"' \
  'exact v1 rollback does not restore its dump through the fatal-safe control operation'
block_contains exact-v1-rollback "$ROLLBACK_PHASE_BLOCK" 'promote phase: code-stage' \
  'exact v1 rollback does not require code staging before lifecycle activation'
block_contains exact-v1-rollback "$ROLLBACK_PHASE_BLOCK" 'target_wp maintenance-mode deactivate' \
  'exact v1 rollback does not release target maintenance after promotion'
grep -Fq 'ROLLBACK_MAINTENANCE_HELD=0' "$SCRIPT" || fail 'rollback maintenance held flag is not initialized/released'
grep -Fq 'ROLLBACK_PROMOTION_SUCCEEDED=0' "$SCRIPT" || fail 'rollback promotion success guard is not initialized'
grep -Fq '[ "$ROLLBACK_MAINTENANCE_HELD" = 1 ] && [ "$ROLLBACK_PROMOTION_SUCCEEDED" != 1 ]' "$SCRIPT" \
  || fail 'rollback failure cleanup does not preserve maintenance before successful promotion'
grep -Fq 'ecommerce rollback maintenance remains held after an incomplete recovery' "$SCRIPT" \
  || fail 'rollback failure cleanup does not report its fail-closed maintenance state'

block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'source_wp wc product delete "$DELETION_PROBE_SOURCE_ID" --force=true --user=admin >/dev/null' 'fail-closed phase does not execute the public Woo product delete'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'if DELETION_REFUSAL_OUT="$(source_wp duo capture --repo=/siterepo 2>&1)"; then' 'fail-closed phase does not capture the unsupported deletion refusal'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" "deletion intent for post:product is unsupported" 'fail-closed phase does not require the exact unsupported selector diagnostic'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'DELETION_PROBE_STATE_TREE_BEFORE=' 'fail-closed phase does not snapshot the canonical state tree'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'DELETION_PROBE_REPO_HEAD_BEFORE=' 'fail-closed phase does not snapshot the local repository revision'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'DELETION_PROBE_ORIGIN_HEAD_BEFORE=' 'fail-closed phase does not snapshot the published origin revision'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'DELETION_PROBE_STATUS_BEFORE=' 'fail-closed phase does not snapshot repository status'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'DELETION_PROBE_LEDGER_BEFORE="$(source_duo_ledger_snapshot)"' 'fail-closed phase does not snapshot all source Duo ledgers immediately before capture'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'assert_eq "$DELETION_PROBE_STATE_TREE_BEFORE" "$(state_tree_hash "$SITE")"' 'fail-closed phase does not compare the state tree after refusal'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'assert_eq "$DELETION_PROBE_REPO_HEAD_BEFORE" "$(git -C "$SITE" rev-parse HEAD)"' 'fail-closed phase does not compare local HEAD after refusal'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'assert_eq "$DELETION_PROBE_ORIGIN_HEAD_BEFORE" "$(git --git-dir="$ORIGIN" rev-parse refs/heads/main)"' 'fail-closed phase does not compare origin HEAD after refusal'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'assert_eq "$DELETION_PROBE_STATUS_BEFORE" "$(git -C "$SITE" status --porcelain=v1 --untracked-files=all)"' 'fail-closed phase does not compare repository status after refusal'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'assert_eq "$DELETION_PROBE_LEDGER_BEFORE" "$(source_duo_ledger_snapshot)"' 'fail-closed phase does not compare all source Duo ledgers after refusal'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" '[ -e "$DELETION_PROBE_STATE_FILE" ]' 'fail-closed phase does not retain the canonical product state'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" '[ ! -e "$SITE/state/deletions/$DELETION_PROBE_UUID.json" ]' 'fail-closed phase does not assert tombstone absence'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'assert_theme_and_dependency' 'fail-closed phase does not assert target theme/dependency survival'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'assert_woo_catalog 1 5' 'fail-closed phase does not assert the unchanged target catalog count'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'assert_deletion_probe_lookup_present "$DELETION_PROBE_V1_TARGET_ID"' 'fail-closed phase does not assert target probe lookup survival'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'assert_derived_indexes 7 instock 16.49 9.99 16.49 1649' 'fail-closed phase does not assert target derived-index survival'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'target-only-synthetic-secret' 'fail-closed phase does not assert target-owned secret survival'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'assert_target_order_unchanged' 'fail-closed phase does not assert target order survival'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'assert_product_visibility_runtime' 'fail-closed phase does not assert target visibility runtime survival'
block_contains fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" 'assert_runtime_isolation '\''unsupported Woo product deletion refusal'\'' 1' 'fail-closed phase does not assert runtime isolation after refusal'

ordered_contract fail-closed-phase "$FAIL_CLOSED_PHASE_BLOCK" \
  'DELETION_PROBE_STATE_FILE="$(find "$SITE/state/posts/product"' \
  'DELETION_PROBE_UUID="$(DUO_CANON="$REPO_ROOT/agent/src/Canon.php" php -r' \
  'DELETION_PROBE_STATE_TREE_BEFORE="$(state_tree_hash "$SITE")"' \
  'DELETION_PROBE_REPO_HEAD_BEFORE="$(git -C "$SITE" rev-parse HEAD)"' \
  'DELETION_PROBE_ORIGIN_HEAD_BEFORE="$(git --git-dir="$ORIGIN" rev-parse refs/heads/main)"' \
  'DELETION_PROBE_STATUS_BEFORE="$(git -C "$SITE" status --porcelain=v1 --untracked-files=all)"' \
  'source_wp wc product delete "$DELETION_PROBE_SOURCE_ID" --force=true --user=admin >/dev/null' \
  'assert_eq "" "$(source_wp post list --post_type=product --name=duo-grind-delete-probe --field=ID)"' \
  'DELETION_PROBE_LEDGER_BEFORE="$(source_duo_ledger_snapshot)"' \
  'if DELETION_REFUSAL_OUT="$(source_wp duo capture --repo=/siterepo 2>&1)"; then' \
  "fail 'unsupported Woo product deletion capture unexpectedly succeeded'" \
  "grep -Fq 'deletion intent for post:product is unsupported' <<<\"\$DELETION_REFUSAL_OUT\"" \
  'assert_eq "$DELETION_PROBE_STATE_TREE_BEFORE" "$(state_tree_hash "$SITE")"' \
  'assert_eq "$DELETION_PROBE_REPO_HEAD_BEFORE" "$(git -C "$SITE" rev-parse HEAD)"' \
  'assert_eq "$DELETION_PROBE_ORIGIN_HEAD_BEFORE" "$(git --git-dir="$ORIGIN" rev-parse refs/heads/main)"' \
  'assert_eq "$DELETION_PROBE_STATUS_BEFORE" "$(git -C "$SITE" status --porcelain=v1 --untracked-files=all)"' \
  'assert_eq "$DELETION_PROBE_LEDGER_BEFORE" "$(source_duo_ledger_snapshot)"' \
  '[ -e "$DELETION_PROBE_STATE_FILE" ]' \
  '[ ! -e "$SITE/state/deletions/$DELETION_PROBE_UUID.json" ]' \
  'assert_theme_and_dependency' \
  'assert_woo_catalog 1 5' \
  'assert_deletion_probe_lookup_present "$DELETION_PROBE_V1_TARGET_ID"' \
  'assert_derived_indexes 7 instock 16.49 9.99 16.49 1649' \
  'target-only-synthetic-secret' \
  'assert_target_order_unchanged' \
  'assert_product_visibility_runtime' \
  "assert_runtime_isolation 'unsupported Woo product deletion refusal' 1"

FAIL_CLOSED_REQUIRED_TOKENS=(
  'source_wp wc product delete "$DELETION_PROBE_SOURCE_ID" --force=true --user=admin >/dev/null'
  'DELETION_PROBE_LEDGER_BEFORE="$(source_duo_ledger_snapshot)"'
  'if DELETION_REFUSAL_OUT="$(source_wp duo capture --repo=/siterepo 2>&1)"; then'
  "grep -Fq 'deletion intent for post:product is unsupported' <<<\"\$DELETION_REFUSAL_OUT\""
  'assert_eq "$DELETION_PROBE_STATE_TREE_BEFORE" "$(state_tree_hash "$SITE")"'
  'assert_eq "$DELETION_PROBE_REPO_HEAD_BEFORE" "$(git -C "$SITE" rev-parse HEAD)"'
  'assert_eq "$DELETION_PROBE_ORIGIN_HEAD_BEFORE" "$(git --git-dir="$ORIGIN" rev-parse refs/heads/main)"'
  'assert_eq "$DELETION_PROBE_STATUS_BEFORE" "$(git -C "$SITE" status --porcelain=v1 --untracked-files=all)"'
  'assert_eq "$DELETION_PROBE_LEDGER_BEFORE" "$(source_duo_ledger_snapshot)"'
  '[ -e "$DELETION_PROBE_STATE_FILE" ]'
  '[ ! -e "$SITE/state/deletions/$DELETION_PROBE_UUID.json" ]'
  'assert_theme_and_dependency'
  'assert_woo_catalog 1 5'
  'assert_deletion_probe_lookup_present "$DELETION_PROBE_V1_TARGET_ID"'
  'assert_derived_indexes 7 instock 16.49 9.99 16.49 1649'
  'target-only-synthetic-secret'
  'assert_target_order_unchanged'
  'assert_product_visibility_runtime'
  "assert_runtime_isolation 'unsupported Woo product deletion refusal' 1"
)
for required_token in "\${FAIL_CLOSED_REQUIRED_TOKENS[@]}"; do
  MUTATED_FAIL_CLOSED_PHASE_BLOCK="$(grep -Fv -- "$required_token" <<<"$FAIL_CLOSED_PHASE_BLOCK")"
  if (ordered_contract fail-closed-negative "$MUTATED_FAIL_CLOSED_PHASE_BLOCK" "$required_token") >/dev/null 2>&1; then
    fail "fail-closed phase negative mutation unexpectedly passed: $required_token"
  fi
done

FAIL_CLOSED_DELETE_TOKEN='source_wp wc product delete "$DELETION_PROBE_SOURCE_ID" --force=true --user=admin >/dev/null'
MUTATED_FAIL_CLOSED_DELETE_COMMENT_BLOCK="$(awk -v needle="$FAIL_CLOSED_DELETE_TOKEN" '
  {
    position = index($0, needle)
    if (position) {
      print substr($0, 1, position - 1) "// " needle substr($0, position + length(needle))
    } else {
      print
    }
  }
' <<<"$FAIL_CLOSED_PHASE_BLOCK" | strip_static_comments)"
if (ordered_contract fail-closed-delete-comment-negative "$MUTATED_FAIL_CLOSED_DELETE_COMMENT_BLOCK" "$FAIL_CLOSED_DELETE_TOKEN") >/dev/null 2>&1; then
  fail 'fail-closed phase negative mutation unexpectedly accepted a commented public delete'
fi

[ "$(block_sha256 "$FAIL_CLOSED_PHASE_BLOCK")" = "$FAIL_CLOSED_PHASE_GOLDEN_HASH" ] \
  || fail "golden hash helper detected fail-closed phase executable drift (got $(block_sha256 "$FAIL_CLOSED_PHASE_BLOCK"))"
[ "$(block_sha256 "$FINAL_RECAPTURE_PHASE_BLOCK")" = "$FINAL_RECAPTURE_PHASE_GOLDEN_HASH" ] \
  || fail "golden hash helper detected final recapture phase executable drift (got $(block_sha256 "$FINAL_RECAPTURE_PHASE_BLOCK"))"

[ -x "$SCRIPT" ] || fail "$SCRIPT must be executable"
bash -n "$SCRIPT"

if ECOMMERCE_PAIR='../unsafe' bash "$SCRIPT" >"$STATIC_INVALID_OUT" 2>&1; then
  fail 'invalid pair name unexpectedly reached the live harness'
fi
grep -Fq "pair name '../unsafe' invalid" "$STATIC_INVALID_OUT" \
  || fail 'invalid pair name refusal is not explicit'

STATIC_PAIR="ecomstatic${BASHPID}"
STATIC_SENTINEL="siterepo/${STATIC_PAIR}1"
if [ ! -d siterepo ]; then
  mkdir siterepo
  STATIC_CREATED_SITEREPO=1
fi
mkdir "$STATIC_SENTINEL"
touch "$STATIC_SENTINEL/keep"
if ECOMMERCE_PAIR="$STATIC_PAIR" bash "$SCRIPT" >"$STATIC_EXISTING_OUT" 2>&1; then
  fail 'pre-existing pair path unexpectedly reached the live harness'
fi
[ -f "$STATIC_SENTINEL/keep" ] || fail 'pre-existing pair path refusal removed unrelated content'
grep -Fq 'refusing to reuse pre-existing pair path' "$STATIC_EXISTING_OUT" \
  || fail 'pre-existing pair path refusal is not explicit'
rm -f "$STATIC_SENTINEL/keep"
rmdir "$STATIC_SENTINEL"
STATIC_SENTINEL=""

while IFS= read -r file; do
  php -l "$file" >/dev/null || fail "PHP syntax: $file"
done < <(find "$FIXTURE" -type f -name '*.php' -print | sort)

# All remaining source contracts read the bounded executable-structure file;
# the original script path is retained above for syntax and collision probes.
SCRIPT="$STATIC_STRUCTURE_FILE"

grep -Fq 'WOO_VERSION="11.0.0"' "$SCRIPT" || fail 'primary Woo pin missing'
grep -Fq 'WOO_DOWNGRADE_VERSION="10.9.4"' "$SCRIPT" || fail 'negative Woo pin missing'
grep -Fq 'ACF_VERSION="6.8.7"' "$SCRIPT" || fail 'ACF pin missing'
grep -Fq 'woocommerce_cod_settings' "$SCRIPT" || fail 'COD merchant seed missing'
grep -Fq 'woocommerce_calc_taxes' "$SCRIPT" || fail 'Woo tax enablement seed/assertion missing'
grep -Fq 'cod_enable_for_methods' "$SCRIPT" || fail 'COD method-scope assertion missing'
grep -Fq 'cod_enable_for_virtual' "$SCRIPT" || fail 'COD virtual-order assertion missing'
grep -Fq '.cod_enable_for_methods == []' "$SCRIPT" || fail 'COD method scope is not asserted empty'
grep -Fq '.cod_enable_for_virtual == "yes"' "$SCRIPT" || fail 'COD virtual enablement is not asserted'
grep -Fq 'coupon_exact' "$SCRIPT" || fail 'exact coupon semantics assertion missing'
grep -Fq 'coupon_products' "$SCRIPT" || fail 'coupon product binding diagnostics missing'
grep -Fq 'coupon_categories' "$SCRIPT" || fail 'coupon category binding diagnostics missing'
grep -Fq 'coupon_expiry' "$SCRIPT" || fail 'coupon expiry diagnostics missing'
grep -Fq 'bundle_children_exact' "$SCRIPT" || fail 'grouped-product child exactness assertion missing'
grep -Fq 'bundle_meta' "$SCRIPT" || fail 'grouped-product derived lookup diagnostics missing'
grep -Fq '.bundle_rows == 1' "$SCRIPT" || fail 'grouped-product lookup cardinality is not exact'
grep -Fq 'expected_bundle_min' "$SCRIPT" || fail 'grouped-product minimum-price assertion missing'
grep -Fq 'expected_bundle_max' "$SCRIPT" || fail 'grouped-product maximum-price assertion missing'
grep -Fq 'thumbnail_exact' "$SCRIPT" || fail 'category thumbnail exactness assertion missing'
grep -Fq '431ced6916a2a21a156e38701afe55bbd7f88969fbbfc56d7fe099d47f265460' "$SCRIPT" || fail 'intended media content hash assertion missing'
grep -Fq 'store_exact' "$SCRIPT" || fail 'Woo store-option exactness assertion missing'
grep -Fq 'woocommerce_currency' "$SCRIPT" || fail 'Woo currency option assertion missing'
grep -Fq 'woocommerce_default_country' "$SCRIPT" || fail 'Woo default-country option assertion missing'
grep -Fq 'woocommerce_allowed_countries' "$SCRIPT" || fail 'Woo allowed-countries option assertion missing'
grep -Fq 'shipping_exact' "$SCRIPT" || fail 'shipping-zone instance exactness assertion missing'
grep -Fq 'expected_shipping_settings' "$SCRIPT" || fail 'shipping instance diagnostics missing'
grep -Fq 'Duo Grind Flat Rate' "$SCRIPT" || fail 'flat-rate title assertion missing'
grep -Fq 'Duo Grind Free Shipping' "$SCRIPT" || fail 'free-shipping title assertion missing'
grep -Fq 'tax_exact' "$SCRIPT" || fail 'CA tax-rate exactness assertion missing'
grep -Fq 'tax_rate_shipping' "$SCRIPT" || fail 'CA tax shipping flag assertion missing'
grep -Fq '.variations == 4' "$SCRIPT" || fail 'stable variation collection count is not exact'
grep -Fq '.coupons == 1' "$SCRIPT" || fail 'stable coupon collection count is not exact'
grep -Fq '.media == 2' "$SCRIPT" || fail 'stable media collection count (Woo placeholder plus authored image) is not exact'
grep -Fq 'duo-grind-widgets' "$SCRIPT" || fail 'product category seed/assertion missing'
grep -Fq 'free_shipping' "$SCRIPT" || fail 'shipping method seed/assertion missing'
grep -Fq 'Duo Grind Deletion Probe' "$SCRIPT" || fail 'sacrificial deletion product seed missing'
grep -Fq 'wc product delete "$DELETION_PROBE_SOURCE_ID" --force=true --user=admin' "$SCRIPT" || fail 'public Woo product deletion move missing or uses incompatible force syntax'
grep -Fq '[ ! -e "$SITE/state/deletions/$DELETION_PROBE_UUID.json" ]' "$SCRIPT" || fail 'unsupported product deletion does not assert tombstone absence'
grep -Fq 'deletion intent for post:product is unsupported' "$SCRIPT" || fail 'unsupported product deletion diagnostic is missing'
grep -Fq 'fetch_artifact "$WOO_SLUG" "$WOO_DOWNGRADE_VERSION"' "$SCRIPT" || fail 'negative artifact is not fetched through the lock-aware helper'
grep -Fq 'PAIR_PATHS_OWNED=1' "$SCRIPT" || fail 'pair paths are not claimed only after collision preflight'
grep -Fq 'run --rm -T -u root cli1 sh -c' "$SCRIPT" || fail 'source-side ownership normalization is missing'
grep -Fq 'run --rm -T -u root cli2 sh -c' "$SCRIPT" || fail 'target-side ownership normalization is missing'
grep -Fq '.duo-woocommerce-next' "$SCRIPT" || fail 'Woo downgrade is not staged beside the current tree'
grep -Fq 'test "$version" = "$expected_version"' "$SCRIPT" || fail 'staged Woo downgrade header is not version-verified'
grep -Fq 'mv "$current" "$previous"' "$SCRIPT" || fail 'Woo downgrade does not preserve the prior tree before atomic swap'
grep -Fq 'command -v unzip >/dev/null' "$SCRIPT" || fail 'container unzip prerequisite is not checked up front'
grep -Fq 'run --rm -T -u root "$cli"' bin/fetch-artifact.sh || fail 'shared artifact cache writes are not isolated to the root fetch command'
if grep -Eq 'db import .*--porcelain' "$SCRIPT"; then
  fail 'wp db import uses unsupported --porcelain'
fi
if grep -Fq 'Duo\\Canon' "$SCRIPT" || grep -Fq 'Duo\\Orchestrator' "$SCRIPT"; then
  fail 'embedded PHP contains an invalid doubled namespace separator'
fi
grep -Fq 'env-set target --name=duo_commerce_extension_gateway_secret' "$SCRIPT" || fail 'target env secret is not provisioned through public duo env-set'
grep -Fq '"manifests": ["core", "woocommerce", "acf"]' "$SCRIPT" || fail 'scenario does not pin only externally ratified shipped manifests'
grep -Fq '"duo_commerce_extension_gateway_secret": {"class": "env", "required": true}' "$SCRIPT" || fail 'custom extension secret is not classified by site-local policy'
grep -Fq '"duo_commerce_extension_settings": {"class": "authored", "autoload": "preserve"}' "$SCRIPT" || fail 'custom extension authored state does not declare the required portable autoload contract'
grep -Fq '"duo_commerce_extension_schema": {"class": "runtime"}' "$SCRIPT" || fail 'custom extension schema marker is not kept runtime-local'
if grep -Eq '"manifests"[^]]*"duo-commerce-extension"' "$SCRIPT"; then
  fail 'custom extension is incorrectly presented as a shipped manifest ratification claim'
fi
grep -Fq 'source_wp plugin activate "$EXT_SLUG"' "$SCRIPT" || fail 'author plugin lifecycle is not exercised through WP API'
grep -Fq 'source_wp theme activate "$PARENT_THEME"' "$SCRIPT" || fail 'author parent theme switch is missing'
grep -Fq 'source_wp theme activate "$CHILD_THEME"' "$SCRIPT" || fail 'author child theme switch is missing'
grep -Fq 'source_wp duo capture --repo=/siterepo' "$SCRIPT" || fail 'ordinary author capture is missing after lifecycle'
grep -Fq 'source_wp post update "$CAP_ID"' "$SCRIPT" || fail 'real author product update is missing'
grep -Fq 'source_wp wc product update "$CAP_ID" --regular_price=16.49' "$SCRIPT" || fail 'real grouped-child price update is missing'
grep -Fq 'assert_derived_indexes 7 instock 16.49 9.99 16.49 1649' "$SCRIPT" || fail 'grouped-root post-child-price convergence assertion missing'
grep -Fq -- "-name 'deploy-*.json'" "$SCRIPT" || fail 'deploy receipts are not selected'
grep -Fq 'promote-$checkpoint.json' "$SCRIPT" || fail 'promote receipts are not bound to the printed checkpoint run'
grep -Fq 'code_source_outside_version_range' "$SCRIPT" || fail 'source version compatibility assertion missing'
grep -Fq 'NATIVE_ACTIVE_PLUGINS_JSON' "$SCRIPT" || fail 'native WordPress active_plugins order assertion missing'
grep -Fq 'provider-first lifecycle planning' "$SCRIPT" || fail 'provider-first activation assertion missing'
grep -Fq 'code_plugin_dependency_inactive' "$SCRIPT" || fail 'dependency closure assertion missing'
grep -Fq 'assert_product_visibility_runtime' "$SCRIPT" || fail 'product_visibility runtime assertion missing'
grep -Fq 'wc_product_meta_lookup' "$SCRIPT" || fail 'Woo derived meta index assertion missing'
grep -Fq 'wc_product_attributes_lookup' "$SCRIPT" || fail 'Woo derived attribute index assertion missing'
grep -Fq 'target variation inventory setup mismatch' "$SCRIPT" || fail 'target-local runtime inventory setup is missing'
grep -Fq 'set_stock_status("instock")' "$SCRIPT" || fail 'target runtime stock status setup is missing'
grep -Fq 'variation_exact' "$SCRIPT" || fail 'exact variation lookup assertions are missing'
grep -Fq 'expected_cap_status' "$SCRIPT" || fail 'runtime-aware cap stock status assertion is missing'
grep -Fq '"onsale" => 0' "$SCRIPT" || fail 'variable parent lookup onsale expectation is missing'
if grep -Fq 'wc_get_products(["parent"' "$SCRIPT"; then
  fail 'variation enumeration still uses wc_get_products parent query (Woo excludes variation from its default type set)'
fi
grep -Fq 'get_children()' "$SCRIPT" || fail 'variable product child enumeration is missing'
grep -Fq 'target variation inventory setup mismatch' "$SCRIPT" || fail 'variation SKU mismatch diagnostics are missing'
grep -Fq '"variation_expected_skus"' "$SCRIPT" || fail 'derived-index expected variation SKU diagnostics are missing'
grep -Fq '"variation_missing_skus"' "$SCRIPT" || fail 'derived-index missing variation SKU diagnostics are missing'
grep -Fq '"variation_unexpected_skus"' "$SCRIPT" || fail 'derived-index unexpected variation SKU diagnostics are missing'
grep -Fq '"variation_ids_exact" => $variation_ids_exact' "$SCRIPT" || fail 'derived-index current variation ID exactness is missing'
grep -Fq '"variation_ids_missing" => $variation_ids_missing' "$SCRIPT" || fail 'derived-index missing variation ID diagnostics are missing'
grep -Fq '"variation_ids_unexpected" => $variation_ids_unexpected' "$SCRIPT" || fail 'derived-index unexpected variation ID diagnostics are missing'
grep -Fq '"attribute_actual_keys" => $attribute_actual_key_set' "$SCRIPT" || fail 'derived-index actual attribute key diagnostics are missing'
grep -Fq '"attribute_expected_keys" => $attribute_expected_keys' "$SCRIPT" || fail 'derived-index expected attribute key diagnostics are missing'
grep -Fq '"attribute_unknown_product_ids" => $attribute_unknown_product_ids' "$SCRIPT" || fail 'derived-index orphan attribute product diagnostics are missing'
grep -Fq '"attribute_duplicate_keys" => $attribute_duplicate_keys' "$SCRIPT" || fail 'derived-index duplicate attribute key diagnostics are missing'
grep -Fq '.attribute_rows == 8' "$SCRIPT" || fail 'derived-index raw attribute row count is not exact'
grep -Fq 'price_negative_request' "$SCRIPT" || fail 'negative Store API price probe is missing'
grep -Fq 'attribute_negative_request' "$SCRIPT" || fail 'negative Store API attribute probe is missing'
grep -Fq '"price_negative_matches" => count($price_negative_rows)' "$SCRIPT" || fail 'negative Store API price diagnostics are missing'
grep -Fq '"attribute_negative_matches" => count($attribute_negative_rows)' "$SCRIPT" || fail 'negative Store API attribute diagnostics are missing'
grep -Fq '.price_negative_matches == 0' "$SCRIPT" || fail 'negative Store API price result is not asserted empty'
grep -Fq '.attribute_negative_matches == 0' "$SCRIPT" || fail 'negative Store API attribute result is not asserted empty'
if grep -Fq 'array_filter($attribute_rows' "$SCRIPT"; then
  fail 'derived-index attribute audit filters unknown rows away'
fi
grep -Fq 'LEFT JOIN {$wpdb->terms}' "$SCRIPT" || fail 'derived-index raw attribute query drops orphan term rows'
grep -Fq '"cap_meta" => $cap_meta' "$SCRIPT" || fail 'derived-index cap lookup diagnostics are missing'
grep -Fq '"tee_meta" => $tee_meta' "$SCRIPT" || fail 'derived-index variable-parent lookup diagnostics are missing'
grep -Fq '"variation_meta" => $variation_meta' "$SCRIPT" || fail 'derived-index variation lookup diagnostics are missing'
grep -Fq '"blue_meta" => $blue_meta' "$SCRIPT" || fail 'derived-index blue-variation lookup diagnostics are missing'
grep -Fq 'normalize_store_value' "$SCRIPT" || fail 'Store API response normalization is missing'
grep -Fq 'get_object_vars' "$SCRIPT" || fail 'Store API stdClass response normalization is missing'
if grep -Fq '$price_data[0]["prices"]["price"]' "$SCRIPT"; then
  fail 'Store API price assertion indexes a possibly-stdClass row directly'
fi
grep -Fq '$zones = array_values(array_map' "$SCRIPT" || fail 'shipping-zone collection is not normalized to a JSON list'
grep -Fq '$categories = $cap ? array_values' "$SCRIPT" || fail 'product-category collection is not normalized to a JSON list'
grep -Fq '$tags = $cap ? array_values' "$SCRIPT" || fail 'product-tag collection is not normalized to a JSON list'
grep -Fq '$shipping_methods = $zone_id ? array_values' "$SCRIPT" || fail 'shipping-method collection is not normalized to a JSON list'
grep -Fq 'assert_deletion_probe_lookup_present' "$SCRIPT" || fail 'pre-delete lookup-root assertion missing'
grep -Fq 'assert_deletion_probe_lookup_present "$DELETION_PROBE_V1_TARGET_ID"' "$SCRIPT" || fail 'target lookup-root survival assertion missing after refusal'
grep -Fq 'assert_woo_catalog 1 5' "$SCRIPT" || fail 'unchanged catalog count after product deletion refusal is missing'
grep -Fq 'target_managed_code_tree_hash' "$SCRIPT" || fail 'full managed code-tree rollback hash missing'
grep -Fq 'source_managed_code_tree_hash' "$SCRIPT" || fail 'repository managed code-tree hash missing'
grep -Fq 'V2_SOURCE_MANAGED_CODE_TREE_HASH' "$SCRIPT" || fail 'full v2 repository/target tree equality assertion missing'
grep -Fq 'V1_TARGET_MANAGED_CODE_TREE_HASH' "$SCRIPT" || fail 'exact v1 managed code-tree equality assertion missing'
grep -Fq 'V1_SOURCE_MANAGED_CODE_TREE_HASH' "$SCRIPT" || fail 'exact v1 source managed code-tree hash is missing'
grep -Fq 'exact v1 source/target managed code tree' "$SCRIPT" || fail 'exact v1 source/target managed code-tree equality assertion missing'
grep -Fq 'deploy_artifact_files' "$SCRIPT" || fail 'deploy receipt inventory binding helper is missing'
grep -Fq 'artifact_for_new_deploy' "$SCRIPT" || fail 'new deploy receipt binding helper is missing'
grep -Fq 'V1_DEPLOY_ARTIFACTS_BEFORE' "$SCRIPT" || fail 'deploy receipt before/after inventory snapshot is missing'
grep -Fq 'comm -13' "$SCRIPT" || fail 'deploy receipt binding does not compute a new-file set difference'
grep -Fq 'V1_DB_DUMP_SHA256' "$SCRIPT" || fail 'pair-local v1 dump SHA-256 binding is missing'
grep -Fq 'pair-local v1 database checkpoint bytes before rollback import' "$SCRIPT" || fail 'pair-local v1 dump hash is not checked immediately before import'
grep -Fq 'target_order_snapshot' "$SCRIPT" || fail 'reusable exact HPOS order snapshot helper missing'
grep -Fq 'assert_target_order_snapshot' "$SCRIPT" || fail 'initial exact HPOS order assertion missing'
grep -Fq 'assert_target_order_unchanged' "$SCRIPT" || fail 'HPOS order transition survival assertion missing'
grep -Fq 'assert_target_order_absent' "$SCRIPT" || fail 'HPOS order rollback absence assertion missing'
grep -Fq 'wc_order_operational_data' "$SCRIPT" || fail 'HPOS operational-row snapshot/absence coverage is missing'
grep -Fq 'wc_order_addresses' "$SCRIPT" || fail 'HPOS address-row snapshot/absence coverage is missing'
grep -Fq 'wc_orders_meta' "$SCRIPT" || fail 'HPOS metadata snapshot/absence coverage is missing'
grep -Fq 'woocommerce_order_itemmeta' "$SCRIPT" || fail 'order-item metadata snapshot coverage is missing'
grep -Fq 'TARGET_ORDER_ITEM_IDS="$(jq -r' "$SCRIPT" || fail 'target order item IDs are not captured from the exact baseline'
grep -Fq 'unique | join(",")' "$SCRIPT" || fail 'target order item ID capture is not normalized to a unique SQL list'
grep -Fq 'target-only HPOS order item ID capture is malformed' "$SCRIPT" || fail 'target order item ID capture does not fail closed on malformed IDs'
grep -Fq 'LEFT JOIN `$order_items_table` AS items' "$SCRIPT" || fail 'rollback absence does not inspect orphaned order-item metadata'
grep -Fq '$order->add_meta_data("_duo_runtime_marker", "target-order-only", true);' "$SCRIPT" || fail 'target order does not seed an exact HPOS metadata marker'
grep -Fq 'target_wp action-scheduler action list --hook=wc-admin_import_orders --args="[$TARGET_ORDER_ID]" --status=pending --format=ids' "$SCRIPT" || fail 'target order does not resolve its exact pending Woo analytics import action'
grep -Fq 'target_wp action-scheduler action run "$TARGET_ORDER_IMPORT_ACTION_ID"' "$SCRIPT" || fail 'target order does not execute its exact Woo analytics import action'
grep -Fq 'target_wp action-scheduler action list --hook=wc-admin_import_orders --args="[$TARGET_ORDER_ID]" --status=complete --format=ids' "$SCRIPT" || fail 'target order does not prove its exact Woo analytics import completed'
if grep -Eq 'action-scheduler[[:space:]]+run|action-scheduler[[:space:]]+action[[:space:]]+run[[:space:]]+\$\(' "$SCRIPT"; then
  fail 'ecommerce grind drains an unbounded Action Scheduler queue'
fi
grep -Fq '200 Target Runtime Way' "$SCRIPT" || fail 'target order does not seed an exact billing address'
grep -Fq '201 Target Fulfillment Way' "$SCRIPT" || fail 'target order does not seed an exact shipping address'
grep -Fq 'assert_extension_runtime_event()' "$SCRIPT" || fail 'extension runtime-row assertion helper is missing'
grep -Fq 'assert_extension_runtime_event_excluded()' "$SCRIPT" || fail 'extension runtime-row canonical exclusion helper is missing'
grep -Fq 'RUNTIME_EVENT_LABEL=' "$SCRIPT" || fail 'deterministic runtime event label is missing'
grep -Fq 'RUNTIME_EVENT_CREATED_AT=' "$SCRIPT" || fail 'deterministic runtime event timestamp is missing'
grep -Fq 'RUNTIME_EVENT_ID="$(target_wp eval' "$SCRIPT" || fail 'runtime event id is not captured from the target insert'
grep -Fq 'Duo Grind runtime v1 event' "$SCRIPT" || fail 'runtime event seed payload is missing'
grep -Fq 'SOURCE_RUNTIME_CUSTOMER_ID=' "$SCRIPT" || fail 'source-only runtime customer seed is missing'
grep -Fq 'SOURCE_RUNTIME_ORDER_ID=' "$SCRIPT" || fail 'source-only runtime order seed is missing'
grep -Fq 'source-customer@example.invalid' "$SCRIPT" || fail 'source-only runtime customer marker is missing'
grep -Fq 'source-order@example.invalid' "$SCRIPT" || fail 'source-only runtime order marker is missing'
grep -Fq 'SOURCE_RUNTIME_EVENT_V2_BASELINE' "$SCRIPT" || fail 'separate source v2 event baseline is missing'
grep -Fq 'SOURCE_RUNTIME_IDENTITY_BASELINE' "$SCRIPT" || fail 'complete source runtime identity baseline is missing'
grep -Fq 'TARGET_RUNTIME_IDENTITY_BASELINE' "$SCRIPT" || fail 'complete target runtime identity baseline is missing'
grep -Fq 'source v2 runtime migration table shape' "$SCRIPT" || fail 'source v1-to-v2 runtime-table migration assertion is missing'
grep -Fq 'v2 source event context default' "$SCRIPT" || fail 'source v2 empty context-default assertion is missing'
grep -Fq "assert_runtime_isolation 'broken v2 migration' 1" "$SCRIPT" || fail 'broken-v2 runtime isolation checkpoint is missing'
grep -Fq "assert_runtime_isolation 'target-only runtime seed' 0" "$SCRIPT" || fail 'target-only runtime sovereignty checkpoint is missing'
grep -Fq "assert_runtime_isolation 'fixed v2 retry' 1" "$SCRIPT" || fail 'fixed-v2 runtime sovereignty checkpoint is missing'
grep -Fq "assert_runtime_isolation 'author product update promote' 1" "$SCRIPT" || fail 'product-update runtime sovereignty checkpoint is missing'
grep -Fq "assert_runtime_isolation 'unsupported Woo product deletion refusal' 1" "$SCRIPT" || fail 'unsupported-product-deletion runtime sovereignty checkpoint is missing'
grep -Fq "assert_runtime_isolation 'v1 checkpoint recovery' 0" "$SCRIPT" || fail 'checkpoint-recovery runtime isolation checkpoint is missing'
grep -Fq "assert_runtime_isolation 'extension removal' 1" "$SCRIPT" || fail 'extension-removal runtime isolation checkpoint is missing'
grep -Fq 'V1_DEACTIVATE_ARTIFACT="$(artifact_for_promote_output "$V1_DEACTIVATE_OUT")"' "$SCRIPT" || fail 'v1 deactivation promote receipt is not output-bound'
grep -Fq 'DRIFT_HEAL_ARTIFACT="$(artifact_for_promote_output "$DRIFT_HEAL_OUT")"' "$SCRIPT" || fail 'code-drift healing promote receipt is not output-bound'
grep -Fq 'REMOVE_ARTIFACT="$(artifact_for_promote_output "$REMOVE_OUT")"' "$SCRIPT" || fail 'extension-removal promote receipt is not output-bound'
grep -Fq 'REMOVE_REVISION="$(jq -r '\''.code.code_revision'\'' "$REMOVE_ARTIFACT")"' "$SCRIPT" || fail 'extension-removal code revision is not bound to its new receipt'
grep -Fq 'assert_receipt "$REMOVE_ARTIFACT" '\''extension removal promote'\'' "$REMOVE_REVISION"' "$SCRIPT" || fail 'extension-removal receipt is checked against its newly published code revision'
grep -Fq '[ "$REMOVE_REVISION" != "$V2_REVISION" ]' "$SCRIPT" || fail 'extension-removal code revision is not required to differ from v2'
grep -Fq 'RESTORED_ARTIFACT="$(artifact_for_promote_output "$RESTORE_OUT")"' "$SCRIPT" || fail 'rollback promote receipt is not output-bound'
grep -Fq 'assert_runtime_state_excluded' "$SCRIPT" || fail 'generated-state runtime exclusion helper is missing'
grep -Fq 'assert_env_secret_isolation' "$SCRIPT" || fail 'repeated env-secret isolation helper is missing'
grep -Fq 'source-only-synthetic-secret' "$SCRIPT" || fail 'source env-secret exclusion marker is missing'
grep -Fq 'target-only-synthetic-secret' "$SCRIPT" || fail 'target env-secret exclusion marker is missing'
grep -Fq 'assert_source_runtime_absent_from_target' "$SCRIPT" || fail 'source runtime absence-from-target helper is missing'
grep -Fq 'assert_target_runtime_absent_from_source' "$SCRIPT" || fail 'target runtime absence-from-source helper is missing'
grep -Fq 'source customer absent from target' "$SCRIPT" || fail 'source customer absence-from-target assertion is missing'
grep -Fq 'source order absent from target' "$SCRIPT" || fail 'source order absence-from-target assertion is missing'
grep -Fq 'target customer absent from source' "$SCRIPT" || fail 'target customer absence-from-source assertion is missing'
grep -Fq 'target order absent from source' "$SCRIPT" || fail 'target order absence-from-source assertion is missing'
grep -Fq 'runtime-customer@example.invalid' "$SCRIPT" || fail 'target-only runtime customer marker is missing'
grep -Fq 'runtime-only@example.invalid' "$SCRIPT" || fail 'target-only runtime order marker is missing'
grep -Fq "assert_store_api_http 1499 'v1 target runtime setup'" "$SCRIPT" || fail 'external Store API v1 checkpoint is missing'
grep -Fq "assert_store_api_http 1499 'fixed v2 retry'" "$SCRIPT" || fail 'external Store API v2 checkpoint is missing'
grep -Fq "assert_store_api_http 1649 'author product update promote'" "$SCRIPT" || fail 'external Store API product-update checkpoint is missing'
grep -Fq "assert_store_api_http 1649 'unsupported Woo product deletion refusal'" "$SCRIPT" || fail 'external Store API deletion-refusal checkpoint is missing'
if grep -Fq 'WooCommerceContract::rebuild' "$SCRIPT"; then
  fail 'ecommerce scenario invokes the legacy whole-catalog Woo projection outside automatic bounded authority'
fi
grep -Fq "assert_extension_runtime_event 0 \"\" 'v1 runtime row before checkpoint'" "$SCRIPT" || fail 'v1 runtime-row checkpoint assertion is missing'
grep -Fq "assert_extension_runtime_event 1 \"\" 'broken v2 migration runtime row'" "$SCRIPT" || fail 'broken v2 runtime-row migration assertion is missing'
grep -Fq "assert_extension_runtime_event 0 \"\" 'v1 runtime row after checkpoint restore'" "$SCRIPT" || fail 'checkpoint recovery runtime-row assertion is missing'
grep -Fq 'assert_eq "$EXTENSION_INACTIVE_ACTIVE_PLUGINS_JSON" "$(active_plugins_json)"' "$SCRIPT" || fail 'checkpoint recovery does not assert the exact intentionally inactive extension state'
grep -Fq "assert_extension_runtime_event 1 \"\" 'fixed v2 migrated runtime row'" "$SCRIPT" || fail 'fixed v2 runtime-row migration assertion is missing'
grep -Fq "assert_extension_runtime_event 0 \"\" 'exact v1 runtime row after rollback'" "$SCRIPT" || fail 'final v1 runtime-row rollback assertion is missing'
grep -Fq 'runtime event total row count' "$SCRIPT" || fail 'runtime-row exact total count assertion is missing'
grep -Fq 'runtime event leaked into canonical state' "$SCRIPT" || fail 'runtime-row canonical-state exclusion is not enforced'
grep -Fq '"product_id" => (int) $item->get_product_id()' "$SCRIPT" || fail 'HPOS order line-item product identity assertion missing'
grep -Fq '"quantity" => (int) $item->get_quantity()' "$SCRIPT" || fail 'HPOS order line-item quantity assertion missing'
grep -Fq '"total" => (string) $item->get_total()' "$SCRIPT" || fail 'HPOS order line-item total assertion missing'
grep -Fq 'target_tee_snapshot()' "$SCRIPT" || fail 'deterministic target tee snapshot helper missing'
grep -Fq 'WHERE meta_key = %s AND meta_value = %s LIMIT 1' "$SCRIPT" || fail 'target tee snapshot does not parameterize both metadata identity inputs'
grep -Fq '"_duo_uuid",' "$SCRIPT" || fail 'target tee snapshot does not bind the exact portable identity key'
if grep -Fq "meta_key = '_duo_uuid'" "$SCRIPT"; then
  fail 'target tee snapshot embeds a shell-unsafe single-quoted SQL literal inside its single-quoted PHP program'
fi
grep -Fq 'assert_target_tee_unchanged()' "$SCRIPT" || fail 'target tee conflict snapshot equality assertion missing'
grep -Fq 'TEE_UUID=' "$SCRIPT" || fail 'canonical tee UUID binding missing'
grep -Fq 'CONFLICT_PRODUCT_PATH=' "$SCRIPT" || fail 'canonical tee path binding missing'
grep -Fq 'Canon::parse_post_file(Duo\Canon::read_file($path))' "$SCRIPT" || fail 'canonical tee branch edit does not parse the Markdown post envelope'
grep -Fq 'Duo\Canon::write_file($path, Duo\Canon::post_file($front, $body))' "$SCRIPT" || fail 'canonical tee branch edit does not preserve/re-encode the Markdown post envelope'
if grep -Fq 'canonicalize_json "$PRODUCT_FILE"' "$SCRIPT"; then
  fail 'canonical tee Markdown record is incorrectly routed through the plain JSON canonicalizer'
fi
grep -Fq 'length == 1 and .[0].uuid == $uuid and .[0].path == $path' "$SCRIPT" || fail 'conflict plan is not bound to exactly the tee UUID/path'
grep -Fq 'CONFLICT_PLAN="$(plan_json)"' "$SCRIPT" || fail 'conflict plan does not keep machine-readable JSON isolated on stdout'
if grep -Fq 'CONFLICT_PLAN="$(plan_json 2>&1' "$SCRIPT"; then
  fail 'conflict plan contaminates machine-readable JSON with Docker/WP-CLI stderr'
fi
grep -Fq '"authored_hash"' "$SCRIPT" || fail 'target tee authored-content hash diagnostic missing'
grep -Fq '"meta" => $meta' "$SCRIPT" || fail 'target tee authored metadata snapshot missing'
grep -Fq '"terms" => $terms' "$SCRIPT" || fail 'target tee term-relation snapshot missing'
grep -Fq 'wp_duo_state WHERE uuid' "$SCRIPT" || fail 'tee state ledger hash invariant missing'
grep -Fq 'CONFLICT_APPLIED_REVISION_BEFORE' "$SCRIPT" || fail 'failed conflict apply applied-revision invariant missing'
grep -Fq 'CONFLICT_APPLY_PROGRESS_BEFORE' "$SCRIPT" || fail 'failed conflict apply progress-marker invariant missing'
grep -Fq 'target-only order after conflict refusal' "$SCRIPT" || fail 'runtime order conflict-refusal invariant missing'
grep -Fq 'target-only stock after conflict refusal' "$SCRIPT" || fail 'runtime stock conflict-refusal invariant missing'
PREFLIGHT_BLOCK="$(sed -n '/say "compile preflight failure/,/say "extension lifecycle boundary/p' "$SCRIPT")"
grep -Fq 'deploy phase: promotion-begin' <<<"$PREFLIGHT_BLOCK" || fail 'compile preflight guard uses the wrong phase prefix'
if grep -Fq 'promote phase: promotion-begin' <<<"$PREFLIGHT_BLOCK"; then
  fail 'compile preflight guard uses the promote phase prefix for deploy output'
fi
grep -Fq 'PREFLIGHT_MANAGED_CODE_TREE_BEFORE' <<<"$PREFLIGHT_BLOCK" || fail 'compile preflight does not snapshot the full managed tree'
grep -Fq 'PREFLIGHT_SESSION_BEFORE' <<<"$PREFLIGHT_BLOCK" || fail 'compile preflight does not snapshot the promotion session'
grep -Fq 'PREFLIGHT_LOCK_BEFORE' <<<"$PREFLIGHT_BLOCK" || fail 'compile preflight does not snapshot the promotion lease'
grep -Fq '.tmp-v1-code/wp-content/themes/duo-commerce-parent' "$SCRIPT" || fail 'v1 parent theme recovery staging missing'
grep -Fq '.tmp-v1-code/wp-content/themes/duo-commerce-child' "$SCRIPT" || fail 'v1 child theme recovery staging missing'

# The first code opt-in must be a real author workflow.  Later jq edits are
# deliberate lifecycle/drift fixtures, but the opt-in block itself may not
# synthesize managed active_plugins/theme state by hand.
OPT_IN="$(sed -n '/say "opt into code/,/say "publish target-only env registry/p' "$SCRIPT")"
grep -Fq 'source_wp plugin activate "$EXT_SLUG"' <<<"$OPT_IN" || fail 'opt-in block does not activate the extension'
grep -Fq 'source_wp duo capture --repo=/siterepo' <<<"$OPT_IN" || fail 'opt-in block does not capture author state'
if grep -Fq '.records.active_plugins.value' <<<"$OPT_IN"; then
  fail 'opt-in block hand-edits active_plugins instead of capturing author lifecycle'
fi
if grep -Fq 'V1_INPUTS="$SITE/' "$SCRIPT"; then
  fail 'rollback inputs are stored inside the repository checkout'
fi
grep -Fq 'cp -a "$SITE/state" "$V1_INPUTS/state"' "$SCRIPT" || fail 'v1 rollback does not preserve the full canonical state tree outside the checkout'
grep -Fq 'cp -a "$V1_INPUTS/state" "$SITE/state"' "$SCRIPT" || fail 'v1 rollback does not restore the full canonical state tree'

grep -Fq 'source_wp menu create '\''Duo Grind Primary'\'' --porcelain' "$SCRIPT" || fail 'authored navigation-menu seed is missing'
grep -Fq 'source_wp menu item add-post "$MENU_ID" "$CAP_ID"' "$SCRIPT" || fail 'menu product-reference seed is missing'
grep -Fq 'source_wp menu item add-custom "$MENU_ID" '\''Duo Grind Support'\''' "$SCRIPT" || fail 'menu target-bound custom-link seed is missing'
grep -Fq 'assert_ecommerce_menu()' "$SCRIPT" || fail 'menu convergence helper is missing'
grep -Fq '.rows[0].object == "product" and .rows[0].object_id == .cap_id' "$SCRIPT" || fail 'menu helper does not bind the product item to the target product identity'
grep -Fq '.rows[1].type == "custom" and .rows[1].url == .support_url' "$SCRIPT" || fail 'menu helper does not verify target-bound custom URL materialization'
grep -Fq "assert_ecommerce_menu 'v1 target apply'" "$SCRIPT" || fail 'v1 menu round-trip assertion is missing'
grep -Fq "assert_ecommerce_menu 'exact v1 rollback'" "$SCRIPT" || fail 'exact-restore menu assertion is missing'

ACF_BLOCK="$(awk '/\.tmp-seed-acf-commerce\.php.*<<PHP/{inside=1; next} inside && /^PHP$/{exit} inside{print}' "$SCRIPT")"
ACF_UNESCAPED="$(sed 's/\\\$//g' <<<"$ACF_BLOCK" | grep -oE '\$[A-Za-z_][A-Za-z0-9_]*' | sort -u || true)"
[ "$ACF_UNESCAPED" = '$CAP_ID' ] \
  || fail "unquoted ACF heredoc has unexpected shell-expanded PHP variables: $ACF_UNESCAPED"

grep -Fq 'Requires Plugins: woocommerce' "$FIXTURE/v1/wp-content/plugins/duo-commerce-extension/duo-commerce-extension.php" || fail 'v1 dependency header missing'
grep -Fq 'Duo Commerce Extension reviewed v2 activation failure' "$FIXTURE/v2/broken/duo-commerce-extension.php" || fail 'broken activation fixture missing'
grep -Fq 'migrate:v1-to-v2:' "$FIXTURE/v2/fixed/duo-commerce-extension.php" || fail 'fixed v2 migration trace missing'

pass 'ecommerce grind syntax, fixture, pin, lifecycle, dependency, rollback, and derived-index contracts pass offline'
