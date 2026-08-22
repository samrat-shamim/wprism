#!/usr/bin/env bash
# Author the two single-site route slugs through the exact sanitizer WPS Hide
# Login registers with WordPress. The source follows the plugin's settings-save
# side effect and flushes once; the target proof deliberately does not.
set -euo pipefail

read -r -d '' SEED_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);

$login = sanitize_title_with_dashes('Duo Login');
$redirect = sanitize_title_with_dashes('Duo Missing');
$unicode = sanitize_title_with_dashes("Duo Login 東京 🚀");
$long = sanitize_title_with_dashes(str_repeat('A', 240));
if ($login !== 'duo-login' || $redirect !== 'duo-missing'
    || $unicode !== 'duo-login-%e6%9d%b1%e4%ba%ac-%f0%9f%9a%80'
    || strlen($long) !== 200
    || sanitize_title_with_dashes('../login') !== 'login'
    || sanitize_title_with_dashes('login/foo') !== 'loginfoo'
    || sanitize_title_with_dashes('   ') !== '') {
    throw new RuntimeException('WPS Hide Login/WordPress slug normalization changed');
}

update_option('whl_page', $login);
update_option('whl_redirect_admin', $redirect);
// The legacy activation marker is runtime-owned and must not enter canonical
// state even when it exists on the authoring site.
update_option('whl_redirect', 'source-only-runtime-marker');
flush_rewrite_rules(true);

$control = wp_insert_post([
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_title' => 'WPS Hide Login Public Control',
    'post_name' => 'wps-hide-login-public-control',
    'post_content' => '<p class="wps-hide-login-public-marker">ordinary public routing remains available</p>',
], true);
if (is_wp_error($control) || !$control) {
    throw new RuntimeException('WPS Hide Login public route control was not persisted');
}

echo wp_json_encode([
    'control' => (int) $control,
    'login' => get_option('whl_page'),
    'login_url' => wp_login_url(),
    'redirect' => get_option('whl_redirect_admin'),
    'rewrite_hash' => hash('sha256', maybe_serialize(get_option('rewrite_rules'))),
], JSON_UNESCAPED_SLASHES);
PHPEOF

SEED_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-wps-hide-login-seed.php"
printf '%s' "$SEED_PHP" > "$SEED_FILE"
SEED_OUT=$(wp_conf1 eval-file /siterepo/.tmp-wps-hide-login-seed.php)
require_observed_nonempty "WPS Hide Login source seed" "$SEED_OUT"
SEED_JSON=$(printf '%s\n' "$SEED_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$SEED_JSON" | jq -e '
  .control > 0 and .login == "duo-login" and .redirect == "duo-missing" and
  (.login_url | endswith("/duo-login/")) and
  (.rewrite_hash | test("^[0-9a-f]{64}$"))
' >/dev/null || fail "WPS Hide Login source settings or rewrite premise did not land: $SEED_JSON"
rm -f "$SEED_FILE"
pass "WPS Hide Login source uses sanitized slugs, a native settings-path rewrite flush, and an ordinary public route control"
