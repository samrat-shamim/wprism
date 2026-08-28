#!/usr/bin/env bash
# Persist a wide toolbar/admin fixture through the exact plugin's private
# import/save path. Deliberately hostile unknown and long UTF-8 identifiers
# prove that the plugin's whitelist, not the fixture, defines portable bytes.
set -euo pipefail

read -r -d '' SEED_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
$instance = null;
foreach (($GLOBALS['wp_filter']['mce_buttons']->callbacks ?? []) as $callbacks) {
    foreach ($callbacks as $callback) {
        $fn = $callback['function'] ?? null;
        if (is_array($fn) && is_object($fn[0] ?? null) && ($fn[1] ?? null) === 'mce_buttons_1') {
            $instance = $fn[0];
            break 2;
        }
    }
}
if (!$instance) {
    throw new RuntimeException('Advanced Editor Tools live callback instance was not registered');
}
$hostile = str_repeat("unknown-未知-\u{1F680}", 128);
$save = new ReflectionMethod($instance, 'save_settings');
$save->invoke($instance, [
    'settings' => [
        'toolbar_1' => "bold,italic,underline,strikethrough,$hostile",
        'toolbar_2' => 'bullist,numlist,blockquote,link,unlink',
        'toolbar_3' => 'forecolor,backcolor,removeformat,charmap',
        'toolbar_4' => 'code,fullscreen,searchreplace',
        'toolbar_classic_block' => 'bold,italic,link,undo,redo',
        'options' => "menubar,menubar_block,merge_toolbars,$hostile",
        'plugins' => $hostile,
    ],
    'admin_settings' => [
        'options' => "no_autop,table_resize_bars,$hostile",
        'disabled_editors' => 'rest_of_wpadmin,invalid-editor-location',
    ],
]);
$settings = get_option('tadv_settings');
$admin = get_option('tadv_admin_settings');
if (str_contains(wp_json_encode([$settings, $admin]), 'unknown-')) {
    throw new RuntimeException('Advanced Editor Tools persisted an identifier outside its whitelist');
}
echo wp_json_encode(['admin' => $admin, 'settings' => $settings]);
PHPEOF

SEED_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-advanced-editor-tools-seed.php"
printf '%s' "$SEED_PHP" > "$SEED_FILE"
SEED_OUT=$(wp_conf1 eval-file /siterepo/.tmp-advanced-editor-tools-seed.php)
require_observed_nonempty "Advanced Editor Tools source seed" "$SEED_OUT"
SEED_JSON=$(printf '%s\n' "$SEED_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$SEED_JSON" | jq -e '
  .settings.toolbar_1 == "bold,italic,underline,strikethrough" and
  .settings.toolbar_2 == "bullist,numlist,blockquote,link,unlink" and
  .settings.toolbar_3 == "forecolor,backcolor,removeformat,charmap" and
  .settings.toolbar_4 == "code,fullscreen,searchreplace" and
  .settings.toolbar_classic_block == "bold,italic,link,undo,redo" and
  .admin.options == "no_autop,table_resize_bars" and
  .admin.disabled_editors == "rest_of_wpadmin"
' >/dev/null || fail "Advanced Editor Tools did not sanitize/persist the wide source fixture as expected: $SEED_JSON"
rm -f "$SEED_FILE"
pass "Advanced Editor Tools persisted every toolbar surface while stripping unknown long UTF-8 import values"
