#!/usr/bin/env bash
# Production proof for the two endpoint switches. The generic harness proves
# deploy/apply and canonical recapture; this hook proves the plugin-owned REST
# and XML-RPC request behavior, target-only state preservation, lifecycle
# reactivation, independent toggles, and a clean restored state.
set -euo pipefail

REST_BODY=""
XMLRPC_BODY=""
cleanup_requests() {
  [ -z "$REST_BODY" ] || rm -f "$REST_BODY"
  [ -z "$XMLRPC_BODY" ] || rm -f "$XMLRPC_BODY"
}
trap cleanup_requests EXIT

rest_request() {
  local post_id="$1" payload
  REST_BODY=$(mktemp "${TMPDIR:-/tmp}/wprism-disable-comments-rest.XXXXXX")
  payload=$(printf '{"post":%s,"author_name":"WPrism","author_email":"probe@example.test","content":"endpoint probe"}' "$post_id")
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
  if printf '%s\n' "$REST_JSON" | jq -e '.code == "rest_comment_disabled"' >/dev/null 2>&1; then
    fail "REST endpoint still carried Disable Comments' refusal after its REST toggle was disabled: $REST_JSON"
  fi
  pass "REST endpoint no longer carries Disable Comments' refusal when its toggle is disabled"
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

assert_xmlrpc_blocked() {
  xmlrpc_method_list
  ! grep -Fq 'wp.newComment' <<<"$XMLRPC_TEXT" \
    || fail "XML-RPC method list still exposes wp.newComment while blocked"
  grep -Fq 'wp.getComments' <<<"$XMLRPC_TEXT" \
    || fail "XML-RPC method list lost wp.getComments while only new comments are blocked"
  pass "XML-RPC removes wp.newComment while preserving wp.getComments"
}

assert_xmlrpc_enabled() {
  xmlrpc_method_list
  grep -Fq 'wp.newComment' <<<"$XMLRPC_TEXT" \
    || fail "XML-RPC method list did not restore wp.newComment after its toggle was disabled"
  pass "XML-RPC restores wp.newComment when its toggle is disabled"
}

TARGET_POST=$(wp_conf2 post list --post_type=post --name=disable-comments-endpoint-fixture --field=ID)
require_observed_nonempty "Disable Comments target post identity" "$TARGET_POST"

assert_rest_blocked "$TARGET_POST"
assert_xmlrpc_blocked

# The target-only rows are deliberately written after deploy and before Apply;
# the option sub-key merge must preserve them while replacing only the two
# authored endpoint switches.
TARGET_SIBLING=$(wp_conf2 eval '
  $options = (array) get_option("disable_comments_options", []);
  echo isset($options["disable_comments_target_only_probe"]) ? $options["disable_comments_target_only_probe"] : "";
')
[ "$TARGET_SIBLING" = "preserve-me" ] \
  || fail "Apply did not preserve the target-only shared-option sibling: $TARGET_SIBLING"
[ "$(wp_conf2 option get disable_comments_blocked_stats_rest)" = "37" ] \
  || fail "Apply did not preserve the runtime blocked-attempt option"
[ "$(wp_conf2 option get disable_comments_review_trigger)" = "123" ] \
  || fail "Apply did not preserve the runtime review-trigger option"
[ "$(wp_conf2 user meta get 1 disable_comments_review_dismissed)" = "target-only-review" ] \
  || fail "Apply did not preserve target-only review-dismissal user meta"
pass "Apply preserves target-only shared-option, runtime-option, and user-meta rows"

# Deactivation must leave the stored endpoint switches and core post intact;
# deploy must reactivate the exact code and restore both native request gates.
wp_conf2 plugin deactivate disable-comments >/dev/null
xmlrpc_method_list
grep -Fq 'wp.newComment' <<<"$XMLRPC_TEXT" \
  || fail "plugin deactivation did not restore wp.newComment"
rest_request "$TARGET_POST"
if printf '%s\n' "$REST_JSON" | jq -e '.code == "rest_comment_disabled"' >/dev/null 2>&1; then
  fail "plugin deactivation left the REST endpoint blocked"
fi
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
