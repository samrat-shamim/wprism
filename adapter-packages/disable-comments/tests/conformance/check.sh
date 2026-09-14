#!/usr/bin/env bash
# Production proof for the two endpoint switches. The generic harness proves
# deploy/apply and canonical recapture; this hook proves the plugin-owned REST
# and XML-RPC request behavior, target-only state preservation, lifecycle
# reactivation, independent toggles, and a clean restored state.
set -euo pipefail

REST_BODY=""
XMLRPC_BODY=""
REST_PROBE_CONTENT='wprism-disable-comments-rest-probe'
XMLRPC_PROBE_CONTENT='wprism-disable-comments-xmlrpc-probe'
disable_comments_assert_runtime() {
  local cli="$1"
  [ "$($cli option get disable_comment_version)" = '2.9.0' ] \
    || fail 'Apply did not preserve disable_comment_version'
  [ "$($cli option get disable_comments_blocked_since)" = '2026-09-14 09:00:00' ] \
    || fail 'Apply did not preserve disable_comments_blocked_since'
  [ "$($cli option get disable_comments_blocked_stats_comment)" = '11' ] \
    || fail 'Apply did not preserve disable_comments_blocked_stats_comment'
  [ "$($cli option get disable_comments_blocked_stats_rest)" = '37' ] \
    || fail 'Apply did not preserve disable_comments_blocked_stats_rest'
  [ "$($cli option get disable_comments_blocked_stats_trackback)" = '19' ] \
    || fail 'Apply did not preserve disable_comments_blocked_stats_trackback'
  [ "$($cli option get disable_comments_review_trigger)" = '123' ] \
    || fail 'Apply did not preserve disable_comments_review_trigger'
  [ "$($cli user meta get 1 disable_comments_review_dismissed)" = 'target-only-review' ] \
    || fail 'Apply did not preserve disable_comments_review_dismissed'
}
cleanup_rest_probe_comment() {
  if declare -F wp_conf2 >/dev/null 2>&1; then
    wp_conf2 eval '
      global $wpdb;
      $comment_id = $wpdb->get_var($wpdb->prepare(
          "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_content = %s ORDER BY comment_ID DESC LIMIT 1",
          "wprism-disable-comments-rest-probe"
      ));
      if ($comment_id !== null) {
          wp_delete_comment((int) $comment_id, true);
      }
    ' >/dev/null 2>&1 || true
  fi
}
cleanup_xmlrpc_probe_comment() {
  if declare -F wp_conf2 >/dev/null 2>&1; then
    wp_conf2 eval '
      global $wpdb;
      $comment_id = $wpdb->get_var($wpdb->prepare(
          "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_content = %s ORDER BY comment_ID DESC LIMIT 1",
          "wprism-disable-comments-xmlrpc-probe"
      ));
      if ($comment_id !== null) {
          wp_delete_comment((int) $comment_id, true);
      }
    ' >/dev/null 2>&1 || true
  fi
}
cleanup_requests() {
  [ -z "$REST_BODY" ] || rm -f "$REST_BODY"
  [ -z "$XMLRPC_BODY" ] || rm -f "$XMLRPC_BODY"
  cleanup_rest_probe_comment
  cleanup_xmlrpc_probe_comment
}
trap cleanup_requests EXIT

rest_request() {
  local post_id="$1" payload
  REST_BODY=$(mktemp "${TMPDIR:-/tmp}/wprism-disable-comments-rest.XXXXXX")
  payload=$(printf '{"post":%s,"author_name":"WPrism","author_email":"probe@example.test","content":"%s"}' "$post_id" "$REST_PROBE_CONTENT")
  REST_CODE=$(curl --max-time 20 -sS -X POST \
    -H 'Content-Type: application/json' \
    --data "$payload" \
    -o "$REST_BODY" -w '%{http_code}' \
    "http://localhost:${CONF2_PORT}/wp-json/wp/v2/comments") \
    || fail "REST endpoint request failed before an HTTP response"
  REST_JSON=$(cat "$REST_BODY")
}

assert_rest_blocked() {
  local post_id="$1"
  rest_request "$post_id"
  [ "$REST_CODE" = "403" ] \
    || fail "REST endpoint was not blocked with HTTP 403 (status=$REST_CODE body=$REST_JSON)"
  printf '%s\n' "$REST_JSON" | jq -e '.code == "rest_comment_disabled" and .data.status == 403' >/dev/null \
    || fail "REST endpoint returned the wrong native refusal: $REST_JSON"
  pass "REST comment creation is refused by Disable Comments with rest_comment_disabled"
}

assert_rest_not_plugin_blocked() {
  local post_id="$1"
  rest_request "$post_id"
  [ "$REST_CODE" = "201" ] \
    || fail "REST endpoint did not return WordPress' native comment creation response after its REST toggle was disabled (status=$REST_CODE body=$REST_JSON)"
  printf '%s\n' "$REST_JSON" | jq -e --argjson post "$post_id" '
    (.id | numbers) and .post == $post and .content.raw == "wprism-disable-comments-rest-probe"
  ' >/dev/null \
    || fail "REST endpoint returned an unexpected non-plugin response after its REST toggle was disabled: $REST_JSON"
  cleanup_rest_probe_comment
  pass "REST endpoint returns WordPress' native comment creation response when its toggle is disabled"
}

xmlrpc_request() {
  local request="$1"
  XMLRPC_BODY=$(mktemp "${TMPDIR:-/tmp}/wprism-disable-comments-xmlrpc.XXXXXX")
  XMLRPC_CODE=$(curl --max-time 20 -sS \
    -H 'Content-Type: text/xml' \
    --data "$request" \
    -o "$XMLRPC_BODY" -w '%{http_code}' \
    "http://localhost:${CONF2_PORT}/xmlrpc.php") \
    || fail "XML-RPC request failed before an HTTP response"
  XMLRPC_TEXT=$(cat "$XMLRPC_BODY")
}

xmlrpc_method_list() {
  xmlrpc_request '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName><params/></methodCall>'
  [ "$XMLRPC_CODE" = "200" ] || fail "XML-RPC system.listMethods returned HTTP $XMLRPC_CODE"
}

xmlrpc_new_comment_request() {
  local post_id="$1" request
  printf -v request '<?xml version="1.0"?><methodCall><methodName>wp.newComment</methodName><params><param><value><int>1</int></value></param><param><value><string>admin</string></value></param><param><value><string>admin</string></value></param><param><value><int>%s</int></value></param><param><value><struct><member><name>content</name><value><string>%s</string></value></member><member><name>author</name><value><string>WPrism</string></value></member><member><name>author_email</name><value><string>probe@example.test</string></value></member></struct></value></param></params></methodCall>' "$post_id" "$XMLRPC_PROBE_CONTENT"
  xmlrpc_request "$request"
}

assert_xmlrpc_blocked() {
  xmlrpc_method_list
  ! grep -Fq 'wp.newComment' <<<"$XMLRPC_TEXT" \
    || fail "XML-RPC method list still exposes wp.newComment while blocked"
  grep -Fq 'wp.getComments' <<<"$XMLRPC_TEXT" \
    || fail "XML-RPC method list lost wp.getComments while only new comments are blocked"
  xmlrpc_new_comment_request "$TARGET_POST"
  [ "$XMLRPC_CODE" = "200" ] \
    || fail "XML-RPC blocked wp.newComment returned HTTP $XMLRPC_CODE"
  grep -Fq '<fault>' <<<"$XMLRPC_TEXT" \
    || fail "XML-RPC blocked wp.newComment unexpectedly succeeded: $XMLRPC_TEXT"
  grep -Fq '<int>-32601</int>' <<<"$XMLRPC_TEXT" \
    || fail "XML-RPC blocked wp.newComment returned the wrong native refusal: $XMLRPC_TEXT"
  pass "XML-RPC refuses wp.newComment while preserving wp.getComments"
}

assert_xmlrpc_enabled() {
  xmlrpc_method_list
  grep -Fq 'wp.newComment' <<<"$XMLRPC_TEXT" \
    || fail "XML-RPC method list did not restore wp.newComment after its toggle was disabled"
  xmlrpc_new_comment_request "$TARGET_POST"
  [ "$XMLRPC_CODE" = "200" ] \
    || fail "XML-RPC enabled wp.newComment returned HTTP $XMLRPC_CODE"
  ! grep -Fq '<fault>' <<<"$XMLRPC_TEXT" \
    || fail "XML-RPC enabled wp.newComment returned a fault: $XMLRPC_TEXT"
  grep -Eq '<int>[1-9][0-9]*</int>' <<<"$XMLRPC_TEXT" \
    || fail "XML-RPC enabled wp.newComment did not return a comment ID: $XMLRPC_TEXT"
  cleanup_xmlrpc_probe_comment
  pass "XML-RPC restores callable wp.newComment when its toggle is disabled"
}

TARGET_POST=$(wp_conf2 post list --post_type=post --name=disable-comments-endpoint-fixture --field=ID)
require_observed_nonempty "Disable Comments target post identity" "$TARGET_POST"

# The target-only rows are deliberately written after deploy and before Apply;
# the option sub-key merge must preserve them while replacing only the two
# authored endpoint switches.
TARGET_SIBLING=$(wp_conf2 eval '
  $options = (array) get_option("disable_comments_options", []);
  echo isset($options["disable_comments_target_only_probe"]) ? $options["disable_comments_target_only_probe"] : "";
')
[ "$TARGET_SIBLING" = "preserve-me" ] \
  || fail "Apply did not preserve the target-only shared-option sibling: $TARGET_SIBLING"
disable_comments_assert_runtime wp_conf2
pass "Apply preserves target-only shared-option, runtime-option, and user-meta rows"

assert_rest_blocked "$TARGET_POST"
assert_xmlrpc_blocked

# Deactivation must leave the stored endpoint switches and core post intact;
# deploy must reactivate the exact code and restore both native request gates.
wp_conf2 plugin deactivate disable-comments >/dev/null
assert_xmlrpc_enabled
assert_rest_not_plugin_blocked "$TARGET_POST"
wp_conf2 wprism deploy --repo=/siterepo --format=json >/dev/null
wp_conf2 plugin is-active disable-comments >/dev/null \
  || fail "deploy did not reactivate Disable Comments"
assert_rest_blocked "$TARGET_POST"
assert_xmlrpc_blocked
pass "deactivation reopens both request paths and deploy reactivation restores them"

# Exercise each native toggle independently after the WPrism result has been
# verified. The endpoint settings are reversible booleans, not deletion state.
wp_conf2 disable-comments settings --xmlrpc --rest-api=false >/dev/null
assert_rest_not_plugin_blocked "$TARGET_POST"
assert_xmlrpc_blocked
wp_conf2 disable-comments settings --xmlrpc=false --rest-api >/dev/null
assert_rest_blocked "$TARGET_POST"
assert_xmlrpc_enabled
wp_conf2 disable-comments settings --xmlrpc --rest-api >/dev/null
assert_rest_blocked "$TARGET_POST"
assert_xmlrpc_blocked
pass "REST and XML-RPC endpoint switches are independently reversible through the native writer"
