#!/usr/bin/env bash
# Production-oriented WPS Hide Login proof. The generic harness already
# proves clean apply and byte-identical recapture; this hook adds real request
# routing, stale rewrite independence, lifecycle recovery, conflicts,
# authorized option deletion, hostile data, and permalink-mode boundaries.
set -euo pipefail

observe_wps_hide_login() {
  local side="$1" repo_var file out
  case "$side" in
    conf1) repo_var="${CONF_REPO1:-siterepo/conf1}" ;;
    conf2) repo_var="${CONF_REPO2:-siterepo/conf2}" ;;
    *) fail "invalid WPS Hide Login observation side: $side" ;;
  esac
  read -r -d '' OBSERVE_PHP <<'PHPEOF' || true
<?php
$rules = get_option('rewrite_rules');
echo wp_json_encode([
    'login' => get_option('whl_page', null),
    'login_url' => wp_login_url(),
    'lostpassword_url' => wp_lostpassword_url(),
    'neighbor' => get_option('wps-hide-login-target-runtime-probe', null),
    'redirect' => get_option('whl_redirect_admin', null),
    'registration_url' => wp_registration_url(),
    'rewrite_hash' => hash('sha256', maybe_serialize($rules)),
    'rewrite_probe_hash' => get_option('wps-hide-login-target-rewrite-hash', null),
    'runtime' => get_option('whl_redirect', null),
    'sentinel' => is_array($rules) && array_key_exists('^target-only-wps-route/?$', $rules),
    'site_login_url' => site_url('wp-login.php'),
    'sanitizers' => [
        'empty' => sanitize_title_with_dashes('   '),
        'long_length' => strlen(sanitize_title_with_dashes(str_repeat('A', 240))),
        'path' => sanitize_title_with_dashes('login/foo'),
        'traversal' => sanitize_title_with_dashes('../login'),
        'unicode' => sanitize_title_with_dashes("WPrism Login 東京 🚀"),
        'wp_login' => sanitize_title_with_dashes('wp-login.php'),
    ],
], JSON_UNESCAPED_SLASHES);
PHPEOF
  file="$repo_var/.tmp-wps-hide-login-observe.php"
  printf '%s' "$OBSERVE_PHP" > "$file"
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file /siterepo/.tmp-wps-hide-login-observe.php)
  else
    out=$(wp_conf2 eval-file /siterepo/.tmp-wps-hide-login-observe.php)
  fi
  rm -f "$file"
  require_observed_nonempty "WPS Hide Login $side runtime observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

WPS_BODY=""
WPS_HEADERS=""
cleanup_wps_request() {
  [ -z "$WPS_BODY" ] || rm -f "$WPS_BODY"
  [ -z "$WPS_HEADERS" ] || rm -f "$WPS_HEADERS"
}
trap cleanup_wps_request EXIT

wps_request() { # <GET|POST> <path> [form data]
  local method="$1" path="$2" data="${3:-}" url
  cleanup_wps_request
  WPS_BODY=$(mktemp "${TMPDIR:-/tmp}/wprisms-body.XXXXXX")
  WPS_HEADERS=$(mktemp "${TMPDIR:-/tmp}/wprisms-headers.XXXXXX")
  url="http://localhost:${CONF2_PORT}${path}"
  if [ "$method" = POST ]; then
    WPS_CODE=$(curl --path-as-is --max-time 20 -sS -X POST \
      -H 'Content-Type: application/x-www-form-urlencoded' --data "$data" \
      -D "$WPS_HEADERS" -o "$WPS_BODY" -w '%{http_code}' "$url") \
      || fail "WPS Hide Login POST request failed before HTTP response: $url"
  else
    WPS_CODE=$(curl --path-as-is --max-time 20 -sS \
      -D "$WPS_HEADERS" -o "$WPS_BODY" -w '%{http_code}' "$url") \
      || fail "WPS Hide Login GET request failed before HTTP response: $url"
  fi
  WPS_LOCATION=$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/\r$/, ""); print substr($0, 11) }' "$WPS_HEADERS" | tail -1)
}

assert_wps_routes() { # <login slug> <redirect slug>
  local login="$1" redirect="$2"

  wps_request GET "/${login}/"
  [ "$WPS_CODE" = 200 ] && grep -Fq 'id="loginform"' "$WPS_BODY" \
    && grep -Fq "/${login}/" "$WPS_BODY" \
    || fail "custom login route /$login/ did not serve its native login form (status=$WPS_CODE)"

  wps_request GET "/${login}"
  [[ "$WPS_CODE" =~ ^30[1278]$ ]] && [ "$WPS_LOCATION" = "http://localhost:${CONF2_PORT}/${login}/" ] \
    || fail "custom login route without trailing slash did not canonicalize exactly (status=$WPS_CODE location=${WPS_LOCATION:-<none>})"

  wps_request GET "/${login}/?action=lostpassword"
  [ "$WPS_CODE" = 200 ] && grep -Fq 'id="lostpasswordform"' "$WPS_BODY" \
    && grep -Fq "/${login}/" "$WPS_BODY" \
    || fail "custom lost-password route did not serve its native form (status=$WPS_CODE)"

  wps_request POST "/${login}/" 'log=wprism-no-such-user&pwd=wrong&wp-submit=Log+In&redirect_to=%2Fwp-admin%2F&testcookie=1'
  [ "$WPS_CODE" = 200 ] && grep -Fq 'id="loginform"' "$WPS_BODY" \
    && grep -Fq 'login_error' "$WPS_BODY" \
    || fail "custom login POST did not remain on the plugin-routed login form with a native error (status=$WPS_CODE)"

  wps_request GET '/wp-login.php'
  [ "$WPS_CODE" = 404 ] && ! grep -Fq 'id="loginform"' "$WPS_BODY" \
    && ! grep -Fq "/${login}/" "$WPS_BODY" \
    || fail "direct wp-login.php exposed a login form or the custom route (status=$WPS_CODE)"

  wps_request GET '/wp-login%2Ephp'
  [ "$WPS_CODE" = 404 ] && ! grep -Fq 'id="loginform"' "$WPS_BODY" \
    && ! grep -Fq "/${login}/" "$WPS_BODY" \
    || fail "URL-encoded wp-login.php bypassed hiding or leaked the custom route (status=$WPS_CODE)"

  wps_request GET '/wp-register.php'
  [ "$WPS_CODE" = 404 ] && ! grep -Fq 'id="loginform"' "$WPS_BODY" \
    || fail "legacy wp-register.php exposed an authentication form (status=$WPS_CODE)"

  wps_request GET '/wp-admin/'
  [ "$WPS_CODE" = 302 ] && [ "$WPS_LOCATION" = "http://localhost:${CONF2_PORT}/${redirect}/" ] \
    || fail "anonymous wp-admin did not redirect to /$redirect/ (status=$WPS_CODE location=${WPS_LOCATION:-<none>})"

  wps_request GET '/wp-admin/options.php'
  [ "$WPS_CODE" = 302 ] && [ "$WPS_LOCATION" = "http://localhost:${CONF2_PORT}/${redirect}/" ] \
    || fail "anonymous wp-admin/options.php escaped the configured redirect (status=$WPS_CODE location=${WPS_LOCATION:-<none>})"

  wps_request GET '/wps-hide-login-public-control/'
  [ "$WPS_CODE" = 200 ] && grep -Fq 'wps-hide-login-public-marker' "$WPS_BODY" \
    || fail "ordinary public rewrite routing broke while the hidden login route was active (status=$WPS_CODE)"
}

save_wps_profile() { # <conf1|conf2> <initial|reinstall|repository|target>
  local side="$1" profile="$2" login redirect eval_code
  case "$profile" in
    initial) login='wprism-login'; redirect='wprism-missing' ;;
    reinstall) login='recovered-login'; redirect='recovered-missing' ;;
    repository) login='branch-login'; redirect='branch-missing' ;;
    target) login='hostile-login'; redirect='hostile-missing' ;;
    *) fail "unknown WPS Hide Login profile: $profile" ;;
  esac
  eval_code="update_option('whl_page', sanitize_title_with_dashes('$login')); update_option('whl_redirect_admin', sanitize_title_with_dashes('$redirect'));"
  if [ "$side" = conf1 ]; then
    wp_conf1 eval "$eval_code flush_rewrite_rules(true);" >/dev/null
  elif [ "$side" = conf2 ]; then
    wp_conf2 eval "$eval_code" >/dev/null
  else
    fail "invalid WPS Hide Login profile side: $side"
  fi
}

install_wps_rewrite_probe() {
  wp_conf2 eval '
    $rules = get_option("rewrite_rules");
    if (!is_array($rules) || !$rules) { throw new RuntimeException("rewrite probe requires existing rules"); }
    $rules = ["^target-only-wps-route/?$" => "index.php?wps_target_only=1"] + $rules;
    update_option("rewrite_rules", $rules);
    update_option("wps-hide-login-target-rewrite-hash", hash("sha256", maybe_serialize(get_option("rewrite_rules"))));
  ' >/dev/null
}

INITIAL=$(observe_wps_hide_login conf2)
printf '%s\n' "$INITIAL" | jq -e --arg port "$CONF2_PORT" '
  .login == "wprism-login" and .redirect == "wprism-missing" and
  .runtime == "target-only-runtime-marker" and
  .neighbor == "target-only-neighbor" and .sentinel == true and
  .rewrite_hash == .rewrite_probe_hash and
  (.login_url | endswith("/wprism-login/")) and
  (.site_login_url | endswith("/wprism-login/")) and
  (.lostpassword_url | contains("/wprism-login/")) and
  (.registration_url | contains("/wprism-login/")) and
  .sanitizers.empty == "" and .sanitizers.long_length == 200 and
  .sanitizers.path == "loginfoo" and .sanitizers.traversal == "login" and
  .sanitizers.unicode == "wprism-login-%e6%9d%b1%e4%ba%ac-%f0%9f%9a%80" and
  .sanitizers.wp_login == "wp-login-php"
' >/dev/null || fail "WPS Hide Login target APIs did not consume the applied settings without rewriting target state: $INITIAL"
assert_wps_routes wprism-login wprism-missing
pass "raw option apply converges both routes while preserving stale rewrite bytes, runtime state, a neighbor, login APIs, GET/POST forms, old-path refusals, and public routing"

# Prove a successful authenticated journey through the custom path, then the
# logged-in wp-admin branch that anonymous requests above cannot reach.
COOKIE_JAR=$(mktemp "${TMPDIR:-/tmp}/wprisms-cookies.XXXXXX")
LOGIN_HEADERS=$(mktemp "${TMPDIR:-/tmp}/wprisms-login-headers.XXXXXX")
LOGIN_BODY=$(mktemp "${TMPDIR:-/tmp}/wprisms-login-body.XXXXXX")
curl --max-time 20 -sS -c "$COOKIE_JAR" "http://localhost:${CONF2_PORT}/wprism-login/" -o /dev/null \
  || fail "custom login cookie premise request failed"
LOGIN_CODE=$(curl --max-time 20 -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  -D "$LOGIN_HEADERS" -o "$LOGIN_BODY" -w '%{http_code}' -X POST \
  --data "log=admin&pwd=admin&wp-submit=Log+In&redirect_to=http%3A%2F%2Flocalhost%3A${CONF2_PORT}%2Fwp-admin%2F&testcookie=1" \
  "http://localhost:${CONF2_PORT}/wprism-login/")
LOGIN_LOCATION=$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/\r$/, ""); print substr($0, 11) }' "$LOGIN_HEADERS" | tail -1)
[ "$LOGIN_CODE" = 302 ] && [ "$LOGIN_LOCATION" = "http://localhost:${CONF2_PORT}/wp-admin/" ] \
  && grep -q 'wordpress_logged_in' "$COOKIE_JAR" \
  || fail "valid credentials through the custom route did not establish an authenticated WordPress session"
AUTH_ADMIN_CODE=$(curl --max-time 20 -sS -L -b "$COOKIE_JAR" -o "$LOGIN_BODY" -w '%{http_code}' "http://localhost:${CONF2_PORT}/wp-admin/")
[ "$AUTH_ADMIN_CODE" = 200 ] && grep -Fq '<body class="wp-admin ' "$LOGIN_BODY" \
  && grep -Fq 'id="wpadminbar"' "$LOGIN_BODY" && ! grep -Fq 'id="loginform"' "$LOGIN_BODY" \
  || fail "authenticated wp-admin request did not reach a native admin screen after core canonical redirects (status=$AUTH_ADMIN_CODE)"
rm -f "$COOKIE_JAR" "$LOGIN_HEADERS" "$LOGIN_BODY"
if wp_conf2 option get whl_redirect >/dev/null 2>&1; then
  fail "WPS Hide Login's first authenticated admin request did not consume its legacy one-shot whl_redirect marker"
fi
[ "$(wp_conf2 option get wps-hide-login-target-runtime-probe)" = target-only-neighbor ] \
  || fail "WPS Hide Login legacy-marker cleanup mutated the undeclared neighbor"
POST_ADMIN_REWRITE=$(wp_conf2 eval 'echo hash("sha256", maybe_serialize(get_option("rewrite_rules")));')
[ "$POST_ADMIN_REWRITE" = "$(jq -r '.rewrite_hash' <<<"$INITIAL")" ] \
  || fail "WPS Hide Login legacy-marker cleanup changed rewrite bytes"
pass "custom-path authentication succeeds, authenticated wp-admin remains reachable, and the plugin consumes only its legacy one-shot marker"

BEFORE_DEACTIVATE=$(observe_wps_hide_login conf2)
wp_conf2 plugin deactivate wps-hide-login >/dev/null
[ "$(wp_conf2 option get whl_page)" = "wprism-login" ] \
  && [ "$(wp_conf2 option get whl_redirect_admin)" = "wprism-missing" ] \
  || fail "WPS Hide Login deactivation changed authored route settings"
DEPLOY_AFTER_DEACTIVATE=$(wp_conf2 wprism deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "WPS Hide Login deploy after deactivation" json "$DEPLOY_AFTER_DEACTIVATE"
wp_conf2 plugin is-active wps-hide-login >/dev/null \
  || fail "WPrism deploy did not reactivate exact WPS Hide Login code"
AFTER_REACTIVATE=$(observe_wps_hide_login conf2)
[ "$(jq -r '.rewrite_hash' <<<"$AFTER_REACTIVATE")" = "$(jq -r '.rewrite_hash' <<<"$BEFORE_DEACTIVATE")" ] \
  || fail "WPS Hide Login deactivate/reactivate changed rewrite bytes despite having no rewrite lifecycle hook"
assert_wps_routes wprism-login wprism-missing
pass "deactivation preserves settings/rewrite state and deploy reactivation restores every request branch"

wp_conf2 plugin uninstall wps-hide-login --deactivate >/dev/null
if wp_conf2 plugin is-installed wps-hide-login >/dev/null 2>&1; then
  fail "WPS Hide Login uninstall left plugin code installed"
fi
if wp_conf2 option get whl_page >/dev/null 2>&1 || wp_conf2 option get whl_redirect_admin >/dev/null 2>&1; then
  fail "WPS Hide Login uninstall did not delete both plugin-owned route options"
fi
if wp_conf2 option get whl_redirect >/dev/null 2>&1; then
  fail "WPS Hide Login uninstall recreated its already-consumed legacy runtime marker"
fi
[ "$(wp_conf2 option get wps-hide-login-target-runtime-probe)" = "target-only-neighbor" ] \
  || fail "WPS Hide Login uninstall mutated undeclared neighboring state"
UNINSTALL_REWRITE_HASH=$(wp_conf2 eval 'echo hash("sha256", maybe_serialize(get_option("rewrite_rules")));')
require_observed_nonempty "WPS Hide Login rewrite hash after uninstall" "$UNINSTALL_REWRITE_HASH"

MISSING_BEFORE=$(wp_conf2 eval 'echo hash("sha256", maybe_serialize([get_option("whl_page", null), get_option("whl_redirect_admin", null), get_option("whl_redirect", null), get_option("wps-hide-login-target-runtime-probe", null), get_option("rewrite_rules")]));')
MISSING_RC=0
MISSING_OUT=$(wp_conf2 wprism deploy --repo=/siterepo 2>&1) || MISSING_RC=$?
require_wprism_answered "WPS Hide Login deploy with code absent" human "$MISSING_OUT"
[ "$MISSING_RC" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$MISSING_OUT" \
  || fail "missing WPS Hide Login code did not refuse at the compatibility boundary: $MISSING_OUT"
if wp_conf2 plugin is-installed wps-hide-login >/dev/null 2>&1; then
  fail "missing-code refusal installed WPS Hide Login as a fallback"
fi
MISSING_AFTER=$(wp_conf2 eval 'echo hash("sha256", maybe_serialize([get_option("whl_page", null), get_option("whl_redirect_admin", null), get_option("whl_redirect", null), get_option("wps-hide-login-target-runtime-probe", null), get_option("rewrite_rules")]));')
[ "$MISSING_AFTER" = "$MISSING_BEFORE" ] || fail "missing-code refusal partially mutated WPS Hide Login target state"

WPS_SHA=34e99ee6032f5863b278aef690ebbfdb00290beb4eacbdae3ab931eb719b3a58
WPS_ARTIFACT="/artifacts-cache/plugin-wps-hide-login-1.9.19-${WPS_SHA}.zip"
OBSERVED_SHA=$(wp_conf2 eval "echo hash_file('sha256', '$WPS_ARTIFACT');")
require_observed_nonempty "WPS Hide Login cached artifact digest" "$OBSERVED_SHA"
[ "$OBSERVED_SHA" = "$WPS_SHA" ] \
  || fail "WPS Hide Login reinstall artifact digest moved (expected=$WPS_SHA actual=$OBSERVED_SHA)"
wp_conf2 plugin install "$WPS_ARTIFACT" --force >/dev/null
[ "$(wp_conf2 plugin get wps-hide-login --field=version)" = "1.9.19" ] \
  || fail "WPS Hide Login exact reinstall reported the wrong version"
REINSTALL_DEPLOY=$(wp_conf2 wprism deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "WPS Hide Login deploy after exact reinstall" json "$REINSTALL_DEPLOY"
if wp_conf2 option get whl_page >/dev/null 2>&1 || wp_conf2 option get whl_redirect_admin >/dev/null 2>&1; then
  fail "WPS Hide Login activation invented authored route options before repository reconciliation"
fi
[ "$(wp_conf2 eval 'echo hash("sha256", maybe_serialize(get_option("rewrite_rules")));')" = "$UNINSTALL_REWRITE_HASH" ] \
  || fail "WPS Hide Login exact reinstall/activation changed rewrite bytes"
install_wps_rewrite_probe

# Exact uninstall removed state in the synced base. Publish new source intent
# so target absence plus repository change becomes an explicit conflict; only
# the reviewed force flag may recover it.
save_wps_profile conf1 reinstall
wp_conf1 wprism capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: WPS Hide Login reinstall recovery intent'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
REINSTALL_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "WPS Hide Login reinstall recovery plan" json "$REINSTALL_PLAN"
jq -e '.conflict | any(.uuid == "options/core" and .type == "options")' <<<"$REINSTALL_PLAN" >/dev/null \
  || fail "WPS Hide Login reinstall absence plus new source intent did not become a typed conflict: $REINSTALL_PLAN"
REINSTALL_REWRITE_BEFORE=$(wp_conf2 option get wps-hide-login-target-rewrite-hash)
REINSTALL_RC=0
REINSTALL_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || REINSTALL_RC=$?
require_wprism_answered "WPS Hide Login unforced reinstall recovery" human "$REINSTALL_OUT"
[ "$REINSTALL_RC" -ne 0 ] && grep -qi 'conflicts (env and repo both changed' <<<"$REINSTALL_OUT" \
  || fail "WPS Hide Login reinstall recovery did not refuse before explicit authorization: $REINSTALL_OUT"
if wp_conf2 option get whl_page >/dev/null 2>&1 || wp_conf2 option get whl_redirect_admin >/dev/null 2>&1; then
  fail "unforced WPS Hide Login reinstall conflict partially recreated authored settings"
fi
[ "$(wp_conf2 eval 'echo hash("sha256", maybe_serialize(get_option("rewrite_rules")));')" = "$REINSTALL_REWRITE_BEFORE" ] \
  || fail "unforced WPS Hide Login reinstall conflict changed rewrite bytes"
REINSTALL_APPLY=$(wp_conf2 wprism apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "WPS Hide Login forced reinstall recovery" json "$REINSTALL_APPLY"
[ "$(jq -r '.canary' <<<"$REINSTALL_APPLY")" = clean ] \
  || fail "WPS Hide Login forced reinstall recovery dirtied the apply canary: $REINSTALL_APPLY"
jq -e '.warnings | any(contains("FORCED conflict options/core"))' <<<"$REINSTALL_APPLY" >/dev/null \
  || fail "WPS Hide Login forced reinstall recovery did not report its override: $REINSTALL_APPLY"
RECOVERED=$(observe_wps_hide_login conf2)
printf '%s\n' "$RECOVERED" | jq -e '
  .login == "recovered-login" and .redirect == "recovered-missing" and
  .runtime == null and .neighbor == "target-only-neighbor" and
  .sentinel == true and .rewrite_hash == .rewrite_probe_hash
' >/dev/null || fail "WPS Hide Login exact reinstall/apply did not recover source behavior without rewrite mutation: $RECOVERED"
assert_wps_routes recovered-login recovered-missing
pass "uninstall cleanup, missing-code refusal, digest-bound reinstall, typed conflict, explicit recovery, and retry restore real routing without partial state"

save_wps_profile conf1 repository
wp_conf1 wprism capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: WPS Hide Login repository branch intent'
git -C "$CONF_REPO1" push -q origin main
save_wps_profile conf2 target
git -C "$CONF_REPO2" pull -q origin main

CONFLICT_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "WPS Hide Login competing-settings plan" json "$CONFLICT_PLAN"
jq -e '.conflict | any(.uuid == "options/core" and .type == "options")' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "competing WPS Hide Login slugs did not produce a typed options conflict: $CONFLICT_PLAN"
CONFLICT_BEFORE=$(observe_wps_hide_login conf2)
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_wprism_answered "WPS Hide Login unforced competing-settings apply" human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi 'conflicts (env and repo both changed' <<<"$CONFLICT_OUT" \
  || fail "WPS Hide Login competing settings did not refuse before mutation: $CONFLICT_OUT"
CONFLICT_AFTER=$(observe_wps_hide_login conf2)
[ "$CONFLICT_AFTER" = "$CONFLICT_BEFORE" ] \
  || fail "unforced WPS Hide Login conflict partially mutated target options, runtime state, or rewrite bytes"
FORCED=$(wp_conf2 wprism apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "WPS Hide Login forced competing-settings apply" json "$FORCED"
jq -e '.warnings | any(contains("FORCED conflict options/core"))' <<<"$FORCED" >/dev/null \
  || fail "forced WPS Hide Login conflict did not report its destructive override: $FORCED"
BRANCH=$(observe_wps_hide_login conf2)
printf '%s\n' "$BRANCH" | jq -e '
  .login == "branch-login" and .redirect == "branch-missing" and
  .runtime == null and .neighbor == "target-only-neighbor" and
  .sentinel == true and .rewrite_hash == .rewrite_probe_hash
' >/dev/null || fail "forced WPS Hide Login conflict did not converge without collateral mutation: $BRANCH"
assert_wps_routes branch-login branch-missing

ZERO_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "WPS Hide Login zero-change plan" json "$ZERO_PLAN"
jq -e '(.create|length)==0 and (.update|length)==0 and (.conflict|length)==0 and (.drift|length)==0' <<<"$ZERO_PLAN" >/dev/null \
  || fail "WPS Hide Login retry was not a zero-change plan: $ZERO_PLAN"
ZERO_APPLY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "WPS Hide Login zero-change apply" json "$ZERO_APPLY"
[ "$(jq -r '.canary' <<<"$ZERO_APPLY")" = clean ] \
  || fail "WPS Hide Login zero-change retry dirtied the apply canary: $ZERO_APPLY"
pass "competing route branches refuse atomically, forced intent converges, real routes switch, and retry is idempotent"

wp_conf1 eval "delete_option('whl_redirect_admin'); flush_rewrite_rules(true);" >/dev/null
wp_conf1 wprism capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: delete WPS Hide Login redirect setting'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main

DELETE_RC=0
DELETE_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || DELETE_RC=$?
require_wprism_answered "WPS Hide Login option deletion without authorization" human "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] && grep -q 'authored option deletion intent requires --with-deletes' <<<"$DELETE_OUT" \
  || fail "WPS Hide Login option deletion did not require explicit authorization: $DELETE_OUT"
[ "$(wp_conf2 option get whl_redirect_admin)" = branch-missing ] \
  || fail "unauthorized WPS Hide Login option deletion partially mutated the target"
DELETE_APPLY=$(wp_conf2 wprism apply --repo=/siterepo --with-deletes --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "WPS Hide Login authorized option deletion" json "$DELETE_APPLY"
if wp_conf2 option get whl_redirect_admin >/dev/null 2>&1; then
  fail "authorized WPS Hide Login redirect deletion left the row present"
fi
[ "$(wp_conf2 option get whl_page)" = branch-login ] \
  && [ "$(wp_conf2 option get wps-hide-login-target-runtime-probe)" = target-only-neighbor ] \
  || fail "authorized WPS Hide Login deletion mutated its sibling, runtime row, or undeclared neighbor"
if wp_conf2 option get whl_redirect >/dev/null 2>&1; then
  fail "authorized WPS Hide Login deletion recreated the consumed legacy runtime marker"
fi
wps_request GET '/wp-admin/'
[ "$WPS_CODE" = 302 ] && [ "$WPS_LOCATION" = "http://localhost:${CONF2_PORT}/404/" ] \
  || fail "deleted redirect option did not expose WPS Hide Login's native /404/ fallback"
# The authenticated admin journey above can leave core's transient Customizer
# sentinel at -1; it is not adapter state and would make capture warn here.
wp_conf2 eval "remove_theme_mod('custom_css_post_id');" >/dev/null
wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-wps-hide-login-delete-state >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-wps-hide-login-delete-state" \
  || fail "WPS Hide Login authorized deletion did not recapture byte-identically"
rm -rf "$CONF_REPO2/.tmp-wps-hide-login-delete-state"
pass "redirect deletion refuses without authorization, deletes one row exactly, preserves siblings, activates the native fallback, and recaptures identically"

# The plugin uses WordPress's sanitizer and URL comparisons. Exercise the
# empty fallback and the sanitizer's 200-byte ceiling as real routes, restoring
# the canonical slug after each target-local probe.
LONG_SLUG=$(wp_conf2 eval 'echo sanitize_title_with_dashes(str_repeat("A", 240));')
require_observed_nonempty "WPS Hide Login long slug" "$LONG_SLUG"
[ "${#LONG_SLUG}" -eq 200 ] || fail "WPS Hide Login long slug is not capped at 200 bytes"
wp_conf2 option update whl_page "$LONG_SLUG" >/dev/null
wps_request GET "/${LONG_SLUG}/"
[ "$WPS_CODE" = 200 ] && grep -Fq 'id="loginform"' "$WPS_BODY" \
  || fail "the sanitizer's maximum-length ASCII slug did not route to login (status=$WPS_CODE)"
wp_conf2 option delete whl_page >/dev/null
wps_request GET '/login/'
[ "$WPS_CODE" = 200 ] && grep -Fq 'id="loginform"' "$WPS_BODY" \
  || fail "an absent login option did not use WPS Hide Login's native /login/ fallback"
wp_conf2 option update whl_page branch-login >/dev/null
pass "empty and maximum-length ASCII slug boundaries execute through native routing; traversal/path/Unicode normalization bytes are pinned by the live sanitizer observation"

# Switch to plain permalinks and prove the plugin's separate query-parameter
# branch, then restore the canonical pretty-permalink platform state.
wp_conf2 option update permalink_structure '' >/dev/null
wp_conf2 rewrite flush --hard >/dev/null 2>&1 || true
wps_request GET '/?branch-login'
[ "$WPS_CODE" = 200 ] && grep -Fq 'id="loginform"' "$WPS_BODY" \
  || fail "plain-permalink ?branch-login did not serve the custom login form"
wps_request GET '/wp-login.php'
[[ "$WPS_CODE" =~ ^(301|404)$ ]] && ! grep -Fq 'id="loginform"' "$WPS_BODY" \
  && [[ "$WPS_LOCATION" != *branch-login* ]] \
  || fail "plain-permalink direct wp-login.php exposed or leaked the custom route (status=$WPS_CODE location=${WPS_LOCATION:-<none>})"
wps_request GET '/wp-admin/'
[ "$WPS_CODE" = 302 ] && [ "$WPS_LOCATION" = "http://localhost:${CONF2_PORT}/?404" ] \
  || fail "plain-permalink anonymous wp-admin did not use the native ?404 fallback (status=$WPS_CODE location=${WPS_LOCATION:-<none>})"
wp_conf2 option update permalink_structure '/%postname%/' >/dev/null
wp_conf2 rewrite flush --hard >/dev/null 2>&1 || true
wps_request GET '/branch-login/'
[ "$WPS_CODE" = 200 ] && grep -Fq 'id="loginform"' "$WPS_BODY" \
  || fail "pretty-permalink routing did not recover after the plain-mode probe"
pass "pretty and plain permalink modes hide direct login, route the custom endpoint, redirect wp-admin, and restore cleanly"
