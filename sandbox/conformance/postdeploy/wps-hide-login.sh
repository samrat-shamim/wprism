#!/usr/bin/env bash
# Start the target with opposite owned slugs, runtime/neighbor state, and a
# deliberately stale rewrite rule. WPS Hide Login registers no rewrite rules;
# preserving this exact byte set while real requests converge proves its
# routes are option-driven and require no repair provider.
set -euo pipefail

read -r -d '' HOSTILE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
update_option('whl_page', sanitize_title_with_dashes('Target Login'));
update_option('whl_redirect_admin', sanitize_title_with_dashes('Target Missing'));
update_option('whl_redirect', 'target-only-runtime-marker');
update_option('wps-hide-login-target-runtime-probe', 'target-only-neighbor');

$rules = get_option('rewrite_rules');
if (!is_array($rules) || !$rules) {
    throw new RuntimeException('target rewrite rules are unavailable for the stale-state premise');
}
$rules = ['^target-only-wps-route/?$' => 'index.php?wps_target_only=1'] + $rules;
update_option('rewrite_rules', $rules);
$hash = hash('sha256', maybe_serialize(get_option('rewrite_rules')));
update_option('wps-hide-login-target-rewrite-hash', $hash);

echo wp_json_encode([
    'login' => get_option('whl_page'),
    'neighbor' => get_option('wps-hide-login-target-runtime-probe'),
    'redirect' => get_option('whl_redirect_admin'),
    'rewrite_hash' => $hash,
    'runtime' => get_option('whl_redirect'),
    'sentinel' => array_key_exists('^target-only-wps-route/?$', (array) get_option('rewrite_rules')),
], JSON_UNESCAPED_SLASHES);
PHPEOF

HOSTILE_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-wps-hide-login-hostile.php"
printf '%s' "$HOSTILE_PHP" > "$HOSTILE_FILE"
HOSTILE_OUT=$(wp_conf2 eval-file /siterepo/.tmp-wps-hide-login-hostile.php)
require_observed_nonempty "WPS Hide Login hostile target seed" "$HOSTILE_OUT"
HOSTILE_JSON=$(printf '%s\n' "$HOSTILE_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$HOSTILE_JSON" | jq -e '
  .login == "target-login" and .redirect == "target-missing" and
  .runtime == "target-only-runtime-marker" and
  .neighbor == "target-only-neighbor" and .sentinel == true and
  (.rewrite_hash | test("^[0-9a-f]{64}$"))
' >/dev/null || fail "WPS Hide Login hostile target premise did not land: $HOSTILE_JSON"
rm -f "$HOSTILE_FILE"
pass "WPS Hide Login target starts with opposite slugs, runtime/neighbor state, and stale rewrite bytes"
